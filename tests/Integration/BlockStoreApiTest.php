<?php

/*
 * Tests for the Store API extensions of the Checkout Block (PR 2 of issue
 * #74, SS_Shipping_Store_Api): the cart endpoint carries the server-computed
 * pickup point state ("data down"), the checkout endpoint accepts agent_no
 * and persists the server-resolved pickup point through the repository
 * ("data up") with byte-identical meta to the classic checkout, and an
 * unresolvable agent_no on an agent method rejects the checkout with a
 * Store API validation error.
 *
 * Dispatch layer: the schema/data/update callbacks are exercised through the
 * REAL ExtendSchema registry (StoreApi::container()) that the plugin
 * registered into at bootstrap, plus one full REST GET of /wc/store/v1/cart;
 * the checkout write fires the real woocommerce_store_api_checkout_update_order_from_request
 * action against the plugin's registered listener. Full REST POSTs to
 * /wc/store/v1/checkout are not used - they require a payment gateway and
 * draft-order session state that the in-process bootstrap does not provide
 * reliably.
 *
 * NOTE: like FrontendSelectorHooksTest.php, this file must run before the
 * WC_DOING_AJAX-defining test in ShippingDebugModeTest.php (alphabetical
 * file order guarantees this).
 */

use Automattic\WooCommerce\StoreApi\Schemas\ExtendSchema;
use Automattic\WooCommerce\StoreApi\StoreApi;
use Automattic\WooCommerce\StoreApi\Exceptions\RouteException;

/**
 * Set up a cart the way the block checkout sees it server-side: an item in
 * the cart, the customer's shipping address known to WooCommerce, a Smart
 * Send rate injected into the calculated packages and chosen in the session.
 */
function block_cart_setup(string $method_code = 'postnord_agent', array $address = []): void
{
    if (is_null(WC()->cart)) {
        wc_load_cart();
    }

    WC()->cart->empty_cart();
    WC()->cart->add_to_cart(create_simple_product()->get_id());

    $address = array_merge([
        'country'  => 'DK',
        'postcode' => '2300',
        'city'     => 'Copenhagen',
        'address'  => 'Islands Brygge 39',
    ], $address);

    $customer = WC()->customer;
    $customer->set_shipping_country($address['country']);
    $customer->set_shipping_postcode($address['postcode']);
    $customer->set_shipping_city($address['city']);
    $customer->set_shipping_address_1($address['address']);

    $rate_filter = function (array $rates) use ($method_code): array {
        $rate = new WC_Shipping_Rate('smart_send_shipping:1', 'Smart Send', 49.0, [], 'smart_send_shipping', 1);
        $rate->add_meta_data('smart_send_shipping_method', $method_code);

        $rates['smart_send_shipping:1'] = $rate;

        return $rates;
    };
    add_filter('woocommerce_package_rates', $rate_filter);

    // The CI store is provisioned with --skip-seed, so NO shipping zone
    // method exists there - and WC_Cart::show_shipping() short-circuits
    // (calculate_shipping() then never builds any package) whenever
    // wc_get_shipping_method_count() is zero. Create a throwaway zone with
    // an enabled method so shipping is calculated at all; the Smart Send
    // rate itself still comes from the woocommerce_package_rates filter.
    $zone = new WC_Shipping_Zone();
    $zone->set_zone_name('Block Store API test zone');
    $zone->add_location($address['country'] ?: 'DK', 'country');
    $zone->save();
    $zone->add_shipping_method('flat_rate');

    // Adding the method bumps the 'shipping' transient version, but the
    // version stamp is (string) time() - SECOND resolution
    // (WC_Cache_Helper::get_transient_version()). When an earlier test in
    // the suite primed the wc_shipping_method_count transient with a
    // 0-count in the SAME wall-clock second, the "bumped" version equals
    // the cached one and wc_get_shipping_method_count() keeps returning
    // the stale 0 - which made show_shipping() short-circuit in CI while
    // passing locally whenever a second boundary happened to fall in
    // between. Drop the count transient so the zone method is recounted.
    delete_transient('wc_shipping_method_count');

    remember_cleanup_callback(function () use ($zone): void {
        $zone->delete(true);
        // Same second-resolution staleness in the other direction: without
        // this, a later test could keep counting the deleted zone's method.
        delete_transient('wc_shipping_method_count');
    });

    remember_cleanup_callback(function () use ($rate_filter): void {
        remove_filter('woocommerce_package_rates', $rate_filter);
        WC()->cart->empty_cart();
        WC()->session->set('chosen_shipping_methods', null);
        WC()->session->set('ss_shipping_agents', null);
        WC()->session->set(SS_Shipping_Store_Api::SESSION_SELECTED_AGENT_NO, null);

        $customer = WC()->customer;
        $customer->set_shipping_country('');
        $customer->set_shipping_postcode('');
        $customer->set_shipping_city('');
        $customer->set_shipping_address_1('');
    });

    // Verify the gate BEFORE calculating: WC_Cart::show_shipping() (and
    // needs_shipping()) short-circuit when the store counts zero enabled
    // shipping methods, and calculate_shipping() then silently builds no
    // packages at all.
    if (!WC()->cart->show_shipping()) {
        throw new RuntimeException(
            'block_cart_setup: WC()->cart->show_shipping() is false even though the test zone exists'
            . ' (wc_get_shipping_method_count(true) = ' . wc_get_shipping_method_count(true) . ')'
        );
    }

    WC()->cart->calculate_shipping();

    // Fail loudly when the rate did not make it into the calculated
    // packages - otherwise a setup regression reads as a silently wrong
    // rate selection in the actual tests.
    $packages      = WC()->shipping()->get_packages();
    $package_rates = array_keys($packages[0]['rates'] ?? []);
    if (!in_array('smart_send_shipping:1', $package_rates, true)) {
        throw new RuntimeException(
            'block_cart_setup: the Smart Send rate is missing from the calculated package rates ('
            . (empty($packages) ? 'no packages were calculated' : implode(', ', $package_rates)) . ')'
        );
    }

    // Choose the Smart Send rate AFTER calculating, as the LAST setup step:
    // calculating a changed package resets the session choice to the
    // default rate.
    WC()->session->set('chosen_shipping_methods', ['smart_send_shipping:1']);
}

