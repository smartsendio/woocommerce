<?php

/*
 * Logging policy (#92): the WooCommerce log is an audit trail of business
 * events at the info level (label created, order note added, tracking number
 * stored, pickup point selected/changed) plus developer trace at the debug
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

it('logs an info event when the shopper selects a pickup point at checkout', function () {
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

    $selected = ss_policy_entry($spy, 'Pickup point selected at checkout');
    expect($selected)->not->toBeNull()
        ->and($selected['level'])->toBe('info')
        ->and($selected['context']['order_id'])->toBe($order->get_id())
        ->and($selected['context']['agent_no'])->toBe('1234');

    expect(SS_SHIPPING_WC()->order_meta()->read($order->get_id())->get_pickup_point()->get_agent_no())->toBe('1234');
});

it('logs an info event when the pickup point is changed on an order', function () {
    $spy = spy_on_logger();
    mock_smart_send_api(function () {
        return ss_api_response(200, ['data' => sample_agent(['agent_no' => '5678'])]);
    });
    $product = create_simple_product(['price' => 100, 'weight' => 1]);
    $order   = create_order(['products' => [$product], 'shipping_method' => 'postnord_agent']);

    $result = SS_SHIPPING_WC()->pickup_point_validator()->validate_and_store($order->get_id(), true, '5678');

    expect($result)->toBeTrue();

    $changed = ss_policy_entry($spy, 'Pickup point changed on order');
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

    $result = SS_SHIPPING_WC()->pickup_point_validator()->validate_and_store($order->get_id(), true, '9999');

    expect($result)->toBeString();

    $rejected = ss_policy_entry($spy, 'Pickup point not found - agent number rejected');
    expect($rejected)->not->toBeNull()
        ->and($rejected['level'])->toBe('warning')
        ->and($rejected['context']['agent_no'])->toBe('9999');
});

it('logs a debug trace when the meta box is rendered for a non Smart Send order', function () {
    $spy     = spy_on_logger();
    $product = create_simple_product(['price' => 100, 'weight' => 1]);
    $order   = create_order(['products' => [$product]]); // No Smart Send shipping item.

    ob_start();
    SS_SHIPPING_WC()->meta_box()->render_smart_send_order_meta_box($order);
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

it('logs the rate calculation start and cost outcome as a debug trace', function () {
    $spy = spy_on_logger();

    $product = create_simple_product(['price' => 100, 'weight' => 2]);
    $package = ss_policy_package([$product]);

    $method = ss_policy_method([
        'cost_weight' => [['ss_min_weight' => '1', 'ss_max_weight' => '5', 'ss_cost_weight' => '49']],
    ], 99955);
    $method->calculate_shipping($package);

    $debugs = implode("\n", ss_policy_logged($spy, 'debug'));
    expect($debugs)->toContain('Calculating shipping cost for shipping rate smart_send_shipping:99955')
        ->and($debugs)->toContain('Calculated shipping cost for shipping rate smart_send_shipping:99955: 49');

    $outcome = ss_policy_entry($spy, 'Calculated shipping cost for shipping rate smart_send_shipping:99955: 49');
    expect($outcome)->not->toBeNull()
        ->and($outcome['context']['rate_id'])->toBe('smart_send_shipping:99955');
});

it('logs a not-available outcome when the cost calculation adds no rate', function () {
    $spy = spy_on_logger();

    $product = create_simple_product(['price' => 100, 'weight' => 10]);
    $package = ss_policy_package([$product]);

    $method = ss_policy_method([
        'cost_weight' => [['ss_min_weight' => '1', 'ss_max_weight' => '5', 'ss_cost_weight' => '49']],
    ], 99956);
    $method->calculate_shipping($package);

    $debugs = implode("\n", ss_policy_logged($spy, 'debug'));
    expect($method->rates)->toHaveCount(0)
        ->and($debugs)->toContain('Calculating shipping cost for shipping rate smart_send_shipping:99956')
        ->and($debugs)->toContain('Shipping rate smart_send_shipping:99956 is not available for this package - no rate added');
});

it('logs an error when the order cannot be loaded while deleting pickup point meta, even with debug off', function () {
    with_ss_settings(['ss_debug' => 'no']);
    $spy = spy_on_logger();

    SS_SHIPPING_WC()->order_meta()->delete_pickup_point(999999999);

    $failed = ss_policy_entry($spy, 'Failed to load WooCommerce order when deleting pickup point meta - skipping');
    expect($failed)->not->toBeNull()
        ->and($failed['level'])->toBe('error')
        ->and($failed['context']['order_id'])->toBe(999999999);
});

/**
 * Run the Validate API Token AJAX callback in-process: forge the nonce,
 * make wp_doing_ajax() return true (outside AJAX wp_send_json() calls plain
 * `die` and would kill the test process), and intercept the resulting
 * wp_die() via the die handler filter.
 */
function ss_policy_run_connection_test(): void
{
    $_REQUEST['test_connection_nonce'] = wp_create_nonce('ss-test-connection');

    $die_handler = function () {
        return function (): void {
            throw new RuntimeException('wp_die intercepted');
        };
    };
    add_filter('wp_doing_ajax', '__return_true');
    add_filter('wp_die_handler', $die_handler);
    add_filter('wp_die_ajax_handler', $die_handler);

    remember_cleanup_callback(function () use ($die_handler): void {
        unset($_REQUEST['test_connection_nonce']);
        remove_filter('wp_doing_ajax', '__return_true');
        remove_filter('wp_die_handler', $die_handler);
        remove_filter('wp_die_ajax_handler', $die_handler);
    });

    ob_start();
    try {
        SS_SHIPPING_WC()->test_connection()->handle_ajax();
    } catch (RuntimeException $e) {
        // Expected: wp_send_json() ends with wp_die().
    }
    ob_end_clean();
}

it('logs a successful connection test at info level even with the debug setting off', function () {
    with_ss_settings(['ss_debug' => 'no', 'api_token' => 'policy-test-token']);
    $spy = spy_on_logger();
    mock_smart_send_api(function () {
        return ss_api_response(200, ['data' => ['email' => 'merchant@example.test', 'website' => 'example.test']]);
    });

    ss_policy_run_connection_test();

    $succeeded = ss_policy_entry($spy, 'API token connection test succeeded');
    expect($succeeded)->not->toBeNull()
        ->and($succeeded['level'])->toBe('info')
        ->and($succeeded['context']['message'])->toContain('merchant@example.test');
});

it('logs a failed connection test at error level with the API response detail in context', function () {
    with_ss_settings(['ss_debug' => 'no', 'api_token' => 'policy-test-token']);
    $spy = spy_on_logger();
    mock_smart_send_api(function () {
        return ss_api_response(401, ['code' => 'Unauthenticated', 'message' => 'The API token is invalid.']);
    });

    ss_policy_run_connection_test();

    $failed = ss_policy_entry($spy, 'API token connection test failed');
    expect($failed)->not->toBeNull()
        ->and($failed['level'])->toBe('error')
        ->and($failed['context']['message'])->toContain('The API token is invalid.');
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
