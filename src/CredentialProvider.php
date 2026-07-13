<?php

declare(strict_types=1);

namespace Drupal\cybersource_rest;

use Drupal\Core\StreamWrapper\LocalStream;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Loads Cybersource REST API credentials from a fixed private file.
 *
 * Credentials are deliberately kept OUT of Drupal config, and the file
 * location is fixed (not configurable), because:
 * - Secrets that never enter config entities cannot leak through config
 *   export (drush cex), git, config sync between environments, or a copied
 *   staging database.
 * - Reading or replacing the shared secret always requires filesystem
 *   (deployment) access; no Drupal role or admin form exposes or accepts
 *   credential material.
 * - A single well-known URI keeps the requirements check, the admin
 *   messages and the documentation unambiguous.
 *
 * All access goes through the private:// stream wrapper (PHP filesystem
 * functions accept stream URIs), so a private filesystem backed by a remote
 * wrapper (e.g. S3) behaves the same as local disk.
 *
 * The file holds one block per mode (`test`, `live`); each block carries the
 * three HTTP-Signature credentials: merchant_id, key_id (the keyId / Serial
 * Number) and shared_secret. One Cybersource merchant account serves all
 * currencies, so — unlike Secure Acceptance — there is no per-currency profile.
 */
final class CredentialProvider {

  /**
   * Fixed credentials file URI. Not configurable by design (see class docs).
   */
  public const CREDENTIALS_URI = 'private://keys/cybersource_rest.yml';

  /**
   * The credential keys every complete profile must carry.
   */
  private const REQUIRED_KEYS = ['merchant_id', 'key_id', 'shared_secret'];

  /**
   * Parsed YAML cache, keyed by URI, with mtime for invalidation.
   *
   * @var array<string, array{mtime:int, data:array<string, mixed>}>
   */
  protected array $cache = [];

  public function __construct(
    protected LoggerInterface $logger,
    protected StreamWrapperManagerInterface $streamWrapperManager,
  ) {}

  /**
   * Parse and cache the credentials file.
   *
   * @return array<string, mixed>
   *   The parsed YAML.
   *
   * @throws \RuntimeException
   *   If the private filesystem is unconfigured, or the file is missing,
   *   unreadable, or invalid.
   */
  public function load(): array {
    $uri = self::CREDENTIALS_URI;
    if (!$this->streamWrapperManager->isValidUri($uri)) {
      throw new \RuntimeException(sprintf(
        'The private filesystem is not configured, so %s cannot exist. Set $settings["file_private_path"].',
        $uri
      ));
    }
    if (!file_exists($uri)) {
      throw new \RuntimeException(sprintf(
        'Cybersource REST credentials file not found at %s. Place the file there.',
        $uri
      ));
    }
    if (!is_readable($uri)) {
      throw new \RuntimeException(sprintf('Cybersource REST credentials file is not readable: %s', $uri));
    }
    $this->warnIfWorldReadable();
    $mtime = (int) @filemtime($uri);
    if (isset($this->cache[$uri]) && $this->cache[$uri]['mtime'] === $mtime) {
      return $this->cache[$uri]['data'];
    }
    $raw = @file_get_contents($uri);
    if ($raw === FALSE) {
      throw new \RuntimeException(sprintf('Cybersource REST credentials file could not be read: %s', $uri));
    }
    try {
      $data = Yaml::parse($raw);
    }
    catch (\Throwable $e) {
      throw new \RuntimeException(sprintf('Cybersource REST credentials file is not valid YAML: %s', $e->getMessage()), 0, $e);
    }
    if (!is_array($data)) {
      throw new \RuntimeException('Cybersource REST credentials file did not parse to a mapping.');
    }
    $this->cache[$uri] = ['mtime' => $mtime, 'data' => $data];
    return $data;
  }

