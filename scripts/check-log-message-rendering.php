#!/usr/bin/env php
<?php

/**
 * @file
 * Scans module sources for incorrect watchdog log message rendering.
 *
 * Run from the module root:
 *   php scripts/check-log-message-rendering.php.
 *
 * Exit code 0 = pass, 1 = violations found.
 */

declare(strict_types=1);

$moduleRoot = dirname(__DIR__);
$srcDir = $moduleRoot . '/src';

if (!is_dir($srcDir)) {
  fwrite(STDERR, "Source directory not found: {$srcDir}\n");
  exit(1);
}

/**
 * Banned rendering patterns to detect in source files.
 *
 * @var list<array{id: string, regex: string, message: string}> $bannedPatterns
 */
$bannedPatterns = [
  [
    'id' => 'nested-message-placeholder',
    'regex' => '/\$this->t\(\s*[\'"]@message[\'"]\s*,/',
    'message' => 'Do not wrap watchdog messages in $this->t(\'@message\', ...). Placeholders inside the stored message will not be replaced. Use CloudflarePurgeController::formatWatchdogMessage() or FormattableMarkup with the stored message and unserialized variables.',
  ],
  [
    'id' => 'nested-message-placeholder-t-function',
    'regex' => '/\bt\(\s*[\'"]@message[\'"]\s*,/',
    'message' => 'Do not wrap watchdog messages in t(\'@message\', ...). Use formatWatchdogMessage() or FormattableMarkup instead.',
  ],
];

$violations = [];
$iterator = new RecursiveIteratorIterator(
  new RecursiveDirectoryIterator($srcDir, FilesystemIterator::SKIP_DOTS),
);

foreach ($iterator as $file) {
  if (!$file->isFile() || $file->getExtension() !== 'php') {
    continue;
  }

  $path = $file->getPathname();
  $contents = file_get_contents($path);
  if ($contents === FALSE) {
    continue;
  }

  $relative = substr($path, strlen($moduleRoot) + 1);
  $lines = explode("\n", $contents);

  foreach ($bannedPatterns as $pattern) {
    foreach ($lines as $lineNumber => $line) {
      if (preg_match($pattern['regex'], $line) === 1) {
        $violations[] = [
          'file' => $relative,
          'line' => $lineNumber + 1,
          'id' => $pattern['id'],
          'message' => $pattern['message'],
        ];
      }
    }
  }

  // Watchdog display code must use the shared formatter.
  $readsWatchdogMessage = (bool) preg_match(
    '/\$row->message|\$dblog->message|\$log->message/',
    $contents,
  );
  $queriesWatchdog = (bool) preg_match(
    "/(?:select|->fields)\([^)]*['\"]watchdog['\"]|->from\([^)]*['\"]watchdog['\"]/i",
    $contents,
  );

  if ($readsWatchdogMessage && $queriesWatchdog) {
    $usesApprovedFormatter = (bool) preg_match(
      '/formatWatchdogMessage|FormattableMarkup\s*\(\s*\(string\)\s*\$row->message|FormattableMarkup\s*\(\s*\$row->message|->t\(\s*Xss::filterAdmin\(\s*\$row->message/',
      $contents,
    );
    if (!$usesApprovedFormatter) {
      $violations[] = [
        'file' => $relative,
        'line' => 0,
        'id' => 'watchdog-message-without-formatter',
        'message' => 'This file queries watchdog and uses $row->message but does not call formatWatchdogMessage(), FormattableMarkup($row->message, ...), or dblog-style $this->t(Xss::filterAdmin($row->message), $variables).',
      ];
    }
  }
}

if ($violations === []) {
  fwrite(STDOUT, "OK: No watchdog log message rendering issues found in src/.\n");
  exit(0);
}

fwrite(STDERR, "Watchdog log message rendering check failed:\n\n");
foreach ($violations as $violation) {
  $location = $violation['line'] > 0
    ? "{$violation['file']}:{$violation['line']}"
    : $violation['file'];
  fwrite(STDERR, "  [{$violation['id']}] {$location}\n");
  fwrite(STDERR, "    {$violation['message']}\n\n");
}

exit(1);
