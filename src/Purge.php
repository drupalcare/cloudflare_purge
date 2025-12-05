<?php

declare(strict_types=1);

namespace Drupal\cloudflare_purge;

use Drupal\cloudflare_purge\Form\CloudflarePurgeForm;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\key\KeyRepositoryInterface;

/**
 * Provides a comprehensive service to purge Cloudflare cache.
 *
 * This service supports all Cloudflare purge methods:
 * - Purge Everything
 * - Purge by URL (single or batch)
 * - Purge by Cache Tags
 * - Purge by Prefixes
 * - Purge by Hostnames.
 *
 * Authentication priority (three-tier resolution):
 * 1. settings.php override (highest priority)
 * 2. Key module storage
 * 3. Plain text config (backward compatibility)
 *
 * @package Drupal\cloudflare_purge
 */
final class Purge implements PurgeInterface {

  use StringTranslationTrait;

  /**
   * Purge type constants.
   */
  public const TYPE_EVERYTHING = 'everything';
  public const TYPE_URLS = 'urls';
  public const TYPE_TAGS = 'tags';
  public const TYPE_PREFIXES = 'prefixes';
  public const TYPE_HOSTNAMES = 'hostnames';

  /**
   * State key for tracking rate limit.
   */
  private const RATE_LIMIT_STATE_KEY = 'cloudflare_purge.rate_limit';

