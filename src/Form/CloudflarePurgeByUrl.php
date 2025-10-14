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
    $form['cloudflare_purge_url']['url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('URL'),
      '#size' => 60,
      '#required' => TRUE,
      '#description' => $this->t('<p>Enter the URL or directory you want to purge. Valid requests include:
      <ul><li>http://www.example.com</li><li>http://www.example.com/foo</li><li>http://www.example.com/bar.jpg</li></ul></p>'),
    ];

    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Submit'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    if ($form_state->getValue('url') && !UrlHelper::isValid($form_state->getValue('url'), TRUE)) {
      $form_state->setErrorByName('url', $this->t('The URL entered is not valid.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->purgeService->purge($form_state->getValue('url'));
  }

}
