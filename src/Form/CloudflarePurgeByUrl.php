<?php

declare(strict_types=1);

namespace Drupal\cloudflare_purge\Form;

use Drupal\cloudflare_purge\Purge;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for purging Cloudflare by URL.
 */
class CloudflarePurgeByUrl extends FormBase {

  /**
   * Constructs a CloudflarePurgeByUrl form.
   *
   * @param \Drupal\cloudflare_purge\Purge $purgeService
   *   The Cloudflare purge service.
   */
  public function __construct(
    protected Purge $purgeService,
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
    return 'cloudflare_purge_url';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('URL'),
      '#size' => 60,
      '#required' => TRUE,
      '#description' => $this->t('Enter the URL or directory you want to purge. Valid examples: https://example.com, https://example.com/page, https://example.com/image.jpg'),
    ];

    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Purge URL'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $url = $form_state->getValue('url');
    if ($url && !UrlHelper::isValid($url, TRUE)) {
      $form_state->setErrorByName('url', $this->t('The URL entered is not valid. Please enter a fully qualified URL (e.g., https://example.com).'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    try {
      $this->purgeService->purge($form_state->getValue('url'));
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('Failed to purge URL: @message', [
        '@message' => $e->getMessage(),
      ]));
    }
  }

}
