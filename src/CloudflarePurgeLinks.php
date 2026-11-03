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
      'cloudflare_purge.queued_tags' => $instance->t('Queued Tags'),
      'cloudflare_purge.history' => $instance->t('Purge History'),
      'cloudflare_purge.limits' => $instance->t('Plans & Limits'),
    ];

    $groups = [
      'setup' => [$instance->t('Set up'), ['cloudflare_purge.form', 'cloudflare_purge.settings']],
      'purge' => [$instance->t('Purge'), [
        'cloudflare_purge.purge_by_url',
        'cloudflare_purge.purge_by_tag',
        'cloudflare_purge.purge_by_prefix',
        'cloudflare_purge.purge_by_hostname',
        'cloudflare_purge.purge_everything',
      ]],
      'monitor' => [$instance->t('Monitor'), [
        'cloudflare_purge.queued_tags',
        'cloudflare_purge.history',
        'cloudflare_purge.limits',
      ]],
    ];
    $icons = [
      'cloudflare_purge.form' => 'key',
      'cloudflare_purge.settings' => 'tune',
      'cloudflare_purge.purge_by_url' => 'link',
      'cloudflare_purge.purge_by_tag' => 'label',
      'cloudflare_purge.purge_by_prefix' => 'folder',
      'cloudflare_purge.purge_by_hostname' => 'dns',
      'cloudflare_purge.purge_everything' => 'delete_forever',
      'cloudflare_purge.queued_tags' => 'schedule',
      'cloudflare_purge.history' => 'history',
      'cloudflare_purge.limits' => 'bar_chart',
    ];

    $nav_groups = [];
    foreach ($groups as $key => [$group_label, $routes]) {
      $items = [];
      foreach ($routes as $route) {
        $url = Url::fromRoute($route);

        // Skip pages the current user has no permission to reach.
        if (!$url->access()) {
          continue;
        }

        $classes = ['cloudflare-purge-tabs__link', 'cfp-icon--' . $icons[$route]];
        if ($route === 'cloudflare_purge.purge_everything') {
          $classes[] = 'is-danger';
        }
        $item_classes = ['cloudflare-purge-tabs__item'];

        if ($route === $currentRoute) {
          $classes[] = 'is-active';
          $item_classes[] = 'is-active';
          $items[] = [
            '#type' => 'html_tag',
            '#tag' => 'span',
            '#value' => $labels[$route],
            '#attributes' => ['class' => $classes, 'aria-current' => 'page'],
            '#wrapper_attributes' => ['class' => $item_classes],
          ];
          continue;
        }

        $items[] = [
          '#type' => 'link',
          '#title' => $labels[$route],
          '#url' => $url,
          '#attributes' => ['class' => $classes],
          '#wrapper_attributes' => ['class' => $item_classes],
        ];
      }

      if ($items !== []) {
        $nav_groups[$key] = [
          '#theme' => 'item_list',
          '#title' => $group_label,
          '#items' => $items,
          '#attributes' => ['class' => ['cloudflare-purge-tabs']],
          '#wrapper_attributes' => ['class' => ['cloudflare-purge-tabs-group', 'cfp-group--' . $key]],
        ];
      }
    }

    // Nothing the user can access: render nothing.
    if ($nav_groups === []) {
      return [];
    }

    $config = \Drupal::config(CloudflarePurgeSettings::NAME);
    $resolver = \Drupal::service('cloudflare_purge.credential_resolver');
    $storage = match (TRUE) {
      $resolver->hasSettingsOverride() => $instance->t('settings.php'),
      $resolver->detectStorageMethod() === CloudflarePurgeSettings::STORAGE_KEY => $instance->t('Key module'),
      default => $instance->t('Form'),
    };
    $chips = [
      ['label' => $instance->t('Credentials: @where', ['@where' => $storage]), 'on' => TRUE],
      ['label' => $instance->t('Auto-purge: @state', ['@state' => $config->get('auto_purge_enabled') ? $instance->t('On') : $instance->t('Off')]), 'on' => (bool) $config->get('auto_purge_enabled')],
      ['label' => $instance->t('Queue: @state', ['@state' => $config->get('auto_purge_use_queue') ? $instance->t('On') : $instance->t('Off')]), 'on' => (bool) $config->get('auto_purge_use_queue')],
    ];
    $chip_items = [];
    foreach ($chips as $chip) {
      $chip_items[] = [
        '#type' => 'html_tag',
        '#tag' => 'li',
        '#value' => $chip['label'],
        '#attributes' => ['class' => ['cfp-chip', $chip['on'] ? 'cfp-chip--on' : 'cfp-chip--off']],
      ];
    }

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['cloudflare-purge-tabs-wrapper', 'cfp-console']],
      'band' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['cfp-band']],
        'name' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => $instance->t('Cloudflare Purge'),
          '#attributes' => ['class' => ['cfp-band__name']],
        ],
        'purpose' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => $instance->t("Clear Cloudflare's cache for this site, by URL, tag, prefix, host or the whole zone."),
          '#attributes' => ['class' => ['cfp-band__purpose']],
        ],
        'chips' => [
          '#type' => 'html_tag',
          '#tag' => 'ul',
          '#attributes' => ['class' => ['cfp-band__chips']],
          'items' => $chip_items,
        ],
      ],
      'nav' => [
        '#type' => 'html_tag',
        '#tag' => 'nav',
        '#attributes' => ['class' => ['cfp-nav'], 'aria-label' => $instance->t('Cloudflare Purge')],
        'groups' => $nav_groups,
      ],
      '#attached' => ['library' => ['cloudflare_purge/navigation']],
      // Print above the page's own content, but after the help block.
      '#weight' => -100,
      '#cache' => [
        // Visible links depend on permissions; the active tab depends on route.
        'contexts' => ['user.permissions', 'route.name'],
        'tags' => ['config:' . CloudflarePurgeSettings::NAME],
      ],
    ];
  }

  /**
   * Builds the decorative scope meter shown above a purge form.
   *
   * @param string $scope
   *   One of url, tag, prefix, hostname, everything.
   *
   * @return array<string, mixed>
   *   A render array. The meter is decorative; its text carries the meaning.
   */
  public static function scope(string $scope): array {
    $instance = new self();
    $level = ['url' => 1, 'tag' => 2, 'prefix' => 3, 'hostname' => 4, 'everything' => 5][$scope] ?? 1;
    $text = match ($scope) {
      'tag' => $instance->t('Scope: every page with the tag'),
      'prefix' => $instance->t('Scope: everything under the prefix'),
      'hostname' => $instance->t('Scope: everything on the host'),
      'everything' => $instance->t('Scope: the whole zone'),
      default => $instance->t('Scope: one URL at a time'),
    };
    $segments = '';
    for ($i = 1; $i <= 5; $i++) {
      $segments .= '<span class="cfp-meter__seg' . ($i <= $level ? ' is-on' : '') . '"></span>';
    }
    return [
      '#type' => 'inline_template',
      '#template' => '<div class="cfp-scope cfp-scope--{{ scope }}"><span class="cfp-meter" aria-hidden="true">{{ segments|raw }}</span><span class="cfp-scope__text">{{ text }}</span></div>',
      '#context' => ['scope' => $scope, 'segments' => $segments, 'text' => $text],
      '#weight' => -90,
    ];
  }


}
