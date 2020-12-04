<?php

namespace Drupal\cloudflare_purge;

use GuzzleHttp\Exception\RequestException;

/**
 * Cloudflare Purge Credentials.
 */
class CloudflarePurgeCredentials {

  /**
   * Function to get response.
   *
   * @param string $zoneId
   *   CF zone ID.
   * @param string $authorization
   *   CF authorization.
   *
   * @return int
   *   Return code status.
   *
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  public static function cfPurgeCache(string $zoneId, string $authorization) {
    $url = "https://api.cloudflare.com/client/v4/zones/{$zoneId}/purge_cache";
    $method = 'POST';

    try {
      $client = \Drupal::httpClient();
      $options = [
        'json' => [
          'purge_everything' => TRUE,
        ],
        'headers' => [
          'Authorization' => 'Bearer ' . $authorization,
        ],
      ];
      $response = $client->request($method, $url, $options);
      $code = $response->getStatusCode();
      if ($code == 200) {
        return $code;
      }
    }
    catch (RequestException $e) {
      watchdog_exception('cloudflare_purge', $e);
    }

  }

}
