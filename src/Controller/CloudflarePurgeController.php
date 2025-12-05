<?php

declare(strict_types=1);

namespace Drupal\cloudflare_purge\Controller;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Url;
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
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $dateFormatter
   *   The date formatter service.
   */
  public function __construct(
    private readonly LoggerChannelInterface $logger,
    private readonly Connection $database,
    private readonly DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('logger.channel.cloudflare_purge'),
      $container->get('database'),
      $container->get('date.formatter'),
    );
  }

  /**
   * Displays the purge history page.
   *
   * @return array<string, mixed>
   *   A render array for the history page.
   */
  public function history(): array {
    $build = [];

    $build['description'] = [
      '#type' => 'markup',
      '#markup' => '<p>' . $this->t('Recent Cloudflare cache purge operations from the Drupal log. <a href=":settings">Enable detailed logging</a> in Auto-Purge Settings to see more entries.', [
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
      if (!$this->database->schema()->tableExists('watchdog')) {
        $build['error'] = [
          '#type' => 'markup',
          '#markup' => '<p>' . $this->t('Database logging is not enabled. Enable the Database Logging module to track purge history.') . '</p>',
        ];
        return $build;
      }

      $query = $this->database->select('watchdog', 'w')
        ->fields('w', ['wid', 'uid', 'type', 'message', 'variables', 'severity', 'timestamp'])
        ->condition('w.type', 'cloudflare_purge')
        ->orderBy('w.timestamp', 'DESC')
        ->range(0, 100)
        ->extend('Drupal\Core\Database\Query\TableSortExtender');

      $results = $query->execute();

      if ($results === NULL) {
        $build['empty'] = [
          '#type' => 'markup',
          '#markup' => '<p>' . $this->t('No purge history found.') . '</p>',
        ];
        return $build;
      }

      $rows = [];

      foreach ($results as $row) {
        $variables = [];
        if (!empty($row->variables) && is_string($row->variables)) {
          try {
            $unserialized = unserialize($row->variables, ['allowed_classes' => FALSE]);
            if (is_array($unserialized)) {
              $variables = $unserialized;
            }
          }
          catch (\Throwable) {
            // Malformed serialized data - skip variables for this entry.
          }
        }

        $message = $variables !== []
          ? $this->t('@message', ['@message' => $row->message] + $variables)
          : $row->message;

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
        $build['empty'] = [
          '#type' => 'markup',
          '#markup' => '<p>' . $this->t('No purge history found. Enable detailed logging to track purge operations.') . '</p>',
        ];
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
