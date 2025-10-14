<?php

declare(strict_types=1);

namespace Drupal\cloudflare_purge;

use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Utility\Error;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;

/**
 * Cloudflare API service for cache purging operations.
 */
class CloudflarePurgeApi {

  /**
   * Constructs a CloudflarePurgeApi object.
   *
   * @param \GuzzleHttp\ClientInterface $httpClient
   *   The HTTP client.
   * @param \Drupal\Core\Logger\LoggerChannelInterface $logger
   *   The logger channel.
   */
  public function __construct(
    protected ClientInterface $httpClient,
    protected LoggerChannelInterface $logger,
  ) {}

  /**
   * Purges the Cloudflare cache via API.
   *
   * @param bool $useBearerToken
   *   Whether to use a bearer token for authentication.
   * @param string $zoneId
   *   The Cloudflare zone ID.
   * @param string $bearerToken
   *   The Cloudflare bearer token.
   * @param string|null $authorization
   *   The Cloudflare authorization key.
   * @param string|null $email
   *   The Cloudflare account email.
   * @param string $urlToPurge
   *   The specific URL to purge. Leave empty to purge everything.
   *
   * @return int|null
   *   The response status code on success, or NULL on failure.
   *
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  public function cfPurgeCache(
    bool $useBearerToken,
    string $zoneId,
    string $bearerToken,
    ?string $authorization,
    ?string $email,
    string $urlToPurge,
  ): ?int {
    $url = "https://api.cloudflare.com/client/v4/zones/{$zoneId}/purge_cache";
    $method = 'POST';

    try {
      if ($useBearerToken) {
        $options = [
          'headers' => [
            'Authorization' => 'Bearer ' . $bearerToken,
          ],
        ];
      }
      else {
        $options = [
          'headers' => [
            'X-Auth-Email' => $email,
            'X-Auth-Key' => $authorization,
          ],
        ];
      }

      if (empty($urlToPurge)) {
        $options['json']['purge_everything'] = TRUE;
      }
      else {
        $options['json']['files'] = [$urlToPurge];
      }

      $response = $this->httpClient->request($method, $url, $options);
      $code = $response->getStatusCode();

      if ($code == 200) {
        return $code;
      }
    }
    catch (RequestException $e) {
      Error::logException($this->logger, $e);
    }

    return NULL;
  }

}
