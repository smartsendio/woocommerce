<?php

/*
|--------------------------------------------------------------------------
| Guides -> Shipping -> Configure a Smart Send shipping method
|--------------------------------------------------------------------------
|
| Screenshots for the "add Smart Send to a shipping zone" documentation
| flow: WooCommerce -> Settings -> Shipping -> a zone -> add the Smart Send
| method -> pick a carrier method and title it -> save -> confirm it shows
| up in the zone's method list.
|
| The method picker (radio list) and the carrier "Shipping Method" dropdown
| are the two steps merchants most often get stuck on - the picker because
| "Smart Send" sits below the built-in methods and is easy to miss, the
| dropdown because it lists every carrier/service combination Smart Send
| supports and merchants need to find their own contract's method. Both get
| a highlighted screenshot.
|
| One test per UI state, each producing its own named screenshot under
| docs/screenshots/ShippingMethod/ (see tests/Docs/Support/Screenshots.php).
| pest-plugin-browser resets the browser after every test, so each test
| re-navigates to the state it documents rather than continuing on from the
| previous one - only the WordPress database carries state across tests.
|
| Each test opens with the same explicit
| visit()->fill()->fill()->click()->navigate() chain rather than delegating
| to a shared login helper. pest-plugin-browser only recognises a test as a
| browser test - and boots the Playwright server for it - when the test's
| own closure literally calls visit(), or the file lives under
| tests/Browser/ (see Pest\Browser\Support\BrowserTestIdentifier). Neither
| is true for a call to a helper function defined elsewhere, so the visit()
| call has to stay inline in every test here.
|
| Runs against the "Denmark" shipping zone that bin/setup-local-dev.sh
| seeds by default. beforeAll snapshots the zone's real methods (the seeded
| Flat rate) and clears the zone - the "empty zone" screenshot needs it
| empty, and any Smart Send method left over from a previous run would get
| duplicated by the "add method" test - and afterAll restores the snapshot,
| so a Docs run leaves the local store the way it found it (a zone stripped
| of its Flat rate broke the Browser suite's flat-rate storefront test until
| someone restored it by hand).
|
*/

beforeAll(function (): void {
    if (!ss_browser_store_manageable()) {
        return;
    }

    // Through WooCommerce's own API (WP-CLI, see the helper's docblock)
    // rather than by scripting the admin UI - the zone screen is
    // client-side rendered, so "click Delete until the page looks empty"
    // can never reliably terminate.
    ss_browser_snapshot_and_clear_zone_methods(1, 'ss_docs_zone_snapshot');
});

afterAll(function (): void {
    if (!ss_browser_store_manageable()) {
        return;
    }

    ss_browser_restore_zone_methods(1, 'ss_docs_zone_snapshot');
});

/**
 * The Denmark zone (zone 1) seeded by bin/setup-local-dev.sh - the zone
 * this documentation flow is captured against.
 */
function docs_zone_url(): string
{
    return ss_zone_page_url(1);
}

it('starts from an empty shipping zone', function () {
    // The zone was snapshotted and cleared in beforeAll.
    $page = visit(base_url('/wp-login.php'))
        ->fill('#user_login', admin_username())
        ->fill('#user_pass', admin_password())
        ->click('#wp-submit')
        ->assertPathContains('wp-admin')
        ->navigate(docs_zone_url());

    $page->assertSee('Denmark')
        ->assertSee('You can add multiple shipping methods within this zone.');

    highlight_element($page, '.wc-shipping-zone-add-method');

    capture_doc_screenshot($page, 'ShippingMethod', 'zone-empty');
});

it('shows the method picker with Smart Send available', function () {
    $page = visit(base_url('/wp-login.php'))
        ->fill('#user_login', admin_username())
        ->fill('#user_pass', admin_password())
        ->click('#wp-submit')
        ->assertPathContains('wp-admin')
        ->navigate(docs_zone_url());

    $page->click('Add shipping method')
        ->assertSee('Smart Send');

    highlight_element($page, '#smart_send_shipping');

    capture_doc_screenshot($page, 'ShippingMethod', 'method-picker');
});

it('adds the Smart Send method to the zone', function () {
    $page = visit(base_url('/wp-login.php'))
        ->fill('#user_login', admin_username())
        ->fill('#user_pass', admin_password())
        ->click('#wp-submit')
        ->assertPathContains('wp-admin')
        ->navigate(docs_zone_url());

    // The add-method dialog interaction (including the hidden-radio
    // clickable-label selector detail) lives in the shared step helper -
    // see tests/Support/ShippingMethodSteps.php.
    ss_step_add_smart_send_method($page);
    $page->assertSee('Advanced shipping solution for PostNord, GLS and Bring');

    capture_doc_screenshot($page, 'ShippingMethod', 'method-added-to-zone');
});

it('shows the empty method settings form', function () {
    $page = visit(base_url('/wp-login.php'))
        ->fill('#user_login', admin_username())
        ->fill('#user_pass', admin_password())
        ->click('#wp-submit')
        ->assertPathContains('wp-admin')
        ->navigate(docs_zone_url());

    ss_step_open_method_settings($page);

    highlight_element($page, '#woocommerce_smart_send_shipping_method');

    capture_doc_screenshot($page, 'ShippingMethod', 'settings-form-empty');
});

it('fills in the method title and carrier method', function () {
    $page = visit(base_url('/wp-login.php'))
        ->fill('#user_login', admin_username())
        ->fill('#user_pass', admin_password())
        ->click('#wp-submit')
        ->assertPathContains('wp-admin')
        ->navigate(docs_zone_url());

    ss_step_open_method_settings($page);

    // ss_set_input_value() under the hood, not fill() - see the shared
    // step helpers for why the title field defeats fill()'s retry loop.
    ss_step_fill_method_settings($page, 'Smart Send Shipping', 'postnord_collect');

    highlight_element($page, '#woocommerce_smart_send_shipping_method');

    capture_doc_screenshot($page, 'ShippingMethod', 'settings-form-filled');
});

it('saves the configured method', function () {
    $page = visit(base_url('/wp-login.php'))
        ->fill('#user_login', admin_username())
        ->fill('#user_pass', admin_password())
        ->click('#wp-submit')
        ->assertPathContains('wp-admin')
        ->navigate(docs_zone_url());

    ss_step_open_method_settings($page);
    ss_step_fill_method_settings($page, 'Smart Send Shipping', 'postnord_collect');
    ss_step_save_method_settings($page);

    capture_doc_screenshot($page, 'ShippingMethod', 'settings-saved');
});

it('lists the configured method in the zone', function () {
    $page = visit(base_url('/wp-login.php'))
        ->fill('#user_login', admin_username())
        ->fill('#user_pass', admin_password())
        ->click('#wp-submit')
        ->assertPathContains('wp-admin')
        ->navigate(docs_zone_url());

    $page->assertSee('Smart Send')
        ->assertDontSee('You can add multiple shipping methods within this zone.');

    capture_doc_screenshot($page, 'ShippingMethod', 'zone-method-configured');
});
