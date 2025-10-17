<?php

declare(strict_types=1);

namespace Drupal\Tests\cloudflare_purge\Unit;

use Drupal\cloudflare_purge\Form\CloudflarePurgeByUrl;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Tests\UnitTestCase;

/**
 * Tests for CloudflarePurgeByUrl form.
 *
 * @group cloudflare_purge
 * @coversDefaultClass \Drupal\cloudflare_purge\Form\CloudflarePurgeByUrl
 */
class CloudflarePurgeByUrlTest extends UnitTestCase {

  /**
   * Test form ID.
   *
   * @covers ::getFormId
   */
  public function testGetFormId(): void {
    $form = $this->createFormWithTranslation();
    $this->assertSame('cloudflare_purge_url', $form->getFormId());
  }

  /**
   * Test form validation with valid URL.
   *
   * @covers ::validateForm
   */
  public function testValidateFormWithValidUrl(): void {
    $form = $this->createFormWithTranslation();
    $form_state = $this->createMock(FormStateInterface::class);

    // Valid URL should not set error.
    $form_state->expects($this->any())
      ->method('getValue')
      ->with('url')
      ->willReturn('https://example.com');

    $form_state->expects($this->never())
      ->method('setErrorByName');

    $form_array = [];
    $form->validateForm($form_array, $form_state);
  }

  /**
   * Test form validation with invalid URL.
   *
   * @covers ::validateForm
   */
  public function testValidateFormWithInvalidUrl(): void {
    $form = $this->createFormWithTranslation();
    $form_state = $this->createMock(FormStateInterface::class);

    // Invalid URL should set error.
    $form_state->expects($this->any())
      ->method('getValue')
      ->with('url')
      ->willReturn('not-a-valid-url');

    $form_state->expects($this->once())
      ->method('setErrorByName')
      ->with('url', $this->isInstanceOf(TranslatableMarkup::class));

    $form_array = [];
    $form->validateForm($form_array, $form_state);
  }

  /**
   * Test form validation with relative URL (should fail).
   *
   * @covers ::validateForm
   */
  public function testValidateFormWithRelativeUrl(): void {
    $form = $this->createFormWithTranslation();
    $form_state = $this->createMock(FormStateInterface::class);

    // Relative URL should set error (requires absolute URL).
    $form_state->expects($this->any())
      ->method('getValue')
      ->with('url')
      ->willReturn('/relative/path');

    $form_state->expects($this->once())
      ->method('setErrorByName')
      ->with('url', $this->isInstanceOf(TranslatableMarkup::class));

    $form_array = [];
    $form->validateForm($form_array, $form_state);
  }

  /**
   * Creates form instance with mocked translation service.
   *
   * @return \Drupal\cloudflare_purge\Form\CloudflarePurgeByUrl
   *   The form instance.
   */
  protected function createFormWithTranslation(): CloudflarePurgeByUrl {
    $form = new CloudflarePurgeByUrl();

    // Mock translation service.
    $translation = $this->createMock(TranslationInterface::class);
    $translation->expects($this->any())
      ->method('translateString')
      ->willReturnCallback(function ($string) {
        return $string;
      });

    $form->setStringTranslation($translation);

    return $form;
  }

}
