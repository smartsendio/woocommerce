<?php

/*
 * Characterization tests for SS_Shipping_WC_Method rate calculation: the
 * weight-bracket table, the free-shipping rules and the availability
 * options. These capture the CURRENT v8 semantics; #16 will change the
 * free-shipping behaviour deliberately and must update these tests.
 */

/**
 * Build a Smart Send shipping method instance with the given instance
 * settings persisted in the options table (restored after the test).
 */
function create_ss_method(array $instance_settings = [], int $instance_id = 99931): SS_Shipping_WC_Method
{
    $settings = array_merge([
        'title'                    => 'SS Test Method',
        'method'                   => 'postnord_agent',
        'return_method'            => 'postnord_returndropoff',
        'auto_generate_return_label' => 'no',
        'tax_status'               => 'none',
        'requires'                 => '',
        'flatfee_cost'             => '0',
        'min_amount'               => '0',
        'cost_weight'              => [],
        'advanced_settings_enable' => 'no',
    ], $instance_settings);

    with_option('woocommerce_smart_send_shipping_' . $instance_id . '_settings', $settings);

    return new SS_Shipping_WC_Method($instance_id);
}

/**
 * Put products in a fresh cart and return the shipping package the way
 * WooCommerce hands it to calculate_shipping().
 */
function cart_package(array $product_lines): array
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

beforeEach(function (): void {
    with_ss_settings();
});

it('adds a rate when the cart weight falls inside a bracket, with the Smart Send meta data', function () {
    $product = create_simple_product(['price' => 100, 'weight' => 1.5]);
    $package = cart_package([[$product, 2]]); // 3 kg

    $method = create_ss_method([
        'cost_weight' => [
            ['ss_min_weight' => '1', 'ss_max_weight' => '5', 'ss_cost_weight' => '49'],
        ],
    ]);
    $method->calculate_shipping($package);

    expect($method->rates)->toHaveCount(1);

    $rate = current($method->rates);
    expect($rate->get_id())->toBe('smart_send_shipping:99931')
        ->and($rate->get_label())->toBe('SS Test Method')
        ->and((float) $rate->get_cost())->toBe(49.0)
        ->and($rate->get_meta_data())->toEqual([
            'smart_send_shipping_method'            => 'postnord_agent',
            'smart_send_return_method'              => 'postnord_returndropoff',
            'smart_send_auto_generate_return_label' => 'no',
            // Added by WooCommerce core (WC_Shipping_Method::add_rate).
            'Items'                                 => 'Integration Test Product &times; 2',
        ]);
});

it('treats the bracket minimum as inclusive and the maximum as exclusive', function () {
    $product = create_simple_product(['price' => 100, 'weight' => 3]);
    $package = cart_package([$product]); // exactly 3 kg

    $on_min = create_ss_method([
        'cost_weight' => [['ss_min_weight' => '3', 'ss_max_weight' => '5', 'ss_cost_weight' => '49']],
    ]);
    $on_min->calculate_shipping($package);

    $on_max = create_ss_method([
        'cost_weight' => [['ss_min_weight' => '1', 'ss_max_weight' => '3', 'ss_cost_weight' => '49']],
    ], 99932);
    $on_max->calculate_shipping($package);

    expect($on_min->rates)->toHaveCount(1)
        ->and($on_max->rates)->toHaveCount(0);
});

it('treats a bracket bound of 0 as "no bound" because of the empty() check', function () {
    $product = create_simple_product(['price' => 100, 'weight' => 0.5]);
    $package = cart_package([$product]);

    // v8 oddity: min/max are checked with empty(), so a "0" minimum (and an
    // empty maximum) never constrain the bracket.
    $method = create_ss_method([
        'cost_weight' => [['ss_min_weight' => '0', 'ss_max_weight' => '', 'ss_cost_weight' => '29']],
    ]);
    $method->calculate_shipping($package);

    expect($method->rates)->toHaveCount(1)
        ->and((float) current($method->rates)->get_cost())->toBe(29.0);
});

it('adds no rate when no bracket matches the cart weight', function () {
    $product = create_simple_product(['price' => 100, 'weight' => 10]);
    $package = cart_package([$product]);

    $method = create_ss_method([
        'cost_weight' => [['ss_min_weight' => '1', 'ss_max_weight' => '5', 'ss_cost_weight' => '49']],
    ]);
    $method->calculate_shipping($package);

    expect($method->rates)->toHaveCount(0);
});

it('keeps only the last matching bracket when brackets overlap', function () {
    $product = create_simple_product(['price' => 100, 'weight' => 2]);
    $package = cart_package([$product]);

    // v8 oddity: every matching bracket calls add_rate() with the same rate
    // id, so the last matching row silently wins.
    $method = create_ss_method([
        'cost_weight' => [
            ['ss_min_weight' => '1', 'ss_max_weight' => '5', 'ss_cost_weight' => '49'],
            ['ss_min_weight' => '1', 'ss_max_weight' => '10', 'ss_cost_weight' => '99'],
        ],
    ]);
    $method->calculate_shipping($package);

    expect($method->rates)->toHaveCount(1)
        ->and((float) current($method->rates)->get_cost())->toBe(99.0);
});

