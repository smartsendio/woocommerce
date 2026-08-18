<?php

use Pest\Browser\Playwright\Playwright;

/*
|--------------------------------------------------------------------------
| Pest configuration
|--------------------------------------------------------------------------
|
| The browser tests run against a local development store created by
| bin/setup-local-dev.sh. Point WP_BASE_URL at the store (and adjust the
| admin credentials) if you deviate from the script's defaults.
|
*/

// Bound every browser operation so a broken page fails the test instead of
// hanging the suite (observed in CI after an fpm worker crash).
pest()->browser()->timeout(15_000);

/*
|--------------------------------------------------------------------------
| Docs suite
|--------------------------------------------------------------------------
|
| Drives Playwright through real admin UI flows and captures named
| screenshots for documentation - see tests/Docs/Support/Screenshots.php.
|
| This bootstrap lives here rather than in a tests/Docs/Pest.php: Pest does
| not load a directory-level Pest.php for test files that sit in a
| subdirectory of it (confirmed the hard way - see the ShippingMethod test's
| git history), so a Pest.php any deeper than tests/ itself is silently
| never executed. Everything Docs-specific is registered here instead, with
| the headed-mode switch scoped to the Docs directory via uses()->in() so it
| does not affect the Browser suite.
|
*/

require __DIR__ . '/Docs/Support/Screenshots.php';

/*
|--------------------------------------------------------------------------
| Shared support helpers
|--------------------------------------------------------------------------
|
| Store-management helpers for the Browser suite (WP-CLI seeding, the API
| mock) and the shipping-method admin UI steps shared between the Browser
| and Docs suites. Loaded here because Pest loads every test file into one
| process - a helper function defined in two test files would collide.
|
*/

require __DIR__ . '/Browser/Support/SmartSendStore.php';
require __DIR__ . '/Support/ShippingMethodSteps.php';

uses()->beforeEach(function (): void {
    if (! getenv('CI') && ! getenv('SS_DOCS_HEADLESS')) {
        Playwright::headed();
    }
})->afterEach(function (): void {
    // In slow-motion mode (SS_DOCS_SLOWMO), also pause AFTER the last
    // assertion of each test - the final UI state is usually the one worth
    // seeing, and without this the browser closes the moment it is reached.
    docs_slowmo_pause();
})->in('Docs');

/*
|--------------------------------------------------------------------------
| Integration suite
|--------------------------------------------------------------------------
|
| Runs against an in-process WordPress + WooCommerce (loaded by
| tests/bootstrap.php from the bin/setup-local-dev.sh install). The suite
| shares the development database, so fixtures built via the factories in
| tests/Integration/Helpers.php are force-deleted after every test.
|
*/

require __DIR__ . '/Integration/Helpers.php';

uses()->afterEach(function (): void {
    cleanup_created_objects();
})->in('Integration');

function base_url(string $path = '/'): string
{
    $base = getenv('WP_BASE_URL') ?: 'http://127.0.0.1:8181';

    return rtrim($base, '/') . $path;
}

function admin_username(): string
{
    return getenv('WP_ADMIN_USER') ?: 'admin';
}

function admin_password(): string
{
    return getenv('WP_ADMIN_PASS') ?: 'password';
}

function login_as_admin()
{
    return visit(base_url('/wp-login.php'))
        ->fill('#user_login', admin_username())
        ->fill('#user_pass', admin_password())
        ->click('#wp-submit')
        ->assertPathContains('wp-admin');
}
