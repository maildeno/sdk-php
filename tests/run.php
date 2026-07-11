<?php

declare(strict_types=1);

/**
 * Zero-dependency test runner.
 *
 *   php tests/run.php
 *   php tests/run.php cache      # run only files matching "cache"
 *
 * The client suite needs a maildeno-engine binary bundled for the current
 * platform (bin/<platform>/) and SKIPs gracefully if there isn't one.
 */

require __DIR__ . '/../autoload.php';
require __DIR__ . '/helpers.php';

$filter = $argv[1] ?? null;

$files = \glob(__DIR__ . '/*_test.php');
\sort($files);

foreach ($files as $file) {
    if ($filter !== null && !\str_contains(\basename($file), $filter)) {
        continue;
    }
    echo "\n==== " . \basename($file) . " ====\n";
    require $file;
}

echo "\n" . \str_repeat('-', 40) . "\n";
\printf("TOTAL: %d passed, %d failed, %d skipped\n", T::$pass, T::$fail, T::$skip);

if (T::$fail > 0) {
    echo "\nFailures:\n";
    foreach (T::$failures as $f) {
        echo "  - {$f}\n";
    }
    exit(1);
}

echo "ALL TESTS PASSED\n";
exit(0);
