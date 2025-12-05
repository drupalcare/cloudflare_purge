<?php

declare(strict_types=1);

namespace Drupal\cloudflare_purge;

use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Utility\Error;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;

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
final class CloudflarePurgeApi {

  /**
   * Maximum items per batch request (Cloudflare API limit).
   */
  public const MAX_BATCH_SIZE = 30;

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
    return (bool) preg_match('/^[a-f0-9]{32}$/i', $zoneId);
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
        $this->logger->info('Cloudflare cache purged successfully. Payload: @payload', [
          '@payload' => json_encode($payload, JSON_THROW_ON_ERROR),
        ]);
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

      if ($e->hasResponse()) {
        $response = $e->getResponse();
        if ($response !== NULL) {
          $statusCode = $response->getStatusCode();
          $body = (string) $response->getBody();
          $data = $this->safeJsonDecode($body);
          $errorMessage = $this->extractErrorMessage($data) ?: $errorMessage;

          $this->logger->error('Cloudflare API error. Status: @status, Body: @body', [
            '@status' => $statusCode,
            '@body' => $body,
          ]);
        }
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
    catch (\JsonException $e) {
      $this->logger->error('Failed to encode payload as JSON: @message', [
        '@message' => $e->getMessage(),
      ]);

      return new CloudflarePurgeResult(
        success: FALSE,
        statusCode: 0,
        message: 'Failed to encode request payload.',
      );
    }
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
   * Sanitizes and validates an array of items for API request.
   *
   * @param array<int|string, mixed> $items
   *   The items to sanitize.
   * @param int $maxItems
   *   Maximum number of items allowed.
   *
   * @return array<int, string>
   *   The sanitized items.
   */
  private function sanitizeItems(array $items, int $maxItems = self::MAX_BATCH_SIZE): array {
    $sanitized = [];

    foreach ($items as $item) {
      // Skip non-string or empty items.
      if (!is_string($item)) {
        continue;
      }

      $trimmed = trim($item);
      if ($trimmed !== '') {
        $sanitized[] = $trimmed;
      }
    }

    // Remove duplicates and re-index.
    $sanitized = array_values(array_unique($sanitized));

    // Limit to max allowed.
    return array_slice($sanitized, 0, $maxItems);
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
   *   Array of URLs to purge (max 30).
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
   *   Array of cache tags to purge (max 30).
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
   *   Array of URL prefixes to purge (max 30).
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
   *   Array of hostnames to purge (max 30).
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
