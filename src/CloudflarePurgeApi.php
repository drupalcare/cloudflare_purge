<?php

declare(strict_types=1);

namespace Drupal\cloudflare_purge;

use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Utility\Error;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Psr\Http\Message\ResponseInterface;

/**
 * Cloudflare API service for comprehensive cache purging operations.
 *
 * Supports all Cloudflare purge methods:
 * - Purge Everything
 * - Purge by URL (single or batch)
 * - Purge by Cache Tags
 * - Purge by Prefixes
 * - Purge by Hostnames.
 *
 * @package Drupal\cloudflare_purge
 */
final class CloudflarePurgeApi implements CloudflarePurgeApiInterface {

  /**
   * Maximum items per purge request (Cloudflare API limit).
   *
   * Cloudflare allows up to 100 operations per purge request for purge by URL,
   * cache tag, hostname and prefix across all plans (Enterprise allows 500 for
   * single-file/URL purges). 100 is the safe ceiling for every plan and
   * operation type, and minimizes request count / rate-limit pressure.
   *
   * @see https://developers.cloudflare.com/cache/how-to/purge-cache/
   */
  public const MAX_BATCH_SIZE = 100;

  /**
   * Authoritative Cloudflare purge limits documentation URL.
   */
  public const PURGE_DOCS_URL = 'https://developers.cloudflare.com/cache/how-to/purge-cache/';

  /**
   * Cloudflare purge-by-tags documentation URL (Cache-Tag header limit).
   */
  public const PURGE_BY_TAGS_DOCS_URL = 'https://developers.cloudflare.com/cache/how-to/purge-cache/purge-by-tags/';

  /**
   * Date the published Cloudflare limits were last verified (ISO 8601).
   *
   * Verified against Cloudflare docs last updated 2026-04-16.
   */
  public const LIMITS_LAST_VERIFIED = '2026-06-14';

  /**
   * Validation pattern for a Cloudflare Zone ID (32-character hex string).
   */
  public const ZONE_ID_PATTERN = '/^[a-f0-9]{32}$/i';

  /**
   * Cloudflare API base URL.
   */
  private const API_BASE_URL = 'https://api.cloudflare.com/client/v4/zones';

  /**
   * Request timeout in seconds.
   */
  private const REQUEST_TIMEOUT = 30;

