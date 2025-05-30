<?php

namespace Drupal\cloudflare_purge;

use Drupal\Core\Utility\Error;
use GuzzleHttp\Exception\RequestException;

/**
 * Cloudflare Purge Credentials.
 */
class CloudflarePurgeCredentials {

  /**
   * Function to get response.
   * @param bool $use_bearer_token
   *   Authorization type.
   * @param string $bearer_token
   *   CF bearer_token.
   * @param string $zoneId
   *   CF zone ID.
   * @param string $authorization
   *   CF authorization.
   * @param string $email
   *  CF email
   * @param string $url
   *   URL to purge
   *
   * @return int
   *   Return code status.
   *
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  public static function cfPurgeCache(bool $use_bearer_token, string $zoneId, string $bearer_token, $authorization, $email, string $url_to_purge) {
    $url = "https://api.cloudflare.com/client/v4/zones/{$zoneId}/purge_cache";
    $method = 'POST';

    try {
      $client = \Drupal::httpClient();

      if ($use_bearer_token ) {
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
