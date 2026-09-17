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

    // The address entry above triggers a (debounced) cart update that
    // re-fetches the shipping rates. While that request is in flight the
    // rate radios are disabled - noticeably long on older WooCommerce
    // (8.2 on the CI floor leg), where a click landing in that window was
    // silently dropped and the flat rate stayed selected. Wait until the
    // cart has absorbed the address and is idle before choosing a rate.
    ss_block_checkout_wait_for_cart_idle($page, '2300');

    return $page;
}

/**
 * Block until the Checkout block's cart store has the server-confirmed
 * shipping destination at the given postcode and has been quiet (no
 * customer-data / rate-selection request in flight) for longer than the
 * block's debounce, so the next click on a shipping rate is not swallowed
 * by a re-render. Every filled field schedules its own debounced cart
 * update, so a single idle sample is not enough - the last one (the phone)
 * may not have started yet. Polls the wc/store/cart selectors (stable
 * across the supported WooCommerce range) rather than any version-specific
 * wording in the order summary.
 */
function ss_block_checkout_wait_for_cart_idle($page, string $postcode, int $timeoutSeconds = 20): void
{
    $deadline = microtime(true) + $timeoutSeconds;
    $quietFor = 2.0; // seconds; the block debounces address pushes by ~1s
    $probe = <<<JS
        (() => {
            const store = window.wp && wp.data && wp.data.select('wc/store/cart');
            if (!store) { return false; }
            const packages = store.getShippingRates();
            const destination = packages.length ? packages[0].destination : null;
            return !!destination
                && destination.postcode === '$postcode'
                && !store.isCustomerDataUpdating()
                && !store.isShippingRateBeingSelected()
                && !store.isCartDataStale();
        })()
    JS;

    $quietSince = null;

    do {
        if ($page->script($probe) === true) {
            $quietSince = $quietSince ?? microtime(true);
            if (microtime(true) - $quietSince >= $quietFor) {
                return;
            }
        } else {
            $quietSince = null;
        }
        usleep(250000);
    } while (microtime(true) < $deadline);

    throw new RuntimeException("The block checkout cart did not settle on postcode $postcode within {$timeoutSeconds}s.");
}

/**
 * Choose the Smart Send agent rate and make sure the cart store registered
 * it. A click that lands while the Checkout block has the rate radios
 * disabled (a cart update in flight - long on WooCommerce 8.2) is dropped
 * silently, so verify the selection through wc/store/cart and click again
 * when it did not take.
 */
function ss_block_checkout_select_agent_rate($page, string $rateId)
{
    $selectedProbe = <<<JS
        (() => {
            const store = window.wp && wp.data && wp.data.select('wc/store/cart');
            if (!store) { return false; }
            const packages = store.getShippingRates();
            return packages.length > 0
                && packages[0].shipping_rates.some((rate) => rate.selected && rate.rate_id === '$rateId');
        })()
    JS;

    for ($attempt = 1; $attempt <= 3; $attempt++) {
        $page->click('input[value="' . $rateId . '"]');

        $deadline = microtime(true) + 5;
        do {
            if ($page->script($selectedProbe) === true) {
                return $page;
            }
            usleep(250000);
        } while (microtime(true) < $deadline);
    }

    throw new RuntimeException("The shipping rate $rateId was not selected after 3 clicks.");
}

/**
 * Continue to the Smart Send agent rate: the pickup point block renders its
 * selector, fed by the mocked API through the cart extension data.
 */
