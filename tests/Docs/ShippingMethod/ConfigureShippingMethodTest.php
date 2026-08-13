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
| seeds by default. The first test removes any Smart Send method already
| on that zone so the suite is safe to re-run locally without resetting the
| store between runs.
|
*/

function docs_zone_url(): string
{
    return base_url('/wp-admin/admin.php?page=wc-settings&tab=shipping&zone_id=1');
}

it('starts from an empty shipping zone', function () {
    // Idempotency: a Smart Send method left over from a previous run of this
    // suite would otherwise show up in the "empty" screenshot and get
    // duplicated by the "add method" test below.
    //
    // This is done through WooCommerce's own API (docs_clear_shipping_zone_methods(),
    // shelling out via WP-CLI - see its docblock) rather than by scripting
    // the admin UI ("click Delete until the page looks empty"). The zone
    // screen is a client-side-rendered (Vue) app: its compiled JS bundle
    // contains every method's name and every row's CSS class as template
    // strings, so both are present in the page source regardless of whether
    // anything is actually configured - a page-content check for "is there
    // something to delete" can never reliably go false, and a stray
    // ->click('Delete') on a method that was never actually rendered just
    // hangs waiting for a locator that will never appear. Resetting state
    // directly sidesteps both failure modes entirely.
    docs_clear_shipping_zone_methods(1);

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

    // The radio input itself (#smart_send_shipping) is visually hidden -
    // WooCommerce's own add-method dialog (wc-backbone-modal-add-shipping-method)
    // pairs it with a clickable <label for="smart_send_shipping"> as the
    // actual interactive surface. Clicking the input directly waits forever
    // for an element that can never become actionable; click the label
    // instead, same as a real user would.
    $page->click('Add shipping method')
        ->click('label[for="smart_send_shipping"]')
        ->click('Continue')
        ->assertSee('Smart Send')
        ->assertSee('Advanced shipping solution for PostNord, GLS and Bring');

    capture_doc_screenshot($page, 'ShippingMethod', 'method-added-to-zone');
});

it('shows the empty method settings form', function () {
    $page = visit(base_url('/wp-login.php'))
        ->fill('#user_login', admin_username())
        ->fill('#user_pass', admin_password())
        ->click('#wp-submit')
        ->assertPathContains('wp-admin')
        ->navigate(docs_zone_url());

    $page->click('Edit')->assertSee('Method Title');

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

    $page->click('Edit')->assertSee('Method Title');

    // set_input_value(), not fill() - see its docblock: this screen keeps
    // resetting the title field back to its default shortly after fill()
    // sets it, and fill()'s built-in verify-and-retry then polls forever
    // against a value that never stays put.
    set_input_value($page, '#woocommerce_smart_send_shipping_title', 'Smart Send Shipping');
    $page->select('woocommerce_smart_send_shipping_method', 'postnord_collect');

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

    $page->click('Edit')->assertSee('Method Title');

    // set_input_value(), not fill() - see its docblock.
    set_input_value($page, '#woocommerce_smart_send_shipping_title', 'Smart Send Shipping');
    $page->select('woocommerce_smart_send_shipping_method', 'postnord_collect')
        ->click('Save changes')
        ->assertSee('Denmark');

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