/**
 * The 'smart-send' cart extension data, computed through the real
 * ExtendSchema registry the plugin registered into.
 */
function block_cart_extension_data(): array
{
    $data = StoreApi::container()->get(ExtendSchema::class)->get_endpoint_data('cart');

    return $data->{SS_Shipping_Block_Checkout::INTEGRATION_NAME};
}

/**
 * A checkout REST request carrying (or omitting) our extension data.
 */
function block_checkout_request(?string $agent_no): WP_REST_Request
{
    $request = new WP_REST_Request('POST', '/wc/store/v1/checkout');

    if ($agent_no !== null) {
        $request->set_param('extensions', [
            SS_Shipping_Block_Checkout::INTEGRATION_NAME => ['agent_no' => $agent_no],
        ]);
    }

    return $request;
}

beforeEach(function (): void {
    with_ss_settings();
});

it('registers cart and checkout schema extensions under the smart-send namespace', function () {
    $extend = StoreApi::container()->get(ExtendSchema::class);

    $cart_schema = $extend->get_endpoint_schema('cart');
    expect($cart_schema)->toHaveProperty('smart-send');
    expect($cart_schema->{'smart-send'}['properties'] ?? $cart_schema->{'smart-send'})
        ->toHaveKeys(['selected_rate_is_agent', 'pickup_points', 'selected_agent_no', 'select_default']);

    $checkout_schema = $extend->get_endpoint_schema('checkout');
    expect($checkout_schema)->toHaveProperty('smart-send');
    expect($checkout_schema->{'smart-send'}['properties'] ?? $checkout_schema->{'smart-send'})
        ->toHaveKeys(['agent_no']);
});

it('carries the smart-send extension data in the full cart REST response', function () {
    mock_smart_send_api(function () {
        return ss_api_response(200, ['data' => [sample_agent()]]);
    });
    block_cart_setup();

    $response = rest_do_request(new WP_REST_Request('GET', '/wc/store/v1/cart'));

    expect($response->get_status())->toBe(200);

    $extensions = (array) $response->get_data()['extensions'];

    expect($extensions)->toHaveKey('smart-send')
        ->and((array) $extensions['smart-send'])
        ->toHaveKeys(['selected_rate_is_agent', 'pickup_points', 'selected_agent_no', 'select_default']);
});

