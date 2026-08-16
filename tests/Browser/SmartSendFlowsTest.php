<?php

/*
 * End-to-end characterization of the three big Smart Send flows against a
 * running store:
 *
 *  - classic checkout with an agent method: pickup point selector shown,
 *    a point chosen, order placed, agent shown on the thank-you page;
 *  - generating a label from the admin order screen;
 *  - the bulk "Generate Labels" action.
 *
 * The Smart Send API mock and the store fixtures (shipping method instance,
 * classic checkout page, orders) are managed through the shared helpers in
 * tests/Browser/Support/SmartSendStore.php - seeded in beforeAll via WP-CLI
 * and torn down in afterAll.
 */

beforeAll(function (): void {
    if (!ss_browser_store_manageable()) {
        return;
    }

    // Two admin-side orders: one for the meta box label test, one for the
    // bulk action tests.
    ss_browser_seed_store(['orders' => [[], []]]);
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

it('shows the pickup point selector on classic checkout and stores the chosen agent on the order', function () {
    $state = ss_browser_state();

    $page = visit(base_url('/?add-to-cart=' . $state['product_id']));

    $page->navigate(base_url('/?page_id=' . $state['checkout_page_id']))
        ->assertSee('Billing details')
        ->fill('#billing_first_name', 'Browser')
        ->fill('#billing_last_name', 'Test')
        ->fill('#billing_address_1', 'Islands Brygge 39')
        ->fill('#billing_city', 'Copenhagen')
        ->fill('#billing_postcode', '2300')
        ->fill('#billing_phone', '+4512345678')
        ->fill('#billing_email', 'ss-browser-test@smartsend.io');

    // Choose the Smart Send agent method; the checkout refreshes and the
    // plugin renders the pickup point dropdown (fed by the mocked API).
    // WooCommerce derives the radio id from the rate id
    // ('smart_send_shipping:N' -> 'smart_send_shippingN').
    $page->click('#shipping_method_0_smart_send_shipping' . $state['instance_id'])
        ->assertPresent('select[name=ss_shipping_store_pickup]')
        // The mocked agents are options of the (closed) dropdown, so assert
        // against the markup rather than visible text.
        ->assertSourceHas('Browser Test Shop');

    // Cash on delivery is the only enabled gateway, so it is preselected
    // (and its radio hidden). Pick the agent last so no further checkout
    // refresh re-renders the dropdown before submitting.
    $page->assertSee('Cash on delivery')
        ->select('ss_shipping_store_pickup', '1234')
        // Explicit selector: text-based lookups do not match submit buttons
        // by their value and hang instead of failing.
        ->click('#place_order');

    // Thank-you page: the frontend hook renders the stored pickup point,
    // which proves the agent meta ended up on the order.
    $page->assertSee('order has been received')
        ->assertSee('Pickup Point')
        ->assertSee('Browser Test Shop')
        ->assertSee('Main Street 1');
});

it('generates a shipping label from the admin order screen', function () {
    $state = ss_browser_state();

    login_as_admin()
        ->navigate(base_url(ss_browser_order_edit_path($state['orders'][0])))
        ->assertSee('Smart Send Shipping')
        ->assertSee('Pickup Point')
        ->assertSee('Browser Test Shop')
        ->click('#ss-shipping-label-button')
        ->assertSeeIn('#ss-label-created', 'Download shipping label');
});

it('rejects the bulk label action for more than one order', function () {
    // Multi-order bulk processing (and the combined PDF) is temporarily
    // removed pending the Phase 7 async bulk rebuild - selecting more
    // than one order must error without booking anything.
    $state = ss_browser_state();

    $page = login_as_admin()->navigate(base_url($state['orders_list_path']));

    $checkbox_name = $state['hpos'] ? 'id[]' : 'post[]';
    $page->check($checkbox_name, (string) $state['orders'][0])
        ->check($checkbox_name, (string) $state['orders'][1])
        ->select('action', 'ss_shipping_label_bulk')
        // Explicit selector: the Apply button is an input[type=submit], which
        // text-based lookups cannot find.
        ->click('#doaction');

    $page->assertSee('only a single order can be processed at a time');
});

it('generates a label for a single order through the bulk action', function () {
    $state = ss_browser_state();

    $page = login_as_admin()->navigate(base_url($state['orders_list_path']));

    $checkbox_name = $state['hpos'] ? 'id[]' : 'post[]';
    $page->check($checkbox_name, (string) $state['orders'][1])
        ->select('action', 'ss_shipping_label_bulk')
        // Explicit selector: the Apply button is an input[type=submit], which
        // text-based lookups cannot find.
        ->click('#doaction');

    $page->assertSee('Shipping label created by Smart Send');
});
