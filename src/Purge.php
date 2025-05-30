<?php

namespace Drupal\cloudflare_purge;

use Drupal\cloudflare_purge\Form\CloudflarePurgeForm;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Site\Settings;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a service to purge Cloudflare cache.
 */
class Purge {

  /**
   * Get config.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  public function __construct(ConfigFactoryInterface $config_factory) {
    $this->configFactory = $config_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
    );
  }

  /**
   * Purge cloudflare cache.
   *
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  public function purge(string $url = '') {
    $use_bearer_token = $this->getCredentials('use_bearer_token');
    $zoneId = $this->getCredentials('zone_id');
    if ($use_bearer_token == TRUE) {
      $bearer_token = $this->getCredentials('bearer_token');

      if (!empty($zoneId) && !empty($bearer_token)) {
        $results = CloudflarePurgeCredentials::cfPurgeCache($use_bearer_token, $zoneId, $bearer_token, $authorization = NULL, $email = NULL, $url);
        if ($results == 200) {
          \Drupal::messenger()->addMessage($this->t('Purge successful.'));
        }
        else {
          \Drupal::messenger()->addError($this->t('An error occurred, check drupal log for more info.'));
        }
      }
      else {
        \Drupal::messenger()->addError($this->t('Please insert Cloudflare credentials.'));
      }
    }
    else {
      $authorization = $this->getCredentials('authorization');
      $email = $this->getCredentials('email');

      if (!empty($zoneId) && !empty($authorization) && !empty($email)) {
        $results = CloudflarePurgeCredentials::cfPurgeCache($use_bearer_token, $zoneId, $authorization, $email, $url);
        if ($results == 200) {
          \Drupal::messenger()->addMessage($this->t('Purge successful.'));
        }
        else {
          \Drupal::messenger()->addError($this->t('An error occurred, check drupal log for more info.'));
        }
      }
      else {
        \Drupal::messenger()->addError($this->t('Please insert Cloudflare credentials.'));
      }
    }

  }

  /**
   * Gets Cloudflare credentials from config or settings.php fallback.
   *
   * @param string $name
   *   The credential key name to retrieve.
   *
   * @return mixed
   *   The credential value or NULL if not found.
   */
  protected function getCredentials(string $name) {
    $cloudflare_settings = Settings::get('cloudflare_purge_credentials');
    $cloudflare_config = $this->configFactory->get(CloudflarePurgeForm::SETTINGS);
    return $cloudflare_config->get($name) ?? $cloudflare_settings[$name];
  }

}
