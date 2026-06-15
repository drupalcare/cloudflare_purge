<?php

declare(strict_types=1);

namespace Drupal\cloudflare_purge\EventSubscriber;

use Drupal\cloudflare_purge\Cache\CacheTagFormatterInterface;
use Drupal\cloudflare_purge\CloudflarePurgeSettings;
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
   * @param \Drupal\cloudflare_purge\Cache\CacheTagFormatterInterface $cacheTagFormatter
   *   The cache tag formatter.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerChannelInterface $logger,
    private readonly CacheTagFormatterInterface $cacheTagFormatter,
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
      $config = $this->configFactory->get(CloudflarePurgeSettings::NAME);

      // Only add headers if auto-purge is enabled.
      if ($config->get('auto_purge_enabled') !== TRUE) {
        return;
      }

      $response = $event->getResponse();

      // Only process responses that implement CacheableResponseInterface.
      if (!$response instanceof CacheableResponseInterface) {
        return;
      }

      $cacheableMetadata = $response->getCacheableMetadata();

      // Skip uncacheable responses (max-age = 0), such as admin/edit pages.
      // These pages implement CacheableResponseInterface but aren't meant to be
      // cached. Adding a Cache-Tag header with potentially hundreds of tags can
      // exceed web server header size limits and cause HTTP 500 errors.
      if ($cacheableMetadata->getCacheMaxAge() === 0) {
        return;
      }

      // Build the header value through the shared formatter: filter to
      // purgeable tags, apply the prefix/optional hashing wire transform, and
      // cap the size so the header cannot exceed web-server/CDN limits and
      // trigger a 5xx on tag-rich pages.
      $cloudflareTags = $this->cacheTagFormatter->buildHeaderValue($cacheableMetadata->getCacheTags());

      if ($cloudflareTags === NULL) {
        return;
      }

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
