<?php

declare(strict_types=1);

namespace Drupal\Tests\cloudflare_purge\Unit;

use Drupal\cloudflare_purge\PrefixNormalizer;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests for the PrefixNormalizer helper.
 *
 * @group cloudflare_purge
 */
#[CoversClass(PrefixNormalizer::class)]
class PrefixNormalizerTest extends UnitTestCase {

  /**
   * Tests that schemes are stripped from prefixes.
   */
  #[DataProvider('normalizeProvider')]
  public function testNormalize(string $input, string $expected): void {
    $this->assertSame($expected, PrefixNormalizer::normalize($input));
  }

  /**
   * Data provider for testNormalize().
   *
   * @return array<string, array{string, string}>
   *   Input prefix and expected normalized output.
   */
  public static function normalizeProvider(): array {
    return [
      'https scheme stripped' => ['https://example.com/files/', 'example.com/files/'],
      'http scheme stripped' => ['http://example.com/blog/', 'example.com/blog/'],
      'protocol-relative stripped' => ['//example.com/images/', 'example.com/images/'],
      'no scheme unchanged' => ['example.com/files/', 'example.com/files/'],
      'whitespace trimmed' => ['  https://example.com/x/  ', 'example.com/x/'],
      'uppercase scheme stripped' => ['HTTPS://example.com/x', 'example.com/x'],
      'path preserved' => ['example.com/a/b/c.jpg', 'example.com/a/b/c.jpg'],
      'query preserved' => ['https://example.com/a?b=c', 'example.com/a?b=c'],
      'path-only unchanged' => ['/files/', '/files/'],
      'empty unchanged' => ['', ''],
    ];
  }

  /**
   * Tests that normalize() is idempotent.
   */
  public function testNormalizeIsIdempotent(): void {
    $once = PrefixNormalizer::normalize('https://example.com/files/');
    $twice = PrefixNormalizer::normalize($once);
    $this->assertSame($once, $twice);
  }

  /**
   * Tests prefix validity detection.
   */
  #[DataProvider('isValidProvider')]
  public function testIsValid(string $input, bool $expected): void {
    $this->assertSame($expected, PrefixNormalizer::isValid($input));
  }

  /**
   * Data provider for testIsValid().
   *
   * @return array<string, array{string, bool}>
   *   Input prefix and expected validity.
   */
  public static function isValidProvider(): array {
    return [
      'host with scheme' => ['https://example.com/files/', TRUE],
      'host without scheme' => ['example.com/files/', TRUE],
      'host only' => ['example.com', TRUE],
      'protocol-relative host' => ['//example.com/x', TRUE],
      'path-only rejected' => ['/files/', FALSE],
      'empty rejected' => ['', FALSE],
      'whitespace rejected' => ['   ', FALSE],
    ];
  }

}
