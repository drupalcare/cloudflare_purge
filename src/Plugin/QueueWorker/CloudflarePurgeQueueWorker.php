<?php

declare(strict_types=1);

namespace Drupal\cloudflare_purge\Plugin\QueueWorker;

use Drupal\cloudflare_purge\Cache\CloudflareCacheTagInvalidator;
use Drupal\cloudflare_purge\Purge;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Queue\SuspendQueueException;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Processes queued Cloudflare cache purge operations.
 *
 * This queue worker handles deferred purge requests, which is useful for:
 * - High-traffic sites where immediate purging could slow down page saves
 * - Batching multiple purge requests to stay within rate limits
 * - Ensuring purge operations don't block user interactions.
 *
 * @QueueWorker(
 *   id = "cloudflare_purge_tags",
 *   title = @Translation("Cloudflare Cache Tag Purge"),
 *   cron = {"time" = 30}
 * )
 *
 * @package Drupal\cloudflare_purge\Plugin\QueueWorker
 */
final class CloudflarePurgeQueueWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * Maximum age of a queue item before logging a warning (1 hour).
   */
  private const MAX_AGE_WARNING = 3600;

  /**
   * Maximum retries before giving up on an item.
   */
  private const MAX_RETRIES = 3;

  /**
   * Constructs a CloudflarePurgeQueueWorker.
   *
   * @param array<string, mixed> $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\cloudflare_purge\Purge $purgeService
   *   The Cloudflare purge service.
   * @param \Drupal\Core\Logger\LoggerChannelInterface $logger
   *   The logger channel.
   * @param \Drupal\Core\Queue\QueueFactory $queueFactory
   *   The queue factory.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly Purge $purgeService,
    private readonly LoggerChannelInterface $logger,
    private readonly QueueFactory $queueFactory,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition,
  ): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('cloudflare_purge.purge'),
      $container->get('logger.channel.cloudflare_purge'),
      $container->get('queue'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    // Validate data structure.
    if (!is_array($data)) {
      $this->logger->warning('Invalid queue item: expected array, got @type.', [
        '@type' => gettype($data),
      ]);
      return;
    }

    if (!isset($data['tags']) || !is_array($data['tags'])) {
      $this->logger->warning('Invalid queue item: missing or invalid tags.');
      return;
    }

    $tags = $data['tags'];
    $timestamp = isset($data['timestamp']) && is_int($data['timestamp']) ? $data['timestamp'] : time();
    $retries = isset($data['retries']) && is_int($data['retries']) ? $data['retries'] : 0;

    // Log if item has been in queue for too long.
    $age = time() - $timestamp;
    if ($age > self::MAX_AGE_WARNING) {
      $this->logger->notice('Processing Cloudflare purge item that was queued @age seconds ago.', [
        '@age' => $age,
      ]);
    }

    try {
      $result = $this->purgeService->purgeByTags($tags, FALSE);

      if ($result->isSuccess()) {
        $this->logger->info('Queue worker purged @count cache tag(s) from Cloudflare.', [
          '@count' => count($tags),
        ]);
        return;
      }

      // Handle rate limiting - suspend queue processing.
      if ($result->isRateLimited()) {
        $this->logger->warning('Rate limited while purging cache tags. Suspending queue.');
        // Re-queue with same retry count before suspending.
        $this->requeueWithRetry($tags, $timestamp, $retries);
        throw new SuspendQueueException('Cloudflare rate limit reached');
      }

      // Don't retry authentication errors.
      if ($result->isAuthenticationError()) {
        $this->logger->error('Authentication error while purging cache tags. Check credentials.');
        return;
      }

      // Handle retriable failures.
      $this->handleFailure($tags, $timestamp, $retries, $result->getMessage());
    }
    catch (SuspendQueueException $e) {
      throw $e;
    }
    catch (\Exception $e) {
      $this->logger->error('Queue worker exception during cache tag purge: @message', [
        '@message' => $e->getMessage(),
      ]);
      $this->handleFailure($tags, $timestamp, $retries, $e->getMessage());
    }
  }

  /**
   * Handles a failed purge attempt by requeueing with incremented retry count.
   *
   * @param array<int, string> $tags
   *   The cache tags that failed to purge.
   * @param int $timestamp
   *   The original queue timestamp.
   * @param int $retries
   *   The current retry count.
   * @param string $message
   *   The error message.
   */
  private function handleFailure(array $tags, int $timestamp, int $retries, string $message): void {
    if ($retries < self::MAX_RETRIES) {
      $this->logger->warning('Failed to purge cache tags (attempt @attempt/@max): @message. Requeueing.', [
        '@attempt' => $retries + 1,
        '@max' => self::MAX_RETRIES,
        '@message' => $message,
      ]);
      $this->requeueWithRetry($tags, $timestamp, $retries + 1);
    }
    else {
      $this->logger->error('Queue worker failed to purge cache tags after @max attempts: @message', [
        '@max' => self::MAX_RETRIES,
        '@message' => $message,
      ]);
    }
  }

  /**
   * Re-queues tags with updated retry count.
   *
   * @param array<int, string> $tags
   *   The cache tags to requeue.
   * @param int $timestamp
   *   The original queue timestamp.
   * @param int $retries
   *   The new retry count.
   */
  private function requeueWithRetry(array $tags, int $timestamp, int $retries): void {
    try {
      $queue = $this->queueFactory->get(CloudflareCacheTagInvalidator::QUEUE_NAME);
      $queue->createItem([
        'tags' => $tags,
        'timestamp' => $timestamp,
        'retries' => $retries,
      ]);
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to requeue cache tags: @message', [
        '@message' => $e->getMessage(),
      ]);
    }
  }

}
