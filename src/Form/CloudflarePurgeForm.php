<?php

namespace Drupal\cloudflare_purge\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Site\Settings;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Default ConfigFormBase for the cloudflare_purge module.
 */
class CloudflarePurgeForm extends ConfigFormBase {
  /**
   * Config settings.
   *
   * @var string
   */
  public const SETTINGS = 'cloudflare_purge.settings';
  /**
   * Log activity when user enter credentials.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * Cloudflare purge constructor.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The factory for configuration objects.
   * @param \Drupal\Core\Logger\LoggerChannelInterface $logger
   *   The logger.
   */
  public function __construct(ConfigFactoryInterface $config_factory, LoggerChannelInterface $logger) {
    parent::__construct($config_factory);
    $this->logger = $logger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('cloudflare_purge.logger.channel.cloudflare_purge')
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return [
      static::SETTINGS,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId():string {
    return 'cloudflare_purge_form';
  }

  /**
   * Build the form.
   *
   * @param array $form
   *   Form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form State interface.
   *
   * @return array
   *   Return array.
   */
  public function buildForm(array $form, FormStateInterface $form_state):array {
    $config = $this->config(static::SETTINGS);

    $form['cloudflare_purge_form']['zone_id'] = [
      '#type' => 'textfield',
      '#title' => t('Zone ID'),
      '#size' => 60,
      '#required' => TRUE,
      '#default_value' => $this->isOverridden('zone_id') ?? $config->get('zone_id'),
      '#disabled' => $this->isOverridden('zone_id'),
      '#attributes' => [
        'placeholder' => [
          'Zone ID',
        ],
      ],
      '#description' => t('Enter Cloudflare Zone Id.'),
    ];
    $form['cloudflare_purge_form']['authorization'] = [
      '#type' => 'textfield',
      '#title' => t('Authorization'),
      '#size' => 60,
      '#required' => TRUE,
      '#default_value' => $this->isOverridden('authorization') ?? $config->get('authorization'),
      '#disabled' => $this->isOverridden('authorization'),
      '#attributes' => [
        'placeholder' => [
          'Authorization',
        ],
      ],
      '#description' => t('Enter Cloudflare Authorization.'),
    ];
    return parent::buildForm($form, $form_state);

  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // Save all configs on submit.
    $this->config(self::SETTINGS)
      ->set('zone_id', $form_state->getValue('zone_id'))
      ->set('authorization', $form_state->getValue('authorization'))
      ->save();
    parent::submitForm($form, $form_state);

  }

  /**
   * Check if config variable is overridden by the settings.php.
   *
   * @param string $name
   *   Check for the field value.
   *
   * @return mixed
   *   Return the value
   */
  protected function isOverridden(string $name) {
    $cloudflareCredentials = Settings::get('cloudflare_purge_credentials');
    if (!empty($cloudflareCredentials[$name])) {
      return $cloudflareCredentials[$name];
    }
    return FALSE;
  }

}
