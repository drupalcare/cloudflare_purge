<?php

declare(strict_types=1);

namespace Drupal\cloudflare_purge\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configuration form for automatic cache purging settings.
 *
 * This form allows administrators to configure:
 * - Automatic purging when Drupal cache tags are invalidated
 * - Which entity types trigger automatic purging
 * - Queue-based purging for high-traffic sites
 * - Tag prefix for Cloudflare tags
 * - Logging options.
 *
 * @package Drupal\cloudflare_purge\Form
 */
final class CloudflarePurgeSettingsForm extends ConfigFormBase {

  /**
   * Constructs a CloudflarePurgeSettingsForm.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\Core\Config\TypedConfigManagerInterface $typedConfigManager
   *   The typed config manager.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(
    ConfigFactoryInterface $configFactory,
    TypedConfigManagerInterface $typedConfigManager,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct($configFactory, $typedConfigManager);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return [CloudflarePurgeForm::SETTINGS];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'cloudflare_purge_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config(CloudflarePurgeForm::SETTINGS);

    $form['#attached']['library'][] = 'cloudflare_purge/form';
    $form['#attributes']['class'][] = 'cloudflare-purge-form';

    // Auto-purge section.
    $form['auto_purge'] = [
      '#type' => 'details',
      '#title' => $this->t('Automatic Cache Tag Purging'),
      '#open' => TRUE,
    ];

    $form['auto_purge']['auto_purge_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable automatic cache tag purging'),
      '#description' => $this->t('When enabled, Cloudflare cache will be automatically purged when Drupal cache tags are invalidated. This requires adding Cache-Tag headers to your responses. Free tier: 5 requests/minute, consider queue processing for high-traffic sites.'),
      '#default_value' => (bool) ($config->get('auto_purge_enabled') ?? FALSE),
    ];

    $form['auto_purge']['tag_prefix'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Cache Tag Prefix'),
      '#description' => $this->t('Optional prefix to add to all cache tags sent to Cloudflare. Useful for multi-site setups sharing a zone. Example: <code>mysite-</code> would transform <code>node:1</code> to <code>mysite-node:1</code>.'),
      '#default_value' => $config->get('tag_prefix') ?? '',
      '#size' => 30,
      '#maxlength' => 50,
      '#states' => [
        'visible' => [
          ':input[name="auto_purge_enabled"]' => ['checked' => TRUE],
        ],
      ],
    ];

    // Entity types.
    $form['auto_purge']['auto_purge_entity_types'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Entity Types for Automatic Purging'),
      '#description' => $this->t('Select which entity types should trigger automatic Cloudflare purges when saved or deleted.'),
      '#options' => $this->getEntityTypeOptions(),
      '#default_value' => $config->get('auto_purge_entity_types') ?? ['node', 'taxonomy_term', 'media'],
      '#states' => [
        'visible' => [
          ':input[name="auto_purge_enabled"]' => ['checked' => TRUE],
        ],
      ],
    ];

    // Queue settings.
    $form['queue'] = [
      '#type' => 'details',
      '#title' => $this->t('Queue Settings'),
      '#open' => TRUE,
    ];

    $form['queue']['auto_purge_use_queue'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Use queue for automatic purging'),
      '#description' => $this->t('Queue purge requests instead of executing immediately. Recommended for high-traffic sites to avoid rate limiting and improve page save performance. Queue is processed during cron runs.'),
      '#default_value' => (bool) ($config->get('auto_purge_use_queue') ?? FALSE),
    ];

    // Rate limiting.
    $form['rate_limit'] = [
      '#type' => 'details',
      '#title' => $this->t('Rate Limiting'),
      '#open' => FALSE,
    ];

    $form['rate_limit']['rate_limit_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable rate limiting'),
      '#description' => $this->t('Limit the number of purge requests per minute to avoid hitting Cloudflare API limits.'),
      '#default_value' => (bool) ($config->get('rate_limit_enabled') ?? FALSE),
    ];

    $form['rate_limit']['rate_limit_per_minute'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum requests per minute'),
      '#description' => $this->t('Cloudflare free tier: 1000 API calls/day, 5 requests/minute for tag/prefix/hostname purges. Paid plans have higher limits.'),
      '#default_value' => (int) ($config->get('rate_limit_per_minute') ?? 60),
      '#min' => 1,
      '#max' => 1000,
      '#states' => [
        'visible' => [
          ':input[name="rate_limit_enabled"]' => ['checked' => TRUE],
        ],
      ],
    ];

    // Logging.
    $form['logging'] = [
      '#type' => 'details',
      '#title' => $this->t('Logging'),
      '#open' => FALSE,
    ];

    $form['logging']['logging_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable detailed logging'),
      '#description' => $this->t('Log all purge operations to the Drupal log. Useful for debugging but may generate many log entries on busy sites.'),
      '#default_value' => (bool) ($config->get('logging_enabled') ?? FALSE),
    ];

    // Cache-Tag header instructions.
    $form['instructions'] = [
      '#type' => 'details',
      '#title' => $this->t('Setup Instructions'),
      '#open' => FALSE,
    ];

    $form['instructions']['content'] = [
      '#markup' => '<h4>' . $this->t('Adding Cache-Tag Headers') . '</h4>' .
      '<p>' . $this->t("For automatic tag-based purging to work, your responses must include <code>Cache-Tag</code> headers. This module automatically converts Drupal's <code>X-Drupal-Cache-Tags</code> header to Cloudflare's <code>Cache-Tag</code> format when auto-purge is enabled.") . '</p>' .
      '<h4>' . $this->t('Example Header') . '</h4>' .
      '<pre>Cache-Tag: node-123, taxonomy_term-45, user-1</pre>' .
      '<h4>' . $this->t('Requirements') . '</h4>' .
      '<ul>' .
      '<li>' . $this->t('Tag-based purging is available on all Cloudflare plans (since April 2025).') . '</li>' .
      '<li>' . $this->t('Free tier has rate limits: 5 requests/minute, max 100 tags per request.') . '</li>' .
      '<li>' . $this->t('Ensure your Cloudflare cache is configured to cache responses.') . '</li>' .
      '<li>' . $this->t('Configure appropriate Page Rules in Cloudflare if needed.') . '</li>' .
      '</ul>',
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    // Validate tag prefix (alphanumeric and hyphens only).
    $tagPrefix = $form_state->getValue('tag_prefix');
    if (is_string($tagPrefix) && $tagPrefix !== '') {
      if (!preg_match('/^[a-zA-Z0-9-_]+$/', $tagPrefix)) {
        $form_state->setErrorByName('tag_prefix', $this->t('Tag prefix can only contain letters, numbers, hyphens, and underscores.'));
      }
    }

    // Validate rate limit.
    $rateLimit = $form_state->getValue('rate_limit_per_minute');
    if ($rateLimit !== NULL && (!is_numeric($rateLimit) || (int) $rateLimit < 1 || (int) $rateLimit > 1000)) {
      $form_state->setErrorByName('rate_limit_per_minute', $this->t('Rate limit must be between 1 and 1000.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $config = $this->config(CloudflarePurgeForm::SETTINGS);

    // Save auto-purge settings.
    $config->set('auto_purge_enabled', (bool) $form_state->getValue('auto_purge_enabled'));
    $config->set('tag_prefix', trim((string) ($form_state->getValue('tag_prefix') ?? '')));

    // Filter out unchecked entity types and ensure array values.
    $entityTypes = $form_state->getValue('auto_purge_entity_types');
    if (is_array($entityTypes)) {
      $entityTypes = array_values(array_filter($entityTypes));
    }
    else {
      $entityTypes = [];
    }
    $config->set('auto_purge_entity_types', $entityTypes);

    // Save queue settings.
    $config->set('auto_purge_use_queue', (bool) $form_state->getValue('auto_purge_use_queue'));

    // Save rate limiting settings.
    $config->set('rate_limit_enabled', (bool) $form_state->getValue('rate_limit_enabled'));
    $config->set('rate_limit_per_minute', (int) ($form_state->getValue('rate_limit_per_minute') ?? 60));

    // Save logging settings.
    $config->set('logging_enabled', (bool) $form_state->getValue('logging_enabled'));

    $config->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * Gets entity type options for the form.
   *
   * @return array<string, string>
   *   Array of entity type options.
   */
  private function getEntityTypeOptions(): array {
    $options = [];

    try {
      $definitions = $this->entityTypeManager->getDefinitions();

      foreach ($definitions as $entityTypeId => $definition) {
        // Only include content entity types.
        if ($definition->getGroup() === 'content') {
          $label = $definition->getLabel();
          $options[$entityTypeId] = is_string($label) ? $label : (string) $label;
        }
      }

      asort($options);
    }
    catch (\Exception $e) {
      // Log error but don't break the form.
      $this->getLogger('cloudflare_purge')->error('Failed to load entity types: @message', [
        '@message' => $e->getMessage(),
      ]);
    }

    return $options;
  }

}
