<?php

declare(strict_types=1);

namespace Drupal\cloudflare_purge\Commands;

use Drupal\cloudflare_purge\CloudflarePurgeApi;
use Drupal\cloudflare_purge\Purge;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Drush commands for Cloudflare Purge module.
 *
 * Provides comprehensive cache purge capabilities from the command line:
 * - Purge everything
 * - Purge by URL(s)
 * - Purge by cache tag(s)
 * - Purge by prefix(es)
 * - Purge by hostname(s)
 *
 * @package Drupal\cloudflare_purge\Commands
 */
final class CloudflarePurgeCommands extends DrushCommands {

  /**
   * Constructs a CloudflarePurgeCommands object.
   *
   * @param \Drupal\cloudflare_purge\Purge $purgeService
   *   The Cloudflare purge service.
   */
  public function __construct(
    private readonly Purge $purgeService,
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
  public function purgeAll(): void {
    if (!$this->checkCredentials()) {
      return;
    }

    try {
      $result = $this->purgeService->purgeEverything(FALSE);

      if ($result->isSuccess()) {
        $this->logger()->success('Successfully purged all Cloudflare cache.');
      }
      else {
        $this->logger()->error('Failed to purge Cloudflare cache: ' . $result->getMessage());
      }
    }
    catch (\Exception $e) {
      $this->logger()->error('Exception during cache purge: ' . $e->getMessage());
    }
  }

  /**
   * Purges URLs from Cloudflare cache.
   *
   * @param array<int, string> $urls
   *   The URLs to purge (space-separated or comma-separated).
   */
  #[CLI\Command(name: 'cloudflare:purge-url', aliases: ['cf-purge-url', 'cfpu'])]
  #[CLI\Argument(name: 'urls', description: 'URLs to purge (space or comma separated)')]
  #[CLI\Usage(name: 'drush cloudflare:purge-url https://example.com/page', description: 'Purge a single URL')]
  #[CLI\Usage(name: 'drush cf-purge-url https://example.com/a https://example.com/b', description: 'Purge multiple URLs')]
  #[CLI\Usage(name: 'drush cf-purge-url "https://example.com/a,https://example.com/b"', description: 'Purge comma-separated URLs')]
  public function purgeUrls(array $urls): void {
    if (!$this->checkCredentials()) {
      return;
    }

    $allUrls = $this->parseMultipleInputs($urls);

    if ($allUrls === []) {
      $this->logger()->error('At least one URL is required.');
      return;
    }

    // Validate URLs.
    foreach ($allUrls as $url) {
      if (!filter_var($url, FILTER_VALIDATE_URL)) {
        $this->logger()->error('Invalid URL: ' . $url);
        return;
      }
    }

    $this->processBatchPurge($allUrls, 'URL', function (array $batch): bool {
      $result = $this->purgeService->purgeByUrls($batch, FALSE);
      if (!$result->isSuccess()) {
        $this->logger()->error('Failed: ' . $result->getMessage());
      }
      return $result->isSuccess();
    });
  }

  /**
   * Purges cache by tag(s).
   *
   * @param array<int, string> $tags
   *   The cache tags to purge.
   */
  #[CLI\Command(name: 'cloudflare:purge-tags', aliases: ['cf-purge-tags', 'cfpt'])]
  #[CLI\Argument(name: 'tags', description: 'Cache tags to purge (space or comma separated)')]
  #[CLI\Usage(name: 'drush cloudflare:purge-tags node-123 taxonomy-term-45', description: 'Purge by cache tags')]
  #[CLI\Usage(name: 'drush cf-purge-tags "product-1,product-2,product-3"', description: 'Purge comma-separated tags')]
  public function purgeTags(array $tags): void {
    if (!$this->checkCredentials()) {
      return;
    }

    $allTags = $this->parseMultipleInputs($tags);

    if ($allTags === []) {
      $this->logger()->error('At least one cache tag is required.');
      return;
    }

    $this->processBatchPurge($allTags, 'tag', function (array $batch): bool {
      $result = $this->purgeService->purgeByTags($batch, FALSE);
      if (!$result->isSuccess()) {
        $this->logger()->error('Failed: ' . $result->getMessage());
      }
      return $result->isSuccess();
    });
  }

  /**
   * Purges cache by prefix(es).
   *
   * @param array<int, string> $prefixes
   *   The URL prefixes to purge.
   */
  #[CLI\Command(name: 'cloudflare:purge-prefixes', aliases: ['cf-purge-prefixes', 'cfpp'])]
  #[CLI\Argument(name: 'prefixes', description: 'URL prefixes to purge (space or comma separated)')]
  #[CLI\Usage(name: 'drush cloudflare:purge-prefixes https://example.com/blog/', description: 'Purge all content under /blog/')]
  #[CLI\Usage(name: 'drush cf-purge-prefixes "https://example.com/products/,https://example.com/images/"', description: 'Purge multiple prefixes')]
  public function purgePrefixes(array $prefixes): void {
    if (!$this->checkCredentials()) {
      return;
    }

    $allPrefixes = $this->parseMultipleInputs($prefixes);

    if ($allPrefixes === []) {
      $this->logger()->error('At least one prefix is required.');
      return;
    }

    // Validate prefixes are valid URLs.
    foreach ($allPrefixes as $prefix) {
      if (!filter_var($prefix, FILTER_VALIDATE_URL)) {
        $this->logger()->error('Invalid prefix (must be full URL): ' . $prefix);
        return;
      }
    }

    $this->processBatchPurge($allPrefixes, 'prefix', function (array $batch): bool {
      $result = $this->purgeService->purgeByPrefixes($batch, FALSE);
      if (!$result->isSuccess()) {
        $this->logger()->error('Failed: ' . $result->getMessage());
      }
      return $result->isSuccess();
    });
  }

  /**
   * Purges cache by hostname(s).
   *
   * @param array<int, string> $hostnames
   *   The hostnames to purge.
   */
  #[CLI\Command(name: 'cloudflare:purge-hostnames', aliases: ['cf-purge-hosts', 'cfph'])]
  #[CLI\Argument(name: 'hostnames', description: 'Hostnames to purge (space or comma separated)')]
  #[CLI\Usage(name: 'drush cloudflare:purge-hostnames www.example.com', description: 'Purge all content for a hostname')]
  #[CLI\Usage(name: 'drush cf-purge-hosts "cdn.example.com,images.example.com"', description: 'Purge multiple hostnames')]
  public function purgeHostnames(array $hostnames): void {
    if (!$this->checkCredentials()) {
      return;
    }

    $allHostnames = $this->parseMultipleInputs($hostnames);
    $allHostnames = array_map('strtolower', $allHostnames);

    if ($allHostnames === []) {
      $this->logger()->error('At least one hostname is required.');
      return;
    }

    // Validate hostnames (no protocol).
    foreach ($allHostnames as $hostname) {
      if (str_starts_with($hostname, 'http://') || str_starts_with($hostname, 'https://')) {
        $this->logger()->error('Do not include protocol. Invalid: ' . $hostname);
        return;
      }
    }

    $this->processBatchPurge($allHostnames, 'hostname', function (array $batch): bool {
      $result = $this->purgeService->purgeByHostnames($batch, FALSE);
      if (!$result->isSuccess()) {
        $this->logger()->error('Failed: ' . $result->getMessage());
      }
      return $result->isSuccess();
    });
  }

  /**
   * Shows Cloudflare Purge status and configuration.
   */
  #[CLI\Command(name: 'cloudflare:status', aliases: ['cf-status', 'cfs'])]
  #[CLI\Usage(name: 'drush cloudflare:status', description: 'Show Cloudflare Purge configuration status')]
  public function status(): void {
    if ($this->purgeService->hasCredentials()) {
      $this->logger()->success('Cloudflare credentials are configured.');
    }
    else {
      $this->logger()->warning('Cloudflare credentials are NOT configured or invalid.');
      $this->logger()->notice('Configure credentials at: /admin/config/cloudflare-purge/credentials');
    }
  }

  /**
   * Checks if credentials are configured.
   *
   * @return bool
   *   TRUE if credentials are configured, FALSE otherwise.
   */
  private function checkCredentials(): bool {
    if (!$this->purgeService->hasCredentials()) {
      $this->logger()->error('Cloudflare credentials are not configured.');
      $this->logger()->notice('Configure credentials at: /admin/config/cloudflare-purge/credentials');
      $this->logger()->notice('Or run: drush cloudflare:status');
      return FALSE;
    }
    return TRUE;
  }

  /**
   * Parses multiple inputs (space or comma separated).
   *
   * @param array<int, string> $inputs
   *   The input array from command line.
   *
   * @return array<int, string>
   *   The parsed and cleaned items.
   */
  private function parseMultipleInputs(array $inputs): array {
    $items = [];

    foreach ($inputs as $input) {
      if (!is_string($input)) {
        continue;
      }

      // Split by comma.
      $parts = explode(',', $input);

      foreach ($parts as $part) {
        $trimmed = trim($part);
        if ($trimmed !== '') {
          $items[] = $trimmed;
        }
      }
    }

    return array_values(array_unique($items));
  }

  /**
   * Processes a batch purge operation with progress logging.
   *
   * @param array<int, string> $items
   *   The items to purge.
   * @param string $itemType
   *   The type of item (for logging).
   * @param callable $purgeCallback
   *   The callback to execute for each batch.
   */
  private function processBatchPurge(array $items, string $itemType, callable $purgeCallback): void {
    $count = count($items);

    if ($count > CloudflarePurgeApi::MAX_BATCH_SIZE) {
      $this->logger()->notice(sprintf(
        'Processing %d %s(s) in batches of %d.',
        $count,
        $itemType,
        CloudflarePurgeApi::MAX_BATCH_SIZE,
      ));
    }

    try {
      $batches = array_chunk($items, CloudflarePurgeApi::MAX_BATCH_SIZE);
      $totalSuccess = 0;
      $batchCount = count($batches);

      foreach ($batches as $index => $batch) {
        $success = $purgeCallback($batch);

        if ($success) {
          $totalSuccess += count($batch);
          if ($batchCount > 1) {
            $this->logger()->info(sprintf('Batch %d/%d: Purged %d %s(s)', $index + 1, $batchCount, count($batch), $itemType));
          }
        }
      }

      $this->logger()->success(sprintf('Successfully purged %d of %d %s(s).', $totalSuccess, $count, $itemType));
    }
    catch (\Exception $e) {
      $this->logger()->error('Exception during ' . $itemType . ' purge: ' . $e->getMessage());
    }
  }

}
