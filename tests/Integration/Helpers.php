<?php

/*
|--------------------------------------------------------------------------
| Integration suite factories
|--------------------------------------------------------------------------
|
| Small factories for building WooCommerce fixtures inside integration
| tests. The suite runs against the shared local development database (no
| transaction rollback), so every created object is registered here and
| force-deleted after each test by the afterEach hook in tests/Pest.php.
|
*/

/**
 * @var WC_Data[] Objects created during the current test, oldest first.
 */
$GLOBALS['ss_integration_created'] = [];

/**
 * Track a created WooCommerce object for post-test deletion.
 */
function remember_for_cleanup($object)
{
    $GLOBALS['ss_integration_created'][] = $object;

    return $object;
}

/**
 * Force-delete every object created during the test, newest first (orders
 * before the products they reference).
 */
function cleanup_created_objects(): void
{
    foreach (array_reverse($GLOBALS['ss_integration_created']) as $object) {
        $object->delete(true);
    }

    $GLOBALS['ss_integration_created'] = [];
}

function create_simple_product(array $props = []): WC_Product_Simple
{
    $product = new WC_Product_Simple();
    $product->set_name($props['name'] ?? 'Integration Test Product');
    $product->set_regular_price((string) ($props['price'] ?? '100'));
    $product->set_weight((string) ($props['weight'] ?? '1'));

    if (isset($props['sale_price'])) {
        $product->set_sale_price((string) $props['sale_price']);
    }
    if (isset($props['length'], $props['width'], $props['height'])) {
        $product->set_length((string) $props['length']);
        $product->set_width((string) $props['width']);
        $product->set_height((string) $props['height']);
    }
    if (isset($props['virtual'])) {
        $product->set_virtual($props['virtual']);
    }

    $product->save();

    return remember_for_cleanup($product);
}

function create_coupon(array $props = []): WC_Coupon
{
    $coupon = new WC_Coupon();
    $coupon->set_code($props['code'] ?? uniqid('ss-test-'));
    $coupon->set_discount_type($props['type'] ?? 'percent');
    $coupon->set_amount((float) ($props['amount'] ?? 10));
    $coupon->save();

    return remember_for_cleanup($coupon);
}

/**
 * Build an order the way checkout would: line items from real products, a
 * Danish shipping/billing address, optional coupons, recalculated totals.
 *
 * Supported $args:
 *   products : array of WC_Product or [WC_Product, int $quantity]
 *   coupons  : coupon code string or array of codes
 *   address  : partial address overrides (merged over the Danish default)
 *   status   : order status (default 'processing')
 */
function create_order(array $args = []): WC_Order
{
    $order = wc_create_order([
        'status'      => $args['status'] ?? 'processing',
        'created_via' => 'integration-test',
    ]);
    remember_for_cleanup($order);

    foreach ($args['products'] ?? [] as $line) {
        [$product, $quantity] = is_array($line) ? $line : [$line, 1];
        $order->add_product($product, $quantity);
    }

    $address = array_merge([
        'first_name' => 'Test',
        'last_name'  => 'Customer',
        'address_1'  => 'Islands Brygge 39',
        'city'       => 'Copenhagen',
        'postcode'   => '2300',
        'country'    => 'DK',
    ], $args['address'] ?? []);

    $order->set_address(array_merge($address, [
        'email' => 'integration-test@smartsend.io',
        'phone' => '+4512345678',
    ]), 'billing');
    $order->set_address($address, 'shipping');

    foreach ((array) ($args['coupons'] ?? []) as $code) {
        $result = $order->apply_coupon($code);
        if (is_wp_error($result)) {
            throw new RuntimeException("Could not apply coupon '{$code}': " . $result->get_error_message());
        }
    }

    $order->calculate_totals();
    $order->save();

    return $order;
}
