<?php

/*
|--------------------------------------------------------------------------
| Guides -> Orders -> Create shipping labels from the order screen
|--------------------------------------------------------------------------
|
| Screenshots of the "Smart Send" meta box on the WooCommerce
| order screen (#182), one per state of the issue's section 1.2: the
| not-yet-booked box (the sectioned Option A layout), the parcels section
| collapsed to its summary and expanded into the editor, the pickup point
| row in its edit state and with a looked-up point, the shipping method
| select, a booked outbound label with its green result box (parcel row
| and documents), the "Booked shipments" timeline that is what is left
| after a reload, outbound + return booked in one run, a
| booking failure shown on the field it belongs to, an order placed
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

    $page->assertSeeIn('#woocommerce-ss-shipping-label .hndle', 'Smart Send')
        ->assertSeeIn('[data-ss-section="pickup_point"]', 'Browser Test Shop')
        // Both actions: the primary "Create shipping label" and the
        // secondary "Create return label".
        ->assertEnabled('[data-ss-action="create-label"]')
        ->assertEnabled('[data-ss-action="create-return-label"]');

    highlight_element($page, '#smart-send-fulfillment');

    capture_doc_screenshot($page, 'OrderFulfillment', 'not-booked', false);
});

it('shows the parcels section collapsed to its summary line', function () {
    $page = visit(base_url('/wp-login.php'))
        ->fill('#user_login', admin_username())
        ->fill('#user_pass', admin_password())
        ->click('#wp-submit')
        ->assertPathContains('wp-admin')
        ->navigate(docs_order_url(1));

    $page->assertSeeIn('#woocommerce-ss-shipping-label .hndle', 'Smart Send')
        ->assertSeeIn('[data-ss-section="parcel_plan"]', '1 parcel · 3.00 kg')
        ->assertPresent('[data-ss-action="edit-parcels"]');

    highlight_element($page, '[data-ss-section="parcel_plan"]');

    capture_doc_screenshot($page, 'OrderFulfillment', 'parcels-collapsed', false);
});

it('shows the parcel editor with a line split across two boxes', function () {
    $page = visit(base_url('/wp-login.php'))
        ->fill('#user_login', admin_username())
        ->fill('#user_pass', admin_password())
        ->click('#wp-submit')
        ->assertPathContains('wp-admin')
        ->navigate(docs_order_url(1));

    $page->assertSeeIn('#woocommerce-ss-shipping-label .hndle', 'Smart Send')
        ->click('[data-ss-action="edit-parcels"]')
        // ▼ below the last box creates box 2 with one unit of the line.
        ->click('[data-ss-box="1"] [data-ss-action="move-down"]')
        ->assertSeeIn('[data-ss-section="parcel_plan"]', '2 parcels');

    highlight_element($page, '[data-ss-section="parcel_editor"]');

    capture_doc_screenshot($page, 'OrderFulfillment', 'parcel-editor', false);
});

it('shows the pickup point row in its edit state', function () {
    $page = visit(base_url('/wp-login.php'))
        ->fill('#user_login', admin_username())
        ->fill('#user_pass', admin_password())
        ->click('#wp-submit')
        ->assertPathContains('wp-admin')
        ->navigate(docs_order_url(0));

    $page->assertSeeIn('#woocommerce-ss-shipping-label .hndle', 'Smart Send')
        ->click('[data-ss-action="edit-pickup-point"]')
        ->assertPresent('[data-ss-field="pickup_point.agent_no"]')
        ->assertSeeIn('[data-ss-section="pickup_point"]', 'Browser Test Shop');

    highlight_element($page, '[data-ss-section="pickup_point"]');

    capture_doc_screenshot($page, 'OrderFulfillment', 'pickup-point-edit', false);
});

it('shows a pickup point looked up by its agent number', function () {
    $page = visit(base_url('/wp-login.php'))
        ->fill('#user_login', admin_username())
        ->fill('#user_pass', admin_password())
        ->click('#wp-submit')
        ->assertPathContains('wp-admin')
        ->navigate(docs_order_url(0));

    $page->assertSeeIn('#woocommerce-ss-shipping-label .hndle', 'Smart Send')
        ->click('[data-ss-action="edit-pickup-point"]')
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

    $page->assertSeeIn('#woocommerce-ss-shipping-label .hndle', 'Smart Send')
        ->click('[data-ss-action="edit-method"]')
        ->assertPresent('[data-ss-field="shipping_method"]');

    highlight_element($page, '[data-ss-field="shipping_method"]');

    capture_doc_screenshot($page, 'OrderFulfillment', 'method-change', false);
});

it('shows a booked shipping label with its parcels and documents', function () {
    $page = visit(base_url('/wp-login.php'))
        ->fill('#user_login', admin_username())
        ->fill('#user_pass', admin_password())
        ->click('#wp-submit')
        ->assertPathContains('wp-admin')
        ->navigate(docs_order_url(2));

    // A booking: the form stays exactly as it was and the green result box
    // of the run - "Open" into the Smart Send app, the parcel row, the
    // documents - appears above the actions, with a new timeline entry
    // under them.
    $page->assertSeeIn('#woocommerce-ss-shipping-label .hndle', 'Smart Send')
        ->click('[data-ss-action="create-label"]')
        ->assertPresent('[data-ss-result="outbound"]')
        ->assertSeeIn('[data-ss-section="parcels"]', 'BROWSERTRACK1')
        ->assertSeeIn('[data-ss-section="documents"]', 'Download shipping label (PDF)')
        ->assertPresent('[data-ss-action="create-label"]')
        ->assertPresent('[data-ss-section="timeline"]');

    highlight_element($page, '[data-ss-result="outbound"]');

    capture_doc_screenshot($page, 'OrderFulfillment', 'booked-outbound', false);
});

it('shows the booked shipments timeline that is left after a page reload', function () {
    $page = visit(base_url('/wp-login.php'))
        ->fill('#user_login', admin_username())
        ->fill('#user_pass', admin_password())
        ->click('#wp-submit')
        ->assertPathContains('wp-admin')
        ->navigate(docs_order_url(2))
        // Order 2 carries the label the test above booked (the WordPress
        // database is what carries state between these tests); this is the
        // reload: the green result box is gone - it is the memory of the
        // run just made - and the "Booked shipments" timeline is what
        // stays, over the unchanged form.
        ->assertNotPresent('[data-ss-result="outbound"]')
        ->assertSeeIn('[data-ss-section="timeline"]', 'Booked shipments')
        ->assertPresent('[data-ss-timeline="outbound"]');

    highlight_element($page, '[data-ss-section="timeline"]');

    capture_doc_screenshot($page, 'OrderFulfillment', 'booked-timeline', false);
});

it('shows outbound and return labels booked in one run', function () {
    $page = visit(base_url('/wp-login.php'))
        ->fill('#user_login', admin_username())
        ->fill('#user_pass', admin_password())
        ->click('#wp-submit')
        ->assertPathContains('wp-admin')
        ->navigate(docs_order_url(3));

    $page->assertSeeIn('#woocommerce-ss-shipping-label .hndle', 'Smart Send')
        ->assertChecked('[data-ss-field="with_return"]')
        ->click('[data-ss-action="create-label"]')
        ->assertPresent('[data-ss-result="outbound"]')
        ->assertPresent('[data-ss-result="return"]')
        ->assertPresent('[data-ss-timeline="return"]');

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

        $page->assertSeeIn('#woocommerce-ss-shipping-label .hndle', 'Smart Send')
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

    $page->assertSeeIn('#woocommerce-ss-shipping-label .hndle', 'Smart Send')
        ->assertSeeIn('[data-ss-notice="no_method"]', 'Shipping method is not from the Smart Send plugin.')
        ->assertSeeIn('[data-ss-value="shipping_method"]', 'None')
        ->assertSeeIn('[data-ss-value="return_method"]', 'None')
        ->click('[data-ss-action="edit-method"]')
        ->assertPresent('[data-ss-field="shipping_method"]');

    highlight_element($page, '[data-ss-section="shipping_method"]');

    capture_doc_screenshot($page, 'OrderFulfillment', 'no-smart-send-method', false);
});

it('shows the not-connected notice when no API token is configured', function () {
    ss_browser_update_plugin_setting('api_token', '');

    try {
        $page = visit(base_url('/wp-login.php'))
            ->fill('#user_login', admin_username())
            ->fill('#user_pass', admin_password())
            ->click('#wp-submit')
            ->assertPathContains('wp-admin')
            ->navigate(docs_order_url(6));

        $page->assertSeeIn('#woocommerce-ss-shipping-label .hndle', 'Smart Send')
            ->assertSeeIn('[data-ss-notice="not_connected"]', 'Smart Send is not connected');

        highlight_element($page, '[data-ss-notice="not_connected"]');

        capture_doc_screenshot($page, 'OrderFulfillment', 'not-connected', false);
    } finally {
        ss_browser_update_plugin_setting('api_token', 'ss-mock-api-token');
    }
});
