<?php

/*
 * The customer checkout journey with a Smart Send agent method on the
 * BLOCK checkout (issue #74) - the mirror of CheckoutFlowTest.php: the
 * pickup point block renders below the shipping options (fed by the mocked
 * API riding the cart extension data), Place Order is blocked until a
 * pickup point is selected, the chosen point ends up stored on the placed
 * order, and a non-agent rate renders no selector at all.
 *
 * The block checkout page is seeded with the stock minimal Checkout block
 * markup - the pickup point block is NOT in the saved content, so these
 * tests also prove the force-render path (the selector working with no
 * merchant action on existing checkout pages).
 */

/**
 * Walk the block checkout up to the point where the address is complete and
 * the shipping options have rendered.
 */
function ss_block_checkout_reach_shipping_options()
{
    $state = ss_browser_state();

    $page = visit(base_url('/?add-to-cart=' . $state['product_id']));

    $page->navigate(base_url('/?page_id=' . $GLOBALS['ss_block_checkout_page_id']))
        ->assertSee('Contact information')
        ->fill('#email', 'ss-browser-test@smartsend.io')
        ->fill('#shipping-first_name', 'Browser')
        ->fill('#shipping-last_name', 'Test')
        ->fill('#shipping-address_1', 'Islands Brygge 39')
        ->fill('#shipping-city', 'Copenhagen')
        ->fill('#shipping-postcode', '2300')
        ->fill('#shipping-phone', '+4512345678')
        // Both zone rates offered: the flat rate (ordered first, so
        // preselected) and the Smart Send agent method.
        ->assertSee('Flat rate')
        ->assertSee('Smart Send Pickup Point');

    return $page;
}

/**
 * Continue to the Smart Send agent rate: the pickup point block renders its
 * selector, fed by the mocked API through the cart extension data.
 */
function ss_block_checkout_reach_pickup_selector()
{
    $state = ss_browser_state();

    $page = ss_block_checkout_reach_shipping_options();

    $page->click('input[value="smart_send_shipping:' . $state['instance_id'] . '"]')
        // The selector element renders as soon as the agent rate is chosen,
        // but its options arrive with the next Store API cart response - so
        // wait on the component's observable state (data-status flips to
        // "ready" once the pickup points are in) and on the concrete option
        // element, not on a source grep that could sample the in-between
        // render on a slow runner.
        ->assertPresent('.ss-pickup-point-block[data-status="ready"]')
        ->assertPresent('#ss-pickup-point-select option[value="1234"]')
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
    $GLOBALS['ss_block_checkout_page_id'] = ss_browser_create_block_checkout_page();
});

afterAll(function (): void {
    if (!ss_browser_store_manageable()) {
        return;
    }

    if (!empty($GLOBALS['ss_block_checkout_page_id'])) {
        ss_browser_delete_block_checkout_page($GLOBALS['ss_block_checkout_page_id']);
        unset($GLOBALS['ss_block_checkout_page_id']);
    }

    ss_browser_cleanup_store();
});

beforeEach(function (): void {
    ss_browser_skip_unless_store_manageable($this);
});

it('blocks Place Order until a pickup point is selected', function () {
    $page = ss_block_checkout_reach_pickup_selector();

    // Submit with the "- Select Pickup Point -" placeholder still selected:
    // the wc/store/validation error registered by the block must reject the
    // submission client-side (the Store API RouteException remains the
    // server-side backstop). Explicit selector: text-based lookups do not
    // reliably match the Place Order button.
    $page->assertSee('Cash on delivery')
        // Submit only once the checkout is idle (WooCommerce disables the
        // button while cart updates are in flight).
        ->assertButtonEnabled('.wc-block-components-checkout-place-order-button')
        ->click('.wc-block-components-checkout-place-order-button')
        ->assertSee('A pickup point must be selected.')
        ->assertDontSee('order has been received');
});

it('shows the pickup point selector on block checkout and stores the chosen agent on the order', function () {
    $page = ss_block_checkout_reach_pickup_selector();

    $page->assertSee('Cash on delivery')
        ->select('#ss-pickup-point-select', '1234')
        // Wait until the component reports the selection registered (it sets
        // data-selected-agent in the same handler that pushes the agent_no
        // into the checkout POST payload), and until the checkout is idle
        // again after the selection's session round trip, before submitting.
        ->assertPresent('.ss-pickup-point-block[data-selected-agent="1234"]')
        ->assertButtonEnabled('.wc-block-components-checkout-place-order-button')
        ->click('.wc-block-components-checkout-place-order-button');

    // Thank-you page: the frontend hook renders the stored pickup point,
    // which proves the agent meta ended up on the order - the exact same
    // assertion as the classic checkout journey.
    $page->assertSee('order has been received')
        ->assertSee('Pickup Point')
        ->assertSee('Browser Test Shop')
        ->assertSee('Main Street 1');
});

it('shows the none-found state and places the order without a selection when no pickup points are found', function () {
    $state = ss_browser_state();

    ss_browser_set_api_scenario('no-pickup-points');

    try {
        $page = ss_block_checkout_reach_shipping_options();

        // Choosing the agent rate renders the block's empty state: the
        // friendly fallback text (same translated string as the classic
        // checkout), no selector, and NO client-side validation error. Wait
        // on the component's own data-status affordance ("empty" once the
        // none-found cart response is in).
        $page->click('input[value="smart_send_shipping:' . $state['instance_id'] . '"]')
            ->assertPresent('.ss-pickup-point-block[data-status="empty"]')
            ->assertSee('Shipping to closest pickup point')
            ->assertMissing('#ss-pickup-point-select');

        // The order goes through WITHOUT a pickup point selection - neither
        // the client-side validation nor the Store API rejects it.
        $page->assertSee('Cash on delivery')
            ->assertButtonEnabled('.wc-block-components-checkout-place-order-button')
            ->click('.wc-block-components-checkout-place-order-button')
            ->assertSee('order has been received')
            ->assertDontSee('A pickup point must be selected.')
            // No pickup point block on the thank-you page: no agent meta was
            // written. (The shipping method title 'Smart Send Pickup Point'
            // legitimately contains 'Pickup Point', so assert on the mocked
            // shop name and the block's heading markup instead.)
            ->assertDontSee('Browser Test Shop')
            ->assertSourceMissing('<h2>Pickup Point</h2>');
    } finally {
        ss_browser_set_api_scenario(null);
    }
});

it('renders no pickup point selector for a non-agent rate', function () {
    $page = ss_block_checkout_reach_shipping_options();

    // The flat rate (a non-Smart-Send, non-agent rate) is preselected, so
    // the block renders nothing.
    $page->assertMissing('#ss-pickup-point-select');
});
