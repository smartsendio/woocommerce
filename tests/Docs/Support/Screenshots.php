<?php

/*
|--------------------------------------------------------------------------
| Docs suite helpers
|--------------------------------------------------------------------------
|
| The Docs suite is not correctness testing - it drives Playwright through
| real admin UI flows and captures named screenshots that get used directly
| in documentation. These helpers are the plugin-specific equivalent of
| smartsendio/dumbledore's tests/Docs/Support helpers, adapted to this
| repo's procedural test-helper style (see login_as_admin() etc. in
| tests/Pest.php) instead of dumbledore's Laravel/Livewire-specific classes.
|
*/

use Pest\Browser\Api\AwaitableWebpage;
use Pest\Browser\Api\Webpage;
use Pest\Browser\Support\Screenshot;

/**
 * The directory documentation screenshots are written to, grouped by area.
 *
 * Screenshots live at the repository root (docs/screenshots/), not inside
 * smart-send-logistics/, mirroring the existing split between the shipped
 * plugin and dev tooling - see CLAUDE.md "Screenshot storage".
 */
function docs_screenshots_root(): string
{
    return dirname(__DIR__, 3) . '/docs/screenshots';
}

/**
 * Ensure the target directory for a named screenshot exists and return the
 * full path it should be saved to. This is the "screenshot-path-ensuring"
 * helper called for in the issue, adapted from dumbledore's
 * ensureScreenshotPathExists().
 */
function ensure_screenshot_path(string $area, string $name): string
{
    $dir = docs_screenshots_root() . '/' . trim($area, '/');

    if (! is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $filename = preg_replace('/[^a-z0-9\-]+/i', '-', $name);

    return $dir . '/' . $filename . '.png';
}

/**
 * Capture a named documentation screenshot of the current page.
 *
 * Asserts assertNoJavaScriptErrors() first (matching dumbledore's
 * assertNoSmoke() convention in spirit) so a broken page never silently
 * produces a documentation screenshot, then saves it under
 * docs/screenshots/<area>/<name>.png.
 *
 * Deliberately NOT the combined assertNoSmoke() (errors + zero console
 * logs): WordPress admin pages always emit at least one benign console.log
 * (jQuery Migrate's own startup notice), so requiring zero logs would fail
 * every screenshot in this suite regardless of whether the page actually
 * works. Real breakage is what assertNoJavaScriptErrors() catches.
 *
 * pest-plugin-browser only knows how to save screenshots under the fixed
 * tests/Browser/Screenshots directory (Pest\Browser\Support\Screenshot::dir()
 * is hardcoded), so this captures under a throwaway name there first and
 * moves the file into place.
 */
function capture_doc_screenshot(Webpage|AwaitableWebpage $page, string $area, string $name, bool $fullPage = true): string
{
    $page->assertNoJavaScriptErrors();

    $temporaryName = 'docs-' . str_replace('/', '-', $area) . '-' . uniqid();

    $page->screenshot($fullPage, $temporaryName);

    $target = ensure_screenshot_path($area, $name);

    rename(Screenshot::path($temporaryName), $target);

    return $target;
}

/**
 * Run a PHP snippet inside the store via WP-CLI, discarding its output.
 *
 * Same wp-cli.phar-in-the-store pattern as
 * tests/Browser/SmartSendFlowsTest.php's ss_browser_wp_eval() - reused here
 * (rather than shared) since the two suites' Pest bootstraps don't load each
 * other's files. Used to reset store state directly instead of through the
 * browser: a UI-driven "click Delete until the page stops mentioning the
 * method" loop is unreliable against a client-side-rendered admin screen,
 * where class names and label text from the JS bundle are present in the
 * page source regardless of whether anything is actually rendered - see the
 * ShippingMethod test's history for the two ways that bit this suite.
 */
function docs_wp_eval(string $code): void
{
    $wpPath = rtrim(getenv('WP_DEV_PATH') ?: dirname(__DIR__, 3) . '/local-dev/wordpress', '/');

    $snippet = tempnam(sys_get_temp_dir(), 'ss-docs-eval-') . '.php';
    file_put_contents($snippet, "<?php\n" . $code);

    $command = escapeshellarg(PHP_BINARY)
        . ' -d memory_limit=512M -d error_reporting=0 -d display_errors=0 '
        . escapeshellarg($wpPath . '/.wp-cli/wp-cli.phar')
        . ' --path=' . escapeshellarg($wpPath)
        . ' eval-file ' . escapeshellarg($snippet) . ' 2>/dev/null';

    shell_exec($command);
    unlink($snippet);
}

/**
 * Remove every shipping method configured on the given zone, directly
 * through WooCommerce's own API rather than the admin UI - see
 * docs_wp_eval()'s docblock for why.
 */
function docs_clear_shipping_zone_methods(int $zoneId): void
{
    docs_wp_eval(<<<PHP
        \$zone = new WC_Shipping_Zone({$zoneId});
        foreach (\$zone->get_shipping_methods() as \$method) {
            \$zone->delete_shipping_method(\$method->instance_id);
        }
        PHP);
}

/**
 * Set a text input's value directly via JavaScript and dispatch input/change
 * events, bypassing pest-plugin-browser's fill().
 *
 * WooCommerce's shipping zone method screen re-renders the settings form
 * after certain background activity (observed: the "Action Scheduler
 * past-due actions" admin notice visible on this screen implies some
 * periodic re-fetch/re-render is active), which appears to reset the title
 * field back to its default moments after fill() sets it. Playwright's
 * fill() verifies the value stuck and retries if not, so against a field
 * that keeps getting reset it polls forever rather than failing - exactly
 * the hang this sidesteps. Setting the value directly and dispatching the
 * events WooCommerce's own JS listens for gets the same practical result
 * (the field holds the value long enough to screenshot and save) without
 * relying on Playwright's retry loop ever seeing a stable value.
 */
function set_input_value(Webpage|AwaitableWebpage $page, string $selector, string $value): void
{
    $encodedSelector = json_encode($selector);
    $encodedValue = json_encode($value);

    $page->script(
        <<<JS
        (function () {
            var el = document.querySelector({$encodedSelector});
            if (! el) {
                return false;
            }
            el.focus();
            el.value = {$encodedValue};
            el.dispatchEvent(new Event('input', { bubbles: true }));
            el.dispatchEvent(new Event('change', { bubbles: true }));
            el.blur();
            return true;
        })();
        JS
    );
}

/**
 * Draw a spotlight highlight around the given element, for screenshots that
 * call out a specific field. Adapted from dumbledore's highlightElement(),
 * using pest-plugin-browser's Webpage::script() (Playwright evaluate) rather
 * than dumbledore's Livewire-specific JS.
 */
function highlight_element(Webpage|AwaitableWebpage $page, string $selector): void
{
    $encodedSelector = json_encode($selector);

    $page->script(
        <<<JS
        (function () {
            var el = document.querySelector({$encodedSelector});
            if (! el) {
                return false;
            }
            el.scrollIntoView({ block: 'center', inline: 'center' });
            el.style.outline = '4px solid #ff5a1f';
            el.style.outlineOffset = '2px';
            el.style.boxShadow = '0 0 0 9999px rgba(0, 0, 0, 0.35)';
            el.style.position = el.style.position || 'relative';
            el.style.zIndex = '9999';
            return true;
        })();
        JS
    );
}