it('computes pickup points with formatted labels for an agent rate and a complete address', function () {
    $capture = mock_smart_send_api(function () {
        return ss_api_response(200, ['data' => [
            sample_agent(['agent_no' => '1111', 'company' => 'First Shop']),
            sample_agent(['agent_no' => '2222', 'company' => 'Second Shop']),
        ]]);
    });
    block_cart_setup();

    $data = block_cart_extension_data();

    expect($data['selected_rate_is_agent'])->toBeTrue()
        ->and($data['pickup_points'])->toHaveCount(2)
        ->and($data['pickup_points'][0]['agent_no'])->toBe('1111')
        // Format 4 from with_ss_settings(): '#Company, #Street, #Zipcode #City'.
        ->and($data['pickup_points'][0]['label'])->toContain('First Shop, Main Street 1, 2300 Copenhagen')
        ->and($data['pickup_points'][1]['label'])->toContain('Second Shop, Main Street 1, 2300 Copenhagen')
        ->and($data['selected_agent_no'])->toBeNull()
        ->and($data['select_default'])->toBeFalse();

    // The lookup went to the closest-by-address endpoint with the
    // customer's server-side address.
    expect(end($capture->requests)['url'])->toContain('/agents/closest/carrier/postnord/country/DK/postalcode/2300/city/Copenhagen/street/Islands');
});

it('applies the smart_send_pickup_point_option_label filter to the labels, like classic checkout', function () {
    mock_smart_send_api(function () {
        return ss_api_response(200, ['data' => [sample_agent()]]);
    });
    block_cart_setup();

    $filter = function (string $label, object $agent) {
        return 'Custom Label ' . $agent->agent_no;
    };
    add_filter('smart_send_pickup_point_option_label', $filter, 10, 2);
    remember_cleanup_callback(function () use ($filter): void {
        remove_filter('smart_send_pickup_point_option_label', $filter, 10);
    });

    $data = block_cart_extension_data();

    expect($data['pickup_points'][0]['label'])->toBe('Custom Label 1234');
});

it('reports a non-agent rate with no pickup points and makes no API call', function () {
    $capture = mock_smart_send_api();
    block_cart_setup('postnord_homedelivery');

    $data = block_cart_extension_data();

    expect($data['selected_rate_is_agent'])->toBeFalse()
        ->and($data['pickup_points'])->toBe([])
        ->and($capture->requests)->toBe([]);
});

it('returns no pickup points and makes no API call while the address is incomplete', function () {
    $capture = mock_smart_send_api();
    block_cart_setup('postnord_agent', ['postcode' => '']);

    $data = block_cart_extension_data();

    expect($data['selected_rate_is_agent'])->toBeTrue()
        ->and($data['pickup_points'])->toBe([])
        ->and($capture->requests)->toBe([]);
});

it('reflects the Select Default setting in the cart data', function () {
    with_ss_settings(['default_select_agent' => 'yes']);
    mock_smart_send_api(function () {
        return ss_api_response(200, ['data' => [sample_agent()]]);
    });
    block_cart_setup();

    expect(block_cart_extension_data()['select_default'])->toBeTrue();
});

it('stores and clears the in-progress selection through the registered extension update callback', function () {
    mock_smart_send_api(function () {
        return ss_api_response(200, ['data' => [sample_agent()]]);
    });
    block_cart_setup();

    $callback = StoreApi::container()->get(ExtendSchema::class)
        ->get_update_callback(SS_Shipping_Block_Checkout::INTEGRATION_NAME);

    $callback(['agent_no' => '1234']);
    expect(block_cart_extension_data()['selected_agent_no'])->toBe('1234');

    $callback(['agent_no' => '']);
    expect(block_cart_extension_data()['selected_agent_no'])->toBeNull();
});

