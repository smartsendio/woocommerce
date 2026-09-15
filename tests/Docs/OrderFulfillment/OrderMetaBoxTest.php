<?php

/*
|--------------------------------------------------------------------------
| Guides -> Orders -> Create shipping labels from the order screen
|--------------------------------------------------------------------------
|
| Screenshots of the "Smart Send Shipping" meta box on the WooCommerce
| order screen (#182), one per state of the issue's section 1.2: the
| not-yet-booked box, the parcel editor, changing the pickup point and the
| shipping method, a booked outbound label, outbound + return booked in one
| run, a booking failure shown on the field it belongs to, an order placed
| without a Smart Send method, and the not-connected notice.
|
| One test per UI state, each producing its own named screenshot under
| docs/screenshots/OrderFulfillment/ (see tests/Docs/Support/Screenshots.php).
| pest-plugin-browser resets the browser after every test, so each test
| re-navigates to the state it documents; only the WordPress database (the
| seeded fixture orders) carries state across tests.
|
| Each test opens with the explicit visit()->fill()->fill()->click() chain:
| pest-plugin-browser only recognises a test as a browser test when the
| test's own closure literally calls visit() (or the file lives under
| tests/Browser/), so the login cannot move into a helper - see
| tests/Docs/ShippingMethod/ConfigureShippingMethodTest.php.
|
| The fixtures are the Browser suite's: ss_browser_seed_store() installs
| the API mock and creates the orders; the meta box is server-rendered
| disabled and enabled by the app once mounted, so every click waits for
| hydration through Playwright's actionability checks.
|
*/

beforeAll(function (): void {
    if (!ss_browser_store_manageable()) {
        return;
    }

    ss_browser_seed_store(['orders' => [
        [],                       // 0: not booked / pickup point change / method change
        ['quantity' => 3],        // 1: parcel editor
        [],                       // 2: booked outbound
        ['auto_return' => true],  // 3: booked outbound + return
        [],                       // 4: booking failed on a field
        ['flat_rate' => true],    // 5: no Smart Send method
        [],                       // 6: not connected
    ]]);
});

afterAll(function (): void {
    if (!ss_browser_store_manageable()) {
        return;
    }

    ss_browser_cleanup_store();
});

beforeEach(function (): void {
    ss_browser_skip_unless_store_manageable($this);
});

/**
 * The order screen path of a fixture order (HPOS-aware).
 */
function docs_order_url(int $index): string
{
    return base_url(ss_browser_order_edit_path(ss_browser_state()['orders'][$index]));
}

it('shows the not-yet-booked box', function () {
    $page = visit(base_url('/wp-login.php'))
        ->fill('#user_login', admin_username())
        ->fill('#user_pass', admin_password())
        ->click('#wp-submit')
        ->assertPathContains('wp-admin')
        ->navigate(docs_order_url(0));

    $page->assertSee('Smart Send Shipping')
        ->assertSeeIn('[data-ss-section="pickup_point"]', 'Browser Test Shop')
        ->assertEnabled('[data-ss-action="create-label"]');

    highlight_element($page, '#smart-send-fulfillment');

    capture_doc_screenshot($page, 'OrderFulfillment', 'not-booked', false);
});

it('shows the parcel editor with a unit moved to a second box', function () {
    $page = visit(base_url('/wp-login.php'))
        ->fill('#user_login', admin_username())
        ->fill('#user_pass', admin_password())
        ->click('#wp-submit')
        ->assertPathContains('wp-admin')
        ->navigate(docs_order_url(1));

    $page->assertSee('Smart Send Shipping')
        ->click('[data-ss-action="edit-parcels"]')
        ->click('[data-ss-action="add-box"]')
        ->select('[data-ss-field="parcel_plan.units[2].box"]', '1')
        ->assertSeeIn('[data-ss-section="parcel_plan"]', '2 parcels');

    highlight_element($page, '[data-ss-section="parcel_editor"]');

    capture_doc_screenshot($page, 'OrderFulfillment', 'parcel-editor', false);
});

it('shows a pickup point looked up by its agent number', function () {
    $page = visit(base_url('/wp-login.php'))
        ->fill('#user_login', admin_username())
        ->fill('#user_pass', admin_password())
        ->click('#wp-submit')
        ->assertPathContains('wp-admin')
        ->navigate(docs_order_url(0));

    $page->assertSee('Smart Send Shipping')
        ->click('[data-ss-action="change-pickup-point"]')
        ->fill('[data-ss-field="pickup_point.agent_no"]', '5678')
        ->click('[data-ss-action="lookup-pickup-point"]')
        ->assertSeeIn('[data-ss-section="pickup_point"]', 'Second Test Shop');

    highlight_element($page, '[data-ss-section="pickup_point"]');

    capture_doc_screenshot($page, 'OrderFulfillment', 'pickup-point-change', false);
});

