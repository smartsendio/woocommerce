<?php

/*
|--------------------------------------------------------------------------
| Shared shipping-method admin UI steps
|--------------------------------------------------------------------------
|
| The UI walk for putting a Smart Send shipping method on a WooCommerce
| shipping zone (zone screen -> add method -> settings form -> save) is
| driven by two suites: tests/Docs captures screenshots of each step, and
| tests/Browser/ShippingMethodSetupTest.php asserts the walk actually
| configures a working method. The step logic and its hard-won selector
| knowledge live here once (#146) so the two suites cannot drift apart.
|
| Loaded from tests/Pest.php. Every helper operates on an already-visited
| page object: pest-plugin-browser only recognises a test as a browser test
| when the test's own closure literally calls visit() (or the file lives
| under tests/Browser/), so the Docs tests keep their inline visit()/login
| chains and hand the resulting page to these steps.
|
*/

use Pest\Browser\Api\AwaitableWebpage;
use Pest\Browser\Api\Webpage;

/**
 * The admin URL of a shipping zone's edit screen.
 */
function ss_zone_page_url(int $zoneId): string
{
    return base_url('/wp-admin/admin.php?page=wc-settings&tab=shipping&zone_id=' . $zoneId);
}

/**
 * From the zone screen, open the add-method dialog and add the Smart Send
 * method to the zone.
 *
 * The radio input itself (#smart_send_shipping) is visually hidden -
 * WooCommerce's own add-method dialog (wc-backbone-modal-add-shipping-method)
 * pairs it with a clickable <label for="smart_send_shipping"> as the
 * actual interactive surface. Clicking the input directly waits forever
 * for an element that can never become actionable; click the label
 * instead, same as a real user would.
 */
function ss_step_add_smart_send_method(Webpage|AwaitableWebpage $page): void
{
    $page->click('Add shipping method');

    // Two generations of WooCommerce's add-method dialog are in the
    // supported range: the Backbone dialog of WooCommerce < 8.3 (a
    // <select name="add_method_id"> plus an "Add shipping method" #btn-ok
    // button) and the redesigned one (radio cards + "Continue"). Detect the
    // old one once the dialog is up and drive it directly - waiting on the
    // new dialog's label there never returns (pest-plugin-browser clicks
    // have no action timeout), which hung the WooCommerce 8.2 floor leg.
    $legacyDialog = ss_wait_for_script($page, <<<'JS'
        (function () {
            if (document.querySelector('select[name="add_method_id"]')) { return 'legacy'; }
            if (document.querySelector('label[for="smart_send_shipping"]')) { return 'current'; }
            return false;
        })()
        JS
    );

    if ('legacy' === $legacyDialog) {
        $page->script(<<<'JS'
            (function () {
                var select = document.querySelector('select[name="add_method_id"]');
                select.value = 'smart_send_shipping';
                select.dispatchEvent(new Event('change', { bubbles: true }));
                document.querySelector('.wc-backbone-modal #btn-ok').click();
            })();
            JS
        );
    } else {
        $page->click('label[for="smart_send_shipping"]')
            ->click('Continue');
    }

    $page->assertSee('Smart Send');
}

/**
 * Poll a page-side expression until it returns a truthy value (returned)
 * or the timeout passes (RuntimeException). The browser plugin's own
 * waits have no deadline, so version detection and similar "which UI is
 * this" probes go through this instead of an element wait that may never
 * resolve.
 */
function ss_wait_for_script(Webpage|AwaitableWebpage $page, string $expression, int $timeoutSeconds = 10)
{
    $deadline = microtime(true) + $timeoutSeconds;

    do {
        $result = $page->script($expression);
        if ($result) {
            return $result;
        }
        usleep(200000);
    } while (microtime(true) < $deadline);

    throw new RuntimeException("Page-side probe did not become truthy within {$timeoutSeconds}s: " . trim($expression));
}

/**
 * From the zone screen, open the Smart Send method's settings form.
 *
 * Assumes the Smart Send method is the only method on the zone - with
 * several methods the 'Edit' link text is ambiguous.
 */
function ss_step_open_method_settings(Webpage|AwaitableWebpage $page): void
{
    // WooCommerce < 8.3 renders the zone's method table with WordPress
    // list-table row actions: "Edit | Delete" sit at left:-9999em until the
    // row is hovered, so a Playwright click on the link (which waits for it
    // to be in view and hit-testable) never resolves - the hang behind the
    // WooCommerce 8.2 floor leg's timeout. Click the link page-side instead;
    // that also covers the redesigned table, whose link is always visible.
    ss_wait_for_script($page, <<<'JS'
        (function () {
            var table = document.querySelector('.wc-shipping-zone-methods') || document;
            var links = Array.prototype.filter.call(table.querySelectorAll('a, button'), function (element) {
                return element.textContent.trim() === 'Edit';
            });
            if (links.length !== 1) { return false; }
            links[0].click();
            return true;
        })()
        JS
    );

    $page->assertSee('Method Title');
}

/**
 * Fill the open method settings form: title and carrier method.
 *
 * ss_set_input_value(), not fill(), for the title - see its docblock: this
 * screen keeps resetting the title field back to its default shortly after
 * fill() sets it, and fill()'s built-in verify-and-retry then polls forever
 * against a value that never stays put.
 */
function ss_step_fill_method_settings(Webpage|AwaitableWebpage $page, string $title, string $method): void
{
    ss_set_input_value($page, '#woocommerce_smart_send_shipping_title', $title);
    $page->select('woocommerce_smart_send_shipping_method', $method);
}

/**
 * Fill one row of the open settings form's cost-per-weight table.
 * ss_set_input_value() for the same re-render reason as the title field.
 */
function ss_step_fill_weight_row(Webpage|AwaitableWebpage $page, int $row, string $min, string $max, string $cost): void
{
    ss_set_input_value($page, 'input[name="ss_min_weight[' . $row . ']"]', $min);
    ss_set_input_value($page, 'input[name="ss_max_weight[' . $row . ']"]', $max);
    ss_set_input_value($page, 'input[name="ss_cost_weight[' . $row . ']"]', $cost);
}

/**
 * Save the open method settings form and wait for WooCommerce's saved
 * notice. Saving stays on the settings form (it does not return to the
 * zone's method list), so the notice is the reliable completion signal.
 */
function ss_step_save_method_settings(Webpage|AwaitableWebpage $page): void
{
    $page->click('Save changes')->assertSee('Your settings have been saved.');
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
function ss_set_input_value(Webpage|AwaitableWebpage $page, string $selector, string $value): void
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
