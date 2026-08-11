<?php

/*
 * Verifies the integration harness itself: WordPress + WooCommerce are
 * loaded in-process, the Smart Send plugin is active, and the factories in
 * Helpers.php can build realistic orders. Scenario tests (payload building,
 * filters) build on this foundation.
 */

it('loads WordPress and WooCommerce in-process', function () {
    expect(defined('ABSPATH'))->toBeTrue()
        ->and(did_action('init'))->toBeGreaterThan(0)
        ->and(class_exists('WooCommerce'))->toBeTrue()
        ->and(did_action('woocommerce_loaded'))->toBeGreaterThan(0);
});

it('has the Smart Send plugin active', function () {
    expect(function_exists('SS_SHIPPING_WC'))->toBeTrue()
        ->and(SS_SHIPPING_WC())->toBeInstanceOf(SS_Shipping_WC::class)
        ->and(class_exists('Smartsend\\Api'))->toBeTrue();
});

it('creates an order with products and totals', function () {
    $product = create_simple_product(['price' => 100, 'weight' => 1.5]);

    $order = create_order(['products' => [[$product, 2]]]);

    expect($order->get_id())->toBeGreaterThan(0)
        ->and((float) $order->get_total())->toBe(200.0)
        ->and($order->get_shipping_country())->toBe('DK')
        ->and(count($order->get_items()))->toBe(1);
});

it('creates an order with a percentage coupon applied', function () {
    $product = create_simple_product(['price' => 100]);
    $coupon  = create_coupon(['type' => 'percent', 'amount' => 10]);

    $order = create_order([
        'products' => [$product],
        'coupons'  => $coupon->get_code(),
    ]);

    expect((float) $order->get_discount_total())->toBe(10.0)
        ->and((float) $order->get_total())->toBe(90.0)
        ->and($order->get_coupon_codes())->toBe([$coupon->get_code()]);
});

it('cleans up created objects between tests', function () {
    // The orders and products from the previous tests must be gone; the
    // factories force-delete everything they create after each test.
    $orders = wc_get_orders([
        'created_via' => 'integration-test',
        'limit'       => -1,
        'return'      => 'ids',
    ]);

    expect($orders)->toBe([]);
});
