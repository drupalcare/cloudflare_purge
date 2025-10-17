<?php

declare(strict_types=1);

namespace Drupal\Tests\cloudflare_purge\Unit;

use Drupal\cloudflare_purge\CloudflarePurgeApi;
use Drupal\Core\Logger\LoggerChannelInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/**
 * Tests for CloudflarePurgeApi service.
 *
 * @group cloudflare_purge
 * @coversDefaultClass \Drupal\cloudflare_purge\CloudflarePurgeApi
 */
class CloudflarePurgeApiTest extends TestCase {

  /**
   * Test successful cache purge with Bearer Token.
   *
   * @covers ::cfPurgeCache
   */
  public function testPurgeCacheWithBearerToken(): void {
    $httpClient = $this->createMock(ClientInterface::class);
    $logger = $this->createMock(LoggerChannelInterface::class);

    // Mock successful response.
    $response = new Response(200);
    $httpClient->expects($this->once())
      ->method('request')
      ->with(
        'POST',
        'https://api.cloudflare.com/client/v4/zones/test-zone-id/purge_cache',
        $this->callback(function ($options) {
          return isset($options['headers']['Authorization'])
            && $options['headers']['Authorization'] === 'Bearer test-token'
            && $options['json']['purge_everything'] === TRUE;
        })
      )
      ->willReturn($response);

    $api = new CloudflarePurgeApi($httpClient, $logger);
    $result = $api->cfPurgeCache(TRUE, 'test-zone-id', 'test-token', NULL, NULL, '');

    $this->assertSame(200, $result);
  }

  /**
   * Test successful cache purge with API Key.
   *
   * @covers ::cfPurgeCache
   */
  public function testPurgeCacheWithApiKey(): void {
    $httpClient = $this->createMock(ClientInterface::class);
    $logger = $this->createMock(LoggerChannelInterface::class);

    // Mock successful response.
    $response = new Response(200);
    $httpClient->expects($this->once())
      ->method('request')
      ->with(
        'POST',
        'https://api.cloudflare.com/client/v4/zones/test-zone-id/purge_cache',
        $this->callback(function ($options) {
          return isset($options['headers']['X-Auth-Email'])
            && $options['headers']['X-Auth-Email'] === 'test@example.com'
            && isset($options['headers']['X-Auth-Key'])
            && $options['headers']['X-Auth-Key'] === 'test-api-key'
            && $options['json']['purge_everything'] === TRUE;
        })
      )
      ->willReturn($response);

    $api = new CloudflarePurgeApi($httpClient, $logger);
    $result = $api->cfPurgeCache(FALSE, 'test-zone-id', '', 'test-api-key', 'test@example.com', '');

    $this->assertSame(200, $result);
  }

  /**
   * Test purge specific URL.
   *
   * @covers ::cfPurgeCache
   */
  public function testPurgeSpecificUrl(): void {
    $httpClient = $this->createMock(ClientInterface::class);
    $logger = $this->createMock(LoggerChannelInterface::class);

    // Mock successful response.
    $response = new Response(200);
    $httpClient->expects($this->once())
      ->method('request')
      ->with(
        'POST',
        'https://api.cloudflare.com/client/v4/zones/test-zone-id/purge_cache',
        $this->callback(function ($options) {
          return isset($options['json']['files'])
            && $options['json']['files'] === ['https://example.com/test'];
        })
      )
      ->willReturn($response);

    $api = new CloudflarePurgeApi($httpClient, $logger);
    $result = $api->cfPurgeCache(TRUE, 'test-zone-id', 'test-token', NULL, NULL, 'https://example.com/test');

    $this->assertSame(200, $result);
  }

}
