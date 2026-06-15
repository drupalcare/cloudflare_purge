<?php

declare(strict_types=1);

namespace Drupal\Tests\cloudflare_purge\Unit;

use Drupal\cloudflare_purge\CloudflarePurgeSettings;
use Drupal\cloudflare_purge\CredentialResolver;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Site\Settings;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the CredentialResolver service.
 *
 * @group cloudflare_purge
 */
#[CoversClass(CredentialResolver::class)]
class CredentialResolverTest extends UnitTestCase {

  /**
   * Builds a resolver with the given config values, settings.php and key state.
   *
   * @param array<string, mixed> $config
   *   Config values for cloudflare_purge.settings.
   * @param array<string, mixed>|null $settingsOverride
   *   The value returned by Settings::get('cloudflare_purge_credentials').
   * @param bool $keyModule
   *   Whether the Key module is "installed".
   */
  private function createResolver(array $config = [], ?array $settingsOverride = NULL, bool $keyModule = FALSE): CredentialResolver {
    // Control the static Settings singleton.
    new Settings($settingsOverride === NULL ? [] : ['cloudflare_purge_credentials' => $settingsOverride]);

    $immutable = $this->createMock(ImmutableConfig::class);
    $immutable->method('get')->willReturnCallback(
      static fn(string $key): mixed => $config[$key] ?? NULL,
    );

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->with(CloudflarePurgeSettings::NAME)->willReturn($immutable);

    $moduleHandler = $this->createMock(ModuleHandlerInterface::class);
    $moduleHandler->method('moduleExists')->willReturnCallback(
      static fn(string $module): bool => $module === 'key' && $keyModule,
    );

    return new CredentialResolver($configFactory, $moduleHandler, NULL);
  }

  /**
   * Tests getAuthMethod() priority: settings.php wins first.
   */
  public function testGetAuthMethodSettingsOverrideWins(): void {
    $resolver = $this->createResolver(
      ['auth_method' => CloudflarePurgeSettings::AUTH_LEGACY],
      ['bearer_token' => 'abc'],
    );
    $this->assertSame(CloudflarePurgeSettings::AUTH_BEARER, $resolver->getAuthMethod());

    $resolver = $this->createResolver(
      ['auth_method' => CloudflarePurgeSettings::AUTH_BEARER],
      ['email' => 'a@b.c', 'authorization' => 'k'],
    );
    $this->assertSame(CloudflarePurgeSettings::AUTH_LEGACY, $resolver->getAuthMethod());
  }

  /**
   * Tests getAuthMethod() falls back to the saved config preference.
   */
  public function testGetAuthMethodConfigPreference(): void {
    $resolver = $this->createResolver(['auth_method' => CloudflarePurgeSettings::AUTH_LEGACY]);
    $this->assertSame(CloudflarePurgeSettings::AUTH_LEGACY, $resolver->getAuthMethod());
  }

  /**
   * Tests getAuthMethod() detection from stored credentials and default.
   */
  public function testGetAuthMethodDetectionAndDefault(): void {
    // Plain legacy credentials.
    $resolver = $this->createResolver(['email' => 'a@b.c', 'authorization' => 'k']);
    $this->assertSame(CloudflarePurgeSettings::AUTH_LEGACY, $resolver->getAuthMethod());

    // Plain bearer token.
    $resolver = $this->createResolver(['bearer_token' => 'abc']);
    $this->assertSame(CloudflarePurgeSettings::AUTH_BEARER, $resolver->getAuthMethod());

    // Key-stored legacy credentials.
    $resolver = $this->createResolver(['email_key' => 'k1', 'authorization_key' => 'k2']);
    $this->assertSame(CloudflarePurgeSettings::AUTH_LEGACY, $resolver->getAuthMethod());

    // Nothing configured: default bearer.
    $resolver = $this->createResolver();
    $this->assertSame(CloudflarePurgeSettings::AUTH_BEARER, $resolver->getAuthMethod());
  }

  /**
   * Tests getCredential() priority: settings.php overrides config.
   */
  public function testGetCredentialSettingsOverride(): void {
    $resolver = $this->createResolver(
      ['zone_id' => 'config-zone'],
      ['zone_id' => 'settings-zone'],
    );
    $this->assertSame('settings-zone', $resolver->getCredential('zone_id'));
  }

  /**
   * Tests getCredential() falls back to plain config.
   */
  public function testGetCredentialPlainConfig(): void {
    $resolver = $this->createResolver(['zone_id' => 'config-zone']);
    $this->assertSame('config-zone', $resolver->getCredential('zone_id'));
    $this->assertNull($resolver->getCredential('bearer_token'));
  }

  /**
   * Tests getCredentials() assembles a bearer credential set.
   */
  public function testGetCredentialsBearer(): void {
    $resolver = $this->createResolver([
      'auth_method' => CloudflarePurgeSettings::AUTH_BEARER,
      'zone_id' => 'z',
      'bearer_token' => 'tok',
      'email' => 'a@b.c',
    ]);
    $creds = $resolver->getCredentials();
    $this->assertTrue($creds['use_bearer']);
    $this->assertSame('z', $creds['zone_id']);
    $this->assertSame('tok', $creds['bearer_token']);
    $this->assertNull($creds['email']);
    $this->assertNull($creds['authorization']);
  }

