<?php

/*
 * Logging policy (#92): the WooCommerce log is an audit trail of business
 * events at the info level (label created, order note added, tracking number
 * stored, pick-up point selected/changed) plus developer trace at the debug
 * level, while the checkout shipping debug bar surfaces which Smart Send
 * rates ended up offered for the package and the overlapping-weight-row
 * outcome (the "uniqueness filtering": every matching weight row calls
 * add_rate() with the same rate id, so the last matching row wins).
 *
 * NOTE: tests here assert on checkout notices and must run before the
 * WC_DOING_AJAX-defining tests in ShippingDebugModeTest.php (alphabetical
 * file order guarantees this). Reuses spy_on_logger() from LoggerTest.php
 * and the label helpers from LabelGenerationTest.php, both of which load
 * earlier alphabetically.
 */

/**
 * Build a Smart Send shipping method instance with the given instance
 * settings persisted in the options table (restored after the test).
 */
function ss_policy_method(array $instance_settings = [], int $instance_id = 99951): SS_Shipping_WC_Method
{
    $settings = array_merge([
        'title'                      => 'SS Policy Method',
        'method'                     => 'postnord_agent',
        'return_method'              => 'postnord_returndropoff',
        'auto_generate_return_label' => 'no',
        'tax_status'                 => 'none',
        'requires'                   => '',
        'flatfee_cost'               => '0',
        'min_amount'                 => '0',
        'cost_weight'                => [],
        'advanced_settings_enable'   => 'no',
    ], $instance_settings);

    with_option('woocommerce_smart_send_shipping_' . $instance_id . '_settings', $settings);

    return new SS_Shipping_WC_Method($instance_id);
}

/**
 * Put products in a fresh cart and return the shipping package the way
 * WooCommerce hands it to calculate_shipping().
 */
function ss_policy_package(array $product_lines): array
{
    if (is_null(WC()->cart)) {
        wc_load_cart();
    }

    WC()->cart->empty_cart();
    remember_cleanup_callback(function (): void {
        WC()->cart->empty_cart();
    });

    foreach ($product_lines as $line) {
        [$product, $quantity] = is_array($line) ? $line : [$line, 1];
        WC()->cart->add_to_cart($product->get_id(), $quantity);
    }

    WC()->cart->calculate_totals();

    return current(WC()->cart->get_shipping_packages());
}

/**
 * All queued WooCommerce notices of the default "success" type (the type
 * wc_add_notice() uses when none is given, matching core's debug notices).
 */
function ss_policy_notices(): array
{
    return array_map(
        fn (array $notice): string => (string) $notice['notice'],
        wc_get_notices('success')
    );
}

/**
 * The logged messages of a given level recorded by the logger spy.
 */
function ss_policy_logged(object $spy, string $level): array
{
    $messages = [];
    foreach ($spy->entries as $entry) {
        if ($entry['level'] === $level) {
            $messages[] = $entry['message'];
        }
    }

    return $messages;
}

/**
 * The first spy entry whose message matches exactly, or null.
 */
function ss_policy_entry(object $spy, string $message): ?array
{
    foreach ($spy->entries as $entry) {
        if ($entry['message'] === $message) {
            return $entry;
        }
    }

    return null;
}

beforeEach(function (): void {
    with_ss_settings(['ss_debug' => 'yes']);

    if (is_null(WC()->cart)) {
        wc_load_cart();
    }
    wc_clear_notices();
    remember_cleanup_callback(function (): void {
        wc_clear_notices();
    });
});

it('logs the label, order note and tracking business events at info level on label creation', function () {
    $spy = spy_on_logger();
    mock_smart_send_api(function () {
        return ss_api_response(200, ['data' => ss_api_shipment_data(['shipment_id' => 'policy-shipment'])]);
    });
    $order = create_labelable_order();

    create_labels_for($order->get_id());

    $infos = implode("\n", ss_policy_logged($spy, 'info'));
    expect($infos)->toContain('Shipping label created')
        ->toContain('Order note with label and tracking added')
        ->toContain('Tracking number stored');

    $created = ss_policy_entry($spy, 'Shipping label created');
    expect($created)->not->toBeNull()
        ->and($created['context']['order_id'])->toBe($order->get_id())
        ->and($created['context']['shipment_id'])->toBe('policy-shipment')
        ->and($created['context']['carrier'])->toBe('PostNord');

    $tracking = ss_policy_entry($spy, 'Tracking number stored');
    expect($tracking)->not->toBeNull()
        ->and($tracking['context']['tracking_code'])->toBe('TRACK-1234');
});

