<?php

declare(strict_types=1);

namespace Drupal\Tests\cybersource_rest\Unit;

use Drupal\Core\File\FileSystemInterface;
use Drupal\cybersource_rest\CredentialProvider;
use Drupal\Tests\UnitTestCase;
use Psr\Log\NullLogger;

/**
 * Tests credential resolution from the fixed private YAML file.
 *
 * @coversDefaultClass \Drupal\cybersource_rest\CredentialProvider
 * @group cybersource_rest
 */
class CredentialProviderTest extends UnitTestCase {

  /**
   * Path to the temporary credentials file standing in for the private URI.
   *
   * @var string
   */
  protected string $credFile;

  /**
   * The provider under test (file present).
   *
   * @var \Drupal\cybersource_rest\CredentialProvider
   */
  protected CredentialProvider $provider;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->credFile = sys_get_temp_dir() . '/crest_' . uniqid() . '.yml';
    file_put_contents($this->credFile, <<<YAML
    test:
      merchant_id: test_merchant
      key_id: test-key-id
      shared_secret: "test-secret"
    live:
      merchant_id: live_merchant
      key_id: live-key-id
      shared_secret: "live-secret"
    YAML);
    $this->provider = new CredentialProvider(new NullLogger(), $this->mockFileSystem($this->credFile));
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    @unlink($this->credFile);
    parent::tearDown();
  }

  /**
   * A file_system mock that maps the fixed credentials URI to a real path.
   */
  protected function mockFileSystem(string|false $realpath): FileSystemInterface {
    $fs = $this->createMock(FileSystemInterface::class);
    $fs->method('realpath')->willReturnCallback(
      fn ($uri) => $uri === CredentialProvider::CREDENTIALS_URI ? $realpath : FALSE
    );
    return $fs;
  }

  /**
   * Each mode resolves its own complete profile.
   *
   * @covers ::getProfile
   */
  public function testGetProfile(): void {
    $test = $this->provider->getProfile('test');
    $this->assertSame('test_merchant', $test['merchant_id']);
    $this->assertSame('test-key-id', $test['key_id']);
    $this->assertSame('test-secret', $test['shared_secret']);

    $live = $this->provider->getProfile('live');
    $this->assertSame('live_merchant', $live['merchant_id']);
  }

  /**
   * An unknown mode falls back to 'test'.
   *
   * @covers ::getProfile
   */
  public function testUnknownModeFallsBackToTest(): void {
    $this->assertSame('test_merchant', $this->provider->getProfile('nonsense')['merchant_id']);
  }

  /**
   * An incomplete profile is treated as missing (completeness check).
   *
   * @covers ::getProfile
   * @covers ::hasProfile
   * @covers ::configuredModes
   */
  public function testIncompleteProfileIsMissing(): void {
    $file = sys_get_temp_dir() . '/crest_partial_' . uniqid() . '.yml';
    file_put_contents($file, <<<YAML
    test:
      merchant_id: test_merchant
      key_id: test-key-id
    YAML);
    $provider = new CredentialProvider(new NullLogger(), $this->mockFileSystem($file));
    try {
      $this->assertFalse($provider->hasProfile('test'));
      $this->assertSame([], $provider->configuredModes());
      $this->expectException(\RuntimeException::class);
      $provider->getProfile('test');
    }
    finally {
      @unlink($file);
    }
  }

  /**
   * Only modes with a complete profile are reported as configured.
   *
   * @covers ::configuredModes
   * @covers ::hasProfile
   */
  public function testConfiguredModes(): void {
    $this->assertSame(['test', 'live'], $this->provider->configuredModes());
    $this->assertTrue($this->provider->hasProfile('test'));
    $this->assertTrue($this->provider->hasProfile('live'));
  }

  /**
   * An unresolvable/absent credentials file throws.
   *
   * @covers ::load
   */
  public function testMissingFileThrows(): void {
    $provider = new CredentialProvider(new NullLogger(), $this->mockFileSystem(FALSE));
    $this->expectException(\RuntimeException::class);
    $provider->getProfile('test');
  }

  /**
   * The optional key_expiry is reported per mode, when present.
   *
   * @covers ::keyExpiries
   */
  public function testKeyExpiries(): void {
    // The default fixture has no key_expiry.
    $this->assertSame([], $this->provider->keyExpiries());

    $file = sys_get_temp_dir() . '/crest_exp_' . uniqid() . '.yml';
    file_put_contents($file, <<<YAML
    test:
      merchant_id: test_merchant
      key_id: test-key-id
      shared_secret: "test-secret"
      key_expiry: "2027-01-01"
    live:
      merchant_id: live_merchant
      key_id: live-key-id
      shared_secret: "live-secret"
    YAML);
    $provider = new CredentialProvider(new NullLogger(), $this->mockFileSystem($file));
    try {
      // Only the mode that has a key_expiry is reported.
      $this->assertSame(['test' => '2027-01-01'], $provider->keyExpiries());
    }
    finally {
      @unlink($file);
    }
  }

}
