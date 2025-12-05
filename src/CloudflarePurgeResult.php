<?php

declare(strict_types=1);

namespace Drupal\cloudflare_purge;

/**
 * Immutable value object representing result of a Cloudflare purge operation.
 *
 * This class encapsulates all information about a purge operation result,
 * including success status, HTTP status code, messages, and raw API response.
 *
 * @package Drupal\cloudflare_purge
 */
final class CloudflarePurgeResult {

  /**
   * Constructs a CloudflarePurgeResult object.
   *
   * @param bool $success
   *   Whether the purge operation was successful.
   * @param int $statusCode
   *   The HTTP status code from the API response.
   * @param string $message
   *   A human-readable message about the result.
   * @param array<string, mixed>|null $data
   *   Optional raw response data from the API.
   */
  public function __construct(
    private readonly bool $success,
    private readonly int $statusCode,
    private readonly string $message,
    private readonly ?array $data = NULL,
  ) {}

  /**
   * Checks if the purge operation was successful.
   *
   * @return bool
   *   TRUE if successful, FALSE otherwise.
   */
  public function isSuccess(): bool {
    return $this->success;
  }

  /**
   * Gets the HTTP status code.
   *
   * @return int
   *   The HTTP status code (0 if no response was received).
   */
  public function getStatusCode(): int {
    return $this->statusCode;
  }

  /**
   * Gets the result message.
   *
   * @return string
   *   The result message.
   */
  public function getMessage(): string {
    return $this->message;
  }

  /**
   * Gets the raw API response data.
   *
   * @return array<string, mixed>|null
   *   The raw response data, or NULL if not available.
   */
  public function getData(): ?array {
    return $this->data;
  }

  /**
   * Gets the purge ID from the response.
   *
   * @return string|null
   *   The purge operation ID, or NULL if not available.
   */
  public function getPurgeId(): ?string {
    if ($this->data === NULL) {
      return NULL;
    }

    $id = $this->data['result']['id'] ?? NULL;

    return is_string($id) ? $id : NULL;
  }

  /**
   * Checks if the result indicates a rate limit error.
   *
   * @return bool
   *   TRUE if rate limited, FALSE otherwise.
   */
  public function isRateLimited(): bool {
    return $this->statusCode === 429;
  }

  /**
   * Checks if the result indicates an authentication error.
   *
   * @return bool
   *   TRUE if authentication failed, FALSE otherwise.
   */
  public function isAuthenticationError(): bool {
    return $this->statusCode === 401 || $this->statusCode === 403;
  }

  /**
   * Gets error details from the API response.
   *
   * @return array<int, array<string, mixed>>
   *   Array of error details, or empty array if none.
   */
  public function getErrors(): array {
    if ($this->data === NULL || !isset($this->data['errors'])) {
      return [];
    }

    $errors = $this->data['errors'];

    return is_array($errors) ? $errors : [];
  }

}
