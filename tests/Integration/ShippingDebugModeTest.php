<?php

/*
 * WooCommerce shipping debug mode support (#5): when the merchant enables
 * WooCommerce → Settings → Shipping → "Enable debug mode", every evaluated
 * Smart Send method is surfaced as ONE summary notice through
 * SS_Shipping_Checkout_Debug::add_notice() - the availability verdict with
 * the evaluated total and cart weight, plus how the offered cost was
 * derived. The step-by-step trace stays in the log only. With the option
 * off nothing changes.
 *
 * The notice gating mirrors WooCommerce core: only when
 * woocommerce_shipping_debug_mode is "yes", never when WOOCOMMERCE_CHECKOUT
 * or WC_DOING_AJAX is defined, and deduplicated via wc_has_notice().
 */

/**
 * Build a Smart Send shipping method instance with the given instance
 * settings persisted in the options table (restored after the test).
 */
function ss_debug_create_method(array $instance_settings = [], int $instance_id = 99941): SS_Shipping_WC_Method
{
    $settings = array_merge([
        'title'                      => 'SS Debug Method',
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
function ss_debug_cart_package(array $product_lines): array
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
function ss_debug_notice_texts(): array
{
    return array_map(
        fn (array $notice): string => (string) $notice['notice'],
        wc_get_notices('success')
    );
}

/**
 * Only the Smart Send debug notices - WooCommerce core adds its own zone
 * notices ("Customer matched zone ...") to the same bar while debug mode
 * is on.
 */
function ss_debug_smart_send_notices(): array
{
    return array_values(array_filter(
        ss_debug_notice_texts(),
        fn (string $notice): bool => str_starts_with($notice, 'Smart Send')
    ));
}

beforeEach(function (): void {
    with_ss_settings();

    // Notices live in the WooCommerce session; make sure it exists and is
    // clean before and after every test.
    if (is_null(WC()->cart)) {
        wc_load_cart();
    }
    wc_clear_notices();
    remember_cleanup_callback(function (): void {
        wc_clear_notices();
    });
});

it('surfaces one evaluation summary line per method when shipping debug mode is on', function () {
    with_option('woocommerce_shipping_debug_mode', 'yes');

    $product = create_simple_product(['price' => 100, 'weight' => 1.5]);
    $package = ss_debug_cart_package([[$product, 2]]); // 3 kg, 200 total

    $method = ss_debug_create_method([
        'cost_weight' => [
            ['ss_min_weight' => '1', 'ss_max_weight' => '5', 'ss_cost_weight' => '49'],
        ],
    ]);
    $method->calculate_shipping($package);

    $unit    = get_option('woocommerce_weight_unit');
    $notices = ss_debug_smart_send_notices();

    // ONE Smart Send line: the verdict, the evaluated inputs and the cost derivation.
    expect($notices)->toHaveCount(1)
        ->and($notices[0])->toBe(
            'Smart Send: Evaluated method "SS Debug Method" (smart_send_shipping:99941) as available'
            . ' (total=200, weight=3 ' . $unit . '). Weight table row 1-5 ' . $unit . ' applied, cost 49.'
        );
});

it('surfaces the free shipping decision inside the evaluation summary', function () {
    with_option('woocommerce_shipping_debug_mode', 'yes');

    $product = create_simple_product(['price' => 100, 'weight' => 2]);
    $package = ss_debug_cart_package([$product]);

    $method = ss_debug_create_method([
        'requires'     => 'enabled',
        'flatfee_cost' => '25',
        'cost_weight'  => [['ss_min_weight' => '1', 'ss_max_weight' => '5', 'ss_cost_weight' => '49']],
    ]);
    $method->calculate_shipping($package);

    $unit    = get_option('woocommerce_weight_unit');
    $notices = ss_debug_smart_send_notices();

    expect($notices)->toHaveCount(1)
        ->and($notices[0])->toBe(
            'Smart Send: Evaluated method "SS Debug Method" (smart_send_shipping:99941) as available'
            . ' (total=100, weight=2 ' . $unit . '). Flat fee cost 25 applied.'
        );
});

it('explains why no rate was added when the cart weight matches no table row', function () {
    with_option('woocommerce_shipping_debug_mode', 'yes');

    $product = create_simple_product(['price' => 100, 'weight' => 10]);
    $package = ss_debug_cart_package([$product]);

    $method = ss_debug_create_method([
        'cost_weight' => [['ss_min_weight' => '1', 'ss_max_weight' => '5', 'ss_cost_weight' => '49']],
    ]);
    $method->calculate_shipping($package);

    $unit    = get_option('woocommerce_weight_unit');
    $notices = ss_debug_smart_send_notices();

    expect($method->rates)->toHaveCount(0)
        ->and($notices)->toHaveCount(1)
        ->and($notices[0])->toBe(
            'Smart Send: Evaluated method "SS Debug Method" (smart_send_shipping:99941) as not available'
            . ' (total=100, weight=10 ' . $unit . '): the cart weight matched no weight table row.'
        );
});

it('explains why the method is excluded by the availability options', function () {
    with_option('woocommerce_shipping_debug_mode', 'yes');

    $product = create_simple_product(['price' => 100, 'weight' => 1]);
    $package = ss_debug_cart_package([$product]);

    // The integration suite runs unauthenticated, so the current "customer"
    // is the guest pseudo-role.
    $method = ss_debug_create_method([
        'advanced_settings_enable' => 'yes',
        'user_roles'               => ['guest'],
    ]);

    expect($method->is_available($package))->toBeFalse();

    $trace = implode("\n", ss_debug_notice_texts());

    expect($trace)->toContain('Smart Send: Evaluated method "SS Debug Method" (smart_send_shipping:99941) as not available: customer role "guest" is excluded.');
});

it('explains why the method is excluded by the shipping-class condition', function () {
    with_option('woocommerce_shipping_debug_mode', 'yes');

    $product = create_simple_product(['price' => 100, 'weight' => 1]);
    $package = ss_debug_cart_package([$product]);

    // Require ALL products in a shipping class the product is not in.
    $method = ss_debug_create_method([
        'advanced_settings_enable'   => 'yes',
        'display_shipping_class'     => ['some-missing-class'],
        'display_shipping_class_opt' => 'all_shipping_class',
    ]);

    expect($method->is_available($package))->toBeFalse();

    $trace = implode("\n", ss_debug_notice_texts());

    expect($trace)->toContain(
        'Smart Send: Evaluated method "SS Debug Method" (smart_send_shipping:99941) as not available:'
        . ' not ALL products belong to one of the selected shipping classes.'
    );
});

it('adds no notices when shipping debug mode is off', function () {
    with_option('woocommerce_shipping_debug_mode', 'no');

    $product = create_simple_product(['price' => 100, 'weight' => 1.5]);
    $package = ss_debug_cart_package([[$product, 2]]);

    $method = ss_debug_create_method([
        'cost_weight' => [['ss_min_weight' => '1', 'ss_max_weight' => '5', 'ss_cost_weight' => '49']],
    ]);
    $method->calculate_shipping($package);

    expect($method->rates)->toHaveCount(1)
        ->and(wc_notice_count())->toBe(0);
});

it('does not duplicate notices when rates are calculated twice', function () {
    with_option('woocommerce_shipping_debug_mode', 'yes');

    $product = create_simple_product(['price' => 100, 'weight' => 1.5]);
    $package = ss_debug_cart_package([[$product, 2]]);

    $method = ss_debug_create_method([
        'cost_weight' => [['ss_min_weight' => '1', 'ss_max_weight' => '5', 'ss_cost_weight' => '49']],
    ]);

    $method->calculate_shipping($package);
    $first_count = wc_notice_count('success');
    expect($first_count)->toBeGreaterThan(0);

    $method->calculate_shipping($package);

    expect(wc_notice_count('success'))->toBe($first_count);
});

/*
 * WARNING: the tests below define WC_DOING_AJAX for the remainder of the
 * PHP process (constants cannot be undefined), which is why they must stay
 * the LAST tests in this file. No later suite file asserts on checkout
 * notices.
 */
it('never adds notices when WC_DOING_AJAX is defined', function () {
    with_option('woocommerce_shipping_debug_mode', 'yes');

    if (! defined('WC_DOING_AJAX')) {
        define('WC_DOING_AJAX', true);
    }

    $product = create_simple_product(['price' => 100, 'weight' => 1.5]);
    $package = ss_debug_cart_package([[$product, 2]]);

    $method = ss_debug_create_method([
        'cost_weight' => [['ss_min_weight' => '1', 'ss_max_weight' => '5', 'ss_cost_weight' => '49']],
    ]);
    $method->calculate_shipping($package);

    expect($method->rates)->toHaveCount(1)
        ->and(wc_notice_count())->toBe(0);
});

/*
 * Focused SS_Shipping_Checkout_Debug gating check that needs WC_DOING_AJAX
 * defined. It lives here (not in CheckoutDebugTest.php) because that file
 * runs before this one and defining the constant there would poison every
 * notice test in this file.
 */
it('SS_Shipping_Checkout_Debug adds no notice when WC_DOING_AJAX is defined', function () {
    with_option('woocommerce_shipping_debug_mode', 'yes');

    expect(defined('WC_DOING_AJAX'))->toBeTrue();

    SS_Shipping_Checkout_Debug::add_notice('Smart Send AJAX-gated debug notice.');

    expect(wc_notice_count())->toBe(0);
});
