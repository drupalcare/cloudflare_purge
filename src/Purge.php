<?php

namespace Drupal\cloudflare_purge;

use Drupal\cloudflare_purge\Form\CloudflarePurgeForm;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Site\Settings;
use Symfony\Component\DependencyInjection\ContainerInterface;

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
    $zoneId = $this->getCredentials('zone_id');
    $authorization = $this->getCredentials('authorization');
    $email = $this->getCredentials('email');

    if (!empty($zoneId) && !empty($authorization) && !empty($email)) {
      $results = CloudflarePurgeCredentials::cfPurgeCache($zoneId, $authorization, $email, $url);
      if ($results == 200) {
        \Drupal::messenger()->addMessage(t('Purge successful.'));
      }
      else {
        \Drupal::messenger()->addError(t('An error occurred, check drupal log for more info.'));
      }
    }
    else {
      \Drupal::messenger()->addError(t('Please insert Cloudflare credentials.'));
    }

  }

  protected function getCredentials(string $name) {
    $cloudflare_settings = Settings::get('cloudflare_purge_credentials');
    $cloudflare_config = $this->configFactory->get(CloudflarePurgeForm::SETTINGS);
    return $cloudflare_config->get($name) ?? $cloudflare_settings[$name];
  }

}
