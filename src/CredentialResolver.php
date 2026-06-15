<?php

declare(strict_types=1);

namespace Drupal\cloudflare_purge;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Site\Settings;
use Drupal\key\KeyRepositoryInterface;

/**
 * Default credential resolver.
 *
 * Centralizes all Cloudflare credential, authentication-method and
 * storage-method resolution previously duplicated between the purge service
 * and the configuration form.
 *
 * @package Drupal\cloudflare_purge
 */
final class CredentialResolver implements CredentialResolverInterface {

  /**
   * The settings.php key holding credential overrides.
   */
  private const SETTINGS_OVERRIDE_KEY = 'cloudflare_purge_credentials';

  /**
   * Constructs a CredentialResolver.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler.
   * @param \Drupal\key\KeyRepositoryInterface|null $keyRepository
   *   The Key repository service (optional).
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly ?KeyRepositoryInterface $keyRepository = NULL,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getAuthMethod(): string {
    // Priority 1: Check settings.php first.
    $settingsCredentials = Settings::get(self::SETTINGS_OVERRIDE_KEY);
    if (is_array($settingsCredentials)) {
      if (!empty($settingsCredentials['bearer_token'])) {
        return CloudflarePurgeSettings::AUTH_BEARER;
      }
      if (!empty($settingsCredentials['email']) && !empty($settingsCredentials['authorization'])) {
        return CloudflarePurgeSettings::AUTH_LEGACY;
      }
    }

    // Priority 2: Check config preference.
    $config = $this->config();
    $authMethod = $config->get('auth_method');
    if ($authMethod === CloudflarePurgeSettings::AUTH_BEARER || $authMethod === CloudflarePurgeSettings::AUTH_LEGACY) {
      return $authMethod;
    }

    // Priority 3: Legacy detection from stored credentials.
    // Check plain text credentials.
    if ($config->get('email') && $config->get('authorization')) {
      return CloudflarePurgeSettings::AUTH_LEGACY;
    }
    if ($config->get('bearer_token')) {
      return CloudflarePurgeSettings::AUTH_BEARER;
    }

    // Check key module credentials.
    if ($config->get('email_key') && $config->get('authorization_key')) {
      return CloudflarePurgeSettings::AUTH_LEGACY;
    }
    if ($config->get('bearer_token_key')) {
      return CloudflarePurgeSettings::AUTH_BEARER;
    }

    // Default to bearer token.
    return CloudflarePurgeSettings::AUTH_BEARER;
  }

  /**
   * {@inheritdoc}
   */
  public function getCredential(string $name): ?string {
    // Priority 1: settings.php override.
    $settingsCredentials = Settings::get(self::SETTINGS_OVERRIDE_KEY);
    if (is_array($settingsCredentials) && !empty($settingsCredentials[$name])) {
      $value = $settingsCredentials[$name];
      return is_string($value) ? $value : NULL;
    }

    // Priority 2: Key module.
    if ($this->keyRepository !== NULL && $this->moduleHandler->moduleExists('key')) {
      $keyConfigName = $name . '_key';
      $keyId = $this->config()->get($keyConfigName);

      if (is_string($keyId) && $keyId !== '') {
        $key = $this->keyRepository->getKey($keyId);
        if ($key !== NULL) {
          $keyValue = $key->getKeyValue();
          return is_string($keyValue) ? $keyValue : NULL;
        }
      }
    }

    // Priority 3: Plain text config.
    $value = $this->config()->get($name);
    return is_string($value) ? $value : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getCredentials(): array {
    $useBearerToken = $this->getAuthMethod() === CloudflarePurgeSettings::AUTH_BEARER;

    return [
      'use_bearer' => $useBearerToken,
      'zone_id' => $this->getCredential('zone_id') ?? '',
      'bearer_token' => $useBearerToken ? ($this->getCredential('bearer_token') ?? '') : '',
      'email' => !$useBearerToken ? $this->getCredential('email') : NULL,
      'authorization' => !$useBearerToken ? $this->getCredential('authorization') : NULL,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function hasCredentials(): bool {
    $credentials = $this->getCredentials();

    // Check zone ID.
    if ($credentials['zone_id'] === '') {
      return FALSE;
    }

    // Check authentication credentials.
    if ($credentials['use_bearer']) {
      return $credentials['bearer_token'] !== '';
    }

    return $credentials['email'] !== NULL && $credentials['email'] !== '' &&
           $credentials['authorization'] !== NULL && $credentials['authorization'] !== '';
  }

  /**
   * {@inheritdoc}
   */
  public function hasSettingsOverride(): bool {
    $credentials = Settings::get(self::SETTINGS_OVERRIDE_KEY);
    if (!is_array($credentials)) {
      return FALSE;
    }

    // Check for complete Bearer Token credentials.
    $hasCompleteBearer = !empty($credentials['zone_id']) && !empty($credentials['bearer_token']);

    // Check for complete Legacy credentials.
    $hasCompleteLegacy = !empty($credentials['zone_id']) &&
                         !empty($credentials['email']) &&
                         !empty($credentials['authorization']);

    return $hasCompleteBearer || $hasCompleteLegacy;
  }

  /**
   * {@inheritdoc}
   */
  public function hasIncompleteSettingsOverride(): bool {
    $credentials = Settings::get(self::SETTINGS_OVERRIDE_KEY);
    if (!is_array($credentials) || empty($credentials)) {
      return FALSE;
    }

    // If we have a complete set, it's not incomplete.
    if ($this->hasSettingsOverride()) {
      return FALSE;
    }

    // settings.php has some credentials but not a complete set.
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function getSettingsOverrideAuthType(): ?string {
    $credentials = Settings::get(self::SETTINGS_OVERRIDE_KEY);
    if (!is_array($credentials)) {
      return NULL;
    }

    if (!empty($credentials['bearer_token'])) {
      return CloudflarePurgeSettings::AUTH_BEARER;
    }
    if (!empty($credentials['email']) && !empty($credentials['authorization'])) {
      return CloudflarePurgeSettings::AUTH_LEGACY;
    }

    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function detectAuthMethod(): string {
    $config = $this->config();

    // Check if user has explicitly set a preference.
    $savedMethod = $config->get('auth_method');
    if ($savedMethod) {
      return $savedMethod;
    }

    // For existing users, detect from credentials.
    if ($config->get('bearer_token') || $config->get('bearer_token_key')) {
      return CloudflarePurgeSettings::AUTH_BEARER;
    }

    if ($config->get('email') || $config->get('authorization') ||
        $config->get('email_key') || $config->get('authorization_key')) {
      return CloudflarePurgeSettings::AUTH_LEGACY;
    }

    // New user: default to bearer (modern approach).
    return CloudflarePurgeSettings::AUTH_BEARER;
  }

  /**
   * {@inheritdoc}
   */
  public function detectStorageMethod(): string {
    $config = $this->config();

    // Check if user has explicitly set a preference.
    $savedMethod = $config->get('storage_method');
    if ($savedMethod) {
      return $savedMethod;
    }

    // If any _key field has a value, they're using the Key module.
    $keyFields = ['zone_id_key', 'bearer_token_key', 'email_key', 'authorization_key'];
    foreach ($keyFields as $keyField) {
      if ($config->get($keyField)) {
        return CloudflarePurgeSettings::STORAGE_KEY;
      }
    }

    // Check if plain text credentials exist.
    $plainFields = ['zone_id', 'bearer_token', 'email', 'authorization'];
    foreach ($plainFields as $plainField) {
      if ($config->get($plainField)) {
        return CloudflarePurgeSettings::STORAGE_PLAIN;
      }
    }

    // New user: default to Key module if installed, otherwise plain.
    return $this->moduleHandler->moduleExists('key')
      ? CloudflarePurgeSettings::STORAGE_KEY
      : CloudflarePurgeSettings::STORAGE_PLAIN;
  }

  /**
   * Gets the module's immutable settings config.
   */
  private function config(): ImmutableConfig {
    return $this->configFactory->get(CloudflarePurgeSettings::NAME);
  }

}