it('logs "Return shipping label created" for return labels without a tracking event', function () {
    $spy = spy_on_logger();
    mock_smart_send_api();
    $order = create_labelable_order();

    create_labels_for($order->get_id(), true);

    $infos = implode("\n", ss_policy_logged($spy, 'info'));
    expect($infos)->toContain('Return shipping label created')
        ->and($infos)->not->toContain('Tracking number stored');
});

it('logs an info event when the shopper selects a pick-up point at checkout', function () {
    $spy     = spy_on_logger();
    $product = create_simple_product(['price' => 100, 'weight' => 1]);
    $order   = create_order(['products' => [$product], 'shipping_method' => 'postnord_agent']);

    WC()->session->set('ss_shipping_agents', [sample_agent()]);
    $_POST['ss_shipping_store_pickup'] = '1234';
    remember_cleanup_callback(function (): void {
        unset($_POST['ss_shipping_store_pickup']);
        WC()->session->set('ss_shipping_agents', null);
    });

    (new SS_Shipping_Frontend())->process_ss_pickup_points($order->get_id(), null);

    $selected = ss_policy_entry($spy, 'Pick-up point selected at checkout');
    expect($selected)->not->toBeNull()
        ->and($selected['level'])->toBe('info')
        ->and($selected['context']['order_id'])->toBe($order->get_id())
        ->and($selected['context']['agent_no'])->toBe('1234');

    expect(SS_SHIPPING_WC()->get_ss_shipping_wc_order()->get_ss_shipping_order_agent_no($order->get_id()))->toBe('1234');
});

it('logs an info event when the pick-up point is changed on an order', function () {
    $spy = spy_on_logger();
    mock_smart_send_api(function () {
        return ss_api_response(200, ['data' => sample_agent(['agent_no' => '5678'])]);
    });
    $product = create_simple_product(['price' => 100, 'weight' => 1]);
    $order   = create_order(['products' => [$product], 'shipping_method' => 'postnord_agent']);

    $method = new ReflectionMethod(SS_Shipping_WC_Order::class, 'save_shipping_agent');
    $result = $method->invoke(SS_SHIPPING_WC()->get_ss_shipping_wc_order(), $order->get_id(), true, '5678');

    expect($result)->toBeTrue();

    $changed = ss_policy_entry($spy, 'Pick-up point changed on order');
    expect($changed)->not->toBeNull()
        ->and($changed['level'])->toBe('info')
        ->and($changed['context']['order_id'])->toBe($order->get_id())
        ->and($changed['context']['agent_no'])->toBe('5678')
        ->and($changed['context']['carrier'])->toBe('postnord');
});

it('logs a warning when the agent number is rejected, even with the debug setting off', function () {
    with_ss_settings(['ss_debug' => 'no']);
    $spy = spy_on_logger();
    mock_smart_send_api(function () {
        return ss_api_response(404, ['code' => 'NoResults', 'message' => 'The agent was not found.']);
    });
    $product = create_simple_product(['price' => 100, 'weight' => 1]);
    $order   = create_order(['products' => [$product], 'shipping_method' => 'postnord_agent']);

    $method = new ReflectionMethod(SS_Shipping_WC_Order::class, 'save_shipping_agent');
    $result = $method->invoke(SS_SHIPPING_WC()->get_ss_shipping_wc_order(), $order->get_id(), true, '9999');

    expect($result)->toBeString();

    $rejected = ss_policy_entry($spy, 'Pick-up point not found - agent number rejected');
    expect($rejected)->not->toBeNull()
        ->and($rejected['level'])->toBe('warning')
        ->and($rejected['context']['agent_no'])->toBe('9999');
});

it('logs a debug trace when the meta box is rendered for a non Smart Send order', function () {
    $spy     = spy_on_logger();
    $product = create_simple_product(['price' => 100, 'weight' => 1]);
    $order   = create_order(['products' => [$product]]); // No Smart Send shipping item.

    ob_start();
    SS_SHIPPING_WC()->get_ss_shipping_wc_order()->render_smart_send_order_meta_box($order);
    $output = ob_get_clean();

    expect($output)->toContain('Order placed with a shipping method that is not from the Smart Send plugin')
        ->and(implode("\n", ss_policy_logged($spy, 'debug')))->toContain('No Smart Send shipping method on order - skipping meta box content');
});

it('surfaces which Smart Send rates ended up offered for the package', function () {
    with_option('woocommerce_shipping_debug_mode', 'yes');
    $spy = spy_on_logger();

    $ss_rate = new WC_Shipping_Rate('smart_send_shipping:7', 'SS Policy Rate', '49', [], 'smart_send_shipping', 7);
    $other   = new WC_Shipping_Rate('flat_rate:3', 'Flat rate', '10', [], 'flat_rate', 3);

    SS_SHIPPING_WC()->ss_sort_shipping_methods([
        'smart_send_shipping:7' => $ss_rate,
        'flat_rate:3'           => $other,
    ]);

    $expected = 'Smart Send: rates offered for this package: "SS Policy Rate" (smart_send_shipping:7, cost 49).';
    $trace    = implode("\n", ss_policy_notices());

    expect($trace)->toContain($expected)
        ->and($trace)->not->toContain('Flat rate')
        ->and(implode("\n", ss_policy_logged($spy, 'debug')))->toContain($expected);
});

