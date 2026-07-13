<?php

declare(strict_types=1);

namespace Drupal\Tests\cybersource_rest\Unit;

use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\cybersource_rest\CredentialProvider;
use Drupal\Tests\cybersource_rest\Unit\Fixture\NonLocalPrivateStreamWrapper;
use Drupal\Tests\UnitTestCase;
use Psr\Log\NullLogger;

/**
 * Tests credential resolution from the fixed private YAML file.
 *
 * The private:// scheme is backed by a NON-local test stream wrapper (one
 * realpath() cannot resolve), so every test here also proves the provider
 * works when the private filesystem is remote (e.g. S3).
 *
 * @coversDefaultClass \Drupal\cybersource_rest\CredentialProvider
 * @group cybersource_rest
 */
class CredentialProviderTest extends UnitTestCase {

  /**
   * Directory backing the fake private:// scheme.
   *
   * @var string
   */
  protected string $privateDir;

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
    $this->privateDir = sys_get_temp_dir() . '/crest_' . uniqid();
    mkdir($this->privateDir . '/keys', 0777, TRUE);
    $this->writeCredentials(<<<YAML
    test:
      merchant_id: test_merchant
      key_id: test-key-id
      shared_secret: "test-secret"
    live:
      merchant_id: live_merchant
      key_id: live-key-id
      shared_secret: "live-secret"
    YAML);
    NonLocalPrivateStreamWrapper::$root = $this->privateDir;
    if (in_array('private', stream_get_wrappers(), TRUE)) {
      stream_wrapper_unregister('private');
    }
    stream_wrapper_register('private', NonLocalPrivateStreamWrapper::class);
    $this->provider = new CredentialProvider(new NullLogger(), $this->mockStreamWrapperManager(TRUE));
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    if (in_array('private', stream_get_wrappers(), TRUE)) {
      stream_wrapper_unregister('private');
    }
    @unlink($this->privateDir . '/keys/cybersource_rest.yml');
    @rmdir($this->privateDir . '/keys');
    @rmdir($this->privateDir);
    parent::tearDown();
  }

  /**
   * Write the backing credentials file for the fake private:// scheme.
   */
  protected function writeCredentials(string $yaml): void {
    file_put_contents($this->privateDir . '/keys/cybersource_rest.yml', $yaml);
  }

  /**
   * A stream_wrapper_manager mock for the fake (non-local) private scheme.
   *
   * The mocked getViaUri() returns NULL — not a LocalStream — mirroring how
   * the provider must treat a remote wrapper: no realpath(), no permission
   * checks against a local path.
   */
  protected function mockStreamWrapperManager(bool $valid): StreamWrapperManagerInterface {
    $manager = $this->createMock(StreamWrapperManagerInterface::class);
    $manager->method('isValidUri')->willReturn($valid);
    $manager->method('getViaUri')->willReturn(NULL);
    return $manager;
  }

  /**
   * A fresh provider instance (empty parse cache) over the current file.
   */
  protected function freshProvider(): CredentialProvider {
    return new CredentialProvider(new NullLogger(), $this->mockStreamWrapperManager(TRUE));
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
    $this->writeCredentials(<<<YAML
    test:
      merchant_id: test_merchant
      key_id: test-key-id
    YAML);
    $provider = $this->freshProvider();
    $this->assertFalse($provider->hasProfile('test'));
    $this->assertSame([], $provider->configuredModes());
    $this->expectException(\RuntimeException::class);
    $provider->getProfile('test');
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
   * An absent credentials file throws.
   *
   * @covers ::load
   */
  public function testMissingFileThrows(): void {
    unlink($this->privateDir . '/keys/cybersource_rest.yml');
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessageMatches('/not found/');
    $this->provider->getProfile('test');
  }

  /**
   * An unconfigured private filesystem throws its own actionable message.
   *
   * @covers ::load
   */
  public function testUnconfiguredPrivateFilesystemThrows(): void {
    $provider = new CredentialProvider(new NullLogger(), $this->mockStreamWrapperManager(FALSE));
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessageMatches('/private filesystem is not configured/');
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

    $this->writeCredentials(<<<YAML
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
    // Only the mode that has a key_expiry is reported.
    $this->assertSame(['test' => '2027-01-01'], $this->freshProvider()->keyExpiries());
  }

}
