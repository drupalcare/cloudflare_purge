<?php

declare(strict_types=1);

namespace Drupal\cloudflare_purge\Form;

use Drupal\cloudflare_purge\PurgeHistoryInterface;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Confirmation form for clearing the Cloudflare purge history.
 *
 * Deletes only the module's own log entries (watchdog type
 * "cloudflare_purge"); all other log entries are left untouched.
 *
 * @package Drupal\cloudflare_purge\Form
 */
final class CloudflarePurgeClearHistory extends ConfirmFormBase {

  /**
   * Constructs a CloudflarePurgeClearHistory form.
   *
   * @param \Drupal\cloudflare_purge\PurgeHistoryInterface $history
   *   The purge-history service.
   */
  public function __construct(
    private readonly PurgeHistoryInterface $history,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('cloudflare_purge.history'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'cloudflare_purge_clear_history';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion(): TranslatableMarkup {
    return $this->t('Are you sure you want to clear the Cloudflare purge history?');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription(): TranslatableMarkup {
    return $this->t('This permanently deletes the recorded Cloudflare purge log entries. Other Drupal log messages are not affected. This action cannot be undone.');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText(): TranslatableMarkup {
    return $this->t('Clear history');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return Url::fromRoute('cloudflare_purge.history');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    if (!$this->history->isAvailable()) {
      $this->messenger()->addWarning($this->t('Database logging is not enabled, so there is no purge history to clear.'));
    }
    else {
      $deleted = $this->history->clear();
      $this->messenger()->addStatus($this->formatPlural(
        $deleted,
        'Cleared 1 purge history entry.',
        'Cleared @count purge history entries.',
      ));
    }

    $form_state->setRedirectUrl(Url::fromRoute('cloudflare_purge.history'));
  }

}