  /**
   * The state service.
   *
   * @var \Drupal\Core\State\StateInterface|null
   */
  private ?StateInterface $state = NULL;

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
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerChannelInterface $logger,
    private readonly MessengerInterface $messenger,
    private readonly CloudflarePurgeApi $cloudflarePurgeApi,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly ?KeyRepositoryInterface $keyRepository = NULL,
  ) {}

  /**
   * Sets the state service for rate limiting.
   *
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   *
   * @return $this
   */
  public function setState(StateInterface $state): static {
    $this->state = $state;
    return $this;
  }

  /**
   * Purges everything from the Cloudflare cache.
   *
   * @param bool $showMessage
   *   Whether to show a status message.
   *
   * @return \Drupal\cloudflare_purge\CloudflarePurgeResult
   *   The purge result.
   */
  public function purgeEverything(bool $showMessage = TRUE): CloudflarePurgeResult {
    // Check rate limit.
    $rateLimitResult = $this->checkRateLimit($showMessage);
    if ($rateLimitResult !== NULL) {
      return $rateLimitResult;
    }

    $credentials = $this->getCredentials();

    if (!$this->validateCredentials($credentials)) {
      return new CloudflarePurgeResult(
        success: FALSE,
        statusCode: 0,
        message: 'Invalid or missing credentials',
      );
    }

    $result = $this->cloudflarePurgeApi->purgeEverything(
      useBearerToken: $credentials['use_bearer'],
      zoneId: $credentials['zone_id'],
      bearerToken: $credentials['bearer_token'],
      authorization: $credentials['authorization'],
      email: $credentials['email'],
    );

    if ($showMessage) {
      $this->displayResultMessage($result, self::TYPE_EVERYTHING);
    }

    $this->logPurgeOperation(self::TYPE_EVERYTHING, [], $result);

    return $result;
  }

  /**
   * Purges specific URLs from the Cloudflare cache.
   *
   * @param array<int|string, string> $urls
   *   Array of URLs to purge.
   * @param bool $showMessage
   *   Whether to show a status message.
   *
   * @return \Drupal\cloudflare_purge\CloudflarePurgeResult
   *   The purge result.
   */
  public function purgeByUrls(array $urls, bool $showMessage = TRUE): CloudflarePurgeResult {
    // Check rate limit.
    $rateLimitResult = $this->checkRateLimit($showMessage);
    if ($rateLimitResult !== NULL) {
      return $rateLimitResult;
    }

    $credentials = $this->getCredentials();

    if (!$this->validateCredentials($credentials)) {
      return new CloudflarePurgeResult(
        success: FALSE,
        statusCode: 0,
        message: 'Invalid or missing credentials',
      );
    }

    $result = $this->cloudflarePurgeApi->purgeByUrls(
      useBearerToken: $credentials['use_bearer'],
      zoneId: $credentials['zone_id'],
      bearerToken: $credentials['bearer_token'],
      authorization: $credentials['authorization'],
      email: $credentials['email'],
      urls: $urls,
    );

    if ($showMessage) {
      $this->displayResultMessage($result, self::TYPE_URLS, count($urls));
    }

    $this->logPurgeOperation(self::TYPE_URLS, $urls, $result);

    return $result;
  }

  /**
   * Purges content by cache tags.
   *
   * @param array<int|string, string> $tags
   *   Array of cache tags to purge.
   * @param bool $showMessage
   *   Whether to show a status message.
   *
   * @return \Drupal\cloudflare_purge\CloudflarePurgeResult
   *   The purge result.
   */
  public function purgeByTags(array $tags, bool $showMessage = TRUE): CloudflarePurgeResult {
    // Check rate limit.
    $rateLimitResult = $this->checkRateLimit($showMessage);
    if ($rateLimitResult !== NULL) {
      return $rateLimitResult;
    }

    $credentials = $this->getCredentials();

    if (!$this->validateCredentials($credentials)) {
      return new CloudflarePurgeResult(
        success: FALSE,
        statusCode: 0,
        message: 'Invalid or missing credentials',
      );
    }

    // Apply tag prefix if configured.
    $prefix = $this->getConfigValue('tag_prefix');
    if (is_string($prefix) && $prefix !== '') {
      $tags = array_map(
        static fn($tag): string => is_string($tag) ? $prefix . $tag : $prefix,
        $tags,
      );
    }

    $result = $this->cloudflarePurgeApi->purgeByTags(
      useBearerToken: $credentials['use_bearer'],
      zoneId: $credentials['zone_id'],
      bearerToken: $credentials['bearer_token'],
      authorization: $credentials['authorization'],
      email: $credentials['email'],
      tags: $tags,
    );

    if ($showMessage) {
      $this->displayResultMessage($result, self::TYPE_TAGS, count($tags));
    }

    $this->logPurgeOperation(self::TYPE_TAGS, $tags, $result);

    return $result;
  }

  /**
   * Purges content by URL prefixes.
   *
   * @param array<int|string, string> $prefixes
   *   Array of URL prefixes to purge.
   * @param bool $showMessage
   *   Whether to show a status message.
   *
   * @return \Drupal\cloudflare_purge\CloudflarePurgeResult
   *   The purge result.
   */
  public function purgeByPrefixes(array $prefixes, bool $showMessage = TRUE): CloudflarePurgeResult {
    // Check rate limit.
    $rateLimitResult = $this->checkRateLimit($showMessage);
    if ($rateLimitResult !== NULL) {
      return $rateLimitResult;
    }

    $credentials = $this->getCredentials();

    if (!$this->validateCredentials($credentials)) {
      return new CloudflarePurgeResult(
        success: FALSE,
        statusCode: 0,
        message: 'Invalid or missing credentials',
      );
    }

    $result = $this->cloudflarePurgeApi->purgeByPrefixes(
      useBearerToken: $credentials['use_bearer'],
      zoneId: $credentials['zone_id'],
      bearerToken: $credentials['bearer_token'],
      authorization: $credentials['authorization'],
      email: $credentials['email'],
      prefixes: $prefixes,
    );

    if ($showMessage) {
      $this->displayResultMessage($result, self::TYPE_PREFIXES, count($prefixes));
    }

    $this->logPurgeOperation(self::TYPE_PREFIXES, $prefixes, $result);

    return $result;
  }

  /**
   * Purges content by hostnames.
   *
   * @param array<int|string, string> $hostnames
   *   Array of hostnames to purge.
   * @param bool $showMessage
   *   Whether to show a status message.
   *
   * @return \Drupal\cloudflare_purge\CloudflarePurgeResult
   *   The purge result.
   */
  public function purgeByHostnames(array $hostnames, bool $showMessage = TRUE): CloudflarePurgeResult {
    // Check rate limit.
    $rateLimitResult = $this->checkRateLimit($showMessage);
    if ($rateLimitResult !== NULL) {
      return $rateLimitResult;
    }

    $credentials = $this->getCredentials();

    if (!$this->validateCredentials($credentials)) {
      return new CloudflarePurgeResult(
        success: FALSE,
        statusCode: 0,
        message: 'Invalid or missing credentials',
      );
    }

    $result = $this->cloudflarePurgeApi->purgeByHostnames(
      useBearerToken: $credentials['use_bearer'],
      zoneId: $credentials['zone_id'],
      bearerToken: $credentials['bearer_token'],
      authorization: $credentials['authorization'],
      email: $credentials['email'],
      hostnames: $hostnames,
    );

    if ($showMessage) {
      $this->displayResultMessage($result, self::TYPE_HOSTNAMES, count($hostnames));
    }

    $this->logPurgeOperation(self::TYPE_HOSTNAMES, $hostnames, $result);

    return $result;
  }

  /**
   * Gets all credentials with proper priority resolution.
   *
   * Priority order:
   * 1. settings.php override (highest)
   * 2. Key module storage
   * 3. Plain text config (lowest)
   *
   * @return array{use_bearer: bool, zone_id: string, bearer_token: string, email: string|null, authorization: string|null}
   *   Array containing all credential values.
   */
  private function getCredentials(): array {
    $authMethod = $this->getAuthMethod();
    $useBearerToken = $authMethod === CloudflarePurgeForm::AUTH_BEARER;

    return [
      'use_bearer' => $useBearerToken,
      'zone_id' => $this->getCredential('zone_id') ?? '',
      'bearer_token' => $useBearerToken ? ($this->getCredential('bearer_token') ?? '') : '',
      'email' => !$useBearerToken ? $this->getCredential('email') : NULL,
      'authorization' => !$useBearerToken ? $this->getCredential('authorization') : NULL,
    ];
  }

  /**
   * Validates that required credentials are present.
   *
   * @param array{use_bearer: bool, zone_id: string, bearer_token: string, email: string|null, authorization: string|null} $credentials
   *   The credentials array.
   *
   * @return bool
   *   TRUE if valid, FALSE otherwise.
   */
  private function validateCredentials(array $credentials): bool {
    if ($credentials['zone_id'] === '') {
      $this->messenger->addError($this->t('Zone ID is required. Please configure your Cloudflare credentials.'));
      $this->logger->error('Cloudflare purge failed: Zone ID is missing.');
      return FALSE;
    }

    if ($credentials['use_bearer']) {
      if ($credentials['bearer_token'] === '') {
        $this->messenger->addError($this->t('Bearer Token is required. Please configure your Cloudflare credentials.'));
        $this->logger->error('Cloudflare purge failed: Bearer Token is missing.');
        return FALSE;
      }
    }
    else {
      if ($credentials['email'] === NULL || $credentials['email'] === '' ||
          $credentials['authorization'] === NULL || $credentials['authorization'] === '') {
        $this->messenger->addError($this->t('Email and API Key are required. Please configure your Cloudflare credentials.'));
        $this->logger->error('Cloudflare purge failed: Email or API Key is missing.');
        return FALSE;
      }
    }

    return TRUE;
  }

  /**
   * Displays appropriate message based on purge result.
   *
   * @param \Drupal\cloudflare_purge\CloudflarePurgeResult $result
   *   The purge result.
   * @param string $type
   *   The purge type.
   * @param int $count
   *   Number of items purged (for batch operations).
   */
  private function displayResultMessage(CloudflarePurgeResult $result, string $type, int $count = 0): void {
    if ($result->isSuccess()) {
      $messages = [
        self::TYPE_EVERYTHING => $this->t('Successfully purged entire Cloudflare cache.'),
        self::TYPE_URLS => $this->formatPlural($count, 'Successfully purged 1 URL from Cloudflare cache.', 'Successfully purged @count URLs from Cloudflare cache.'),
        self::TYPE_TAGS => $this->formatPlural($count, 'Successfully purged 1 cache tag from Cloudflare.', 'Successfully purged @count cache tags from Cloudflare.'),
        self::TYPE_PREFIXES => $this->formatPlural($count, 'Successfully purged 1 prefix from Cloudflare cache.', 'Successfully purged @count prefixes from Cloudflare cache.'),
        self::TYPE_HOSTNAMES => $this->formatPlural($count, 'Successfully purged 1 hostname from Cloudflare cache.', 'Successfully purged @count hostnames from Cloudflare cache.'),
      ];

      $message = $messages[$type] ?? $this->t('Purge successful.');
      $this->messenger->addStatus($message);
    }
    else {
      // Provide specific error messages for common issues.
      if ($result->isRateLimited()) {
        $this->messenger->addError($this->t('Cloudflare rate limit exceeded. Please wait a moment and try again.'));
      }
      elseif ($result->isAuthenticationError()) {
        $this->messenger->addError($this->t('Cloudflare authentication failed. Please check your API credentials.'));
      }
      else {
        $this->messenger->addError($this->t('Cloudflare purge failed: @message', [
          '@message' => $result->getMessage(),
        ]));
      }
    }
  }

  /**
   * Logs a purge operation for history tracking.
   *
   * @param string $type
   *   The purge type.
   * @param array<int|string, string> $items
   *   The items that were purged.
   * @param \Drupal\cloudflare_purge\CloudflarePurgeResult $result
   *   The purge result.
   */
  private function logPurgeOperation(string $type, array $items, CloudflarePurgeResult $result): void {
    $loggingEnabled = $this->getConfigValue('logging_enabled');

    if ($loggingEnabled !== TRUE) {
      return;
    }

    $context = [
      '@type' => $type,
      '@count' => count($items),
      '@status' => $result->isSuccess() ? 'success' : 'failure',
      '@message' => $result->getMessage(),
    ];

    if ($result->isSuccess()) {
      $this->logger->info('Cloudflare purge [@type]: @count item(s) - @status', $context);
    }
    else {
      $this->logger->error('Cloudflare purge [@type]: @count item(s) - @status: @message', $context);
    }
  }

  /**
   * Gets the current authentication method.
   *
   * Priority order:
   * 1. settings.php override
   * 2. Config preference
   * 3. Legacy detection.
   *
   * @return string
   *   The authentication method constant.
   */
  private function getAuthMethod(): string {
    // Priority 1: Check settings.php first.
    $settingsCredentials = Settings::get('cloudflare_purge_credentials');
    if (is_array($settingsCredentials)) {
      if (!empty($settingsCredentials['bearer_token'])) {
        return CloudflarePurgeForm::AUTH_BEARER;
      }
      if (!empty($settingsCredentials['email']) && !empty($settingsCredentials['authorization'])) {
        return CloudflarePurgeForm::AUTH_LEGACY;
      }
    }

    // Priority 2: Check config preference.
    $authMethod = $this->getConfigValue('auth_method');
    if ($authMethod === CloudflarePurgeForm::AUTH_BEARER || $authMethod === CloudflarePurgeForm::AUTH_LEGACY) {
      return $authMethod;
    }

    // Priority 3: Legacy detection from stored credentials.
    $config = $this->configFactory->get(CloudflarePurgeForm::SETTINGS);

    // Check plain text credentials.
    if ($config->get('email') && $config->get('authorization')) {
      return CloudflarePurgeForm::AUTH_LEGACY;
    }
    if ($config->get('bearer_token')) {
      return CloudflarePurgeForm::AUTH_BEARER;
    }

    // Check key module credentials.
    if ($config->get('email_key') && $config->get('authorization_key')) {
      return CloudflarePurgeForm::AUTH_LEGACY;
    }
    if ($config->get('bearer_token_key')) {
      return CloudflarePurgeForm::AUTH_BEARER;
    }

    // Default to bearer token.
    return CloudflarePurgeForm::AUTH_BEARER;
  }

  /**
   * Gets a specific credential with three-tier resolution.
   *
   * Priority order:
   * 1. settings.php override (highest)
   * 2. Key module storage
   * 3. Plain text config (lowest)
   *
   * @param string $name
   *   The credential name.
   *
   * @return string|null
   *   The credential value or NULL.
   */
  private function getCredential(string $name): ?string {
    // Priority 1: settings.php override.
    $settingsCredentials = Settings::get('cloudflare_purge_credentials');
    if (is_array($settingsCredentials) && !empty($settingsCredentials[$name])) {
      $value = $settingsCredentials[$name];
      return is_string($value) ? $value : NULL;
    }

    // Priority 2: Key module.
    if ($this->keyRepository !== NULL && $this->moduleHandler->moduleExists('key')) {
      $keyConfigName = $name . '_key';
      $keyId = $this->configFactory->get(CloudflarePurgeForm::SETTINGS)->get($keyConfigName);

      if (is_string($keyId) && $keyId !== '') {
        $key = $this->keyRepository->getKey($keyId);
        if ($key !== NULL) {
          $keyValue = $key->getKeyValue();
          return is_string($keyValue) ? $keyValue : NULL;
        }
      }
    }

    // Priority 3: Plain text config.
    $value = $this->configFactory->get(CloudflarePurgeForm::SETTINGS)->get($name);
    return is_string($value) ? $value : NULL;
  }

  /**
   * Gets a configuration value with type checking.
   *
   * @param string $name
   *   The configuration key.
   *
   * @return mixed
   *   The configuration value.
   */
  private function getConfigValue(string $name): mixed {
    return $this->configFactory->get(CloudflarePurgeForm::SETTINGS)->get($name);
  }

  /**
   * Checks if credentials are configured and valid.
   *
   * @return bool
   *   TRUE if credentials are configured, FALSE otherwise.
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
   * Checks if the rate limit has been exceeded.
   *
   * @param bool $showMessage
   *   Whether to display an error message if rate limited.
   *
   * @return \Drupal\cloudflare_purge\CloudflarePurgeResult|null
   *   Returns a rate limit error result if limit exceeded, NULL if OK.
   */
  private function checkRateLimit(bool $showMessage): ?CloudflarePurgeResult {
    // Check if rate limiting is enabled.
    $rateLimitEnabled = $this->getConfigValue('rate_limit_enabled');
    if ($rateLimitEnabled !== TRUE) {
      return NULL;
    }

    // Get rate limit configuration.
    $maxPerMinute = $this->getConfigValue('rate_limit_per_minute');
    if (!is_int($maxPerMinute) || $maxPerMinute <= 0) {
      $maxPerMinute = 60;
    }

    // Get current rate limit data from state.
    $rateLimitData = $this->getRateLimitData();
    $currentMinute = (int) floor(time() / 60);

    // Reset counter if we're in a new minute.
    if ($rateLimitData['minute'] !== $currentMinute) {
      $rateLimitData = [
        'minute' => $currentMinute,
        'count' => 0,
      ];
    }

    // Check if limit exceeded.
    if ($rateLimitData['count'] >= $maxPerMinute) {
      $message = 'Rate limit exceeded. Maximum ' . $maxPerMinute . ' requests per minute.';
      $this->logger->warning('Cloudflare purge rate limit exceeded: @max/minute', [
        '@max' => $maxPerMinute,
      ]);

      if ($showMessage) {
        $this->messenger->addError($this->t('Cloudflare rate limit exceeded. Please wait a moment and try again (max @max requests/minute).', [
          '@max' => $maxPerMinute,
        ]));
      }

      return new CloudflarePurgeResult(
        success: FALSE,
        statusCode: 429,
        message: $message,
      );
    }

    // Increment counter and save.
    $rateLimitData['count']++;
    $this->setRateLimitData($rateLimitData);

    return NULL;
  }

  /**
   * Gets rate limit tracking data from state.
   *
   * @return array{minute: int, count: int}
   *   The rate limit data with current minute and request count.
   */
  private function getRateLimitData(): array {
    $default = ['minute' => 0, 'count' => 0];

    if ($this->state === NULL) {
      // Try to get state from container if not injected.
      try {
        $this->state = \Drupal::state();
      }
      catch (\Exception) {
        return $default;
      }
    }

    $data = $this->state->get(self::RATE_LIMIT_STATE_KEY, $default);

    if (!is_array($data) || !isset($data['minute']) || !isset($data['count'])) {
      return $default;
    }

    return [
      'minute' => (int) $data['minute'],
      'count' => (int) $data['count'],
    ];
  }

  /**
   * Saves rate limit tracking data to state.
   *
   * @param array{minute: int, count: int} $data
   *   The rate limit data to save.
   */
  private function setRateLimitData(array $data): void {
    if ($this->state === NULL) {
      try {
        $this->state = \Drupal::state();
      }
      catch (\Exception) {
        return;
      }
    }

    $this->state->set(self::RATE_LIMIT_STATE_KEY, $data);
  }

}
