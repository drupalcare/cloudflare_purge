<?php

/**
 * @file
 * Bootstrap file for PHPUnit tests.
 */

declare(strict_types=1);

// Determine Drupal root directory. Prefer an explicit value so tests also work
// when this module is checked out outside a Drupal project's modules directory.
$root = getenv('DRUPAL_ROOT');

if (!is_string($root) || $root === '' || !file_exists($root . '/autoload.php')) {
  $root = NULL;
  $directory = __DIR__;
  while ($directory !== dirname($directory)) {
    if (file_exists($directory . '/autoload.php') && file_exists($directory . '/core/tests/bootstrap.php')) {
      $root = $directory;
      break;
    }
    $directory = dirname($directory);
  }
}

if (!is_string($root) || !file_exists($root . '/autoload.php')) {
  echo "Error: Could not find Drupal autoloader. Set DRUPAL_ROOT to your Drupal web root.\n";
  exit(1);
}

// Include Drupal's autoloader.
$autoloader = require $root . '/autoload.php';

// Set DRUPAL_TEST_IN_CHILD_SITE to FALSE to prevent issues.
if (!defined('DRUPAL_TEST_IN_CHILD_SITE')) {
  define('DRUPAL_TEST_IN_CHILD_SITE', FALSE);
}

// Include Drupal's test bootstrapping if available.
$core_bootstrap = $root . '/core/tests/bootstrap.php';
if (file_exists($core_bootstrap)) {
  require $core_bootstrap;
}

return $autoloader;