  /**
   * Warn once per request if the credentials file is world-readable.
   *
   * File permissions are only meaningful when the private filesystem lives
   * on local disk, and FileSystemInterface::realpath() may only be used on
   * stream wrappers known to be local — so the check runs only for
   * LocalStream wrappers and is skipped for remote ones (e.g. S3), whose
   * access control is the remote service's concern.
   */
  protected function warnIfWorldReadable(): void {
    static $checked = FALSE;
    if ($checked) {
      return;
    }
    $checked = TRUE;
    $wrapper = $this->streamWrapperManager->getViaUri(self::CREDENTIALS_URI);
    if (!$wrapper instanceof LocalStream) {
      return;
    }
    // StreamWrapperInterface::realpath() is typed string but documents (and
    // LocalStream implements) FALSE on failure — e.g. a vfs-backed test
    // filesystem — so guard by truthiness rather than !== FALSE.
    $real = $wrapper->realpath();
    if ($real && ($perms = @fileperms($real)) !== FALSE && ($perms & 0004)) {
      $this->logger->warning('Cybersource REST credentials file @uri is world-readable; restrict it to the web server user.', ['@uri' => self::CREDENTIALS_URI]);
    }
  }

  /**
   * Resolve the complete credentials for a given mode.
   *
   * @param string $mode
   *   The mode: 'test' or 'live'.
   *
   * @return array{merchant_id: string, key_id: string, shared_secret: string}
   *   The profile keyed by merchant_id, key_id and shared_secret.
   *
   * @throws \RuntimeException
   *   If no complete profile exists for the mode.
   */
  public function getProfile(string $mode): array {
    $mode = strtolower($mode) === 'live' ? 'live' : 'test';
    $profile = $this->load()[$mode] ?? NULL;

    if (!is_array($profile)) {
      throw new \RuntimeException(sprintf('No Cybersource REST profile configured for mode "%s".', $mode));
    }
    foreach (self::REQUIRED_KEYS as $key) {
      if (empty($profile[$key])) {
        throw new \RuntimeException(sprintf('Cybersource REST profile for mode "%s" is missing "%s".', $mode, $key));
      }
    }
    return [
      'merchant_id' => (string) $profile['merchant_id'],
      'key_id' => (string) $profile['key_id'],
      'shared_secret' => (string) $profile['shared_secret'],
    ];
  }

  /**
   * Whether a (complete) profile is configured for the given mode.
   *
   * @param string $mode
   *   The mode: 'test' or 'live'.
   *
   * @return bool
   *   TRUE if a complete profile exists for the mode.
   */
  public function hasProfile(string $mode): bool {
    $mode = strtolower($mode) === 'live' ? 'live' : 'test';
    return $this->isCompleteProfile($this->load()[$mode] ?? NULL);
  }

  /**
   * List the modes ('test'/'live') that have a COMPLETE profile.
   *
   * @return string[]
   *   The configured modes.
   */
  public function configuredModes(): array {
    $data = $this->load();
    $modes = [];
    foreach (['test', 'live'] as $mode) {
      if ($this->isCompleteProfile($data[$mode] ?? NULL)) {
        $modes[] = $mode;
      }
    }
    return $modes;
  }

  /**
   * Whether a profile block carries all required HTTP-Signature credentials.
   *
   * Mirrors the per-key check getProfile() enforces, so an incomplete profile
   * is treated as missing everywhere (status report, requirements) rather than
   * appearing valid until it fails at checkout.
   *
   * @param mixed $profile
   *   A parsed profile block (expected to be an array), or NULL.
   *
   * @return bool
   *   TRUE if $profile is an array with non-empty merchant_id, key_id and
   *   shared_secret.
   */
  protected function isCompleteProfile(mixed $profile): bool {
    if (!is_array($profile)) {
      return FALSE;
    }
    foreach (self::REQUIRED_KEYS as $key) {
      if (empty($profile[$key])) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Optional key-expiry dates per mode.
   *
   * Operators may copy a REST API key's expiry / rotation date from the
   * Business
   * Center into an optional `key_expiry` field (YYYY-MM-DD). Used only for the
   * status-report reminder; never required for authentication.
   *
   * @return array<string, string>
   *   Keyed by mode ('test' / 'live') => 'YYYY-MM-DD'.
   */
  public function keyExpiries(): array {
    $data = $this->load();
    $out = [];
    foreach (['test', 'live'] as $mode) {
      $profile = $data[$mode] ?? NULL;
      if (is_array($profile) && !empty($profile['key_expiry'])) {
        $out[$mode] = (string) $profile['key_expiry'];
      }
    }
    return $out;
  }

}
