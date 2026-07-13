<?php

declare(strict_types=1);

namespace Drupal\Tests\cybersource_rest\Unit\Fixture;

/**
 * Minimal read-only PHP stream wrapper backing private:// in unit tests.
 *
 * Deliberately NOT a \Drupal\Core\StreamWrapper\LocalStream: it stands in
 * for a remote private filesystem (e.g. S3), whose URIs cannot be resolved
 * with realpath(). CredentialProvider must work against it using nothing
 * but stream functions.
 *
 * The snake_case method names are PHP's stream wrapper protocol; they are
 * not renameable.
 * phpcs:disable Drupal.NamingConventions.ValidFunctionName.ScopeNotCamelCaps
 */
final class NonLocalPrivateStreamWrapper {

  /**
   * Local directory the fake private:// scheme maps onto.
   */
  public static string $root = '';

  /**
   * The stream context (set by PHP; unused).
   *
   * @var resource|null
   */
  public $context;

  /**
   * The underlying file handle.
   *
   * @var resource|false
   */
  protected $handle = FALSE;

  /**
   * Map a private:// URI onto the backing directory.
   */
  protected function path(string $uri): string {
    return self::$root . '/' . substr($uri, strlen('private://'));
  }

  /**
   * Support for fopen(), file_get_contents(), etc.
   */
  public function stream_open(string $uri, string $mode, int $options, ?string &$opened_path): bool {
    $this->handle = @fopen($this->path($uri), $mode);
    return $this->handle !== FALSE;
  }

  /**
   * Support for fread().
   */
  public function stream_read(int $count): string|false {
    return fread($this->handle, $count);
  }

  /**
   * Support for feof().
   */
  public function stream_eof(): bool {
    return feof($this->handle);
  }

  /**
   * Support for fstat().
   *
   * @return array<int|string, int>|false
   *   The stat array, or FALSE on failure.
   */
  public function stream_stat(): array|false {
    return fstat($this->handle);
  }

  /**
   * Support for fclose().
   */
  public function stream_close(): void {
    if ($this->handle !== FALSE) {
      fclose($this->handle);
    }
  }

  /**
   * Support for fseek().
   */
  public function stream_seek(int $offset, int $whence = SEEK_SET): bool {
    return fseek($this->handle, $offset, $whence) === 0;
  }

  /**
   * Support for ftell().
   */
  public function stream_tell(): int|false {
    return ftell($this->handle);
  }

  /**
   * Support for file_exists(), is_readable(), filemtime(), etc.
   *
   * @return array<int|string, int>|false
   *   The stat array, or FALSE if the file does not exist.
   */
  public function url_stat(string $uri, int $flags): array|false {
    return @stat($this->path($uri)) ?: FALSE;
  }

}
