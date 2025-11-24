<?php

/**
 * @file
 */

declare(strict_types=1);

/**
 * Bootstrap file for PHPUnit tests.
 */


// Determine Drupal root directory.
$root = dirname(__DIR__, 4);

// Check if we're in the right location.
if (!file_exists($root . '/autoload.php')) {
  echo "Error: Could not find Drupal autoloader at: {$root}/autoload.php\n";
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