it('reports when no Smart Send rates were offered for the package', function () {
    with_option('woocommerce_shipping_debug_mode', 'yes');
    spy_on_logger();

    $other = new WC_Shipping_Rate('flat_rate:3', 'Flat rate', '10', [], 'flat_rate', 3);

    SS_SHIPPING_WC()->ss_sort_shipping_methods(['flat_rate:3' => $other]);

    expect(implode("\n", ss_policy_notices()))->toContain('Smart Send: no Smart Send rates offered for this package.');
});

it('explains that the last matching weight row wins when rows overlap', function () {
    with_option('woocommerce_shipping_debug_mode', 'yes');
    $spy = spy_on_logger();

    $product = create_simple_product(['price' => 100, 'weight' => 2]);
    $package = ss_policy_package([$product]);

    $method = ss_policy_method([
        'cost_weight' => [
            ['ss_min_weight' => '1', 'ss_max_weight' => '5', 'ss_cost_weight' => '49'],
            ['ss_min_weight' => '1', 'ss_max_weight' => '10', 'ss_cost_weight' => '99'],
        ],
    ]);
    $method->calculate_shipping($package);

    $unit     = get_option('woocommerce_weight_unit');
    $expected = 'Smart Send "SS Policy Method": 2 overlapping weight table rows matched (1-5 ' . $unit . ', 1-10 ' . $unit
        . ') - the rows share rate id smart_send_shipping:99951, so only the LAST matching row (1-10 ' . $unit
        . ') is offered and the earlier matches were overwritten.';

    // v8 oddity, unchanged: the last matching row silently wins.
    expect($method->rates)->toHaveCount(1)
        ->and((float) current($method->rates)->get_cost())->toBe(99.0)
        ->and(ss_policy_notices())->toContain($expected)
        ->and(implode("\n", ss_policy_logged($spy, 'debug')))->toContain($expected);
});

it('adds no overlap notice when only one weight row matches', function () {
    with_option('woocommerce_shipping_debug_mode', 'yes');
    spy_on_logger();

    $product = create_simple_product(['price' => 100, 'weight' => 2]);
    $package = ss_policy_package([$product]);

    $method = ss_policy_method([
        'cost_weight' => [
            ['ss_min_weight' => '1', 'ss_max_weight' => '5', 'ss_cost_weight' => '49'],
            ['ss_min_weight' => '5', 'ss_max_weight' => '10', 'ss_cost_weight' => '99'],
        ],
    ], 99952);
    $method->calculate_shipping($package);

    expect($method->rates)->toHaveCount(1)
        ->and(implode("\n", ss_policy_notices()))->not->toContain('overlapping weight table rows');
});

it('no longer logs the removed noise entries', function () {
    with_ss_settings(['ss_debug' => 'yes', 'sort_methods_by_cost' => 'yes']);
    $spy = spy_on_logger();

    $product = create_simple_product(['price' => 100, 'weight' => 2]);
    $package = ss_policy_package([$product]);

    // Free shipping path.
    $free = ss_policy_method([
        'requires'     => 'enabled',
        'flatfee_cost' => '25',
    ], 99953);
    $free->calculate_shipping($package);

    // Weight table path.
    $weighted = ss_policy_method([
        'cost_weight' => [['ss_min_weight' => '1', 'ss_max_weight' => '5', 'ss_cost_weight' => '49']],
    ], 99954);
    $weighted->calculate_shipping($package);

    // Sorting path.
    SS_SHIPPING_WC()->ss_sort_shipping_methods([
        'smart_send_shipping:7' => new WC_Shipping_Rate('smart_send_shipping:7', 'SS Policy Rate', '49', [], 'smart_send_shipping', 7),
        'flat_rate:3'           => new WC_Shipping_Rate('flat_rate:3', 'Flat rate', '10', [], 'flat_rate', 3),
    ]);

    $all = implode("\n", array_column($spy->entries, 'message'));

    expect($all)->not->toContain('Handling shipping rate')
        ->and($all)->not->toContain('Rate details (json decode for details)')
        ->and($all)->not->toContain('Free shipping rate added')
        ->and($all)->not->toContain('Weight based shipping rate added')
        ->and($all)->not->toContain('Shipping methods sorted by cost');
});
