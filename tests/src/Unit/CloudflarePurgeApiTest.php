<?php

declare(strict_types=1);

namespace Drupal\Tests\cloudflare_purge\Unit;

use PHPUnit\Framework\Attributes\Group;
use Drupal\cloudflare_purge\CloudflarePurgeApi;
use Drupal\Core\Logger\LoggerChannelInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests for CloudflarePurgeApi service.
 *
 * @group cloudflare_purge
 */
#[CoversClass(CloudflarePurgeApi::class)]
#[Group('cloudflare_purge')]
class CloudflarePurgeApiTest extends TestCase {

  /**
   * Valid test zone ID (32-character hex).
   */
  private const TEST_ZONE_ID = 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4';

  /**
   * A successful purge must not log here; the Purge service owns success logs.
   *
   * Regression test for #3606632: this low-level client previously logged an
   * ungated info() on every success, flooding dblog on auto-purge even with
   * detailed logging disabled.
   */
  public function testSuccessfulPurgeDoesNotLog(): void {
    $httpClient = $this->createMock(ClientInterface::class);
    $logger = $this->createMock(LoggerChannelInterface::class);

    $response = new Response(200, [], (string) json_encode(['success' => TRUE, 'result' => ['id' => 'test']]));
    $httpClient->method('request')->willReturn($response);

    // The client must stay silent on success at every level.
    $logger->expects($this->never())->method('info');
    $logger->expects($this->never())->method('notice');
    $logger->expects($this->never())->method('warning');
    $logger->expects($this->never())->method('error');

    $api = new CloudflarePurgeApi($httpClient, $logger);
    $result = $api->purgeByTags(TRUE, self::TEST_ZONE_ID, 'test-token', NULL, NULL, ['node-1']);

    $this->assertTrue($result->isSuccess());
  }

  /**
   * Test purge everything with Bearer Token.
   */
  public function testPurgeEverythingWithBearerToken(): void {
    $httpClient = $this->createMock(ClientInterface::class);
    $logger = $this->createMock(LoggerChannelInterface::class);

    $responseBody = json_encode(['success' => TRUE, 'result' => ['id' => 'test']]);
    $response = new Response(200, [], $responseBody);

    $httpClient->expects($this->once())
      ->method('request')
      ->with(
        'POST',
        'https://api.cloudflare.com/client/v4/zones/' . self::TEST_ZONE_ID . '/purge_cache',
        $this->callback(function ($options) {
          return isset($options['headers']['Authorization'])
            && $options['headers']['Authorization'] === 'Bearer test-token'
            && $options['json']['purge_everything'] === TRUE;
        })
      )
      ->willReturn($response);

    $api = new CloudflarePurgeApi($httpClient, $logger);
    $result = $api->purgeEverything(TRUE, self::TEST_ZONE_ID, 'test-token', NULL, NULL);

    $this->assertTrue($result->isSuccess());
    $this->assertSame(200, $result->getStatusCode());
  }

  /**
   * Test purge everything with legacy API Key.
   */
  public function testPurgeEverythingWithApiKey(): void {
    $httpClient = $this->createMock(ClientInterface::class);
    $logger = $this->createMock(LoggerChannelInterface::class);

    $responseBody = json_encode(['success' => TRUE, 'result' => ['id' => 'test']]);
    $response = new Response(200, [], $responseBody);

    $httpClient->expects($this->once())
      ->method('request')
      ->with(
        'POST',
        'https://api.cloudflare.com/client/v4/zones/' . self::TEST_ZONE_ID . '/purge_cache',
        $this->callback(function ($options) {
          return isset($options['headers']['X-Auth-Email'])
            && $options['headers']['X-Auth-Email'] === 'test@example.com'
            && isset($options['headers']['X-Auth-Key'])
            && $options['headers']['X-Auth-Key'] === 'test-api-key';
        })
      )
      ->willReturn($response);

    $api = new CloudflarePurgeApi($httpClient, $logger);
    $result = $api->purgeEverything(FALSE, self::TEST_ZONE_ID, '', 'test-api-key', 'test@example.com');

    $this->assertTrue($result->isSuccess());
  }

  /**
   * Test purge by URLs.
   */
  public function testPurgeByUrls(): void {
    $httpClient = $this->createMock(ClientInterface::class);
    $logger = $this->createMock(LoggerChannelInterface::class);

    $responseBody = json_encode(['success' => TRUE, 'result' => ['id' => 'test']]);
    $response = new Response(200, [], $responseBody);

    $httpClient->expects($this->once())
      ->method('request')
      ->with(
        'POST',
        'https://api.cloudflare.com/client/v4/zones/' . self::TEST_ZONE_ID . '/purge_cache',
        $this->callback(function ($options) {
          return isset($options['json']['files'])
            && $options['json']['files'] === ['https://example.com/page1', 'https://example.com/page2'];
        })
      )
      ->willReturn($response);

    $api = new CloudflarePurgeApi($httpClient, $logger);
    $result = $api->purgeByUrls(
      TRUE,
      self::TEST_ZONE_ID,
      'test-token',
      NULL,
      NULL,
      ['https://example.com/page1', 'https://example.com/page2']
    );

    $this->assertTrue($result->isSuccess());
  }

  /**
   * Test purge by tags.
   */
  public function testPurgeByTags(): void {
    $httpClient = $this->createMock(ClientInterface::class);
    $logger = $this->createMock(LoggerChannelInterface::class);

    $responseBody = json_encode(['success' => TRUE, 'result' => ['id' => 'test']]);
    $response = new Response(200, [], $responseBody);

    $httpClient->expects($this->once())
      ->method('request')
      ->with(
        'POST',
        'https://api.cloudflare.com/client/v4/zones/' . self::TEST_ZONE_ID . '/purge_cache',
        $this->callback(function ($options) {
          return isset($options['json']['tags'])
            && $options['json']['tags'] === ['node-123', 'taxonomy-term-45'];
        })
      )
      ->willReturn($response);

    $api = new CloudflarePurgeApi($httpClient, $logger);
    $result = $api->purgeByTags(
      TRUE,
      self::TEST_ZONE_ID,
      'test-token',
      NULL,
      NULL,
      ['node-123', 'taxonomy-term-45']
    );

    $this->assertTrue($result->isSuccess());
  }

  /**
   * Test invalid zone ID format.
   */
  public function testInvalidZoneIdFormat(): void {
    $httpClient = $this->createMock(ClientInterface::class);
    $logger = $this->createMock(LoggerChannelInterface::class);

    // Should not make HTTP request with invalid zone ID.
    $httpClient->expects($this->never())->method('request');

    $api = new CloudflarePurgeApi($httpClient, $logger);
    $result = $api->purgeEverything(TRUE, 'invalid-zone-id', 'test-token', NULL, NULL);

    $this->assertFalse($result->isSuccess());
    $this->assertStringContainsString('Invalid Zone ID', $result->getMessage());
  }

  /**
   * Test empty URLs returns error.
   */
  public function testEmptyUrlsReturnsError(): void {
    $httpClient = $this->createMock(ClientInterface::class);
    $logger = $this->createMock(LoggerChannelInterface::class);

    $httpClient->expects($this->never())->method('request');

    $api = new CloudflarePurgeApi($httpClient, $logger);
    $result = $api->purgeByUrls(TRUE, self::TEST_ZONE_ID, 'test-token', NULL, NULL, []);

    $this->assertFalse($result->isSuccess());
    $this->assertStringContainsString('No valid URLs', $result->getMessage());
  }

}