it('shows the shipping method select', function () {
    $page = visit(base_url('/wp-login.php'))
        ->fill('#user_login', admin_username())
        ->fill('#user_pass', admin_password())
        ->click('#wp-submit')
        ->assertPathContains('wp-admin')
        ->navigate(docs_order_url(0));

    $page->assertSee('Smart Send Shipping')
        ->click('[data-ss-action="change-method"]')
        ->assertPresent('[data-ss-field="shipping_method"]');

    highlight_element($page, '[data-ss-field="shipping_method"]');

    capture_doc_screenshot($page, 'OrderFulfillment', 'method-change', false);
});

it('shows a booked shipping label with its documents and tracking', function () {
    $page = visit(base_url('/wp-login.php'))
        ->fill('#user_login', admin_username())
        ->fill('#user_pass', admin_password())
        ->click('#wp-submit')
        ->assertPathContains('wp-admin')
        ->navigate(docs_order_url(2));

    $page->assertSee('Smart Send Shipping')
        ->click('[data-ss-action="create-label"]')
        ->assertSeeIn('[data-ss-section="outbound_shipment"]', 'Booked')
        ->assertSeeIn('[data-ss-section="documents"]', 'Download shipping label (PDF)');

    highlight_element($page, '[data-ss-section="outbound_shipment"]');

    capture_doc_screenshot($page, 'OrderFulfillment', 'booked-outbound', false);
});

it('shows outbound and return labels booked in one run', function () {
    $page = visit(base_url('/wp-login.php'))
        ->fill('#user_login', admin_username())
        ->fill('#user_pass', admin_password())
        ->click('#wp-submit')
        ->assertPathContains('wp-admin')
        ->navigate(docs_order_url(3));

    $page->assertSee('Smart Send Shipping')
        ->assertChecked('[data-ss-field="with_return"]')
        ->click('[data-ss-action="create-label"]')
        ->assertSeeIn('[data-ss-section="outbound_shipment"]', 'Booked')
        ->assertSeeIn('[data-ss-section="return_shipment"]', 'Booked');

    highlight_element($page, '#smart-send-fulfillment');

    capture_doc_screenshot($page, 'OrderFulfillment', 'booked-outbound-and-return', false);
});

it('shows a booking failure on the field it belongs to', function () {
    ss_browser_set_api_scenarios(['booking' => '422-agent-no']);

    try {
        $page = visit(base_url('/wp-login.php'))
            ->fill('#user_login', admin_username())
            ->fill('#user_pass', admin_password())
            ->click('#wp-submit')
            ->assertPathContains('wp-admin')
            ->navigate(docs_order_url(4));

        $page->assertSee('Smart Send Shipping')
            ->click('[data-ss-action="create-label"]')
            ->assertPresent('[data-ss-error="pickup_point.agent_no"]');

        highlight_element($page, '[data-ss-error="pickup_point.agent_no"]');

        capture_doc_screenshot($page, 'OrderFulfillment', 'booking-failed-field-error', false);
    } finally {
        ss_browser_set_api_scenarios(null);
    }
});

it('shows the method choice for an order placed without a Smart Send method', function () {
    $page = visit(base_url('/wp-login.php'))
        ->fill('#user_login', admin_username())
        ->fill('#user_pass', admin_password())
        ->click('#wp-submit')
        ->assertPathContains('wp-admin')
        ->navigate(docs_order_url(5));

    $page->assertSee('Smart Send Shipping')
        ->assertSeeIn('[data-ss-notice="no_method"]', 'Choose one to book anyway');

    highlight_element($page, '[data-ss-field="shipping_method"]');

    capture_doc_screenshot($page, 'OrderFulfillment', 'no-smart-send-method', false);
});

it('shows the not-connected notice when no API token is configured', function () {
    ss_browser_update_plugin_setting('api_token', '');
    ss_browser_update_plugin_setting('demo', 'no');

    try {
        $page = visit(base_url('/wp-login.php'))
            ->fill('#user_login', admin_username())
            ->fill('#user_pass', admin_password())
            ->click('#wp-submit')
            ->assertPathContains('wp-admin')
            ->navigate(docs_order_url(6));

        $page->assertSee('Smart Send Shipping')
            ->assertSeeIn('[data-ss-notice="not_connected"]', 'Smart Send is not connected');

        highlight_element($page, '[data-ss-notice="not_connected"]');

        capture_doc_screenshot($page, 'OrderFulfillment', 'not-connected', false);
    } finally {
        ss_browser_update_plugin_setting('demo', 'yes');
    }
});
