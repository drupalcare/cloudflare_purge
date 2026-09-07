<?php

declare(strict_types=1);

namespace Drupal\Tests\cloudflare_purge\Unit;

use PHPUnit\Framework\Attributes\Group;
use Drupal\cloudflare_purge\Form\CloudflarePurgeByUrl;
use Drupal\cloudflare_purge\PurgeInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for CloudflarePurgeByUrl form.
 *
 * @group cloudflare_purge
 */
#[CoversClass(CloudflarePurgeByUrl::class)]
#[Group('cloudflare_purge')]
class CloudflarePurgeByUrlTest extends UnitTestCase {

  /**
   * Test form ID.
   */
  public function testGetFormId(): void {
    $form = $this->createForm();
    $this->assertSame('cloudflare_purge_by_url', $form->getFormId());
  }

  /**
   * Test form validation with valid URLs.
   */
  public function testValidateFormWithValidUrls(): void {
    $form = $this->createForm();
    $formState = $this->createMock(FormStateInterface::class);

    $formState->expects($this->once())
      ->method('getValue')
      ->with('urls')
      ->willReturn("https://example.com/page1\nhttps://example.com/page2");

    // Valid URLs should not set error, should store parsed URLs.
    $formState->expects($this->never())
      ->method('setErrorByName');

    $formState->expects($this->once())
      ->method('set')
      ->with('parsed_urls', ['https://example.com/page1', 'https://example.com/page2']);

    $formArray = [];
    $form->validateForm($formArray, $formState);
  }

  /**
   * Test form validation with invalid URL.
   */
  public function testValidateFormWithInvalidUrl(): void {
    $form = $this->createForm();
    $formState = $this->createMock(FormStateInterface::class);

    $formState->expects($this->once())
      ->method('getValue')
      ->with('urls')
      ->willReturn('not-a-valid-url');

    $formState->expects($this->once())
      ->method('setErrorByName')
      ->with('urls', $this->isInstanceOf(TranslatableMarkup::class));

    $formArray = [];
    $form->validateForm($formArray, $formState);
  }

  /**
   * Test form validation with relative URL (should fail).
   */
  public function testValidateFormWithRelativeUrl(): void {
    $form = $this->createForm();
    $formState = $this->createMock(FormStateInterface::class);

    $formState->expects($this->once())
      ->method('getValue')
      ->with('urls')
      ->willReturn('/relative/path');

    $formState->expects($this->once())
      ->method('setErrorByName')
      ->with('urls', $this->isInstanceOf(TranslatableMarkup::class));

    $formArray = [];
    $form->validateForm($formArray, $formState);
  }

  /**
   * Test form validation with empty input.
   */
  public function testValidateFormWithEmptyInput(): void {
    $form = $this->createForm();
    $formState = $this->createMock(FormStateInterface::class);

    $formState->expects($this->once())
      ->method('getValue')
      ->with('urls')
      ->willReturn('');

    $formState->expects($this->once())
      ->method('setErrorByName')
      ->with('urls', $this->isInstanceOf(TranslatableMarkup::class));

    $formArray = [];
    $form->validateForm($formArray, $formState);
  }

  /**
   * More than 30 URLs are accepted (the Purge service batches them).
   *
   * Intended behavior change: the form no longer rejects above MAX_BATCH_SIZE.
   * Large lists are split into Cloudflare-sized batches by the Purge service,
   * so validation must accept them and store all parsed URLs.
   */
  public function testValidateFormAcceptsMoreThanThirtyUrls(): void {
    $form = $this->createForm();
    $formState = $this->createMock(FormStateInterface::class);

    // 31 URLs: previously rejected, now accepted.
    $urls = [];
    for ($i = 1; $i <= 31; $i++) {
      $urls[] = "https://example.com/page{$i}";
    }

    $formState->expects($this->once())
      ->method('getValue')
      ->with('urls')
      ->willReturn(implode("\n", $urls));

    $formState->expects($this->never())
      ->method('setErrorByName');

    $formState->expects($this->once())
      ->method('set')
      ->with('parsed_urls', $urls);

    $formArray = [];
    $form->validateForm($formArray, $formState);
  }

  /**
   * Creates form instance with mocked dependencies.
   *
   * @return \Drupal\cloudflare_purge\Form\CloudflarePurgeByUrl
   *   The form instance.
   */
  protected function createForm(): CloudflarePurgeByUrl {
    $purgeService = $this->createMock(PurgeInterface::class);
    $form = new CloudflarePurgeByUrl($purgeService);
    $form->setStringTranslation($this->getStringTranslationStub());

    return $form;
  }

}
