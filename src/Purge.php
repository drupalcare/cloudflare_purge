<?php

declare(strict_types=1);

namespace Drupal\cloudflare_purge;

use Drupal\cloudflare_purge\Cache\CacheTagFormatterInterface;
use Drupal\cloudflare_purge\Cache\CloudflareCacheTagInvalidator;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

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
   * Constructs a Purge service object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\Core\Logger\LoggerChannelInterface $logger
   *   The logger channel.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   * @param \Drupal\cloudflare_purge\CloudflarePurgeApiInterface $cloudflarePurgeApi
   *   The Cloudflare Purge API service.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service for rate limit tracking.
   * @param \Drupal\cloudflare_purge\Cache\CacheTagFormatterInterface $cacheTagFormatter
   *   The cache tag formatter.
   * @param \Drupal\cloudflare_purge\CredentialResolverInterface $credentialResolver
   *   The credential resolver.
   * @param \Drupal\Core\Queue\QueueFactory $queueFactory
   *   The queue factory, used to drop the pending tag-purge queue once
   *   everything has been purged.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerChannelInterface $logger,
    private readonly MessengerInterface $messenger,
    private readonly CloudflarePurgeApiInterface $cloudflarePurgeApi,
    private readonly StateInterface $state,
    private readonly CacheTagFormatterInterface $cacheTagFormatter,
    private readonly CredentialResolverInterface $credentialResolver,
    private readonly QueueFactory $queueFactory,
  ) {}

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

    if ($result->isSuccess()) {
      // Everything just got purged, so any tags still queued from before this
      // call are now redundant: purging them would only spend rate-limited
      // API requests re-clearing content that's already gone.
      $this->clearTagQueue();
    }

    if ($showMessage) {
      $this->displayResultMessage($result, self::TYPE_EVERYTHING);
    }

    $this->logPurgeOperation(self::TYPE_EVERYTHING, [], $result);

    return $result;
  }

  /**
   * Deletes the queue of pending cache-tag purge requests.
   *
   * Called after a successful purge-everything, since any tags queued before
   * that point have already been cleared along with the rest of the zone.
   */
  private function clearTagQueue(): void {
    try {
      $this->queueFactory->get(CloudflareCacheTagInvalidator::QUEUE_NAME)->deleteQueue();
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to clear the pending cache tag purge queue: @message', [
        '@message' => $e->getMessage(),
      ]);
    }
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
    $credentials = $this->getCredentials();

    if (!$this->validateCredentials($credentials)) {
      return new CloudflarePurgeResult(
        success: FALSE,
        statusCode: 0,
        message: 'Invalid or missing credentials',
      );
    }

    return $this->purgeItemsInBatches(
      $urls,
      self::TYPE_URLS,
      $showMessage,
      fn(array $batch): CloudflarePurgeResult => $this->cloudflarePurgeApi->purgeByUrls(
        useBearerToken: $credentials['use_bearer'],
        zoneId: $credentials['zone_id'],
        bearerToken: $credentials['bearer_token'],
        authorization: $credentials['authorization'],
        email: $credentials['email'],
        urls: $batch,
      ),
    );
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
    $credentials = $this->getCredentials();

    if (!$this->validateCredentials($credentials)) {
      return new CloudflarePurgeResult(
        success: FALSE,
        statusCode: 0,
        message: 'Invalid or missing credentials',
      );
    }

    // Apply the shared wire transform (prefix + optional hashing) so the
    // purged tokens match exactly what the response subscriber emits in the
    // Cache-Tag header.
    $tags = $this->cacheTagFormatter->formatForWire($tags);

    return $this->purgeItemsInBatches(
      $tags,
      self::TYPE_TAGS,
      $showMessage,
      fn(array $batch): CloudflarePurgeResult => $this->cloudflarePurgeApi->purgeByTags(
        useBearerToken: $credentials['use_bearer'],
        zoneId: $credentials['zone_id'],
        bearerToken: $credentials['bearer_token'],
        authorization: $credentials['authorization'],
        email: $credentials['email'],
        tags: $batch,
      ),
    );
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
    $credentials = $this->getCredentials();

    if (!$this->validateCredentials($credentials)) {
      return new CloudflarePurgeResult(
        success: FALSE,
        statusCode: 0,
        message: 'Invalid or missing credentials',
      );
    }

    // Cloudflare rejects prefixes that include a URI scheme (error 1119).
    // Normalize defensively here so every caller is covered, not just the
    // form and Drush command. Normalization is idempotent.
    $prefixes = array_values(array_filter(
      array_map(
        static fn($prefix): string => is_string($prefix) ? PrefixNormalizer::normalize($prefix) : '',
        $prefixes,
      ),
      static fn(string $prefix): bool => $prefix !== '',
    ));

    return $this->purgeItemsInBatches(
      $prefixes,
      self::TYPE_PREFIXES,
      $showMessage,
      fn(array $batch): CloudflarePurgeResult => $this->cloudflarePurgeApi->purgeByPrefixes(
        useBearerToken: $credentials['use_bearer'],
        zoneId: $credentials['zone_id'],
        bearerToken: $credentials['bearer_token'],
        authorization: $credentials['authorization'],
        email: $credentials['email'],
        prefixes: $batch,
      ),
    );
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
    $credentials = $this->getCredentials();

    if (!$this->validateCredentials($credentials)) {
      return new CloudflarePurgeResult(
        success: FALSE,
        statusCode: 0,
        message: 'Invalid or missing credentials',
      );
    }

    return $this->purgeItemsInBatches(
      $hostnames,
      self::TYPE_HOSTNAMES,
      $showMessage,
      fn(array $batch): CloudflarePurgeResult => $this->cloudflarePurgeApi->purgeByHostnames(
        useBearerToken: $credentials['use_bearer'],
        zoneId: $credentials['zone_id'],
        bearerToken: $credentials['bearer_token'],
        authorization: $credentials['authorization'],
        email: $credentials['email'],
        hostnames: $batch,
      ),
    );
  }

  /**
   * Purges a list of items in Cloudflare-sized batches.
   *
   * Sanitizes the full list once (so de-duplication spans the whole input,
   * not just a single batch), splits it into request-sized batches, and runs
   * each batch through $apiCall while enforcing the rate limit per request.
   *
   * A single batch (the common case for forms and queued auto-purge items) is
   * returned verbatim, so its messaging, logging and result are byte-for-byte
   * identical to the pre-batching behavior. Multiple batches purge as much as
   * possible — continuing past a failed batch but stopping if the rate limit
   * is reached — and return an aggregate result.
   *
   * @param array<int|string, string> $items
   *   The items to purge, already type-transformed by the caller (cache tags
   *   formatted for the wire, prefixes normalized, etc.).
   * @param string $type
   *   One of the self::TYPE_* constants.
   * @param bool $showMessage
   *   Whether to surface a status message to the user.
   * @param callable(array<int, string>): \Drupal\cloudflare_purge\CloudflarePurgeResult $apiCall
   *   Executes a single batch against the Cloudflare API.
   *
   * @return \Drupal\cloudflare_purge\CloudflarePurgeResult
   *   The single-batch API result, or an aggregate result for multiple
   *   batches.
   */
  private function purgeItemsInBatches(array $items, string $type, bool $showMessage, callable $apiCall): CloudflarePurgeResult {
    $items = ItemBatcher::sanitize($items);
    $total = count($items);

    // No valid items: mirror the API's per-type "nothing to do" result.
    if ($total === 0) {
      $result = new CloudflarePurgeResult(
        success: FALSE,
        statusCode: 0,
        message: $this->emptyItemsMessage($type),
      );
      if ($showMessage) {
        // Display a translated message rather than echoing the result's
        // English message (which is kept verbatim for logs and Drush output).
        $this->messenger->addError(match ($type) {
          self::TYPE_URLS => $this->t('No valid URLs provided.'),
          self::TYPE_TAGS => $this->t('No valid cache tags provided.'),
          self::TYPE_PREFIXES => $this->t('No valid prefixes provided.'),
          self::TYPE_HOSTNAMES => $this->t('No valid hostnames provided.'),
          default => $this->t('No valid items provided.'),
        });
      }
      $this->logPurgeOperation($type, [], $result);
      return $result;
    }

    $batches = ItemBatcher::chunk($items, CloudflarePurgeApi::MAX_BATCH_SIZE);

    // Single batch: keep the API result's message/status/data verbatim so
    // messaging and logging are unchanged from before batching existed, only
    // attaching the purged-item count on success.
    if (count($batches) === 1) {
      $rateLimitResult = $this->checkRateLimit($showMessage);
      if ($rateLimitResult !== NULL) {
        return $rateLimitResult;
      }

      $result = $apiCall($batches[0]);
      if ($result->isSuccess()) {
        $result = $this->withPurgedCount($result, $total);
      }

      if ($showMessage) {
        $this->displayResultMessage($result, $type, $total);
      }
      $this->logPurgeOperation($type, $items, $result);
      return $result;
    }

    // Multiple batches: execute each, accumulating successes. Per-batch
    // messaging is suppressed in favor of a single aggregate message.
    //
    // A per-batch failure (e.g. one malformed item) does not stop the run —
    // we purge as much as possible. But operation-wide failures recur on
    // every batch, so we stop early instead of hammering the API with calls
    // that cannot succeed: a reached rate limit, or an authentication error
    // (bad credentials), which would fail identically for all remaining
    // batches.
    $purged = 0;
    $stopResult = NULL;
    foreach ($batches as $batch) {
      $rateLimitResult = $this->checkRateLimit(FALSE);
      if ($rateLimitResult !== NULL) {
        $stopResult = $rateLimitResult;
        break;
      }
      $batchResult = $apiCall($batch);
      if ($batchResult->isSuccess()) {
        $purged += count($batch);
        continue;
      }
      // Operation-wide failures recur identically on every batch, so stop
      // instead of firing the rest: a Cloudflare rate limit (429) or an
      // authentication error (401/403). Other per-batch failures fall through
      // and the next batch is still attempted.
      if ($batchResult->isRateLimited() || $batchResult->isAuthenticationError()) {
        $stopResult = $batchResult;
        break;
      }
    }

    $success = ($purged === $total);
    // Preserve the operation-wide failure's status code so callers can still
    // classify the aggregate (rate limit / auth) via the result object.
    $statusCode = $success ? 200 : ($stopResult?->getStatusCode() ?? 0);
    $result = new CloudflarePurgeResult(
      success: $success,
      statusCode: $statusCode,
      message: sprintf('Purged %d of %d %s.', $purged, $total, $this->typeLabel($type)),
      purgedCount: $purged,
    );

    if ($showMessage) {
      if ($success) {
        $this->displayResultMessage($result, $type, $total);
      }
      else {
        $reason = match (TRUE) {
          $stopResult?->isRateLimited() === TRUE => $this->t('The Cloudflare rate limit was reached; wait a moment and purge the remainder.'),
          $stopResult?->isAuthenticationError() === TRUE => $this->t('Cloudflare rejected the request — check your API credentials.'),
          default => $this->t('Some items could not be purged; check the log for details.'),
        };
        $this->messenger->addWarning($this->t('Purged @purged of @total items. @reason', [
          '@purged' => $purged,
          '@total' => $total,
          '@reason' => $reason,
        ]));
      }
    }
    $this->logPurgeOperation($type, $items, $result);
    return $result;
  }

  /**
   * Returns a copy of a result with the purged-item count attached.
   *
   * Keeps the message, status code and data byte-for-byte identical; only the
   * structured count is added so callers (e.g. Drush) can report the real
   * number purged after sanitization.
   *
   * @param \Drupal\cloudflare_purge\CloudflarePurgeResult $result
   *   The result to copy.
   * @param int $count
   *   The number of items purged.
   *
   * @return \Drupal\cloudflare_purge\CloudflarePurgeResult
   *   The enriched result.
   */
  private function withPurgedCount(CloudflarePurgeResult $result, int $count): CloudflarePurgeResult {
    return new CloudflarePurgeResult(
      success: $result->isSuccess(),
      statusCode: $result->getStatusCode(),
      message: $result->getMessage(),
      data: $result->getData(),
      purgedCount: $count,
    );
  }

  /**
   * Returns a human-readable plural label for a purge type.
   *
   * Used in the aggregate result message (and thus logs). "URLs" is
   * capitalized as an acronym; the others read naturally in lower case.
   *
   * @param string $type
   *   One of the self::TYPE_* constants.
   *
   * @return string
   *   The label, e.g. "URLs", "tags".
   */
  private function typeLabel(string $type): string {
    return match ($type) {
      self::TYPE_URLS => 'URLs',
      self::TYPE_TAGS => 'tags',
      self::TYPE_PREFIXES => 'prefixes',
      self::TYPE_HOSTNAMES => 'hostnames',
      default => 'items',
    };
  }

  /**
   * Returns the "no valid items" message for a purge type.
   *
   * Mirrors the messages the API layer returns for an empty request. This is
   * the result object's message, used for the log entry and Drush output, so
   * it is intentionally left untranslated (English); the user-facing UI message
   * is translated separately at the point of display in purgeItemsInBatches().
   *
   * @param string $type
   *   One of the self::TYPE_* constants.
   *
   * @return string
   *   The message.
   */
  private function emptyItemsMessage(string $type): string {
    return match ($type) {
      self::TYPE_URLS => 'No valid URLs provided.',
      self::TYPE_TAGS => 'No valid cache tags provided.',
      self::TYPE_PREFIXES => 'No valid prefixes provided.',
      self::TYPE_HOSTNAMES => 'No valid hostnames provided.',
      default => 'No valid items provided.',
    };
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
    return $this->credentialResolver->getCredentials();
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

    // Validate the Zone ID format up front (same pattern the API enforces) so a
    // malformed Zone ID fails fast with one clear error, instead of being
    // re-rejected once per batch inside the batch loop.
    if (preg_match(CloudflarePurgeApi::ZONE_ID_PATTERN, $credentials['zone_id']) !== 1) {
      $this->messenger->addError($this->t('Zone ID format is invalid. It must be a 32-character hexadecimal string.'));
      $this->logger->error('Cloudflare purge failed: Zone ID format is invalid.');
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
   * Gets a configuration value with type checking.
   *
   * @param string $name
   *   The configuration key.
   *
   * @return mixed
   *   The configuration value.
   */
  private function getConfigValue(string $name): mixed {
    return $this->configFactory->get(CloudflarePurgeSettings::NAME)->get($name);
  }

  /**
   * Checks if credentials are configured and valid.
   *
   * @return bool
   *   TRUE if credentials are configured, FALSE otherwise.
   */
  public function hasCredentials(): bool {
    return $this->credentialResolver->hasCredentials();
  }

  /**
   * Checks if the rate limit has been exceeded.
   *
   * @param bool $showMessage
   *   Whether to display an error message if rate limited.
   *
   * @return \Drupal\cloudflare_purge\CloudflarePurgeResult|null
   *   Returns a rate limit error result if limit exceeded, NULL if OK.
   *
   * @note MULTI-SERVER LIMITATION: This rate limiting uses Drupal's State API,
   *   which stores data per-server. In load-balanced environments, each server
   *   maintains its counter, potentially allowing `maxPerMinute * numServers`
   *   requests per minute. For strict rate limiting across multiple servers,
   *   consider using a shared storage like Redis or database-backed counters.
   *   This applies to every purge entry point, including Drush, since they all
   *   route through the purge service.
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
    $this->state->set(self::RATE_LIMIT_STATE_KEY, $data);
  }

}