it('evaluates cost formulas with the [qty] placeholder', function () {
    $product = create_simple_product(['price' => 100, 'weight' => 1]);
    $package = cart_package([[$product, 3]]);

    $method = create_ss_method([
        'cost_weight' => [['ss_min_weight' => '1', 'ss_max_weight' => '5', 'ss_cost_weight' => '10 * [qty]']],
    ]);
    $method->calculate_shipping($package);

    expect((float) current($method->rates)->get_cost())->toBe(30.0);
});

it('uses the flat fee instead of the weight table when the minimum order amount is met', function () {
    $product = create_simple_product(['price' => 150, 'weight' => 2]);
    $package = cart_package([$product]);

    $method = create_ss_method([
        'requires'     => 'min_amount',
        'min_amount'   => '100',
        'flatfee_cost' => '0',
        'cost_weight'  => [['ss_min_weight' => '1', 'ss_max_weight' => '5', 'ss_cost_weight' => '49']],
    ]);
    $method->calculate_shipping($package);

    expect($method->rates)->toHaveCount(1)
        ->and((float) current($method->rates)->get_cost())->toBe(0.0);
});

it('falls back to the weight table below the free-shipping threshold', function () {
    $product = create_simple_product(['price' => 50, 'weight' => 2]);
    $package = cart_package([$product]);

    $method = create_ss_method([
        'requires'     => 'min_amount',
        'min_amount'   => '100',
        'flatfee_cost' => '0',
        'cost_weight'  => [['ss_min_weight' => '1', 'ss_max_weight' => '5', 'ss_cost_weight' => '49']],
    ]);
    $method->calculate_shipping($package);

    expect($method->rates)->toHaveCount(1)
        ->and((float) current($method->rates)->get_cost())->toBe(49.0);
});

it('grants free shipping for a valid free-shipping coupon when required', function () {
    $product = create_simple_product(['price' => 50, 'weight' => 2]);
    $coupon  = create_coupon(['type' => 'percent', 'amount' => 0]);
    $coupon->set_free_shipping(true);
    $coupon->save();

    $package = cart_package([$product]);
    WC()->cart->apply_coupon($coupon->get_code());

    $method = create_ss_method([
        'requires'     => 'coupon',
        'flatfee_cost' => '0',
        'cost_weight'  => [['ss_min_weight' => '1', 'ss_max_weight' => '5', 'ss_cost_weight' => '49']],
    ]);
    $method->calculate_shipping($package);

    expect($method->rates)->toHaveCount(1)
        ->and((float) current($method->rates)->get_cost())->toBe(0.0);
});

it('charges the flat fee whenever the rules are set to always enabled', function () {
    $product = create_simple_product(['price' => 10, 'weight' => 2]);
    $package = cart_package([$product]);

    $method = create_ss_method([
        'requires'     => 'enabled',
        'flatfee_cost' => '25',
        'cost_weight'  => [['ss_min_weight' => '1', 'ss_max_weight' => '5', 'ss_cost_weight' => '49']],
    ]);
    $method->calculate_shipping($package);

    expect($method->rates)->toHaveCount(1)
        ->and((float) current($method->rates)->get_cost())->toBe(25.0);
});

it('is available by default and respects the guest role exclusion', function () {
    $product = create_simple_product(['price' => 100, 'weight' => 1]);
    $package = cart_package([$product]);

    $default = create_ss_method();
    expect($default->is_available($package))->toBeTrue();

    // The integration suite runs unauthenticated, so the current "customer"
    // is the guest pseudo-role.
    $excluding_guests = create_ss_method([
        'advanced_settings_enable' => 'yes',
        'user_roles'               => ['guest'],
    ], 99932);
    expect($excluding_guests->is_available($package))->toBeFalse();
});

it('applies the shipping class availability options', function () {
    $class_id = create_shipping_class('ss-test-class');
    $classed  = create_simple_product(['price' => 100, 'weight' => 1, 'shipping_class_id' => $class_id]);
    $package  = cart_package([$classed]);

    $class_slug = get_term($class_id, 'product_shipping_class')->slug;

    $requires_one = create_ss_method([
        'advanced_settings_enable'   => 'yes',
        'display_shipping_class_opt' => 'one_shipping_class',
        'display_shipping_class'     => [$class_slug],
    ]);
    expect($requires_one->is_available($package))->toBeTrue();

    $excludes_all = create_ss_method([
        'advanced_settings_enable'   => 'yes',
        'display_shipping_class_opt' => 'nall_shipping_class',
        'display_shipping_class'     => [$class_slug],
    ], 99932);
    expect($excludes_all->is_available($package))->toBeFalse();
});