  /**
   * Tests getCredentials() assembles a legacy credential set.
   */
  public function testGetCredentialsLegacy(): void {
    $resolver = $this->createResolver([
      'auth_method' => CloudflarePurgeSettings::AUTH_LEGACY,
      'zone_id' => 'z',
      'email' => 'a@b.c',
      'authorization' => 'key',
      'bearer_token' => 'ignored',
    ]);
    $creds = $resolver->getCredentials();
    $this->assertFalse($creds['use_bearer']);
    $this->assertSame('a@b.c', $creds['email']);
    $this->assertSame('key', $creds['authorization']);
    $this->assertSame('', $creds['bearer_token']);
  }

  /**
   * Tests hasCredentials() for complete and incomplete sets.
   */
  public function testHasCredentials(): void {
    $this->assertTrue($this->createResolver([
      'auth_method' => CloudflarePurgeSettings::AUTH_BEARER,
      'zone_id' => 'z',
      'bearer_token' => 'tok',
    ])->hasCredentials());

    // Missing zone id.
    $this->assertFalse($this->createResolver([
      'auth_method' => CloudflarePurgeSettings::AUTH_BEARER,
      'bearer_token' => 'tok',
    ])->hasCredentials());

    // Legacy missing authorization.
    $this->assertFalse($this->createResolver([
      'auth_method' => CloudflarePurgeSettings::AUTH_LEGACY,
      'zone_id' => 'z',
      'email' => 'a@b.c',
    ])->hasCredentials());
  }

  /**
   * Tests settings.php override completeness detection.
   */
  public function testSettingsOverrideDetection(): void {
    // Complete bearer override.
    $r = $this->createResolver([], ['zone_id' => 'z', 'bearer_token' => 't']);
    $this->assertTrue($r->hasSettingsOverride());
    $this->assertFalse($r->hasIncompleteSettingsOverride());
    $this->assertSame(CloudflarePurgeSettings::AUTH_BEARER, $r->getSettingsOverrideAuthType());

    // Complete legacy override.
    $r = $this->createResolver([], ['zone_id' => 'z', 'email' => 'a@b.c', 'authorization' => 'k']);
    $this->assertTrue($r->hasSettingsOverride());
    $this->assertSame(CloudflarePurgeSettings::AUTH_LEGACY, $r->getSettingsOverrideAuthType());

    // Incomplete override.
    $r = $this->createResolver([], ['bearer_token' => 't']);
    $this->assertFalse($r->hasSettingsOverride());
    $this->assertTrue($r->hasIncompleteSettingsOverride());

    // No override at all.
    $r = $this->createResolver();
    $this->assertFalse($r->hasSettingsOverride());
    $this->assertFalse($r->hasIncompleteSettingsOverride());
    $this->assertNull($r->getSettingsOverrideAuthType());
  }

  /**
   * Tests detectAuthMethod() ignores settings.php (config-only UI default).
   */
  public function testDetectAuthMethod(): void {
    // Saved preference wins.
    $this->assertSame(
      CloudflarePurgeSettings::AUTH_LEGACY,
      $this->createResolver(['auth_method' => CloudflarePurgeSettings::AUTH_LEGACY])->detectAuthMethod(),
    );
    // Detected from bearer key, settings.php ignored.
    $this->assertSame(
      CloudflarePurgeSettings::AUTH_BEARER,
      $this->createResolver(['bearer_token_key' => 'k'], ['email' => 'x', 'authorization' => 'y'])->detectAuthMethod(),
    );
    // New user default.
    $this->assertSame(CloudflarePurgeSettings::AUTH_BEARER, $this->createResolver()->detectAuthMethod());
  }

  /**
   * Tests detectStorageMethod() detection and defaults.
   */
  public function testDetectStorageMethod(): void {
    // Saved preference wins.
    $this->assertSame(
      CloudflarePurgeSettings::STORAGE_KEY,
      $this->createResolver(['storage_method' => CloudflarePurgeSettings::STORAGE_KEY])->detectStorageMethod(),
    );
    // Detected from a _key field.
    $this->assertSame(
      CloudflarePurgeSettings::STORAGE_KEY,
      $this->createResolver(['zone_id_key' => 'k'])->detectStorageMethod(),
    );
    // Detected from plain field.
    $this->assertSame(
      CloudflarePurgeSettings::STORAGE_PLAIN,
      $this->createResolver(['zone_id' => 'z'])->detectStorageMethod(),
    );
    // New user, Key module installed: key.
    $this->assertSame(
      CloudflarePurgeSettings::STORAGE_KEY,
      $this->createResolver([], NULL, TRUE)->detectStorageMethod(),
    );
    // New user, no Key module: plain.
    $this->assertSame(
      CloudflarePurgeSettings::STORAGE_PLAIN,
      $this->createResolver()->detectStorageMethod(),
    );
  }

}
