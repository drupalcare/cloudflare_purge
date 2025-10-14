<?php

declare(strict_types=1);

namespace Drupal\cloudflare_purge\Commands;

use Drupal\cloudflare_purge\Purge;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Drush commands for Cloudflare Purge module.
 */
final class CloudflarePurgeCommands extends DrushCommands {

  /**
   * Constructs a CloudflarePurgeCommands object.
   *
   * @param \Drupal\cloudflare_purge\Purge $purgeService
   *   The Cloudflare purge service.
   */
  public function __construct(
    protected Purge $purgeService,
  ) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('cloudflare_purge.purge'),
    );
  }

  /**
   * Purges all cached content from Cloudflare.
   */
  #[CLI\Command(name: 'cloudflare:purge-all', aliases: ['cf-purge-all', 'cfpa'])]
  #[CLI\Usage(name: 'drush cloudflare:purge-all', description: 'Purge all Cloudflare cache')]
  #[CLI\Usage(name: 'drush cf-purge-all', description: 'Purge all Cloudflare cache (alias)')]
  public function purgeAll(): void {
    try {
      $this->purgeService->purge();
      $this->logger()->success(dt('Successfully purged all Cloudflare cache.'));
    }
    catch (\Exception $e) {
      $this->logger()->error(dt('Failed to purge Cloudflare cache: @message', [
        '@message' => $e->getMessage(),
      ]));
    }
  }

  /**
   * Purges a specific URL from Cloudflare cache.
   *
   * @param string $url
   *   The fully qualified URL to purge from cache.
   */
  #[CLI\Command(name: 'cloudflare:purge-url', aliases: ['cf-purge-url', 'cfpu'])]
  #[CLI\Argument(name: 'url', description: 'The URL to purge from Cloudflare cache')]
  #[CLI\Usage(name: 'drush cloudflare:purge-url https://example.com/page', description: 'Purge a specific URL')]
  #[CLI\Usage(name: 'drush cf-purge-url https://example.com/image.jpg', description: 'Purge a specific file (alias)')]
  public function purgeUrl(string $url): void {
    if (empty($url)) {
      $this->logger()->error(dt('URL parameter is required.'));
      return;
    }

    // Validate URL format.
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
      $this->logger()->error(dt('Invalid URL format: @url', ['@url' => $url]));
      return;
    }

    try {
      $this->purgeService->purge($url);
      $this->logger()->success(dt('Successfully purged URL from Cloudflare cache: @url', [
        '@url' => $url,
      ]));
    }
    catch (\Exception $e) {
      $this->logger()->error(dt('Failed to purge URL from Cloudflare cache: @message', [
        '@message' => $e->getMessage(),
      ]));
    }
  }

}
