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
