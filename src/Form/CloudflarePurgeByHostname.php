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
 * Form for purging Cloudflare cache by hostname.
 *
 * Hostname purging invalidates all cached content for specific hostnames.
 * This is useful for multi-domain sites or when you need to purge all
 * content for a subdomain like images.example.com.
 *
 * Available on all Cloudflare plans (Free tier: 5 requests/minute).
 *
 * @package Drupal\cloudflare_purge\Form
 */
final class CloudflarePurgeByHostname extends FormBase {

  /**
   * Regex pattern for validating hostnames.
   */
  private const HOSTNAME_PATTERN = '/^([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$/i';

  /**
   * Constructs a CloudflarePurgeByHostname form.
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
    return 'cloudflare_purge_by_hostname';
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
      '#markup' => '<p>' . $this->t('Purge all cached content for specific hostnames. This invalidates all cached assets for the specified domains. Long lists are sent to Cloudflare in batches of @max automatically.', [
        '@max' => CloudflarePurgeApi::MAX_BATCH_SIZE,
      ]) . '</p>',
    ];

    $form['warning'] = [
      '#type' => 'markup',
      '#markup' => '<div class="messages messages--warning">' .
        '<strong>' . $this->t('Warning:') . '</strong> ' .
        $this->t('Purging by hostname will invalidate ALL cached content for that hostname. This can significantly increase load on your origin server. Available on all Cloudflare plans (Free tier: 5 requests/minute).') .
        '</div>',
    ];

    $form['hostnames'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Hostnames'),
      '#required' => TRUE,
      '#description' => $this->t('Enter hostnames to purge, one per line. Do not include the protocol (http/https).'),
      '#placeholder' => "www.example.com\nimages.example.com\ncdn.example.com",
      '#rows' => 8,
    ];

    $form['examples'] = [
      '#type' => 'details',
      '#title' => $this->t('Common Use Cases'),
      '#open' => FALSE,
    ];

    $form['examples']['content'] = [
      '#markup' => '<ul>' .
        '<li>' . $this->t('<strong>Subdomain updates:</strong> Purge <code>blog.example.com</code> when you update your blog section.') . '</li>' .
        '<li>' . $this->t('<strong>CDN invalidation:</strong> Purge <code>cdn.example.com</code> when static assets change.') . '</li>' .
        '<li>' . $this->t('<strong>Regional sites:</strong> Purge <code>us.example.com</code> for region-specific updates.') . '</li>' .
        '<li>' . $this->t('<strong>Staging cleanup:</strong> Purge <code>staging.example.com</code> before testing.') . '</li>' .
        '</ul>',
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Purge Hostnames'),
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
    $rawInput = $form_state->getValue('hostnames');

    if (!is_string($rawInput)) {
      $form_state->setErrorByName('hostnames', $this->t('Invalid input.'));
      return;
    }

    $hostnames = $this->parseHostnames($rawInput);

    if ($hostnames === []) {
      $form_state->setErrorByName('hostnames', $this->t('Please enter at least one hostname.'));
      return;
    }

    // Validate hostname format.
    $invalidHostnames = [];
    foreach ($hostnames as $hostname) {
      // Check if protocol was included by mistake.
      if (str_starts_with($hostname, 'http://') || str_starts_with($hostname, 'https://')) {
        $invalidHostnames[] = $hostname . ' (remove protocol)';
        continue;
      }

      if (!$this->isValidHostname($hostname)) {
        $invalidHostnames[] = $hostname;
      }
    }

    if ($invalidHostnames !== []) {
      $form_state->setErrorByName('hostnames', $this->t('Invalid hostnames: @hostnames', [
        '@hostnames' => implode(', ', array_slice($invalidHostnames, 0, 5)),
      ]));
      return;
    }

    // Store parsed hostnames for submit handler.
    $form_state->set('parsed_hostnames', $hostnames);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $hostnames = $form_state->get('parsed_hostnames');

    if (!is_array($hostnames) || $hostnames === []) {
      $this->messenger()->addError($this->t('No valid hostnames to purge.'));
      return;
    }

    try {
      $this->purgeService->purgeByHostnames($hostnames);
    }
    catch (\Exception $e) {
      // Log full details but show generic message to user to prevent
      // information disclosure.
      $this->getLogger('cloudflare_purge')->error('Exception during hostname purge: @message', [
        '@message' => $e->getMessage(),
      ]);
      $this->messenger()->addError($this->t('An unexpected error occurred while purging hostnames. Please check the logs for details.'));
    }
  }

  /**
   * Parses the hostnames textarea input into an array.
   *
   * @param string $input
   *   The raw textarea input.
   *
   * @return array<int, string>
   *   Array of cleaned hostname strings.
   */
  private function parseHostnames(string $input): array {
    return LineInputParser::parse($input, TRUE);
  }

  /**
   * Validates a hostname format.
   *
   * @param string $hostname
   *   The hostname to validate.
   *
   * @return bool
   *   TRUE if valid, FALSE otherwise.
   */
  private function isValidHostname(string $hostname): bool {
    return (bool) preg_match(self::HOSTNAME_PATTERN, $hostname);
  }

}
