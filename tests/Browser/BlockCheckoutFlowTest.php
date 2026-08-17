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
        ->assertPresent('#ss-pickup-point-select')
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
        ->click('.wc-block-components-checkout-place-order-button')
        ->assertSee('A pickup point must be selected.')
        ->assertDontSee('order has been received');
});

it('shows the pickup point selector on block checkout and stores the chosen agent on the order', function () {
    $page = ss_block_checkout_reach_pickup_selector();

    $page->assertSee('Cash on delivery')
        ->select('#ss-pickup-point-select', '1234')
        ->click('.wc-block-components-checkout-place-order-button');

    // Thank-you page: the frontend hook renders the stored pickup point,
    // which proves the agent meta ended up on the order - the exact same
    // assertion as the classic checkout journey.
    $page->assertSee('order has been received')
        ->assertSee('Pickup Point')
        ->assertSee('Browser Test Shop')
        ->assertSee('Main Street 1');
});

it('renders no pickup point selector for a non-agent rate', function () {
    $page = ss_block_checkout_reach_shipping_options();

    // The flat rate (a non-Smart-Send, non-agent rate) is preselected, so
    // the block renders nothing.
    $page->assertMissing('#ss-pickup-point-select');
});