it('persists the pickup point byte-identically to the classic checkout path', function () {
    if (is_null(WC()->cart)) {
        wc_load_cart();
    }

    // Both paths resolve against the same session-cached lookup results.
    WC()->session->set('ss_shipping_agents', [sample_agent()]);
    remember_cleanup_callback(function (): void {
        WC()->session->set('ss_shipping_agents', null);
        unset($_POST['ss_shipping_store_pickup']);
    });

    // Classic checkout path.
    $classic_order = create_order(['shipping_method' => 'postnord_agent']);
    $_POST['ss_shipping_store_pickup'] = '1234';
    (new SS_Shipping_Frontend())->process_ss_pickup_points($classic_order->get_id(), []);
    unset($_POST['ss_shipping_store_pickup']);

    // Block checkout path: the real registered listener on the real action.
    $block_order = create_order(['shipping_method' => 'postnord_agent']);
    do_action('woocommerce_store_api_checkout_update_order_from_request', $block_order, block_checkout_request('1234'));

    // Byte-equality of the stored meta between the two paths (freshly
    // reloaded orders, read through the CRUD so both storage backends work).
    $classic_fresh = wc_get_order($classic_order->get_id());
    $block_fresh   = wc_get_order($block_order->get_id());

    expect(serialize($block_fresh->get_meta(SS_Shipping_Order_Meta::META_AGENT, true)))
        ->toBe(serialize($classic_fresh->get_meta(SS_Shipping_Order_Meta::META_AGENT, true)))
        ->and($block_fresh->get_meta(SS_Shipping_Order_Meta::META_AGENT_NO, true))
        ->toBe($classic_fresh->get_meta(SS_Shipping_Order_Meta::META_AGENT_NO, true));

    // And the repository reads back the identical pickup point.
    $classic_point = SS_SHIPPING_WC()->order_meta()->read($classic_order->get_id())->get_pickup_point();
    $block_point   = SS_SHIPPING_WC()->order_meta()->read($block_order->get_id())->get_pickup_point();

    expect($block_point->get_agent_no())->toBe('1234')
        ->and(serialize($block_point->to_object()))->toBe(serialize($classic_point->to_object()));
});

it('rejects checkout with a Store API validation error when an agent method has no agent_no', function () {
    $order = create_order(['shipping_method' => 'postnord_agent']);

    expect(function () use ($order) {
        do_action('woocommerce_store_api_checkout_update_order_from_request', $order, block_checkout_request(null));
    })->toThrow(RouteException::class, 'A pickup point must be selected.');

    expect(wc_get_order($order->get_id())->get_meta(SS_Shipping_Order_Meta::META_AGENT_NO, true))->toBe('');
});

it('rejects checkout when the submitted agent_no resolves nowhere (cache miss and API miss)', function () {
    if (is_null(WC()->cart)) {
        wc_load_cart();
    }
    WC()->session->set('ss_shipping_agents', null);

    mock_smart_send_api(function () {
        return ss_api_response(404, ss_api_error_body('Agent not found'));
    });

    $order = create_order(['shipping_method' => 'postnord_agent']);

    expect(function () use ($order) {
        do_action('woocommerce_store_api_checkout_update_order_from_request', $order, block_checkout_request('0000'));
    })->toThrow(RouteException::class, 'A pickup point must be selected.');

    expect(wc_get_order($order->get_id())->get_meta(SS_Shipping_Order_Meta::META_AGENT_NO, true))->toBe('');
});

it('ignores a stray agent_no on a non-agent method and writes nothing', function () {
    $order = create_order(['shipping_method' => 'postnord_homedelivery']);

    do_action('woocommerce_store_api_checkout_update_order_from_request', $order, block_checkout_request('1234'));

    $fresh = wc_get_order($order->get_id());
    expect($fresh->get_meta(SS_Shipping_Order_Meta::META_AGENT_NO, true))->toBe('')
        ->and($fresh->get_meta(SS_Shipping_Order_Meta::META_AGENT, true))->toBe('');
});

it('falls back to the findByAgentNo API when the agent_no is not in the session cache, and persists the server object', function () {
    if (is_null(WC()->cart)) {
        wc_load_cart();
    }
    WC()->session->set('ss_shipping_agents', null);

    $capture = mock_smart_send_api(function () {
        return ss_api_response(200, ['data' => sample_agent(['agent_no' => '9999', 'company' => 'Fallback Shop'])]);
    });

    $order = create_order(['shipping_method' => 'postnord_agent']);

    do_action('woocommerce_store_api_checkout_update_order_from_request', $order, block_checkout_request('9999'));

    // Resolved server-side by agent number, for the order's carrier/country.
    expect(end($capture->requests)['url'])->toContain('/agents/carrier/postnord/country/DK/agentno/9999');

    $point = SS_SHIPPING_WC()->order_meta()->read($order->get_id())->get_pickup_point();
    expect($point->get_agent_no())->toBe('9999')
        ->and($point->get_company())->toBe('Fallback Shop');
});
