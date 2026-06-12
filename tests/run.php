<?php

declare(strict_types=1);

/**
 * Test runner for the ScreenOver PHP SDK (no Composer / PHPUnit required).
 *
 * Usage:
 *   php tests/run.php              # unit tests, plus integration if creds are set
 *   php tests/run.php unit         # offline unit tests only
 *   php tests/run.php integration  # live API tests only (needs SCREENOVER_API_KEY)
 *
 * Exit code is 0 when everything passed, 1 otherwise.
 */

require __DIR__ . '/bootstrap.php';

$mode = $argv[1] ?? 'all';
$valid = ['all', 'unit', 'integration'];
if (!in_array($mode, $valid, true)) {
    fwrite(STDERR, "Unknown mode '$mode'. Use one of: " . implode(', ', $valid) . "\n");
    exit(2);
}

/**
 * @return array<int,string>
 */
function discover(string $dir): array
{
    $files = glob($dir . '/*.php') ?: [];
    sort($files);
    return $files;
}

echo "ScreenOver SDK test suite\n";
echo "=========================\n";

if ($mode === 'all' || $mode === 'unit') {
    foreach (discover(__DIR__ . '/unit') as $file) {
        require $file;
    }
}

if ($mode === 'integration' || ($mode === 'all' && Credentials::hasApiKey())) {
    foreach (discover(__DIR__ . '/integration') as $file) {
        require $file;
    }
} elseif ($mode === 'all') {
    section('LIVE integration (real backend)');
    skip('live integration suite', 'set SCREENOVER_API_KEY (or tests/.env) to enable');
}

echo "\n-------------------------\n";
printf(
    "Passed: %d   Failed: %d   Skipped: %d\n",
    TestContext::$passed,
    TestContext::$failed,
    TestContext::$skipped
);

if (TestContext::$failed > 0) {
    echo "\nFailures:\n";
    foreach (TestContext::$failures as $failure) {
        echo "  - $failure\n";
    }
    echo "\nRESULT: FAILED\n";
    exit(1);
}

echo "\nRESULT: ALL TESTS PASSED\n";
exit(0);
