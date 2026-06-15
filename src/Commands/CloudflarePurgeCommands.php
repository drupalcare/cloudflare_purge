<?php

declare(strict_types=1);

namespace Drupal\cloudflare_purge\Commands;

use Drupal\cloudflare_purge\PrefixNormalizer;
use Drupal\cloudflare_purge\PurgeInterface;
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
   * @param \Drupal\cloudflare_purge\PurgeInterface $purgeService
   *   The Cloudflare purge service.
   */
  public function __construct(
    private readonly PurgeInterface $purgeService,
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

    $this->runPurge($allUrls, 'URL', fn(array $items) => $this->purgeService->purgeByUrls($items, FALSE));
  }

  /**
   * Purges cache by tag(s).
   *
   * @param array<int, string> $tags
   *   The cache tags to purge.
   */
  #[CLI\Command(name: 'cloudflare:purge-tags', aliases: ['cf-purge-tags', 'cfpt'])]
  #[CLI\Argument(name: 'tags', description: 'Cache tags to purge (space or comma separated)')]
  #[CLI\Usage(name: 'drush cloudflare:purge-tags node:123 taxonomy_term:45', description: 'Purge by cache tags')]
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

    $this->runPurge($allTags, 'tag', fn(array $items) => $this->purgeService->purgeByTags($items, FALSE));
  }

  /**
   * Purges cache by prefix(es).
   *
   * @param array<int, string> $prefixes
   *   The URL prefixes to purge.
   */
  #[CLI\Command(name: 'cloudflare:purge-prefixes', aliases: ['cf-purge-prefixes', 'cfpp'])]
  #[CLI\Argument(name: 'prefixes', description: 'URL prefixes to purge (space or comma separated)')]
  #[CLI\Usage(name: 'drush cloudflare:purge-prefixes example.com/blog/', description: 'Purge all content under /blog/')]
  #[CLI\Usage(name: 'drush cf-purge-prefixes "example.com/products/,example.com/images/"', description: 'Purge multiple prefixes')]
  public function purgePrefixes(array $prefixes): void {
    if (!$this->checkCredentials()) {
      return;
    }

    $allPrefixes = $this->parseMultipleInputs($prefixes);

    if ($allPrefixes === []) {
      $this->logger()->error('At least one prefix is required.');
      return;
    }

    // Validate each prefix has a host. The scheme is optional and stripped by
    // the purge service, because Cloudflare rejects prefixes with a scheme.
    foreach ($allPrefixes as $prefix) {
      if (!PrefixNormalizer::isValid($prefix)) {
        $this->logger()->error('Invalid prefix (must include a host, e.g. example.com/path/): ' . $prefix);
        return;
      }
    }

    $this->runPurge($allPrefixes, 'prefix', fn(array $items) => $this->purgeService->purgeByPrefixes($items, FALSE));
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

    $this->runPurge($allHostnames, 'hostname', fn(array $items) => $this->purgeService->purgeByHostnames($items, FALSE));
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
   * Runs a purge through the service and reports the aggregate result.
   *
   * The purge service splits the list into Cloudflare-sized batches itself,
   * enforces the rate limit per request, and short-circuits on operation-wide
   * failures (rate limit / authentication). The command therefore passes the
   * full list in a single call and reports success or the (possibly partial)
   * failure message, rather than chunking and reporting per batch.
   *
   * @param array<int, string> $items
   *   The items to purge (already parsed and de-duplicated).
   * @param string $itemType
   *   The type of item (for messages).
   * @param callable(array<int, string>): \Drupal\cloudflare_purge\CloudflarePurgeResult $purge
   *   Invokes the matching purge-service method for the full list.
   */
  private function runPurge(array $items, string $itemType, callable $purge): void {
    $requested = count($items);

    try {
      $result = $purge($items);

      if ($result->isSuccess()) {
        // The service reports how many items it actually purged after
        // sanitization/de-duplication; fall back to the submitted count if a
        // result ever omits it.
        $purged = $result->getPurgedCount() ?? $requested;
        $this->logger()->success(sprintf('Successfully purged %d %s from Cloudflare.', $purged, $this->pluralize($itemType, $purged)));
      }
      else {
        $this->logger()->error(sprintf('Cloudflare %s purge failed: %s', $itemType, $result->getMessage()));
      }
    }
    catch (\Exception $e) {
      $this->logger()->error('Exception during ' . $itemType . ' purge: ' . $e->getMessage());
    }
  }

  /**
   * Pluralizes a CLI noun for a count (handles "prefix" -> "prefixes").
   *
   * @param string $noun
   *   The singular noun (e.g. "URL", "tag", "prefix", "hostname").
   * @param int $count
   *   The item count.
   *
   * @return string
   *   The singular noun for a count of 1, otherwise the plural form.
   */
  private function pluralize(string $noun, int $count): string {
    if ($count === 1) {
      return $noun;
    }

    return $noun === 'prefix' ? 'prefixes' : $noun . 's';
  }

}