function ss_block_checkout_reach_pickup_selector()
{
    $state = ss_browser_state();

    $page = ss_block_checkout_reach_shipping_options();

    ss_block_checkout_select_agent_rate($page, 'smart_send_shipping:' . $state['instance_id'])
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
    ss_browser_wp_eval(<<<'PHP'
        $config = get_option('ss_test_api', array());
        $config['pickup_label_suffix'] = '<strong id="ss-label-injection"> & "quoted"';
        update_option('ss_test_api', $config);
        echo json_encode(array('ok' => true));
    PHP);
    try {
        $page = ss_block_checkout_reach_pickup_selector();
        expect($page->script("document.querySelector('#ss-pickup-point-select option[value=\"1234\"]').textContent"))
            ->toContain('<strong id="ss-label-injection"> & "quoted"');
        $page->assertMissing('#ss-label-injection')
            ->assertSee('Cash on delivery')
            ->assertButtonEnabled('.wc-block-components-checkout-place-order-button')
            ->click('.wc-block-components-checkout-place-order-button')
            ->assertSee('A pickup point must be selected.')
            ->assertDontSee('order has been received');
    } finally {
        ss_browser_wp_eval(<<<'PHP'
            $config = get_option('ss_test_api', array());
            unset($config['pickup_label_suffix']);
            update_option('ss_test_api', $config);
            echo json_encode(array('ok' => true));
        PHP);
    }
});

