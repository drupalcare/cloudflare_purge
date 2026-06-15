<?php

declare(strict_types=1);

namespace Drupal\cloudflare_purge;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;

/**
 * Builds the in-page quick-links navigation shared by every module page.
 *
 * Single source of truth for the module's sub-page links, so each page (the
 * purge/settings forms and the controller pages) renders the same tab-style
 * navigation row near the top. This lets users jump straight between pages
 * instead of returning to the admin menu each time.
 *
 * @package Drupal\cloudflare_purge
 */
final class CloudflarePurgeLinks {

  use StringTranslationTrait;

  /**
   * Builds the quick-links navigation render array.
   *
   * Only links the current user can access are shown, and the link for the
   * current page is rendered as a non-clickable active tab.
   *
   * @param string $currentRoute
   *   The route name of the page the navigation is rendered on, used to mark
   *   the active tab.
   *
   * @return array<string, mixed>
   *   A render array for the navigation row.
   */
  public static function build(string $currentRoute): array {
    $instance = new self();
    $items = [];

    // Ordered map of route name => translated label. Labels are passed to t()
    // as literals so the localization extractor collects them for translators
    // (a variable argument would not be extracted). Order here is print order.
    $labels = [
      'cloudflare_purge.form' => $instance->t('Credentials'),
      'cloudflare_purge.settings' => $instance->t('Auto-Purge Settings'),
      'cloudflare_purge.purge_by_url' => $instance->t('Purge by URL'),
      'cloudflare_purge.purge_by_tag' => $instance->t('Purge by Cache Tag'),
      'cloudflare_purge.purge_by_prefix' => $instance->t('Purge by Prefix'),
      'cloudflare_purge.purge_by_hostname' => $instance->t('Purge by Hostname'),
      'cloudflare_purge.purge_everything' => $instance->t('Purge Everything'),
      'cloudflare_purge.history' => $instance->t('Purge History'),
      'cloudflare_purge.limits' => $instance->t('Plans & Limits'),
    ];

    foreach ($labels as $route => $label) {
      $url = Url::fromRoute($route);

      // Skip pages the current user has no permission to reach.
      if (!$url->access()) {
        continue;
      }

      if ($route === $currentRoute) {
        $items[] = [
          '#type' => 'html_tag',
          '#tag' => 'span',
          '#value' => $label,
          '#attributes' => ['class' => ['cloudflare-purge-tabs__link', 'is-active']],
          '#wrapper_attributes' => ['class' => ['cloudflare-purge-tabs__item', 'is-active']],
        ];
        continue;
      }

      $items[] = [
        '#type' => 'link',
        '#title' => $label,
        '#url' => $url,
        '#attributes' => ['class' => ['cloudflare-purge-tabs__link']],
        '#wrapper_attributes' => ['class' => ['cloudflare-purge-tabs__item']],
      ];
    }

    // Nothing the user can access: render nothing.
    if ($items === []) {
      return [];
    }

    return [
      '#theme' => 'item_list',
      '#items' => $items,
      '#attributes' => ['class' => ['cloudflare-purge-tabs']],
      '#wrapper_attributes' => ['class' => ['cloudflare-purge-tabs-wrapper']],
      '#attached' => ['library' => ['cloudflare_purge/navigation']],
      // Print above the page's own content, but after the help block.
      '#weight' => -100,
      '#cache' => [
        // Visible links depend on permissions; the active tab depends on route.
        'contexts' => ['user.permissions', 'route.name'],
      ],
    ];
  }

}
