<?php

/*
 * The customer checkout journey with a Smart Send agent method on the
 * classic (shortcode) checkout: the pickup point selector renders (fed by
 * the mocked API), checkout refuses to submit until a pickup point is
 * selected, and the chosen point ends up stored on the placed order.
 *
 * Split from the historic SmartSendFlowsTest.php (#146); the label side of
 * the journey lives in LabelGenerationTest.php. Store fixtures and the API
 * mock are managed through the shared helpers in
 * tests/Browser/Support/SmartSendStore.php.
 */

/**
 * Walk the classic checkout up to the point where the Smart Send agent
 * method is chosen and the pickup point dropdown has rendered.
 */
function ss_checkout_reach_pickup_selector()
{
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

    return $page;
}

beforeAll(function (): void {
    if (!ss_browser_store_manageable()) {
        return;
    }

    ss_browser_seed_store();
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

it('blocks checkout submission until a pickup point is selected', function () {
    $page = ss_checkout_reach_pickup_selector();

    // Submit with the "- Select Pickup Point -" placeholder still selected:
    // the woocommerce_checkout_process validation must reject the order.
    // Explicit selector: text-based lookups do not match submit buttons by
    // their value and hang instead of failing.
    $page->assertSee('Cash on delivery')
        ->click('#place_order')
        ->assertSee('A pickup point must be selected.')
        ->assertDontSee('order has been received');
});

it('shows the pickup point selector on classic checkout and stores the chosen agent on the order', function () {
    $page = ss_checkout_reach_pickup_selector();

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

it('shows the fallback text and places the order without a selection when no pickup points are found', function () {
    $state = ss_browser_state();

    ss_browser_set_api_scenarios(array('pickup-points' => 'empty'));

    try {
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

        // Choosing the agent method now renders the none-found message
        // instead of a dropdown - there is nothing to select.
        $page->click('#shipping_method_0_smart_send_shipping' . $state['instance_id'])
            ->assertSee('We could not find available pickup points')
            ->assertMissing('select[name=ss_shipping_store_pickup]');

        // The order goes through WITHOUT a pickup point selection.
        $page->assertSee('Cash on delivery')
            ->click('#place_order')
            ->assertSee('order has been received')
            ->assertDontSee('A pickup point must be selected.')
            // No pickup point block on the thank-you page: no agent meta was
            // written. (The shipping method title 'Smart Send Pickup Point'
            // legitimately contains 'Pickup Point', so assert on the mocked
            // shop name and the block's heading markup instead.)
            ->assertDontSee('Browser Test Shop')
            ->assertSourceMissing('<h2>Pickup Point</h2>');
    } finally {
        ss_browser_set_api_scenarios(null);
    }
});
