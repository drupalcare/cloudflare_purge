<?php

declare(strict_types=1);

namespace Drupal\cloudflare_purge\Controller;

use Drupal\Component\Render\FormattableMarkup;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Url;
use Drupal\cloudflare_purge\CloudflarePurgeApi;
use Drupal\cloudflare_purge\CloudflarePurgeLinks;
use Drupal\cloudflare_purge\PurgeHistoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides HTTP controller endpoints for Cloudflare cache purging.
 *
 * This controller handles user-initiated cache purge requests and
 * provides a history view of recent purge operations.
 *
 * @package Drupal\cloudflare_purge\Controller
 */
final class CloudflarePurgeController extends ControllerBase {

  /**
   * Constructs a CloudflarePurgeController object.
   *
   * @param \Drupal\Core\Logger\LoggerChannelInterface $logger
   *   The logger channel.
   * @param \Drupal\cloudflare_purge\PurgeHistoryInterface $history
   *   The purge-history reader.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $dateFormatter
   *   The date formatter service.
   */
  public function __construct(
    private readonly LoggerChannelInterface $logger,
    private readonly PurgeHistoryInterface $history,
    private readonly DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('logger.channel.cloudflare_purge'),
      $container->get('cloudflare_purge.history'),
      $container->get('date.formatter'),
    );
  }

  /**
   * Displays the purge history page.
   *
   * @return array<string, mixed>
   *   A render array for the history page.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException
   *   If the user doesn't have the required permission.
   */
  public function history(): array {
    // Defense-in-depth: verify permission even though routing should handle it.
    if (!$this->currentUser()->hasPermission('view cloudflare purge history')) {
      throw new AccessDeniedHttpException();
    }

    $build = [];
    $build['cloudflare_purge_nav'] = CloudflarePurgeLinks::build('cloudflare_purge.history');
    // Also attach the navigation library at page level; CloudflarePurgeLinks
    // attaches it on a cache-keyed sub-element whose bubbled #attached can be
    // served stale from the render cache, leaving the tab strip unstyled.
    $build['#attached']['library'][] = 'cloudflare_purge/navigation';

    // The page reflects live log data, so it must never be cached.
    $build['#cache']['max-age'] = 0;

    $config = $this->config('cloudflare_purge.settings');
    $loggingEnabled = (bool) $config->get('logging_enabled');
    // Clamp to the schema-allowed range as a defensive measure.
    $limit = max(1, min(1000, (int) ($config->get('history_limit') ?? 100)));

    $build['description'] = [
      '#type' => 'markup',
      '#markup' => '<p>' . $this->t('The most recent Cloudflare cache purge operations (up to @limit) from the Drupal log. <a href=":settings">Enable detailed logging</a> in Auto-Purge Settings to record successful operations and to change how many entries appear here.', [
        '@limit' => $limit,
        ':settings' => Url::fromRoute('cloudflare_purge.settings')->toString(),
      ]) . '</p>',
    ];

    // Build table header.
    $header = [
      ['data' => $this->t('Date'), 'field' => 'timestamp', 'sort' => 'desc'],
      ['data' => $this->t('Type')],
      ['data' => $this->t('Message')],
      ['data' => $this->t('User')],
    ];

    // Query watchdog for cloudflare_purge entries.
    try {
      // Check if dblog module is enabled and table exists.
      if (!$this->history->isAvailable()) {
        $build['error'] = [
          '#type' => 'markup',
          '#markup' => '<p>' . $this->t('Purge history requires the core <strong>Database Logging</strong> (dblog) module, which is not enabled. Enable it to record and view purge operations here.') . '</p>',
        ];
        return $build;
      }

      $results = $this->history->getRecentEntries($limit);

      if ($results === NULL) {
        $build['empty'] = $this->buildEmptyHistoryMessage($loggingEnabled);
        return $build;
      }

      $rows = [];

      foreach ($results as $row) {
        $message = self::formatWatchdogMessage(
          (string) $row->message,
          is_string($row->variables ?? NULL) ? $row->variables : '',
        );

        // Determine purge type from message.
        $type = $this->determinePurgeType((string) $row->message);

        $username = $this->t('System');
        if ((int) $row->uid > 0) {
          try {
            $user = $this->entityTypeManager()->getStorage('user')->load($row->uid);
            if ($user !== NULL) {
              $username = $user->getDisplayName();
            }
          }
          catch (\Exception) {
            // User may have been deleted.
            $username = $this->t('User #@uid', ['@uid' => $row->uid]);
          }
        }

        $rows[] = [
          $this->dateFormatter->format((int) $row->timestamp, 'short'),
          $type,
          $message,
          $username,
        ];
      }

      if ($rows === []) {
        $build['empty'] = $this->buildEmptyHistoryMessage($loggingEnabled);
      }
      else {
        $build['table'] = [
          '#type' => 'table',
          '#header' => $header,
          '#rows' => $rows,
          '#empty' => $this->t('No purge history found.'),
          '#attributes' => ['class' => ['cloudflare-purge-history']],
        ];
      }
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to load purge history: @message', [
        '@message' => $e->getMessage(),
      ]);

      $build['error'] = [
        '#type' => 'markup',
        '#markup' => '<p>' . $this->t('Unable to retrieve purge history. An error occurred.') . '</p>',
      ];
    }

    return $build;
  }

  /**
   * Builds the "no history yet" message render array.
   *
   * The wording depends on whether detailed logging is enabled, because
   * successful purge operations are only recorded when it is on.
   *
   * @param bool $loggingEnabled
   *   Whether detailed logging is currently enabled.
   *
   * @return array<string, mixed>
   *   A render array for the empty-state message.
   */
  private function buildEmptyHistoryMessage(bool $loggingEnabled): array {
    if ($loggingEnabled) {
      $message = $this->t('No purge operations have been recorded yet. They will appear here as purges run.');
    }
    else {
      $message = $this->t('No purge history yet. Successful purge operations are only recorded when detailed logging is enabled — <a href=":settings">enable detailed logging</a> in Auto-Purge Settings. Errors are always logged.', [
        ':settings' => Url::fromRoute('cloudflare_purge.settings')->toString(),
      ]);
    }

    return [
      '#type' => 'markup',
      '#markup' => '<p>' . $message . '</p>',
    ];
  }

  /**
   * Displays the Cloudflare plans & limits reference page.
   *
   * Read-only reference of Cloudflare's purge limits and rate limits by plan.
   * Every value links to its authoritative Cloudflare source and shows the date
   * it was last verified, so maintainers can confirm currency at a glance.
   *
   * @return array<string, mixed>
   *   A render array for the limits reference page.
   */
  public function limits(): array {
    $docs_url = CloudflarePurgeApi::PURGE_DOCS_URL;
    $tags_url = CloudflarePurgeApi::PURGE_BY_TAGS_DOCS_URL;
    $changelog_url = 'https://developers.cloudflare.com/cache/changelog/';
    $verified = $this->dateFormatter->format(
      (int) strtotime(CloudflarePurgeApi::LIMITS_LAST_VERIFIED),
      'custom',
      'F j, Y',
    );

    $build = [];
    $build['cloudflare_purge_nav'] = CloudflarePurgeLinks::build('cloudflare_purge.limits');
    // Also attach the navigation library at page level; CloudflarePurgeLinks
    // attaches it on a cache-keyed sub-element whose bubbled #attached can be
    // served stale from the render cache, leaving the tab strip unstyled.
    $build['#attached']['library'][] = 'cloudflare_purge/navigation';

    $build['intro'] = [
      '#type' => 'markup',
      '#markup' => '<p>' . $this->t('Cloudflare cache purge limits and rate limits by plan. Each value below is sourced from Cloudflare\'s official documentation and was last verified on <strong>@date</strong>. Cloudflare can change these at any time — confirm against the <a href=":docs" target="_blank" rel="noopener">authoritative documentation</a> before relying on a specific number.', [
        '@date' => $verified,
        ':docs' => $docs_url,
      ]) . '</p>',
    ];

    // Per-request operation limits.
    $build['per_request_heading'] = [
      '#type' => 'markup',
      '#markup' => '<h3>' . $this->t('Maximum operations per request') . '</h3>',
    ];
    $build['per_request'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Purge type'),
        $this->t('Free'),
        $this->t('Pro'),
        $this->t('Business'),
        $this->t('Enterprise'),
      ],
      '#rows' => [
        [$this->t('URLs (single-file)'), '100', '100', '100', '500'],
        [$this->t('Cache tags'), '100', '100', '100', '100'],
        [$this->t('Prefixes'), '100', '100', '100', '100'],
        [$this->t('Hostnames'), '100', '100', '100', '100'],
      ],
    ];
    $build['per_request_source'] = $this->sourceLine($docs_url, $verified);

    // Request rate limits (tag/prefix/hostname/purge everything).
    $build['rate_heading'] = [
      '#type' => 'markup',
      '#markup' => '<h3>' . $this->t('Request rate — tag, prefix, hostname &amp; purge everything') . '</h3>',
    ];
    $build['rate'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Plan'),
        $this->t('Request rate'),
        $this->t('Bucket size'),
        $this->t('Max ops/request'),
      ],
      '#rows' => [
        [$this->t('Free'), $this->t('5 / minute'), '25', '100'],
        [$this->t('Pro'), $this->t('5 / second'), '25', '100'],
        [$this->t('Business'), $this->t('10 / second'), '50', '100'],
        [$this->t('Enterprise'), $this->t('50 / second'), '500', '100'],
      ],
    ];
    $build['rate_source'] = $this->sourceLine($docs_url, $verified);

    // Single-file URL purge rate limits.
    $build['url_rate_heading'] = [
      '#type' => 'markup',
      '#markup' => '<h3>' . $this->t('Request rate — single-file URL purge') . '</h3>',
    ];
    $build['url_rate'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Plan'),
        $this->t('Rate'),
        $this->t('Max URLs/request'),
      ],
      '#rows' => [
        [$this->t('Free'), $this->t('800 URLs / second'), '100'],
        [$this->t('Pro'), $this->t('1,500 URLs / second'), '100'],
        [$this->t('Business'), $this->t('1,500 URLs / second'), '100'],
        [$this->t('Enterprise'), $this->t('3,000 URLs / second'), '500'],
      ],
    ];
    $build['url_rate_source'] = $this->sourceLine($docs_url, $verified);

    // Availability and Cache-Tag header notes.
    $build['notes'] = [
      '#type' => 'markup',
      '#markup' => '<h3>' . $this->t('Other facts') . '</h3>'
      . '<ul>'
      . '<li>' . $this->t('All purge methods (URL, tag, prefix, hostname, purge everything) are available on <strong>all plans including Free</strong>, since <strong>April 3, 2025</strong>. (<a href=":url" target="_blank" rel="noopener">Cloudflare changelog</a>)', [':url' => $changelog_url]) . '</li>'
      . '<li>' . $this->t('The aggregate <code>Cache-Tag</code> response header may not exceed <strong>16 KB</strong> after the header field name (roughly 1,000 tags). This module defaults to a smaller cap to stay below common web server and proxy header limits. (<a href=":url" target="_blank" rel="noopener">Purge by cache tags</a>)', [':url' => $tags_url]) . '</li>'
      . '<li>' . $this->t('This module batches larger lists automatically on every entry point (UI, Drush, and auto-purge), using a safe ceiling of 100 operations per request.') . '</li>'
      . '</ul>',
    ];

    $build['footer'] = [
      '#type' => 'markup',
      '#markup' => '<p><em>' . $this->t('Last verified on @date against Cloudflare documentation last updated April 16, 2026.', ['@date' => $verified]) . '</em></p>',
    ];

    return $build;
  }

  /**
   * Builds a small "source / last verified" line for a reference table.
   *
   * @param string $url
   *   The authoritative Cloudflare documentation URL.
   * @param string $verified
   *   The human-readable date the value was last verified.
   *
   * @return array<string, mixed>
   *   A render array for the source line.
   */
  private function sourceLine(string $url, string $verified): array {
    return [
      '#type' => 'markup',
      '#markup' => '<p class="description"><small>' . $this->t('Source: <a href=":url" target="_blank" rel="noopener">@url</a> · Last verified @date.', [
        ':url' => $url,
        '@url' => $url,
        '@date' => $verified,
      ]) . '</small></p>',
    ];
  }

  /**
   * Formats a stored watchdog message and variables for display.
   *
   * Watchdog stores the message template and serialized placeholders
   * separately. They must be combined with FormattableMarkup (same
   * substitution model as dblog).
   *
   * @param string $message
   *   The log message template from the watchdog table.
   * @param string $serializedVariables
   *   Serialized placeholder values from the watchdog table.
   *
   * @return \Drupal\Component\Render\FormattableMarkup|string
   *   Render-safe message for table output.
   */
  public static function formatWatchdogMessage(string $message, string $serializedVariables = ''): FormattableMarkup|string {
    $variables = [];
    if ($serializedVariables !== '') {
      try {
        $unserialized = unserialize($serializedVariables, ['allowed_classes' => FALSE]);
        if (is_array($unserialized)) {
          $variables = $unserialized;
        }
      }
      catch (\Throwable) {
        // Malformed serialized data - skip variables for this entry.
      }
    }

    return $variables !== []
      ? new FormattableMarkup($message, $variables)
      : $message;
  }

  /**
   * Determines the purge type from the log message.
   *
   * @param string $message
   *   The log message.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup|string
   *   The purge type label.
   */
  private function determinePurgeType(string $message): TranslatableMarkup|string {
    $messageLower = strtolower($message);

    if (str_contains($messageLower, 'everything')) {
      return $this->t('Purge Everything');
    }
    if (str_contains($messageLower, 'url')) {
      return $this->t('URL');
    }
    if (str_contains($messageLower, 'tag')) {
      return $this->t('Cache Tag');
    }
    if (str_contains($messageLower, 'prefix')) {
      return $this->t('Prefix');
    }
    if (str_contains($messageLower, 'hostname') || str_contains($messageLower, 'host')) {
      return $this->t('Hostname');
    }

    return $this->t('Unknown');
  }

}
