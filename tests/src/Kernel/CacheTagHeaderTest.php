<?php

declare(strict_types=1);

namespace Drupal\Tests\cloudflare_purge\Kernel;

use Drupal\cloudflare_purge\CloudflarePurgeSettings;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\CacheableResponse;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Integration test for the Cache-Tag header flow and shared services.
 *
 * Exercises the module's real container and installed configuration so the
 * single-source-of-truth refactors are covered end to end (config/install,
 * schema, DI wiring, the formatter and the credential resolver).
 */
#[Group('cloudflare_purge')]
#[RunTestsInSeparateProcesses]
class CacheTagHeaderTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['cloudflare_purge'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['cloudflare_purge']);
  }

  /**
   * Builds a cacheable response carrying the given tags and max-age.
   *
   * @param array<int, string> $tags
   *   The cache tags.
   * @param int $maxAge
   *   The cache max-age.
   */
  private function buildResponse(array $tags, int $maxAge): CacheableResponse {
    $response = new CacheableResponse('body');
    $metadata = (new CacheableMetadata())->setCacheTags($tags)->setCacheMaxAge($maxAge);
    $response->addCacheableDependency($metadata);
    return $response;
  }

  /**
   * Runs the response subscriber against a response and returns the header.
   */
  private function runSubscriber(CacheableResponse $response): ?string {
    $event = new ResponseEvent(
      $this->container->get('http_kernel'),
      Request::create('/'),
      HttpKernelInterface::MAIN_REQUEST,
      $response,
    );
    $this->container->get('cloudflare_purge.event_subscriber')->onResponse($event);
    return $response->headers->get('Cache-Tag');
  }

  /**
   * Tests that installed default configuration is present and valid.
   */
  public function testDefaultConfigInstalled(): void {
    $config = $this->config(CloudflarePurgeSettings::NAME);
    $this->assertFalse($config->get('auto_purge_enabled'));
    $this->assertSame(['node', 'taxonomy_term', 'media'], $config->get('auto_purge_entity_types'));
    // Header-sizing defaults from config/install must be present.
    $this->assertTrue($config->get('auto_purge_filter_header_tags'));
    $this->assertFalse($config->get('auto_purge_hash_tags'));
    $this->assertSame(7168, $config->get('auto_purge_header_max_bytes'));
  }

  /**
   * Tests the credential resolver against real default config.
   */
  public function testCredentialResolverDefaults(): void {
    $resolver = $this->container->get('cloudflare_purge.credential_resolver');
    $this->assertFalse($resolver->hasCredentials());
    $this->assertSame(CloudflarePurgeSettings::AUTH_BEARER, $resolver->getAuthMethod());
  }

  /**
   * Tests the formatter filters to purgeable tags using real config.
   */
  public function testFormatterFiltersWithRealConfig(): void {
    $formatter = $this->container->get('cloudflare_purge.cache_tag_formatter');
    $value = $formatter->buildHeaderValue(['node:1', 'config:system.site', 'node_list', 'http_response']);
    $this->assertSame('node:1,node_list', $value);
  }

  /**
   * Tests the subscriber adds a filtered Cache-Tag header when enabled.
   */
  public function testHeaderAddedWhenEnabled(): void {
    $this->config(CloudflarePurgeSettings::NAME)->set('auto_purge_enabled', TRUE)->save();

    $response = $this->buildResponse(['node:1', 'config:system.site', 'node_list'], 3600);
    $this->assertSame('node:1,node_list', $this->runSubscriber($response));
  }

  /**
   * Tests the subscriber skips uncacheable (max-age 0) responses.
   */
  public function testHeaderSkippedForUncacheable(): void {
    $this->config(CloudflarePurgeSettings::NAME)->set('auto_purge_enabled', TRUE)->save();

    $response = $this->buildResponse(['node:1', 'node_list'], 0);
    $this->assertNull($this->runSubscriber($response));
  }

  /**
   * Tests no header is added when auto-purge is disabled (the default).
   */
  public function testHeaderSkippedWhenDisabled(): void {
    $response = $this->buildResponse(['node:1', 'node_list'], 3600);
    $this->assertNull($this->runSubscriber($response));
  }

}
