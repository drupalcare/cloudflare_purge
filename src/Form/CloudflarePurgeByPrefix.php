<?php

declare(strict_types=1);

namespace Drupal\cloudflare_purge\Form;

use Drupal\cloudflare_purge\CloudflarePurgeApi;
use Drupal\cloudflare_purge\PurgeInterface;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for purging Cloudflare cache by URL prefix.
 *
 * Prefix purging allows you to invalidate all cached content under a
 * specific URL path. For example, purging "https://example.com/blog/"
 * will invalidate all cached blog posts.
 *
 * Available on all Cloudflare plans (Free tier: 5 requests/minute).
 *
 * @package Drupal\cloudflare_purge\Form
 */
final class CloudflarePurgeByPrefix extends FormBase {

  /**
   * Constructs a CloudflarePurgeByPrefix form.
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
    return 'cloudflare_purge_by_prefix';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['#attached']['library'][] = 'cloudflare_purge/form';
    $form['#attributes']['class'][] = 'cloudflare-purge-form';

    $form['description'] = [
      '#type' => 'markup',
      '#markup' => '<p>' . $this->t('Purge all cached content under specific URL prefixes. This invalidates entire sections of your site. You can purge up to @max prefixes per request.', [
        '@max' => CloudflarePurgeApi::MAX_BATCH_SIZE,
      ]) . '</p>',
    ];

    $form['note'] = [
      '#type' => 'markup',
      '#markup' => '<div class="messages messages--info"><strong>' . $this->t('Note:') . '</strong> ' . $this->t('Prefix purging is available on all Cloudflare plans. Free tier: 5 requests/minute, max 100 prefixes per request.') . '</div>',
    ];

    $form['prefixes'] = [
      '#type' => 'textarea',
      '#title' => $this->t('URL Prefixes'),
      '#required' => TRUE,
      '#description' => $this->t('Enter URL prefixes to purge, one per line. Include the full URL with scheme (https://). Maximum @max prefixes per request.', [
        '@max' => CloudflarePurgeApi::MAX_BATCH_SIZE,
      ]),
      '#placeholder' => "https://example.com/blog/\nhttps://example.com/products/electronics/\nhttps://example.com/images/2024/",
      '#rows' => 8,
    ];

    $form['examples'] = [
      '#type' => 'details',
      '#title' => $this->t('Example Prefixes'),
      '#open' => FALSE,
    ];

    $form['examples']['content'] = [
      '#markup' => '<p>' . $this->t('For a URL like <code>https://example.com/foo/bar/baz/image.jpg</code>, valid prefixes include:') . '</p>' .
      '<ul>' .
      '<li><code>https://example.com/foo/</code></li>' .
      '<li><code>https://example.com/foo/bar/</code></li>' .
      '<li><code>https://example.com/foo/bar/baz/</code></li>' .
      '</ul>' .
      '<p>' . $this->t('All files under the specified prefix will be purged.') . '</p>',
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Purge by Prefix'),
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
    $rawInput = $form_state->getValue('prefixes');

    if (!is_string($rawInput)) {
      $form_state->setErrorByName('prefixes', $this->t('Invalid input.'));
      return;
    }

    $prefixes = $this->parsePrefixes($rawInput);

    if ($prefixes === []) {
      $form_state->setErrorByName('prefixes', $this->t('Please enter at least one URL prefix.'));
      return;
    }

    if (count($prefixes) > CloudflarePurgeApi::MAX_BATCH_SIZE) {
      $form_state->setErrorByName('prefixes', $this->t('Maximum @max prefixes per request. You entered @count.', [
        '@max' => CloudflarePurgeApi::MAX_BATCH_SIZE,
        '@count' => count($prefixes),
      ]));
      return;
    }

    // Validate each prefix is a valid URL.
    $invalidPrefixes = [];
    foreach ($prefixes as $prefix) {
      if (!UrlHelper::isValid($prefix, TRUE)) {
        $invalidPrefixes[] = $prefix;
      }
    }

    if ($invalidPrefixes !== []) {
      $form_state->setErrorByName('prefixes', $this->t('Invalid prefixes: @prefixes. Prefixes must be fully qualified URLs (e.g., https://example.com/path/).', [
        '@prefixes' => implode(', ', array_slice($invalidPrefixes, 0, 5)),
      ]));
      return;
    }

    // Store parsed prefixes for submit handler.
    $form_state->set('parsed_prefixes', $prefixes);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $prefixes = $form_state->get('parsed_prefixes');

    if (!is_array($prefixes) || $prefixes === []) {
      $this->messenger()->addError($this->t('No valid prefixes to purge.'));
      return;
    }

    try {
      $this->purgeService->purgeByPrefixes($prefixes);
    }
    catch (\Exception $e) {
      // Log full details but show generic message to user to prevent
      // information disclosure.
      $this->getLogger('cloudflare_purge')->error('Exception during prefix purge: @message', [
        '@message' => $e->getMessage(),
      ]);
      $this->messenger()->addError($this->t('An unexpected error occurred while purging by prefix. Please check the logs for details.'));
    }
  }

  /**
   * Parses the prefixes textarea input into an array.
   *
   * @param string $input
   *   The raw textarea input.
   *
   * @return array<int, string>
   *   Array of cleaned prefix strings.
   */
  private function parsePrefixes(string $input): array {
    $lines = preg_split('/\r\n|\r|\n/', $input);

    if ($lines === FALSE) {
      return [];
    }

    $prefixes = [];
    foreach ($lines as $line) {
      $prefix = trim($line);
      if ($prefix !== '') {
        $prefixes[] = $prefix;
      }
    }

    return array_values(array_unique($prefixes));
  }

}
