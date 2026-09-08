<?php

declare(strict_types=1);

namespace Drupal\cloudflare_purge\Form;

use Drupal\cloudflare_purge\CloudflarePurgeApi;
use Drupal\cloudflare_purge\CloudflarePurgeLinks;
use Drupal\cloudflare_purge\LineInputParser;
use Drupal\cloudflare_purge\PurgeInterface;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for purging URLs from Cloudflare cache.
 *
 * Supports purging one or more URLs. Long lists are sent to Cloudflare in
 * batches of CloudflarePurgeApi::MAX_BATCH_SIZE automatically by the Purge
 * service. URLs are validated to ensure they are properly formatted.
 *
 * @package Drupal\cloudflare_purge\Form
 */
final class CloudflarePurgeByUrl extends FormBase {

  /**
   * Constructs a CloudflarePurgeByUrl form.
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
    return 'cloudflare_purge_by_url';
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
      '#markup' => '<p>' . $this->t('Purge specific URLs from the Cloudflare cache. Enter one or more URLs, one per line. Long lists are sent to Cloudflare in batches of @max automatically.', [
        '@max' => CloudflarePurgeApi::MAX_BATCH_SIZE,
      ]) . '</p>',
    ];

    $form['urls'] = [
      '#type' => 'textarea',
      '#title' => $this->t('URLs to Purge'),
      '#required' => TRUE,
      '#description' => $this->t('Enter fully qualified URLs including the scheme (https://). One URL per line.'),
      '#placeholder' => "https://example.com/page\nhttps://example.com/another-page\nhttps://example.com/images/logo.png",
      '#rows' => 8,
    ];

    $form['tips'] = [
      '#type' => 'details',
      '#title' => $this->t('Tips'),
      '#open' => FALSE,
    ];

    $form['tips']['content'] = [
      '#markup' => '<ul>' .
        '<li>' . $this->t('URLs are case-sensitive.') . '</li>' .
        '<li>' . $this->t('Query strings matter: <code>?v=1</code> and <code>?v=2</code> are cached separately.') . '</li>' .
        '<li>' . $this->t('For entire directories, use <strong>Purge by Prefix</strong> instead.') . '</li>' .
        '<li>' . $this->t('For tagged content, use <strong>Purge by Cache Tag</strong>.') . '</li>' .
        '<li>' . $this->t('Long lists are purged in batches of @max automatically; if the rate limit is reached, the remainder can be submitted again in a moment.', [
          '@max' => CloudflarePurgeApi::MAX_BATCH_SIZE,
        ]) . '</li>' .
        '</ul>',
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Purge URLs'),
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
    $rawInput = $form_state->getValue('urls');

    if (!is_string($rawInput)) {
      $form_state->setErrorByName('urls', $this->t('Invalid input.'));
      return;
    }

    $urls = $this->parseUrls($rawInput);

    if ($urls === []) {
      $form_state->setErrorByName('urls', $this->t('Please enter at least one valid URL.'));
      return;
    }

    // Validate each URL.
    $invalidUrls = [];
    foreach ($urls as $url) {
      if (!UrlHelper::isValid($url, TRUE)) {
        $invalidUrls[] = $url;
      }
    }

    if ($invalidUrls !== []) {
      $form_state->setErrorByName('urls', $this->t('The following URLs are invalid: @urls. URLs must be fully qualified (e.g., https://example.com/page).', [
        '@urls' => implode(', ', array_slice($invalidUrls, 0, 5)),
      ]));
    }

    // Store parsed URLs for submit handler.
    $form_state->set('parsed_urls', $urls);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $urls = $form_state->get('parsed_urls');

    if (!is_array($urls) || $urls === []) {
      $this->messenger()->addError($this->t('No valid URLs to purge.'));
      return;
    }

    try {
      $this->purgeService->purgeByUrls($urls);
    }
    catch (\Exception $e) {
      // Log full details but show generic message to user to prevent
      // information disclosure.
      $this->getLogger('cloudflare_purge')->error('Exception during URL purge: @message', [
        '@message' => $e->getMessage(),
      ]);
      $this->messenger()->addError($this->t('An unexpected error occurred while purging URLs. Please check the logs for details.'));
    }
  }

  /**
   * Parses the URLs textarea input into an array.
   *
   * @param string $input
   *   The raw textarea input.
   *
   * @return array<int, string>
   *   Array of cleaned URL strings.
   */
  private function parseUrls(string $input): array {
    return LineInputParser::parse($input);
  }

}
