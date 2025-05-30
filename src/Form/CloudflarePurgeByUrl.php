<?php

namespace Drupal\cloudflare_purge\Form;

use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Form for purging Cloudflare by URL.
 */
class CloudflarePurgeByUrl extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId():string {
    return 'cloudflare_purge_url';
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
  public function validateForm(array &$form, FormStateInterface $form_state) {
    if ($form_state->getValue('url') && !UrlHelper::isValid($form_state->getValue('url'), TRUE)) {
      $form_state->setErrorByName('url', $this->t('The URL entered is not valid.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    \Drupal::service('cloudflare_purge.purge')->purge($form_state->getValue('url'));
  }

}
