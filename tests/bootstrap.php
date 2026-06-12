<?php

/**
 * Zero-dependency test harness for the ScreenOver PHP SDK.
 *
 * No Composer / PHPUnit required: this file provides a tiny assertion API
 * (test(), skip(), assert*()) and a result accumulator. Each test file is a
 * plain script that calls test('label', fn). Run everything with:
 *
 *     php tests/run.php                 # unit always, integration if creds set
 *     php tests/run.php unit            # offline unit tests only
 *     php tests/run.php integration     # live API tests only (needs creds)
 *
 * Credentials for integration tests are read from environment variables (see
 * tests/support/credentials.php) or a tests/.env file.
 */

declare(strict_types=1);

require __DIR__ . '/../autoload.php';
require __DIR__ . '/support/AssertionFailed.php';
require __DIR__ . '/support/FakeClient.php';
require __DIR__ . '/support/credentials.php';

final class TestContext
{
    public static int $passed = 0;
    public static int $failed = 0;
    public static int $skipped = 0;
    /** @var array<int,string> */
    public static array $failures = [];
}

/**
 * Register and immediately run a single test.
 */
function test(string $name, callable $fn): void
{
    try {
        $fn();
        TestContext::$passed++;
        printf("  [PASS] %s\n", $name);
    } catch (AssertionFailed $e) {
        TestContext::$failed++;
        TestContext::$failures[] = $name . ' — ' . $e->getMessage();
        printf("  [FAIL] %s\n         %s\n", $name, $e->getMessage());
    } catch (\Throwable $e) {
        TestContext::$failed++;
        $detail = get_class($e) . ': ' . $e->getMessage();
        TestContext::$failures[] = $name . ' — unexpected ' . $detail;
        printf("  [FAIL] %s\n         unexpected %s\n", $name, $detail);
    }
}

function skip(string $name, string $reason): void
{
    TestContext::$skipped++;
    printf("  [SKIP] %s (%s)\n", $name, $reason);
}

function section(string $title): void
{
    printf("\n== %s ==\n", $title);
}

/**
 * @param mixed $expected
 * @param mixed $actual
 */
function assertSame($expected, $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        throw new AssertionFailed(
            ($msg !== '' ? $msg . ': ' : '')
            . 'expected ' . var_export($expected, true)
            . ' but got ' . var_export($actual, true)
        );
    }
}

/**
 * @param mixed $expected
 * @param mixed $actual
 */
function assertEquals($expected, $actual, string $msg = ''): void
{
    if ($expected != $actual) {
        throw new AssertionFailed(
            ($msg !== '' ? $msg . ': ' : '')
            . 'expected ' . var_export($expected, true)
            . ' but got ' . var_export($actual, true)
        );
    }
}

function assertTrue($cond, string $msg = ''): void
{
    if ($cond !== true) {
        throw new AssertionFailed($msg !== '' ? $msg : 'expected true, got ' . var_export($cond, true));
    }
}

function assertFalse($cond, string $msg = ''): void
{
    if ($cond !== false) {
        throw new AssertionFailed($msg !== '' ? $msg : 'expected false, got ' . var_export($cond, true));
    }
}

/**
 * @param mixed $value
 */
function assertNotEmpty($value, string $msg = ''): void
{
    if (empty($value)) {
        throw new AssertionFailed($msg !== '' ? $msg : 'expected a non-empty value');
    }
}

/**
 * @param array<mixed> $array
 * @param array-key $key
 */
function assertArrayHasKey($key, array $array, string $msg = ''): void
{
    if (!array_key_exists($key, $array)) {
        throw new AssertionFailed(
            ($msg !== '' ? $msg . ': ' : '') . 'missing key ' . var_export($key, true)
            . ' in ' . var_export(array_keys($array), true)
        );
    }
}

function assertStringContains(string $needle, string $haystack, string $msg = ''): void
{
    if (strpos($haystack, $needle) === false) {
        throw new AssertionFailed(
            ($msg !== '' ? $msg . ': ' : '') . var_export($needle, true)
            . ' not found in ' . var_export($haystack, true)
        );
    }
}

/**
 * Assert that $fn throws an exception of the given class.
 */
function assertThrows(string $expectedClass, callable $fn, string $msg = ''): \Throwable
{
    try {
        $fn();
    } catch (\Throwable $e) {
        if (!($e instanceof $expectedClass)) {
            throw new AssertionFailed(
                ($msg !== '' ? $msg . ': ' : '') . 'expected ' . $expectedClass
                . ' but got ' . get_class($e) . ' (' . $e->getMessage() . ')'
            );
        }
        return $e;
    }
    throw new AssertionFailed(
        ($msg !== '' ? $msg . ': ' : '') . 'expected ' . $expectedClass . ' to be thrown, nothing was'
    );
}
