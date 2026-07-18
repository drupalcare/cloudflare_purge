<?php

declare(strict_types=1);

namespace Drupal\cloudflare_purge\Form;

use Drupal\cloudflare_purge\Cache\CloudflareCacheTagInvalidator;
use Drupal\cloudflare_purge\CloudflarePurgeLinks;
use Drupal\cloudflare_purge\CloudflarePurgeSettings;
use Drupal\cloudflare_purge\PendingTagDrainerInterface;
use Drupal\cloudflare_purge\PendingTagsStoreInterface;
use Drupal\cloudflare_purge\QueueBacklogAdvisorInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Review page for cache tags queued for a Cloudflare purge.
 *
 * Shows how many distinct tags are merged into the deduplicated pending set
 * awaiting the next cron drain, how many batched purge requests are already
 * in the Cloudflare purge queue, and how long the oldest tag has been
 * waiting. Also lets an administrator trigger the same drain hook_cron()
 * runs, without waiting for the next cron run.
 *
 * @internal
 *
 * @package Drupal\cloudflare_purge\Form
 */
final class CloudflarePurgeQueuedTags extends FormBase {

  /**
   * Constructs a CloudflarePurgeQueuedTags form.
   *
   * @param \Drupal\cloudflare_purge\PendingTagsStoreInterface $pendingTagsStore
   *   The pending-tags store.
   * @param \Drupal\cloudflare_purge\PendingTagDrainerInterface $drainer
   *   The pending-tag drainer.
   * @param \Drupal\cloudflare_purge\QueueBacklogAdvisorInterface $backlogAdvisor
   *   The queue-backlog advisor.
   * @param \Drupal\Core\Queue\QueueFactory $queueFactory
   *   The queue factory.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $dateFormatter
   *   The date formatter service.
   */
  public function __construct(
    private readonly PendingTagsStoreInterface $pendingTagsStore,
    private readonly PendingTagDrainerInterface $drainer,
    private readonly QueueBacklogAdvisorInterface $backlogAdvisor,
    private readonly QueueFactory $queueFactory,
    private readonly DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('cloudflare_purge.pending_tags_store'),
      $container->get('cloudflare_purge.pending_tag_drainer'),
      $container->get('cloudflare_purge.queue_backlog_advisor'),
      $container->get('queue'),
      $container->get('date.formatter'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'cloudflare_purge_queued_tags';
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

    // The queued/pending counts change on every save, so never cache this page.
    $form['#cache']['max-age'] = 0;

    $config = $this->config(CloudflarePurgeSettings::NAME);
    $useQueue = (bool) $config->get('auto_purge_use_queue');

    $pendingCount = $this->pendingTagsStore->count();
    $queueItemCount = $this->queueFactory->get(CloudflareCacheTagInvalidator::QUEUE_NAME)->numberOfItems();

    // Queueing is off and nothing is left over: purely informational.
    if (!$useQueue && $pendingCount === 0 && $queueItemCount === 0) {
      $form['disabled'] = [
        '#type' => 'markup',
        '#markup' => '<p>' . $this->t('Automatic purging is not currently using the queue, so nothing is queued here. Tags are purged immediately instead. Enable <a href=":settings">"Use queue for automatic purging"</a> to batch and deduplicate purge requests.', [
          ':settings' => Url::fromRoute('cloudflare_purge.settings')->toString(),
        ]) . '</p>',
      ];
      return $form;
    }

    if ($useQueue) {
      // Advisory backlog warnings only apply while queueing is enabled.
      foreach ($this->backlogAdvisor->getWarnings() as $warning) {
        $this->messenger()->addWarning($warning);
      }

      $form['description'] = [
        '#type' => 'markup',
        '#markup' => '<p>' . $this->t('Cache tags invalidated while auto-purge is queueing are merged into a deduplicated set here, so repeated invalidations of the same tag (a resave, several entities sharing a listing tag, etc.) never create duplicate purge requests. On every cron run, they are drained oldest-first into <a href=":limits">Cloudflare-sized</a> queue items for the <a href=":history">purge queue worker</a> to process.', [
          ':limits' => Url::fromRoute('cloudflare_purge.limits')->toString(),
          ':history' => Url::fromRoute('cloudflare_purge.history')->toString(),
        ]) . '</p>',
      ];

      if ($pendingCount === 0 && $queueItemCount === 0) {
        $form['empty'] = [
          '#type' => 'markup',
          '#markup' => '<p>' . $this->t('No cache tags are currently queued for a Cloudflare purge.') . '</p>',
        ];
        return $form;
      }
    }
    else {
      // Queueing was turned off, but tags queued beforehand are still here.
      // Cron drains them regardless of the setting; explain that and offer to
      // process them now so they are never stranded.
      $this->messenger()->addWarning($this->t('Automatic purging is no longer using the queue, but some cache tags queued beforehand are still being processed. They are drained and purged automatically on cron; anything still pending a drain can be processed now below.'));
    }

    $rows = [
      [$this->t('Distinct tags awaiting the next drain'), $pendingCount],
      [$this->t('Batched purge requests already queued'), $queueItemCount],
    ];

    $oldestTimestamp = $this->pendingTagsStore->getOldestTimestamp();
    if ($oldestTimestamp !== NULL) {
      $rows[] = [
        $this->t('Oldest tag awaiting the next drain'),
        $this->t('@interval ago (@date)', [
          '@interval' => $this->dateFormatter->formatTimeDiffSince($oldestTimestamp),
          '@date' => $this->dateFormatter->format($oldestTimestamp, 'short'),
        ]),
      ];
    }

    $form['stats'] = [
      '#type' => 'table',
      '#rows' => $rows,
      '#attributes' => ['class' => ['cloudflare-purge-queued-stats']],
    ];

    // Only offer a manual drain when there is something to drain. Draining
    // promotes pending tags into queue items; it does not process items already
    // in the queue (the queue worker does that on cron), so with nothing
    // pending the button would be a no-op.
    if ($pendingCount > 0) {
      $form['actions'] = ['#type' => 'actions'];
      $form['actions']['drain'] = [
        '#type' => 'submit',
        '#value' => $this->t('Drain queued tags now'),
        '#button_type' => 'primary',
      ];
    }
    elseif ($queueItemCount > 0) {
      $form['queued_note'] = [
        '#type' => 'markup',
        '#markup' => '<p>' . $this->t('Nothing is pending a drain right now. The batched purge request(s) already queued are processed by the queue worker on the next cron run.') . '</p>',
      ];
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    try {
      // Read the pending count first so a zero result can be reported
      // accurately: genuinely empty versus a concurrent drain holding the lock.
      $pendingBefore = $this->pendingTagsStore->count();
      $drained = $this->drainer->drain();

      if ($drained === 0) {
        $this->messenger()->addStatus($pendingBefore === 0
          ? $this->t('No pending tags to drain.')
          : $this->t('No tags were drained; another drain may already be running. Try again in a moment.'));
      }
      else {
        $this->messenger()->addStatus($this->formatPlural(
          $drained,
          'Drained 1 tag into the Cloudflare purge queue.',
          'Drained @count tags into the Cloudflare purge queue.',
        ));
      }
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('An unexpected error occurred: @message', [
        '@message' => $e->getMessage(),
      ]));
      $this->getLogger('cloudflare_purge')->error('Exception during manual pending-tag drain: @message', [
        '@message' => $e->getMessage(),
      ]);
    }

    $form_state->setRedirect('cloudflare_purge.queued_tags');
  }

}
