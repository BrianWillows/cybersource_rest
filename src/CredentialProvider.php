<?php

declare(strict_types=1);

namespace Drupal\cybersource_rest;

use Drupal\Core\File\FileSystemInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Loads Cybersource REST API credentials from a fixed private file.
 *
 * Credentials are deliberately kept OUT of Drupal config (and therefore out of
 * git / config exports) AND the file location is hardcoded — it is NOT
 * configurable. A configurable path would let an attacker with admin rights
 * point the gateway at credentials they control. The file always lives at
 * private://keys/cybersource_rest.yml (the private filesystem, outside docroot).
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
   * Parsed YAML cache, keyed by realpath, with mtime for invalidation.
   *
   * @var array<string, array{mtime:int, data:array<string, mixed>}>
   */
  protected array $cache = [];

  public function __construct(
    protected LoggerInterface $logger,
    protected FileSystemInterface $fileSystem,
  ) {}

  /**
   * Resolve the fixed credentials URI to an absolute path.
   *
   * @return string
   *   The absolute filesystem path.
   *
   * @throws \RuntimeException
   *   If the private filesystem is unconfigured or the file is absent.
   */
  protected function resolvePath(): string {
    $real = $this->fileSystem->realpath(self::CREDENTIALS_URI);
    if ($real === FALSE || !file_exists($real)) {
      throw new \RuntimeException(sprintf(
        'Cybersource REST credentials file not found at %s. Configure the private filesystem and place the file there.',
        self::CREDENTIALS_URI
      ));
    }
    return $real;
  }

  /**
   * Parse and cache the credentials file.
   *
   * @return array<string, mixed>
   *   The parsed YAML.
   *
   * @throws \RuntimeException
   *   If the file is missing, unreadable, or invalid.
   */
  public function load(): array {
    $real = $this->resolvePath();
    if (!is_readable($real)) {
      throw new \RuntimeException(sprintf('Cybersource REST credentials file is not readable: %s', self::CREDENTIALS_URI));
    }
    // Warn once if the file is world-readable; it should be web-user-only.
    static $warned = FALSE;
    if (!$warned && ($perms = @fileperms($real)) !== FALSE && ($perms & 0004)) {
      $this->logger->warning('Cybersource REST credentials file @uri is world-readable; restrict it to the web server user.', ['@uri' => self::CREDENTIALS_URI]);
      $warned = TRUE;
    }
    $mtime = (int) filemtime($real);
    if (isset($this->cache[$real]) && $this->cache[$real]['mtime'] === $mtime) {
      return $this->cache[$real]['data'];
    }
    try {
      $data = Yaml::parseFile($real);
    }
    catch (\Throwable $e) {
      throw new \RuntimeException(sprintf('Cybersource REST credentials file is not valid YAML: %s', $e->getMessage()), 0, $e);
    }
    if (!is_array($data)) {
      throw new \RuntimeException('Cybersource REST credentials file did not parse to a mapping.');
    }
    $this->cache[$real] = ['mtime' => $mtime, 'data' => $data];
    return $data;
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
   * Operators may copy a REST API key's expiry / rotation date from the Business
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
