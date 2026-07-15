<?php

declare(strict_types=1);

namespace Drupal\cloudflare_purge\Form;

use Drupal\cloudflare_purge\CloudflarePurgeLinks;
use Drupal\cloudflare_purge\PurgeInterface;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Confirmation form for purging entire Cloudflare cache.
 *
 * This is a destructive operation that clears ALL cached content
 * for the configured Cloudflare zone. Users must confirm before proceeding.
 *
 * @package Drupal\cloudflare_purge\Form
 */
final class CloudflarePurgeEverything extends ConfirmFormBase {

  /**
   * Constructs a CloudflarePurgeEverything form.
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
    return 'cloudflare_purge_everything';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Are you sure you want to purge the entire Cloudflare cache?');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->t('<strong>Warning:</strong> This will clear ALL cached content from Cloudflare for your entire zone. This action cannot be undone and may temporarily increase load on your origin server as the cache rebuilds. Use this only when necessary, such as after major site updates or configuration changes.');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->t('Purge Everything');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return new Url('cloudflare_purge.form');
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form = parent::buildForm($form, $form_state);

    $form['#attached']['library'][] = 'cloudflare_purge/form';
    // Also attach the navigation library at form level; CloudflarePurgeLinks
    // attaches it on a cache-keyed sub-element whose bubbled #attached can be
    // served stale from the render cache, leaving the tab strip unstyled.
    $form['#attached']['library'][] = 'cloudflare_purge/navigation';
    $form['#attributes']['class'][] = 'cloudflare-purge-form';
    $form['cloudflare_purge_nav'] = CloudflarePurgeLinks::build($this->getRouteMatch()->getRouteName());

    // Check if credentials are configured.
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
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    try {
      $result = $this->purgeService->purgeEverything();

      if ($result->isSuccess()) {
        $this->messenger()->addStatus($this->t('Successfully purged the entire Cloudflare cache.'));
      }
      // Error messages are handled by the purge service.
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('An unexpected error occurred: @message', [
        '@message' => $e->getMessage(),
      ]));
      $this->getLogger('cloudflare_purge')->error('Exception during purge everything: @message', [
        '@message' => $e->getMessage(),
      ]);
    }

    $form_state->setRedirectUrl($this->getCancelUrl());
  }

}
