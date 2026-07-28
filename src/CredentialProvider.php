<?php

declare(strict_types=1);

namespace Drupal\cybersource_rest;

use Drupal\Core\Site\Settings;
use Psr\Log\LoggerInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Loads Cybersource REST API credentials from $settings.
 *
 * Credentials are deliberately kept OUT of Drupal config, and there is no
 * admin UI for them, because:
 * - Secrets that never enter config entities cannot leak through config
 *   export (drush cex), git, config sync between environments, or a copied
 *   staging database.
 * - Reading or replacing the shared secret always requires filesystem
 *   (deployment) access; no Drupal role or admin form exposes or accepts
 *   credential material.
 *
 * Where the credentials come from is a per-site deployment decision made in
 * settings.php, checked in this order:
 * - $settings['cybersource_rest.credentials']: the parsed credentials array
 *   itself. Because settings.php is PHP, a site can populate this from
 *   whatever secret store its host provides (a platform secrets API,
 *   getenv(), an include kept out of version control). The secrets need
 *   never touch the site's filesystem. Never write literal key values into
 *   a settings.php that is committed to version control.
 * - $settings['cybersource_rest.credentials_file']: absolute path to a YAML
 *   file, recommended outside the web root and outside the Drupal-managed
 *   public/private filesystems, readable by the web server user only.
 *
 * The file holds one block per mode (`test`, `live`); each block carries the
 * three HTTP-Signature credentials: merchant_id, key_id (the keyId / Serial
 * Number) and shared_secret. One Cybersource merchant account serves all
 * currencies, so there is no per-currency profile.
 */
final class CredentialProvider {

  /**
   * Settings key for inline credentials (the parsed array itself).
   */
  public const SETTING_CREDENTIALS = 'cybersource_rest.credentials';

  /**
   * Settings key for the absolute path of the credentials YAML file.
   */
  public const SETTING_CREDENTIALS_FILE = 'cybersource_rest.credentials_file';

  /**
   * The credential keys every complete profile must carry.
   */
  private const REQUIRED_KEYS = ['merchant_id', 'key_id', 'shared_secret'];

  /**
   * Parsed YAML cache, keyed by path, with mtime for invalidation.
   *
   * @var array<string, array{mtime:int, data:array<string, mixed>}>
   */
  protected array $cache = [];

  public function __construct(
    protected LoggerInterface $logger,
    protected Settings $settings,
  ) {}

  /**
   * Whether either credentials setting is present in settings.php.
   *
   * Presence only — the credentials may still be incomplete or invalid;
   * load() reports that with its own actionable messages.
   */
  public function isConfigured(): bool {
    return $this->settings->get(self::SETTING_CREDENTIALS) !== NULL
      || $this->settings->get(self::SETTING_CREDENTIALS_FILE) !== NULL;
  }

  /**
   * A printable description of where the credentials come from.
   *
   * Safe to show to admins (it never contains secret values): either the
   * settings key for inline credentials, or the configured file path.
   */
  public function source(): string {
    if ($this->settings->get(self::SETTING_CREDENTIALS) !== NULL) {
      return "\$settings['" . self::SETTING_CREDENTIALS . "']";
    }
    $path = $this->settings->get(self::SETTING_CREDENTIALS_FILE);
    if (is_string($path) && $path !== '') {
      return $path;
    }
    return "\$settings['" . self::SETTING_CREDENTIALS_FILE . "']";
  }

  /**
   * Resolve and cache the credentials.
   *
   * @return array<string, mixed>
   *   The credentials array (see the class docs for the structure).
   *
   * @throws \RuntimeException
   *   If neither setting is configured, or the configured value/file is
   *   missing, unreadable, or invalid.
   */
  public function load(): array {
    $inline = $this->settings->get(self::SETTING_CREDENTIALS);
    if ($inline !== NULL) {
      if (!is_array($inline)) {
        throw new \RuntimeException(sprintf(
          '$settings["%s"] must be an array (see cybersource_rest.credentials.example.yml for the structure).',
          self::SETTING_CREDENTIALS
        ));
      }
      return $inline;
    }

    $path = $this->settings->get(self::SETTING_CREDENTIALS_FILE);
    if (!is_string($path) || $path === '') {
      throw new \RuntimeException(sprintf(
        'No Cybersource REST credentials are configured. In settings.php set $settings["%s"] to the absolute path of your credentials YAML file (outside the web root), or provide the credentials array as $settings["%s"]. See README.md.',
        self::SETTING_CREDENTIALS_FILE,
        self::SETTING_CREDENTIALS
      ));
    }
    if (!file_exists($path)) {
      throw new \RuntimeException(sprintf(
        'Cybersource REST credentials file not found at %s (from $settings["%s"]). Place the file there or correct the setting.',
        $path,
        self::SETTING_CREDENTIALS_FILE
      ));
    }
    if (!is_readable($path)) {
      throw new \RuntimeException(sprintf('Cybersource REST credentials file is not readable: %s', $path));
    }
    $this->warnIfWorldReadable($path);
    $mtime = (int) @filemtime($path);
    if (isset($this->cache[$path]) && $this->cache[$path]['mtime'] === $mtime) {
      return $this->cache[$path]['data'];
    }
    $raw = @file_get_contents($path);
    if ($raw === FALSE) {
      throw new \RuntimeException(sprintf('Cybersource REST credentials file could not be read: %s', $path));
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
    $this->cache[$path] = ['mtime' => $mtime, 'data' => $data];
    return $data;
  }

  /**
   * Warn once per request if the credentials file is world-readable.
   *
   * Best-effort: fileperms() only means anything for plain local paths, and
   * returns FALSE for paths it cannot stat, which is silently skipped.
   *
   * @param string $path
   *   The credentials file path.
   */
  protected function warnIfWorldReadable(string $path): void {
    static $checked = FALSE;
    if ($checked) {
      return;
    }
    $checked = TRUE;
    if (($perms = @fileperms($path)) !== FALSE && ($perms & 0004)) {
      $this->logger->warning('Cybersource REST credentials file @path is world-readable; restrict it to the web server user.', ['@path' => $path]);
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
   * Business Center into an optional `key_expiry` field (YYYY-MM-DD). Used
   * only for the status-report reminder; never required for authentication.
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