  /**
   * Constructs a CloudflarePurgeApi object.
   *
   * @param \GuzzleHttp\ClientInterface $httpClient
   *   The HTTP client.
   * @param \Drupal\Core\Logger\LoggerChannelInterface $logger
   *   The logger channel.
   */
  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly LoggerChannelInterface $logger,
  ) {}

  /**
   * Builds authentication headers based on method.
   *
   * @param bool $useBearerToken
   *   Whether to use bearer token authentication.
   * @param string $bearerToken
   *   The bearer token (if using bearer auth).
   * @param string|null $authorization
   *   The API key (if using legacy auth).
   * @param string|null $email
   *   The email (if using legacy auth).
   *
   * @return array<string, string>
   *   The authentication headers.
   */
  private function buildAuthHeaders(
    bool $useBearerToken,
    string $bearerToken,
    ?string $authorization,
    ?string $email,
  ): array {
    $headers = [
      'Content-Type' => 'application/json',
      'Accept' => 'application/json',
    ];

    if ($useBearerToken) {
      $headers['Authorization'] = 'Bearer ' . $bearerToken;
    }
    else {
      $headers['X-Auth-Email'] = $email ?? '';
      $headers['X-Auth-Key'] = $authorization ?? '';
    }

    return $headers;
  }

  /**
   * Validates zone ID format.
   *
   * @param string $zoneId
   *   The zone ID to validate.
   *
   * @return bool
   *   TRUE if valid, FALSE otherwise.
   */
  private function isValidZoneId(string $zoneId): bool {
    // Cloudflare Zone IDs are 32-character hex strings.
    return (bool) preg_match(self::ZONE_ID_PATTERN, $zoneId);
  }

  /**
   * Makes a purge API request to Cloudflare.
   *
   * @param string $zoneId
   *   The Cloudflare zone ID.
   * @param array<string, string> $headers
   *   The request headers.
   * @param array<string, mixed> $payload
   *   The JSON payload.
   *
   * @return \Drupal\cloudflare_purge\CloudflarePurgeResult
   *   The purge result object.
   */
  private function makeRequest(
    string $zoneId,
    array $headers,
    array $payload,
  ): CloudflarePurgeResult {
    // Validate zone ID format.
    if (!$this->isValidZoneId($zoneId)) {
      $this->logger->error('Invalid Cloudflare Zone ID format: @zone_id', [
        '@zone_id' => $zoneId,
      ]);
      return new CloudflarePurgeResult(
        success: FALSE,
        statusCode: 0,
        message: 'Invalid Zone ID format. Zone ID should be a 32-character hexadecimal string.',
      );
    }

    // Validate payload is not empty.
    if (empty($payload)) {
      return new CloudflarePurgeResult(
        success: FALSE,
        statusCode: 0,
        message: 'Empty payload provided.',
      );
    }

    $url = self::API_BASE_URL . "/{$zoneId}/purge_cache";

    try {
      $response = $this->httpClient->request('POST', $url, [
        'headers' => $headers,
        'json' => $payload,
        'timeout' => self::REQUEST_TIMEOUT,
        'connect_timeout' => 10,
      ]);

      $code = $response->getStatusCode();
      $body = (string) $response->getBody();

      // Safely decode JSON response.
      $data = $this->safeJsonDecode($body);

      if ($code === 200 && ($data['success'] ?? FALSE) === TRUE) {
        // Successful purges are logged (privacy-safely, and gated by the
        // "detailed logging" setting) by the Purge service, which wraps every
        // call into this low-level client. Logging here too would duplicate
        // those entries and — being ungated — flood dblog on auto-purge even
        // when detailed logging is off. Errors below are always logged.
        return new CloudflarePurgeResult(
          success: TRUE,
          statusCode: $code,
          message: 'Purge successful',
          data: $data,
        );
      }

      // Handle API-level errors.
      $errorMessage = $this->extractErrorMessage($data);

      $this->logger->warning('Cloudflare API returned error. Status: @code, Message: @message', [
        '@code' => $code,
        '@message' => $errorMessage,
      ]);

      return new CloudflarePurgeResult(
        success: FALSE,
        statusCode: $code,
        message: $errorMessage,
        data: $data,
      );
    }
    catch (ConnectException $e) {
      $this->logger->error('Cloudflare API connection failed: @message', [
        '@message' => $e->getMessage(),
      ]);

      return new CloudflarePurgeResult(
        success: FALSE,
        statusCode: 0,
        message: 'Connection to Cloudflare API failed. Please check your network connection.',
      );
    }
    catch (RequestException $e) {
      Error::logException($this->logger, $e);

      $statusCode = 0;
      $errorMessage = $e->getMessage();
      $data = NULL;

      $response = $this->responseFromException($e);
      if ($response !== NULL) {
        $statusCode = $response->getStatusCode();
        $body = (string) $response->getBody();
        $data = $this->safeJsonDecode($body);
        $errorMessage = $this->extractErrorMessage($data) ?: $errorMessage;

        // Truncate the raw body: Cloudflare error responses can echo back the
        // offending input (e.g. a URL/prefix) and may be large.
        $this->logger->error('Cloudflare API error. Status: @status, Body: @body', [
          '@status' => $statusCode,
          '@body' => mb_strimwidth($body, 0, 1024, '…'),
        ]);
      }

      return new CloudflarePurgeResult(
        success: FALSE,
        statusCode: $statusCode,
        message: $errorMessage,
        data: $data,
      );
    }
    catch (GuzzleException $e) {
      Error::logException($this->logger, $e);

      return new CloudflarePurgeResult(
        success: FALSE,
        statusCode: 0,
        message: 'HTTP request failed: ' . $e->getMessage(),
      );
    }
  }

  /**
   * Extracts the response from a Guzzle request exception, across Guzzle 7 & 8.
   *
   * The two shipped Guzzle majors disagree about where a response lives on an
   * exception, and this module supports both:
   *
   * - Guzzle 7 (Drupal 10 and 11) declares hasResponse() and getResponse() on
   *   RequestException itself; getResponse() is nullable there.
   * - Guzzle 8 (Drupal 12) removed BOTH from RequestException. A response is
   *   carried only by ResponseException and its subclasses
   *   (BadResponseException, ClientException, ServerException,
   *   TooManyRedirectsException, ResponseTransferException), which still
   *   extend RequestException and so still land in this catch. Calling
   *   hasResponse() on the parent there is a fatal Error, not a caught
   *   exception.
   *
   * Feature detection rather than a version check keeps this correct whichever
   * Guzzle the host site actually resolved, and needs no reference to a class
   * that does not exist on the other major.
   *
   * @param \GuzzleHttp\Exception\RequestException $e
   *   The caught request exception.
   *
   * @return \Psr\Http\Message\ResponseInterface|null
   *   The response the exception carries, or NULL when it carries none.
   */
  private function responseFromException(RequestException $e): ?ResponseInterface {
    // Guzzle 7 only: the exception itself says whether a response came back,
    // and getResponse() is nullable, so a NULL here is an answer, not a gap.
    if (method_exists($e, 'hasResponse') && !$e->hasResponse()) {
      return NULL;
    }

    // Guzzle 8: getResponse() exists only on the response-carrying subclasses,
    // so its absence is itself the "no response" answer.
    if (!method_exists($e, 'getResponse')) {
      return NULL;
    }

    $response = $e->getResponse();
    return $response instanceof ResponseInterface ? $response : NULL;
  }

  /**
   * Safely decodes JSON string.
   *
   * @param string $json
   *   The JSON string.
   *
   * @return array<string, mixed>|null
   *   The decoded array or NULL on failure.
   */
  private function safeJsonDecode(string $json): ?array {
    if ($json === '') {
      return NULL;
    }

    try {
      $decoded = json_decode($json, TRUE, 512, JSON_THROW_ON_ERROR);
      return is_array($decoded) ? $decoded : NULL;
    }
    catch (\JsonException) {
      return NULL;
    }
  }

  /**
   * Extracts error message from API response.
   *
   * @param array<string, mixed>|null $data
   *   The API response data.
   *
   * @return string
   *   The error message.
   */
  private function extractErrorMessage(?array $data): string {
    if ($data === NULL) {
      return 'Unknown error (empty response)';
    }

    // Check for errors array.
    if (!empty($data['errors']) && is_array($data['errors'])) {
      $firstError = reset($data['errors']);
      if (is_array($firstError) && isset($firstError['message'])) {
        $message = (string) $firstError['message'];
        // Include error code if available.
        if (isset($firstError['code'])) {
          $message .= ' (code: ' . $firstError['code'] . ')';
        }
        return $message;
      }
    }

    // Check for messages array.
    if (!empty($data['messages']) && is_array($data['messages'])) {
      $firstMessage = reset($data['messages']);
      if (is_string($firstMessage)) {
        return $firstMessage;
      }
      if (is_array($firstMessage) && isset($firstMessage['message'])) {
        return (string) $firstMessage['message'];
      }
    }

    return 'Unknown error';
  }

  /**
   * Sanitizes an array of items for a single API request.
   *
   * Sanitization (trim/de-dupe) is delegated to the shared ItemBatcher so the
   * rule matches every other purge path. The per-request size limit is a
   * defensive backstop: callers are expected to batch via the Purge service,
   * so receiving more than $maxItems here means a caller did not batch. Rather
   * than silently discard the overflow (which previously turned an oversized
   * request into a partial purge with no trace), the dropped items are logged.
   *
   * @param array<int|string, mixed> $items
   *   The items to sanitize.
   * @param int $maxItems
   *   Maximum number of items allowed in a single request.
   *
   * @return array<int, string>
   *   The sanitized items, capped at $maxItems.
   */
  private function sanitizeItems(array $items, int $maxItems = self::MAX_BATCH_SIZE): array {
    $sanitized = ItemBatcher::sanitize($items);

    if (count($sanitized) > $maxItems) {
      $this->logger->warning('Cloudflare purge request received @count items, exceeding the @max-item per-request limit; @dropped item(s) were dropped. Items should be batched via the Purge service before reaching the API.', [
        '@count' => count($sanitized),
        '@max' => $maxItems,
        '@dropped' => count($sanitized) - $maxItems,
      ]);
      $sanitized = array_slice($sanitized, 0, $maxItems);
    }

    return $sanitized;
  }

  /**
   * Purges everything from the Cloudflare cache.
   *
   * @param bool $useBearerToken
   *   Whether to use bearer token authentication.
   * @param string $zoneId
   *   The Cloudflare zone ID.
   * @param string $bearerToken
   *   The bearer token.
   * @param string|null $authorization
   *   The API key.
   * @param string|null $email
   *   The account email.
   *
   * @return \Drupal\cloudflare_purge\CloudflarePurgeResult
   *   The purge result.
   */
  public function purgeEverything(
    bool $useBearerToken,
    string $zoneId,
    string $bearerToken,
    ?string $authorization,
    ?string $email,
  ): CloudflarePurgeResult {
    $headers = $this->buildAuthHeaders($useBearerToken, $bearerToken, $authorization, $email);
    return $this->makeRequest($zoneId, $headers, ['purge_everything' => TRUE]);
  }

  /**
   * Purges specific URLs from the Cloudflare cache.
   *
   * @param bool $useBearerToken
   *   Whether to use bearer token authentication.
   * @param string $zoneId
   *   The Cloudflare zone ID.
   * @param string $bearerToken
   *   The bearer token.
   * @param string|null $authorization
   *   The API key.
   * @param string|null $email
   *   The account email.
   * @param array<int|string, mixed> $urls
   *   Array of URLs to purge. Cloudflare accepts up to 100 per request (500 on
   *   Enterprise); larger lists must be batched by the caller. See
   *   self::MAX_BATCH_SIZE.
   *
   * @return \Drupal\cloudflare_purge\CloudflarePurgeResult
   *   The purge result.
   */
  public function purgeByUrls(
    bool $useBearerToken,
    string $zoneId,
    string $bearerToken,
    ?string $authorization,
    ?string $email,
    array $urls,
  ): CloudflarePurgeResult {
    $sanitizedUrls = $this->sanitizeItems($urls);

    if ($sanitizedUrls === []) {
      return new CloudflarePurgeResult(
        success: FALSE,
        statusCode: 0,
        message: 'No valid URLs provided.',
      );
    }

    $headers = $this->buildAuthHeaders($useBearerToken, $bearerToken, $authorization, $email);
    return $this->makeRequest($zoneId, $headers, ['files' => $sanitizedUrls]);
  }

  /**
   * Purges content by cache tags.
   *
   * @param bool $useBearerToken
   *   Whether to use bearer token authentication.
   * @param string $zoneId
   *   The Cloudflare zone ID.
   * @param string $bearerToken
   *   The bearer token.
   * @param string|null $authorization
   *   The API key.
   * @param string|null $email
   *   The account email.
   * @param array<int|string, mixed> $tags
   *   Array of cache tags to purge. Cloudflare accepts up to 100 per request on
   *   all plans; larger lists must be batched by the caller. See
   *   self::MAX_BATCH_SIZE.
   *
   * @return \Drupal\cloudflare_purge\CloudflarePurgeResult
   *   The purge result.
   */
  public function purgeByTags(
    bool $useBearerToken,
    string $zoneId,
    string $bearerToken,
    ?string $authorization,
    ?string $email,
    array $tags,
  ): CloudflarePurgeResult {
    $sanitizedTags = $this->sanitizeItems($tags);

    if ($sanitizedTags === []) {
      return new CloudflarePurgeResult(
        success: FALSE,
        statusCode: 0,
        message: 'No valid cache tags provided.',
      );
    }

    $headers = $this->buildAuthHeaders($useBearerToken, $bearerToken, $authorization, $email);
    return $this->makeRequest($zoneId, $headers, ['tags' => $sanitizedTags]);
  }

  /**
   * Purges content by URL prefixes.
   *
   * @param bool $useBearerToken
   *   Whether to use bearer token authentication.
   * @param string $zoneId
   *   The Cloudflare zone ID.
   * @param string $bearerToken
   *   The bearer token.
   * @param string|null $authorization
   *   The API key.
   * @param string|null $email
   *   The account email.
   * @param array<int|string, mixed> $prefixes
   *   Array of URL prefixes to purge. Cloudflare accepts up to 100 per request
   *   on all plans; larger lists must be batched by the caller. See
   *   self::MAX_BATCH_SIZE.
   *
   * @return \Drupal\cloudflare_purge\CloudflarePurgeResult
   *   The purge result.
   */
  public function purgeByPrefixes(
    bool $useBearerToken,
    string $zoneId,
    string $bearerToken,
    ?string $authorization,
    ?string $email,
    array $prefixes,
  ): CloudflarePurgeResult {
    $sanitizedPrefixes = $this->sanitizeItems($prefixes);

    if ($sanitizedPrefixes === []) {
      return new CloudflarePurgeResult(
        success: FALSE,
        statusCode: 0,
        message: 'No valid prefixes provided.',
      );
    }

    $headers = $this->buildAuthHeaders($useBearerToken, $bearerToken, $authorization, $email);
    return $this->makeRequest($zoneId, $headers, ['prefixes' => $sanitizedPrefixes]);
  }

  /**
   * Purges content by hostnames.
   *
   * @param bool $useBearerToken
   *   Whether to use bearer token authentication.
   * @param string $zoneId
   *   The Cloudflare zone ID.
   * @param string $bearerToken
   *   The bearer token.
   * @param string|null $authorization
   *   The API key.
   * @param string|null $email
   *   The account email.
   * @param array<int|string, mixed> $hostnames
   *   Array of hostnames to purge. Cloudflare accepts up to 100 per request on
   *   all plans; larger lists must be batched by the caller. See
   *   self::MAX_BATCH_SIZE.
   *
   * @return \Drupal\cloudflare_purge\CloudflarePurgeResult
   *   The purge result.
   */
  public function purgeByHostnames(
    bool $useBearerToken,
    string $zoneId,
    string $bearerToken,
    ?string $authorization,
    ?string $email,
    array $hostnames,
  ): CloudflarePurgeResult {
    $sanitizedHostnames = $this->sanitizeItems($hostnames);

    if ($sanitizedHostnames === []) {
      return new CloudflarePurgeResult(
        success: FALSE,
        statusCode: 0,
        message: 'No valid hostnames provided.',
      );
    }

    $headers = $this->buildAuthHeaders($useBearerToken, $bearerToken, $authorization, $email);
    return $this->makeRequest($zoneId, $headers, ['hosts' => $sanitizedHostnames]);
  }

}
