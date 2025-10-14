<?php

declare(strict_types=1);

namespace Drupal\cloudflare_purge;

use Drupal\cloudflare_purge\Form\CloudflarePurgeForm;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Provides a service to purge Cloudflare cache.
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
   */
  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected LoggerChannelInterface $logger,
    protected MessengerInterface $messenger,
    protected CloudflarePurgeApi $cloudflarePurgeApi,
  ) {}

  /**
   * Purges Cloudflare cache.
   *
   * @param string $url
   *   The specific URL to purge. Leave empty to purge everything.
   *
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  public function purge(string $url = ''): void {
    $useBearerToken = (bool) $this->getCredentials('use_bearer_token');
    $zoneId = $this->getCredentials('zone_id');

    if ($useBearerToken) {
      $bearerToken = $this->getCredentials('bearer_token');

      if (!empty($zoneId) && !empty($bearerToken)) {
        $results = $this->cloudflarePurgeApi->cfPurgeCache(
          $useBearerToken,
          $zoneId,
          $bearerToken,
          NULL,
          NULL,
          $url
        );

        if ($results == 200) {
          $this->messenger->addMessage($this->t('Purge successful.'));
        }
        else {
          $this->messenger->addError($this->t('An error occurred, check drupal log for more info.'));
        }
      }
      else {
        $this->messenger->addError($this->t('Please insert Cloudflare credentials.'));
      }
    }
    else {
      $authorization = $this->getCredentials('authorization');
      $email = $this->getCredentials('email');

      if (!empty($zoneId) && !empty($authorization) && !empty($email)) {
        $results = $this->cloudflarePurgeApi->cfPurgeCache(
          $useBearerToken,
          $zoneId,
          '',
          $authorization,
          $email,
          $url
        );

        if ($results == 200) {
          $this->messenger->addMessage($this->t('Purge successful.'));
        }
        else {
          $this->messenger->addError($this->t('An error occurred, check drupal log for more info.'));
        }
      }
      else {
        $this->messenger->addError($this->t('Please insert Cloudflare credentials.'));
      }
    }
  }

  /**
   * Gets Cloudflare credentials from config or settings.php fallback.
   *
   * @param string $name
   *   The credential key name to retrieve.
   *
   * @return string|null
   *   The credential value or NULL if not found.
   */
  protected function getCredentials(string $name): ?string {
    $cloudflareSettings = Settings::get('cloudflare_purge_credentials');
    $cloudflareConfig = $this->configFactory->get(CloudflarePurgeForm::SETTINGS);

    if (!empty($cloudflareConfig->get($name))) {
      return $cloudflareConfig->get($name);
    }
    elseif (!empty($cloudflareSettings[$name])) {
      return $cloudflareSettings[$name];
    }

    return NULL;
  }

}
