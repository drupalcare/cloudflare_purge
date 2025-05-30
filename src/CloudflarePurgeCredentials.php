<?php

namespace Drupal\cloudflare_purge;

use Drupal\Core\Utility\Error;
use GuzzleHttp\Exception\RequestException;

/**
 * Cloudflare Purge Credentials.
 */
class CloudflarePurgeCredentials {

  /**
   * Function to purge the Cloudflare cache.
   *
   * @param bool $use_bearer_token
   *   Whether to use a bearer token for authentication.
   * @param string $zoneId
   *   The Cloudflare zone ID.
   * @param string $bearer_token
   *   The Cloudflare bearer token.
   * @param string $authorization
   *   The Cloudflare authorization key.
   * @param string $email
   *   The Cloudflare account email.
   * @param string $url_to_purge
   *   The specific URL to purge. Leave empty to purge everything.
   *
   * @return int|null
   *   The response status code on success, or NULL on failure.
   *
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  public static function cfPurgeCache(bool $use_bearer_token, string $zoneId, string $bearer_token, $authorization, $email, string $url_to_purge) {
    $url = "https://api.cloudflare.com/client/v4/zones/{$zoneId}/purge_cache";
    $method = 'POST';

    try {
      $client = \Drupal::httpClient();

      if ($use_bearer_token) {
        $options = [
          'headers' => [
            "Authorization" => 'Bearer ' . $bearer_token,
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
      if (empty($url_to_purge)) {
        $options['json']['purge_everything'] = TRUE;
      }
      else {
        $options['json']['files'] = [$url_to_purge];
      }
      $response = $client->request($method, $url, $options);
      $code = $response->getStatusCode();
      if ($code == 200) {
        return $code;
      }
    }
    catch (RequestException $e) {
      $logger = \Drupal::logger('cloudflare_purge');
      Error::logException($logger, $e);
    }

  }

}
