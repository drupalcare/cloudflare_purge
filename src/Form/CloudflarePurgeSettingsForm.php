<?php

declare(strict_types=1);

namespace Drupal\cloudflare_purge\Form;

use Drupal\cloudflare_purge\CloudflarePurgeLinks;
use Drupal\cloudflare_purge\CloudflarePurgeSettings;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
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
    return [CloudflarePurgeSettings::NAME];
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
    $config = $this->config(CloudflarePurgeSettings::NAME);

    $form['#attached']['library'][] = 'cloudflare_purge/form';
    $form['#attributes']['class'][] = 'cloudflare-purge-form';
    $form['cloudflare_purge_nav'] = CloudflarePurgeLinks::build($this->getRouteMatch()->getRouteName());

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

    $form['auto_purge']['purge_everything_on_flush'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Purge entire zone when all caches are flushed'),
      '#description' => $this->t('When enabled, a full Cloudflare zone purge runs whenever Drupal flushes all caches (for example <code>drush cr</code>, the "Clear all caches" button, or a deployment). A full flush invalidates every cache tag, so nothing narrower would be correct. This is independent of automatic cache tag purging above. Leave off during active development, since every <code>drush cr</code> would purge the whole zone.'),
      '#default_value' => (bool) ($config->get('purge_everything_on_flush') ?? FALSE),
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

    // Cache-Tag header sizing.
    $form['auto_purge']['header_sizing'] = [
      '#type' => 'details',
      '#title' => $this->t('Cache-Tag header size'),
      '#description' => $this->t('Tag-rich pages (Layout Builder, Site Studio, large listings) can produce a very large Cache-Tag header. Cloudflare allows up to 16 KB for the aggregate Cache-Tag header (about 1,000 tags), but intermediate proxies and web servers often enforce lower header limits, which can cause a 5xx with no PHP error. These settings keep the header within a safe size.'),
      '#open' => FALSE,
      '#states' => [
        'visible' => [
          ':input[name="auto_purge_enabled"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['auto_purge']['header_sizing']['auto_purge_filter_header_tags'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Emit only purgeable tags in the Cache-Tag header'),
      '#description' => $this->t('Recommended. Limits the header to tags for the entity types selected above (the only tags automatic purging acts on), dropping framework tags such as <code>config:*</code> and <code>http_response</code>. This is the biggest header size reduction. Disabling it emits every cache tag <em>and</em> purges every invalidated tag to keep the two in sync, which can sharply increase purge volume (frequently-invalidated tags like <code>config:*</code> and <code>http_response</code> would each trigger a Cloudflare purge) and may hit the rate limit.'),
      '#default_value' => $config->get('auto_purge_filter_header_tags') !== FALSE,
    ];

    $form['auto_purge']['header_sizing']['auto_purge_hash_tags'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Hash cache tags'),
      '#description' => $this->t('Replace each tag with a short fixed-length hash in both the header and the purge call, so tag purges still match at Cloudflare. Useful for pages with very large numbers of purgeable tags. Note: after toggling this, already-cached edge objects keep their old tokens until they are re-cached, so some purges may not match during that window.'),
      '#default_value' => (bool) ($config->get('auto_purge_hash_tags') ?? FALSE),
    ];

    $form['auto_purge']['header_sizing']['auto_purge_hash_length'] = [
      '#type' => 'number',
      '#title' => $this->t('Hashed tag length'),
      '#description' => $this->t('Number of hex characters per hashed tag. Shorter saves space but slightly increases the chance of a collision (which causes an extra purge, never a stale page). 16 is a safe default.'),
      '#default_value' => (int) ($config->get('auto_purge_hash_length') ?? 16),
      '#min' => 6,
      '#max' => 64,
      '#states' => [
        'visible' => [
          ':input[name="auto_purge_hash_tags"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['auto_purge']['header_sizing']['auto_purge_header_max_bytes'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum header size (bytes)'),
      '#description' => $this->t("The Cache-Tag header is capped at this size. If a page still exceeds it, excess tags are dropped and a warning is logged. Cloudflare's own limit is 16 KB; a lower value is recommended to stay below common web server and proxy header limits."),
      '#default_value' => (int) ($config->get('auto_purge_header_max_bytes') ?? 7168),
      '#min' => 1024,
      '#max' => 16384,
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

    $form['queue']['queue_max_age_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Discard queued items older than the configured TTL'),
      '#description' => $this->t('When the queue cannot keep up (e.g., free-tier rate limits), items older than the TTL below are dropped during cron processing. Safe when your Cloudflare cache TTL would have expired the content anyway.'),
      '#default_value' => (bool) ($config->get('queue_max_age_enabled') ?? FALSE),
      '#states' => [
        'visible' => [
          ':input[name="auto_purge_use_queue"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['queue']['queue_max_age'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum queue item age (seconds)'),
      '#description' => $this->t('Set this to your Cloudflare "Cache Everything" TTL (e.g., 900 for 15 minutes). Items older than this are removed from the queue without contacting the API.'),
      '#default_value' => (int) ($config->get('queue_max_age') ?? 900),
      '#min' => 1,
      '#max' => 2592000,
      '#states' => [
        'visible' => [
          ':input[name="auto_purge_use_queue"]' => ['checked' => TRUE],
          ':input[name="queue_max_age_enabled"]' => ['checked' => TRUE],
        ],
      ],
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
      '#description' => $this->t('Cloudflare free tier allows 5 requests/minute for tag, prefix and hostname purges (bucket size 25). Paid plans have higher limits.'),
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

    $form['logging']['history_limit'] = [
      '#type' => 'number',
      '#title' => $this->t('Purge history entries to display'),
      '#description' => $this->t('The maximum number of recent purge operations shown on the <a href=":history">Purge History</a> page. This only affects how many are displayed; entries are stored in the Drupal log and pruned by the Database Logging module. Requires the core Database Logging module.', [
        ':history' => Url::fromRoute('cloudflare_purge.history')->toString(),
      ]),
      '#default_value' => (int) ($config->get('history_limit') ?? 100),
      '#min' => 1,
      '#max' => 1000,
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
      '<pre>Cache-Tag: node:123, taxonomy_term:45, user:1</pre>' .
      '<h4>' . $this->t('Requirements') . '</h4>' .
      '<ul>' .
      '<li>' . $this->t('Tag-based purging is available on all Cloudflare plans (since April 2025).') . '</li>' .
      '<li>' . $this->t('Free tier has rate limits: 5 requests/minute, max 100 tags per request.') . '</li>' .
      '<li>' . $this->t('Ensure your Cloudflare cache is configured to cache responses.') . '</li>' .
      '<li>' . $this->t('Configure appropriate Cache Rules (or legacy Page Rules) in Cloudflare if needed.') . '</li>' .
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

    // Validate queue max age only when the discard feature is enabled.
    if ((bool) $form_state->getValue('queue_max_age_enabled')) {
      $queueMaxAge = $form_state->getValue('queue_max_age');
      if ($queueMaxAge === NULL || $queueMaxAge === '' || !is_numeric($queueMaxAge) || (int) $queueMaxAge < 1 || (int) $queueMaxAge > 2592000) {
        $form_state->setErrorByName('queue_max_age', $this->t('Maximum queue item age must be between 1 and 2592000 seconds.'));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $config = $this->config(CloudflarePurgeSettings::NAME);

    // Save auto-purge settings.
    $config->set('auto_purge_enabled', (bool) $form_state->getValue('auto_purge_enabled'));
    $config->set('purge_everything_on_flush', (bool) $form_state->getValue('purge_everything_on_flush'));
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

    // Save Cache-Tag header sizing settings.
    $config->set('auto_purge_filter_header_tags', (bool) $form_state->getValue('auto_purge_filter_header_tags'));
    $config->set('auto_purge_hash_tags', (bool) $form_state->getValue('auto_purge_hash_tags'));
    $config->set('auto_purge_hash_length', (int) ($form_state->getValue('auto_purge_hash_length') ?? 16));
    $config->set('auto_purge_header_max_bytes', (int) ($form_state->getValue('auto_purge_header_max_bytes') ?? 7168));

    // Save queue settings.
    $config->set('auto_purge_use_queue', (bool) $form_state->getValue('auto_purge_use_queue'));
    $config->set('queue_max_age_enabled', (bool) $form_state->getValue('queue_max_age_enabled'));
    $config->set('queue_max_age', (int) ($form_state->getValue('queue_max_age') ?? 900));

    // Save rate limiting settings.
    $config->set('rate_limit_enabled', (bool) $form_state->getValue('rate_limit_enabled'));
    $config->set('rate_limit_per_minute', (int) ($form_state->getValue('rate_limit_per_minute') ?? 60));

    // Save logging settings. Clamp the history limit to the schema range
    // (1-1000) so config is always valid even if the form element's own
    // validation is ever bypassed, keeping config export/validation healthy.
    $config->set('logging_enabled', (bool) $form_state->getValue('logging_enabled'));
    $historyLimit = (int) ($form_state->getValue('history_limit') ?? 100);
    $config->set('history_limit', max(1, min(1000, $historyLimit)));

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
