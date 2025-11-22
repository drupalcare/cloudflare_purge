<?php

declare(strict_types=1);

namespace Drupal\cloudflare_purge;

use Drupal\cloudflare_purge\Form\CloudflarePurgeForm;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\key\KeyRepositoryInterface;

/**
 * Provides a service to purge Cloudflare cache.
 *
 * This service works with the modernized authentication and storage system:
 * - Supports Bearer Token (default) and Legacy API Key authentication
 * - Supports Plain text and Key module storage methods
 * - Honors settings.php overrides (highest priority)
 */
class Purge {

  use StringTranslationTrait;

  /**
   * Constructs a Purge service object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\Core\Logger\LoggerChannelInterface $logger
   *   The logger channel.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   * @param \Drupal\cloudflare_purge\CloudflarePurgeApi $cloudflarePurgeApi
   *   The Cloudflare Purge API service.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler service.
   * @param \Drupal\key\KeyRepositoryInterface|null $keyRepository
   *   The Key repository service (optional).
   */
  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected LoggerChannelInterface $logger,
    protected MessengerInterface $messenger,
    protected CloudflarePurgeApi $cloudflarePurgeApi,
    protected ModuleHandlerInterface $moduleHandler,
    protected ?KeyRepositoryInterface $keyRepository = NULL,
  ) {}

  /**
   * Purges Cloudflare cache.
   *
   * @param string $url
   *   The specific URL to purge. Leave empty to purge everything.
   */
  public function purge(string $url = ''): void {
    // Determine authentication method.
    $authMethod = $this->getAuthMethod();
    $zoneId = $this->getCredential('zone_id');

    // Validate Zone ID (required for both methods).
    if (empty($zoneId)) {
      $this->messenger->addError($this->t('Zone ID is required. Please configure your Cloudflare credentials.'));
      return;
    }

    // Execute purge based on authentication method.
    if ($authMethod === CloudflarePurgeForm::AUTH_BEARER) {
      $this->purgeBearerToken($zoneId, $url);
    }
    else {
      $this->purgeLegacy($zoneId, $url);
    }
  }

  /**
   * Purges using Bearer Token authentication.
   *
   * @param string $zoneId
   *   The Cloudflare Zone ID.
   * @param string $url
   *   The specific URL to purge.
   */
  protected function purgeBearerToken(string $zoneId, string $url): void {
    $bearerToken = $this->getCredential('bearer_token');

    if (empty($bearerToken)) {
      $this->messenger->addError($this->t('Bearer Token is required. Please configure your Cloudflare credentials.'));
      return;
    }

    $result = $this->cloudflarePurgeApi->cfPurgeCache(
      useBearerToken: TRUE,
      zoneId: $zoneId,
      bearerToken: $bearerToken,
      authorization: NULL,
      email: NULL,
      urlToPurge: $url
    );

    $this->handlePurgeResult($result);
  }

  /**
   * Purges using Legacy API Key authentication.
   *
   * @param string $zoneId
   *   The Cloudflare Zone ID.
   * @param string $url
   *   The specific URL to purge.
   */
  protected function purgeLegacy(string $zoneId, string $url): void {
    $email = $this->getCredential('email');
    $authorization = $this->getCredential('authorization');

    if (empty($email) || empty($authorization)) {
      $this->messenger->addError($this->t('Email and API Key are required. Please configure your Cloudflare credentials.'));
      return;
    }

    $result = $this->cloudflarePurgeApi->cfPurgeCache(
      useBearerToken: FALSE,
      zoneId: $zoneId,
      bearerToken: '',
      authorization: $authorization,
      email: $email,
      urlToPurge: $url
    );

    $this->handlePurgeResult($result);
  }

  /**
   * Handles the purge result and displays appropriate messages.
   *
   * @param int|null $result
   *   The HTTP status code from the API call.
   */
  protected function handlePurgeResult(?int $result): void {
    if ($result === 200) {
      $this->messenger->addMessage($this->t('Purge successful.'));
    }
    else {
      $this->messenger->addError($this->t('An error occurred. Check the Drupal log for more information.'));
    }
  }

  /**
   * Gets the current authentication method.
   *
   * @return string
   *   The authentication method constant (AUTH_BEARER or AUTH_LEGACY).
   */
  protected function getAuthMethod(): string {
    // Check settings.php first.
    $settingsCredentials = Settings::get('cloudflare_purge_credentials');
    if (is_array($settingsCredentials)) {
      // Determine method from settings.php content.
      if (!empty($settingsCredentials['bearer_token'])) {
        return CloudflarePurgeForm::AUTH_BEARER;
      }
      if (!empty($settingsCredentials['email']) && !empty($settingsCredentials['authorization'])) {
        return CloudflarePurgeForm::AUTH_LEGACY;
      }
    }

    // Check config preference.
    $config = $this->configFactory->get(CloudflarePurgeForm::SETTINGS);
    $authMethod = $config->get('auth_method');
    if ($authMethod) {
      return $authMethod;
    }

    // Legacy-safe inference: Check for existing credentials before defaulting.
    // This ensures backward compatibility with installations that have legacy
    // credentials but no auth_method field set (before update hook runs).
    // Check plain text credentials.
    if ($config->get('email') && $config->get('authorization')) {
      return CloudflarePurgeForm::AUTH_LEGACY;
    }
    if ($config->get('bearer_token')) {
      return CloudflarePurgeForm::AUTH_BEARER;
    }

    // Check Key module credentials.
    if ($config->get('email_key') && $config->get('authorization_key')) {
      return CloudflarePurgeForm::AUTH_LEGACY;
    }
    if ($config->get('bearer_token_key')) {
      return CloudflarePurgeForm::AUTH_BEARER;
    }

    // Default to Bearer (modern approach) for new installations.
    return CloudflarePurgeForm::AUTH_BEARER;
  }

  /**
   * Gets Cloudflare credentials with three-tier resolution.
   *
   * Priority order:
   * 1. settings.php override (highest security, always wins)
   * 2. Key module (if installed and configured)
   * 3. Plain text config (backward compatibility)
   *
   * @param string $name
   *   The credential key name to retrieve.
   *
   * @return mixed
   *   The credential value or NULL if not found.
   */
  protected function getCredential(string $name): mixed {
    // Priority 1: settings.php override (highest priority).
    $settingsCredentials = Settings::get('cloudflare_purge_credentials');
    if (is_array($settingsCredentials) && !empty($settingsCredentials[$name])) {
      return $settingsCredentials[$name];
    }

    // Priority 2: Key module (if installed and configured).
    if ($this->keyRepository && $this->moduleHandler->moduleExists('key')) {
      $keyConfigName = $name . '_key';
      $keyId = $this->configFactory->get(CloudflarePurgeForm::SETTINGS)->get($keyConfigName);

      if ($keyId) {
        $key = $this->keyRepository->getKey($keyId);
        if ($key) {
          return $key->getKeyValue();
        }
      }
    }

    // Priority 3: Plain text config (backward compatibility).
    $config = $this->configFactory->get(CloudflarePurgeForm::SETTINGS);
    $configValue = $config->get($name);

    return $configValue ?? NULL;
  }

}
