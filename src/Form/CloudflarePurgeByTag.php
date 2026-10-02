<?php

declare(strict_types=1);

namespace Drupal\cloudflare_purge\Form;

use Drupal\cloudflare_purge\CloudflarePurgeApi;
use Drupal\cloudflare_purge\CloudflarePurgeLinks;
use Drupal\cloudflare_purge\LineInputParser;
use Drupal\cloudflare_purge\PurgeInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for purging Cloudflare cache by cache tags.
 *
 * Cache tags allow you to group and invalidate related cached assets.
 * For example, tag all product images with "product-images" and purge
 * them all at once when the product catalog updates.
 *
 * Available on all Cloudflare plans (Free tier: 5 requests/minute).
 *
 * @package Drupal\cloudflare_purge\Form
 */
final class CloudflarePurgeByTag extends FormBase {

  /**
   * Maximum allowed tag length (Cloudflare limit).
   */
  private const MAX_TAG_LENGTH = 1024;

  /**
   * Constructs a CloudflarePurgeByTag form.
   *
   * @param \Drupal\cloudflare_purge\PurgeInterface $purgeService
   *   The Cloudflare purge service.
   */
  public function __construct(
    private readonly PurgeInterface $purgeService,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('cloudflare_purge.purge'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'cloudflare_purge_by_tag';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['#attached']['library'][] = 'cloudflare_purge/form';
    // Also attach the navigation library at form level; CloudflarePurgeLinks
    // attaches it on a cache-keyed sub-element whose bubbled #attached can be
    // served stale from the render cache, leaving the tab strip unstyled.
    $form['#attached']['library'][] = 'cloudflare_purge/navigation';
    $form['#attributes']['class'][] = 'cloudflare-purge-form';
    $form['cloudflare_purge_nav'] = CloudflarePurgeLinks::build($this->getRouteMatch()->getRouteName());

    $form['description'] = [
      '#type' => 'markup',
      '#markup' => '<p>' . $this->t('Purge cached content by cache tags. Cache tags are added to responses via the <code>Cache-Tag</code> HTTP header. Long lists are sent to Cloudflare in batches of @max automatically.', [
        '@max' => CloudflarePurgeApi::MAX_BATCH_SIZE,
      ]) . '</p>',
    ];

    $form['tags'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Cache Tags'),
      '#required' => TRUE,
      '#description' => $this->t('Enter cache tags to purge, one per line. Tags are case-insensitive and cannot contain spaces. Each tag may be up to @length characters.', [
        '@length' => self::MAX_TAG_LENGTH,
      ]),
      '#placeholder' => "node:123\ntaxonomy_term:45\nuser:67",
      '#rows' => 8,
    ];

    $form['examples'] = [
      '#type' => 'details',
      '#title' => $this->t('Example Cache Tags'),
      '#open' => FALSE,
    ];

    $form['examples']['content'] = [
      '#markup' => '<p>' . $this->t('Drupal cache tags use a colon, e.g. <code>node:1</code>. Common patterns:') . '</p>' .
      '<ul>' .
      '<li><code>node:123</code> - ' . $this->t('A specific node') . '</li>' .
      '<li><code>taxonomy_term:45</code> - ' . $this->t('A taxonomy term') . '</li>' .
      '<li><code>user:67</code> - ' . $this->t('A user profile') . '</li>' .
      '<li><code>node_list</code> - ' . $this->t('Any node list (views, listings)') . '</li>' .
      '<li><code>config:system.site</code> - ' . $this->t('Configuration object') . '</li>' .
      '</ul>',
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Purge Cache Tags'),
      '#button_type' => 'primary',
    ];

    // Disable form if credentials are not configured.
    if (!$this->purgeService->hasCredentials()) {
      $this->messenger()->addWarning($this->t('Cloudflare credentials are not configured. Please <a href=":url">configure your credentials</a> first.', [
        ':url' => Url::fromRoute('cloudflare_purge.form')->toString(),
      ]));
      $form['actions']['submit']['#disabled'] = TRUE;
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $rawInput = $form_state->getValue('tags');

    if (!is_string($rawInput)) {
      $form_state->setErrorByName('tags', $this->t('Invalid input.'));
      return;
    }

    $tags = $this->parseTags($rawInput);

    if ($tags === []) {
      $form_state->setErrorByName('tags', $this->t('Please enter at least one cache tag.'));
      return;
    }

    // Validate tag format.
    $invalidTags = [];
    foreach ($tags as $tag) {
      if (str_contains($tag, ' ')) {
        $invalidTags[] = $tag . ' (contains space)';
      }
      elseif (strlen($tag) > self::MAX_TAG_LENGTH) {
        $invalidTags[] = substr($tag, 0, 30) . '... (too long)';
      }
    }

    if ($invalidTags !== []) {
      $form_state->setErrorByName('tags', $this->t('Invalid tags: @tags', [
        '@tags' => implode(', ', array_slice($invalidTags, 0, 5)),
      ]));
      return;
    }

    // Store parsed tags for submit handler.
    $form_state->set('parsed_tags', $tags);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $tags = $form_state->get('parsed_tags');

    if (!is_array($tags) || $tags === []) {
      $this->messenger()->addError($this->t('No valid cache tags to purge.'));
      return;
    }

    try {
      $this->purgeService->purgeByTags($tags);
    }
    catch (\Exception $e) {
      // Log full details but show generic message to user to prevent
      // information disclosure.
      $this->getLogger('cloudflare_purge')->error('Exception during tag purge: @message', [
        '@message' => $e->getMessage(),
      ]);
      $this->messenger()->addError($this->t('An unexpected error occurred while purging cache tags. Please check the logs for details.'));
    }
  }

  /**
   * Parses the tags textarea input into an array.
   *
   * @param string $input
   *   The raw textarea input.
   *
   * @return array<int, string>
   *   Array of cleaned tag strings.
   */
  private function parseTags(string $input): array {
    return LineInputParser::parse($input);
  }

}