it('shows the pickup point selector on block checkout and stores the chosen agent on the order', function () {
    $page = ss_block_checkout_reach_pickup_selector();

    $page->assertSee('Cash on delivery')
        ->select('#ss-pickup-point-select', '1234')
        // Wait until the component reports the selection registered (it sets
        // data-selected-agent in the same handler that pushes the agent_no
        // into the checkout POST payload), and until the checkout is idle
        // again after the selection's session round trip, before submitting.
        ->assertPresent('.ss-pickup-point-block[data-selected-agent="1234"][data-selection-pending="false"]')
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

it('shows the enter-your-address hint when the agent rate is chosen before an address is entered', function () {
    $state = ss_browser_state();

    $page = visit(base_url('/?add-to-cart=' . $state['product_id']));

    // Straight to the checkout, no address filled in: the store-base
    // country makes the zone rates render, but postcode and street are
    // still empty, so no lookup can run.
    $page->navigate(base_url('/?page_id=' . $GLOBALS['ss_block_checkout_page_id']))
        ->assertSee('Contact information')
        ->assertSee('Smart Send Pickup Point')
        ->click('input[value="smart_send_shipping:' . $state['instance_id'] . '"]')
        ->assertPresent('.ss-pickup-point-block[data-status="awaiting-address"]')
        ->assertSee('Enter your shipping address to see available pickup points.')
        ->assertMissing('#ss-pickup-point-select');
});

it('shows the none-found state and places the order without a selection when no pickup points are found', function () {
    $state = ss_browser_state();

    ss_browser_set_api_scenarios(array('pickup-points' => 'empty'));

    try {
        $page = ss_block_checkout_reach_shipping_options();

        // Choosing the agent rate renders the block's empty state: the
        // none-found message (same server-translated string as the classic
        // checkout), no selector, and NO client-side validation error. Wait
        // on the component's own data-status affordance ("empty" once the
        // none-found cart response is in).
        ss_block_checkout_select_agent_rate($page, 'smart_send_shipping:' . $state['instance_id'])
            ->assertPresent('.ss-pickup-point-block[data-status="empty"]')
            ->assertSee('We could not find available pickup points')
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
        ss_browser_set_api_scenarios(null);
    }
});

it('renders no pickup point selector for a non-agent rate', function () {
    $page = ss_block_checkout_reach_shipping_options();

    // The flat rate (a non-Smart-Send, non-agent rate) is preselected, so
    // the block renders nothing.
    $page->assertMissing('#ss-pickup-point-select');
});

/** Wait for a deliberately held selection response, not an arbitrary delay. */
function ss_block_checkout_wait_for_held_selection($page, int $count): void
{
    $deadline = microtime(true) + 15;
    do {
        if ($page->script('window.ssHeldSelections.length') === $count) {
            return;
        }
        usleep(100000);
    } while (microtime(true) < $deadline);
    throw new RuntimeException('The expected pickup-point selection response was not received.');
}

/** Hold only our selection responses after the real server validates them. */
function ss_block_checkout_hold_selection_responses($page): void
{
    $page->script(<<<'JS'
        window.ssHeldSelections = [];
        wp.apiFetch.use((options, next) => {
            if (options.path === '/wc/store/v1/cart/extensions' && options.data?.namespace === 'smart-send') {
                return next(options).then(response => new Promise(resolve => {
                    window.ssHeldSelections.push(() => resolve(response));
                }));
            }
            return next(options);
        });
    JS);
}

it('keeps the latest choice while an older selection response is delayed and stores that choice', function () {
    $page = ss_block_checkout_reach_pickup_selector();
    ss_block_checkout_hold_selection_responses($page);
    $page->select('#ss-pickup-point-select', '1234');
    ss_block_checkout_wait_for_held_selection($page, 1);
    $page->select('#ss-pickup-point-select', '5678')
        ->assertPresent('.ss-pickup-point-block[data-selected-agent="5678"][data-selection-pending="true"]')
        ->click('.wc-block-components-checkout-place-order-button')
        ->assertSee('Please wait while pickup points are updated.')
        ->assertDontSee('order has been received');
    $page->script('window.ssHeldSelections[0]()');
    ss_block_checkout_wait_for_held_selection($page, 2);
    $page->assertPresent('.ss-pickup-point-block[data-selected-agent="5678"][data-selection-pending="true"]');
    $page->script('window.ssHeldSelections[1]()');
    $page->assertPresent('.ss-pickup-point-block[data-selected-agent="5678"][data-selection-pending="false"]')
        ->click('.wc-block-components-checkout-place-order-button')
        ->assertSee('order has been received')
        ->assertSee('Second Test Shop')
        ->assertSee('Other Street 9');
});

it('does not roll the shipping address back when an old selection response arrives', function () {
    $page = ss_block_checkout_reach_pickup_selector();
    ss_block_checkout_hold_selection_responses($page);
    $page->select('#ss-pickup-point-select', '1234');
    ss_block_checkout_wait_for_held_selection($page, 1);
    ss_browser_set_api_scenarios(['pickup-points' => 'empty']);
    try {
        $page->fill('#shipping-postcode', '8000');
        ss_block_checkout_wait_for_cart_idle($page, '8000');
        // The explicit compatible point survives even though the next closest
        // search is empty. The older response still carries postcode 2300.
        $page->assertPresent('.ss-pickup-point-block[data-status="ready"][data-selected-agent="1234"][data-selection-pending="false"]');
        $page->script('window.ssHeldSelections[0]()');
        expect($page->script("wp.data.select('wc/store/cart').getCartData().shippingAddress.postcode"))->toBe('8000');
        $page->assertValue('#shipping-postcode', '8000')
            ->assertPresent('.ss-pickup-point-block[data-selected-agent="1234"]')
            ->click('.wc-block-components-checkout-place-order-button')
            ->assertSee('order has been received')
            ->assertSee('Browser Test Shop');
    } finally {
        ss_browser_set_api_scenarios(null);
    }
});

it('keeps the confirmed selection visible and blocks checkout until a rejected update is retried', function () {
    $page = ss_block_checkout_reach_pickup_selector();
    $page->select('#ss-pickup-point-select', '1234')
        ->assertPresent('.ss-pickup-point-block[data-selected-agent="1234"][data-selection-pending="false"]');
    $page->script(<<<'JS'
        let failNextSelection = true;
        wp.apiFetch.use((options, next) => {
            if (failNextSelection && options.path === '/wc/store/v1/cart/extensions' && options.data?.namespace === 'smart-send') {
                failNextSelection = false;
                return Promise.reject(new Error('Simulated connection failure'));
            }
            return next(options);
        });
    JS);
    $page->select('#ss-pickup-point-select', '5678')
        ->assertSee('The pickup point could not be saved. Please select it again.')
        ->assertPresent('.ss-pickup-point-block[data-selected-agent="1234"][data-selection-pending="false"]')
        ->click('.wc-block-components-checkout-place-order-button')
        ->assertDontSee('order has been received')
        ->select('#ss-pickup-point-select', '5678')
        ->assertPresent('.ss-pickup-point-block[data-selected-agent="5678"][data-selection-pending="false"]')
        ->assertDontSee('The pickup point could not be saved. Please select it again.');
});
