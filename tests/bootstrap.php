<?php

/*
|--------------------------------------------------------------------------
| PHPUnit / Pest bootstrap
|--------------------------------------------------------------------------
|
| The Browser and Docs suites both talk to a running store over HTTP and
| must not load WordPress into the test process. The Integration suite runs
| against an in-process WordPress + WooCommerce, bootstrapped from the
| testing install created by bin/setup-local-dev.sh --env testing.
|
| The store location comes from .env.testing at the repo root (WP_PATH /
| WP_URL, written by the setup script; composer test:* rebuilds that store
| fresh via bin/run-tests.sh). Real environment variables override the file,
| and the legacy WP_DEV_PATH / WP_BASE_URL names are still honoured.
|
| WordPress has to be loaded in global scope before any test runs, so the
| decision is made here from the requested test suite.
|
*/

require __DIR__ . '/../vendor/autoload.php';

// Push WP_PATH / WP_URL from .env.testing into the environment (real env vars
// win). Runs before the suite check so tests/Pest.php's base_url() sees them.
(function (): void {
    $envFile = __DIR__ . '/../.env.testing';
    if (! is_readable($envFile)) {
        return;
    }
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#') || ! str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        if (in_array($key, ['WP_PATH', 'WP_URL'], true) && getenv($key) === false) {
            putenv($key . '=' . trim($value));
        }
    }
})();

$suite = null;
$argv  = $_SERVER['argv'] ?? [];
foreach ($argv as $i => $arg) {
    if ($arg === '--testsuite') {
        $suite = $argv[$i + 1] ?? null;
    } elseif (str_starts_with($arg, '--testsuite=')) {
        $suite = substr($arg, strlen('--testsuite='));
    }
}

if ($suite === 'Browser' || $suite === 'Docs') {
    return;
}

$wpPath = getenv('WP_PATH') ?: getenv('WP_DEV_PATH') ?: './local-dev/wordpress';
if (! str_starts_with($wpPath, '/')) {
    // Relative paths (as written in .env.testing) resolve against the repo root.
    $wpPath = __DIR__ . '/../' . $wpPath;
}
$wpLoad = rtrim($wpPath, '/') . '/wp-load.php';

if (! file_exists($wpLoad)) {
    fwrite(STDERR, <<<MSG
    The Integration suite needs a local WordPress + WooCommerce install, but none
    was found at: {$wpPath}

    Create one with:  bin/setup-local-dev.sh --env testing
    Or point WP_PATH at an existing install created by that script.
    To run only the browser tests: vendor/bin/pest --testsuite=Browser

    MSG);
    exit(1);
}

// WordPress and WooCommerce expect a web-request-like environment; mirror the
// server variables WP-CLI sets up, derived from the store's base URL.
$base = getenv('WP_URL') ?: getenv('WP_BASE_URL') ?: 'http://localhost:8181';
$host = parse_url($base, PHP_URL_HOST) ?: 'localhost';
$port = parse_url($base, PHP_URL_PORT);

$_SERVER['HTTP_HOST']       = $host . ($port ? ':' . $port : '');
$_SERVER['SERVER_NAME']     = $host;
$_SERVER['SERVER_PORT']     = (string) ($port ?: 80);
$_SERVER['REQUEST_URI']     = '/';
$_SERVER['REQUEST_METHOD']  = 'GET';
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
$_SERVER['REMOTE_ADDR']     = '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = 'Pest Integration Suite';

define('WP_USE_THEMES', false);

require_once $wpLoad;

/*
|--------------------------------------------------------------------------
| Warnings-to-failures (plugin code only)
|--------------------------------------------------------------------------
|
| Any PHP warning, notice, or deprecation raised from a file inside
| smart-send-logistics/ is converted into an ErrorException so the test
| that triggered it fails. Noise from WordPress core, WooCommerce, or
| other plugins is left to PHPUnit's regular reporting. Registered after
| wp-load.php on purpose: loading WordPress itself is not ours to police.
|
*/

const SS_STRICT_ERRNO = E_WARNING | E_NOTICE | E_DEPRECATED | E_USER_WARNING | E_USER_NOTICE | E_USER_DEPRECATED;

function ss_strict_error_handler(int $errno, string $errstr, string $errfile = '', int $errline = 0): bool
{
    if (! (error_reporting() & $errno)) {
        return false; // @-suppressed
    }

    $pluginDir = DIRECTORY_SEPARATOR . 'smart-send-logistics' . DIRECTORY_SEPARATOR;

    if (($errno & SS_STRICT_ERRNO) && str_contains($errfile, $pluginDir)) {
        throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
    }

    return false; // defer to the default / PHPUnit handler
}

set_error_handler('ss_strict_error_handler');

// Keep database driver noise out of the test output. With the SQLite dev
// store, WooCommerce's coupon-hold "SELECT ... FOR UPDATE" queries fail (the
// driver does not support row locks) and would otherwise print a full error
// dump per query; WooCommerce treats the failed lookup as zero held coupons,
// which is fine for tests.
$GLOBALS['wpdb']->suppress_errors();
