<?php

declare(strict_types=1);

namespace Drupal\cloudflare_purge\EventSubscriber;

use Drupal\cloudflare_purge\Form\CloudflarePurgeForm;
use Drupal\Core\Cache\CacheableResponseInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Event subscriber for Cloudflare cache purging.
 *
 * This subscriber adds Cache-Tag headers to responses for Cloudflare
 * to use when caching content. When combined with the cache tag invalidator,
 * this enables automatic cache invalidation based on Drupal's cache tags.
 *
 * @package Drupal\cloudflare_purge\EventSubscriber
 */
final class CloudflarePurgeSubscriber implements EventSubscriberInterface {

  /**
   * Constructs a CloudflarePurgeSubscriber.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\Core\Logger\LoggerChannelInterface $logger
   *   The logger channel.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerChannelInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // Low priority to run after other modules have added cache tags.
    return [
      KernelEvents::RESPONSE => ['onResponse', -100],
    ];
  }

  /**
   * Adds Cache-Tag header to responses for Cloudflare.
   *
   * @param \Symfony\Component\HttpKernel\Event\ResponseEvent $event
   *   The response event.
   */
  public function onResponse(ResponseEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }

    try {
      $config = $this->configFactory->get(CloudflarePurgeForm::SETTINGS);

      // Only add headers if auto-purge is enabled.
      if ($config->get('auto_purge_enabled') !== TRUE) {
        return;
      }

      $response = $event->getResponse();

      // Get cache tags from the response's cacheable metadata.
      // This works without requiring debug_cacheability_headers to be enabled,
      // unlike reading from the X-Drupal-Cache-Tags debug header.
      if (!$response instanceof CacheableResponseInterface) {
        return;
      }

      $tags = $response->getCacheableMetadata()->getCacheTags();

      // Filter empty tags.
      $tags = array_filter($tags, static fn($tag): bool => is_string($tag) && $tag !== '');

      if ($tags === []) {
        return;
      }

      // Apply prefix if configured.
      $prefix = $config->get('tag_prefix');
      if (is_string($prefix) && $prefix !== '') {
        $tags = array_map(static fn($tag): string => $prefix . $tag, $tags);
      }

      // Cloudflare expects comma-separated tags.
      $cloudflareTags = implode(',', $tags);

      // Add Cache-Tag header for Cloudflare.
      $response->headers->set('Cache-Tag', $cloudflareTags);
    }
    catch (\Exception $e) {
      // Log but don't break the response.
      $this->logger->error('Failed to add Cache-Tag header: @message', [
        '@message' => $e->getMessage(),
      ]);
    }
  }

}
