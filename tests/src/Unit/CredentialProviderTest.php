<?php

declare(strict_types=1);

namespace Drupal\Tests\cybersource_rest\Unit;

use Drupal\Core\Site\Settings;
use Drupal\cybersource_rest\CredentialProvider;
use Drupal\Tests\UnitTestCase;
use Psr\Log\NullLogger;

/**
 * Tests credential resolution from the settings.php-provided sources.
 *
 * Covers both sources: the inline $settings['cybersource_rest.credentials']
 * array and the $settings['cybersource_rest.credentials_file'] YAML path, and
 * the precedence between them.
 *
 * @coversDefaultClass \Drupal\cybersource_rest\CredentialProvider
 * @group cybersource_rest
 */
class CredentialProviderTest extends UnitTestCase {

  /**
   * Directory holding the credentials file fixture.
   *
   * @var string
   */
  protected string $credsDir;

  /**
   * The provider under test (file-based, file present).
   *
   * @var \Drupal\cybersource_rest\CredentialProvider
   */
  protected CredentialProvider $provider;

  /**
   * The credentials structure every test starts from.
   *
   * @var array<string, mixed>
   */
  protected const CREDENTIALS = [
    'test' => [
      'merchant_id' => 'test_merchant',
      'key_id' => 'test-key-id',
      'shared_secret' => 'test-secret',
    ],
    'live' => [
      'merchant_id' => 'live_merchant',
      'key_id' => 'live-key-id',
      'shared_secret' => 'live-secret',
    ],
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->credsDir = sys_get_temp_dir() . '/crest_' . uniqid();
    mkdir($this->credsDir, 0777, TRUE);
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
    $this->provider = $this->fileProvider();
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    @unlink($this->credsDir . '/cybersource_rest.yml');
    @rmdir($this->credsDir);
    parent::tearDown();
  }

  /**
   * Write the backing credentials file for the fixture directory.
   */
  protected function writeCredentials(string $yaml): void {
    file_put_contents($this->credsDir . '/cybersource_rest.yml', $yaml);
  }

  /**
   * A provider reading the fixture file via the credentials_file setting.
   */
  protected function fileProvider(): CredentialProvider {
    return new CredentialProvider(new NullLogger(), new Settings([
      CredentialProvider::SETTING_CREDENTIALS_FILE => $this->credsDir . '/cybersource_rest.yml',
    ]));
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
    $provider = $this->fileProvider();
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
   * An absent credentials file throws with the configured path in the message.
   *
   * @covers ::load
   */
  public function testMissingFileThrows(): void {
    unlink($this->credsDir . '/cybersource_rest.yml');
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessageMatches('/not found/');
    $this->provider->getProfile('test');
  }

  /**
   * Neither setting configured throws its own actionable message.
   *
   * @covers ::load
   * @covers ::isConfigured
   */
  public function testUnconfiguredSettingsThrow(): void {
    $provider = new CredentialProvider(new NullLogger(), new Settings([]));
    $this->assertFalse($provider->isConfigured());
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessageMatches('/No Cybersource REST credentials are configured/');
    $provider->getProfile('test');
  }

  /**
   * Inline credentials work without any file.
   *
   * @covers ::load
   * @covers ::getProfile
   * @covers ::isConfigured
   */
  public function testInlineCredentials(): void {
    $provider = new CredentialProvider(new NullLogger(), new Settings([
      CredentialProvider::SETTING_CREDENTIALS => self::CREDENTIALS,
    ]));
    $this->assertTrue($provider->isConfigured());
    $this->assertSame('live_merchant', $provider->getProfile('live')['merchant_id']);
    $this->assertSame('test_merchant', $provider->getProfile('test')['merchant_id']);
  }

  /**
   * Inline credentials take precedence over a configured file path.
   *
   * @covers ::load
   * @covers ::source
   */
  public function testInlineCredentialsTakePrecedence(): void {
    $inline = self::CREDENTIALS;
    $inline['test']['merchant_id'] = 'inline_merchant';
    $provider = new CredentialProvider(new NullLogger(), new Settings([
      CredentialProvider::SETTING_CREDENTIALS => $inline,
      CredentialProvider::SETTING_CREDENTIALS_FILE => $this->credsDir . '/cybersource_rest.yml',
    ]));
    $this->assertSame('inline_merchant', $provider->getProfile('test')['merchant_id']);
    $this->assertSame("\$settings['cybersource_rest.credentials']", $provider->source());
  }

  /**
   * A non-array inline credentials setting throws.
   *
   * @covers ::load
   */
  public function testNonArrayInlineCredentialsThrow(): void {
    $provider = new CredentialProvider(new NullLogger(), new Settings([
      CredentialProvider::SETTING_CREDENTIALS => 'not-an-array',
    ]));
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessageMatches('/must be an array/');
    $provider->getProfile('test');
  }

  /**
   * The source() description names the file path for file-based credentials.
   *
   * @covers ::source
   */
  public function testSourceNamesFilePath(): void {
    $this->assertSame($this->credsDir . '/cybersource_rest.yml', $this->provider->source());
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
    $this->assertSame(['test' => '2027-01-01'], $this->fileProvider()->keyExpiries());
  }

}
