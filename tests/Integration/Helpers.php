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
 * @var callable[] Cleanup callbacks registered during the current test.
 */
$GLOBALS['ss_integration_cleanup_callbacks'] = [];

/**
 * Track a created WooCommerce object for post-test deletion.
 */
function remember_for_cleanup($object)
{
    $GLOBALS['ss_integration_created'][] = $object;

    return $object;
}

/**
 * Register a callback that must run after the test (restore an option,
 * remove a filter, delete a tax rate, ...). Runs newest first.
 */
function remember_cleanup_callback(callable $callback): void
{
    $GLOBALS['ss_integration_cleanup_callbacks'][] = $callback;
}

/**
 * Force-delete every object created during the test, newest first (orders
 * before the products they reference), then run the cleanup callbacks.
 */
function cleanup_created_objects(): void
{
    foreach (array_reverse($GLOBALS['ss_integration_created']) as $object) {
        $object->delete(true);
    }

    $GLOBALS['ss_integration_created'] = [];

    foreach (array_reverse($GLOBALS['ss_integration_cleanup_callbacks']) as $callback) {
        $callback();
    }

    $GLOBALS['ss_integration_cleanup_callbacks'] = [];
}

/**
 * Override the Smart Send plugin settings for the duration of the test.
 * The suite runs against the shared development database, so the previous
 * value is restored afterwards.
 */
function with_ss_settings(array $overrides = []): array
{
    $option_key = 'woocommerce_smart_send_shipping_settings';
    $original   = get_option($option_key);

    $settings = array_merge([
        'demo'                            => 'yes',
        'ss_debug'                        => 'no',
        'include_order_comment'           => 'no',
        'save_shipping_labels_in_uploads' => 'no',
        'dropdown_display_format'         => '4',
        'order_status'                    => '0',
    ], $overrides);

    update_option($option_key, $settings);

    remember_cleanup_callback(function () use ($option_key, $original): void {
        if ($original === false) {
            delete_option($option_key);
        } else {
            update_option($option_key, $original);
        }
    });

    return $settings;
}

/**
 * Override any WordPress option for the duration of the test.
 */
function with_option(string $option_key, $value): void
{
    $original = get_option($option_key);

    update_option($option_key, $value);

    remember_cleanup_callback(function () use ($option_key, $original): void {
        if ($original === false) {
            delete_option($option_key);
        } else {
            update_option($option_key, $original);
        }
    });
}

/**
 * Mock the Smart Send API through the pre_http_request filter. Every request
 * to smartsend.io is captured and answered by $responder (defaults to a
 * successful create-shipment-and-labels response). Returns the capture
 * object; inspect ->requests for [url, method, body] entries.
 */
function mock_smart_send_api(?callable $responder = null): object
{
    $capture = new class {
        public array $requests = [];
    };

    $responder = $responder ?? function () {
        return ss_api_response(200, ['data' => ss_api_shipment_data()]);
    };

    $filter = function ($pre, $args, $url) use ($capture, $responder) {
        if (strpos($url, 'smartsend.io') === false) {
            return $pre;
        }

        $capture->requests[] = [
            'url'     => $url,
            'method'  => $args['method'] ?? null,
            'body'    => $args['body'] ?? null,
            'timeout' => $args['timeout'] ?? null,
        ];

        return $responder($url, $args);
    };

    add_filter('pre_http_request', $filter, 10, 3);

    remember_cleanup_callback(function () use ($filter): void {
        remove_filter('pre_http_request', $filter, 10);
    });

    return $capture;
}

/**
 * Build a wp_remote_request-shaped response array with a JSON body.
 */
function ss_api_response(int $status, array $body): array
{
    return [
        'response' => ['code' => $status, 'message' => $status === 200 ? 'OK' : 'Error'],
        'headers'  => ['content-type' => 'application/json'],
        'body'     => json_encode($body),
        'cookies'  => [],
        'filename' => null,
    ];
}

/**
 * A successful shipment payload as returned by createShipmentAndLabels.
 */
function ss_api_shipment_data(array $overrides = []): array
{
    return array_merge([
        'shipment_id'  => $overrides['shipment_id'] ?? 'test-shipment-' . uniqid(),
        'carrier_name' => 'PostNord',
        'carrier_code' => 'postnord',
        'pdf'          => [
            'link'             => 'https://api.example.test/labels/label.pdf',
            'base_64_encoded'  => base64_encode('%PDF-fake'),
        ],
        'parcels'      => [
            [
                'parcel_internal_id' => 1,
                'tracking_code'      => 'TRACK-1234',
                'tracking_link'      => 'https://tracking.example.test/TRACK-1234',
            ],
        ],
    ], $overrides);
}

/**
 * An error response body in the shape the Smart Send API produces.
 */
function ss_api_error_body(string $message = 'The given data was invalid.'): array
{
    return [
        'links'   => ['about' => 'https://app.smartsend.io/help/errors/ValidationException'],
        'id'      => 'test-error-id',
        'code'    => 'ValidationException',
        'message' => $message,
        'errors'  => ['receiver.postal_code' => ['The postal code is invalid.']],
    ];
}

/**
 * Store a pickup point selection on an order through the repository
 * (SS_Shipping_Order_Meta::write()), the way checkout does.
 */
function save_order_pickup_point(int $order_id, object $agent): void
{
    $details = new SS_Shipping_Delivery_Details();
    $details->set_pickup_point(SS_Shipping_Pickup_Point::from_object($agent));

    SS_SHIPPING_WC()->order_meta()->write($order_id, $details);
}

/**
 * Store a parcel split on an order through the repository, from rows in
 * the frozen id/name/value meta shape.
 */
function save_order_parcels(int $order_id, array $rows): void
{
    $details = new SS_Shipping_Delivery_Details();
    $details->set_parcel_plan(SS_Shipping_Parcel_Plan::from_box_rows($rows));

    SS_SHIPPING_WC()->order_meta()->write($order_id, $details);
}

/**
 * A pickup point agent object as the plugin stores it in order meta.
 */
function sample_agent(array $overrides = []): object
{
    return (object) array_merge([
        'id'            => 7,
        'agent_no'      => '1234',
        'company'       => 'Corner Shop',
        'address_line1' => 'Main Street 1',
        'address_line2' => null,
        'postal_code'   => '2300',
        'city'          => 'Copenhagen',
        'country'       => 'DK',
        'distance'      => 0.5,
    ], $overrides);
}

function create_simple_product(array $props = []): WC_Product_Simple
{
    $product = new WC_Product_Simple();
    $product->set_name($props['name'] ?? 'Integration Test Product');
    $product->set_regular_price((string) ($props['price'] ?? '100'));
    if (array_key_exists('weight', $props)) {
        if ($props['weight'] !== null) {
            $product->set_weight((string) $props['weight']);
        }
    } else {
        $product->set_weight('1');
    }

    if (isset($props['sale_price'])) {
        $product->set_sale_price((string) $props['sale_price']);
    }
    if (isset($props['sku'])) {
        $product->set_sku($props['sku']);
    }
    if (isset($props['shipping_class_id'])) {
        $product->set_shipping_class_id($props['shipping_class_id']);
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

    // Per-product Smart Send customs meta (stored as plain post meta by
    // SS_Shipping_WC_Product and read back by SS_Shipping_Shipment).
    foreach (['hs_code' => '_ss_hs_code', 'customs_desc' => '_ss_customs_desc', 'country_of_origin' => '_ss_country_of_origin'] as $prop => $meta_key) {
        if (isset($props[$prop])) {
            update_post_meta($product->get_id(), $meta_key, $props[$prop]);
        }
    }

    return remember_for_cleanup($product);
}

/**
 * Build a variable product with a single "Size: Large" variation.
 *
 * Variation-level $props: price, weight, sku (all applied to the variation).
 * Returns [WC_Product_Variable $parent, WC_Product_Variation $variation].
 */
function create_variable_product(array $props = []): array
{
    $attribute = new WC_Product_Attribute();
    $attribute->set_name('Size');
    $attribute->set_options(['Large']);
    $attribute->set_visible(true);
    $attribute->set_variation(true);

    $parent = new WC_Product_Variable();
    $parent->set_name($props['name'] ?? 'Integration Test Variable Product');
    $parent->set_attributes([$attribute]);
    $parent->save();
    remember_for_cleanup($parent);

    $variation = new WC_Product_Variation();
    $variation->set_parent_id($parent->get_id());
    $variation->set_attributes(['size' => 'Large']);
    $variation->set_regular_price((string) ($props['price'] ?? '100'));
    $variation->set_weight((string) ($props['weight'] ?? '1'));
    if (isset($props['sku'])) {
        $variation->set_sku($props['sku']);
    }
    $variation->save();
    remember_for_cleanup($variation);

    return [$parent, $variation];
}

/**
 * Create a shipping class term; returns its term id.
 */
function create_shipping_class(string $name): int
{
    $term = wp_insert_term($name . '-' . uniqid(), 'product_shipping_class');

    if (is_wp_error($term)) {
        throw new RuntimeException('Could not create shipping class: ' . $term->get_error_message());
    }

    $term_id = (int) $term['term_id'];

    remember_cleanup_callback(function () use ($term_id): void {
        wp_delete_term($term_id, 'product_shipping_class');
    });

    return $term_id;
}

/**
 * Insert a standard tax rate, deleted again after the test.
 */
function create_tax_rate(array $props = []): int
{
    $rate_id = WC_Tax::_insert_tax_rate([
        'tax_rate_country'  => $props['country'] ?? 'DK',
        'tax_rate_state'    => '',
        'tax_rate'          => (string) ($props['rate'] ?? '25.0000'),
        'tax_rate_name'     => $props['name'] ?? 'Moms',
        'tax_rate_priority' => 1,
        'tax_rate_compound' => 0,
        'tax_rate_shipping' => $props['shipping'] ?? 1,
        'tax_rate_order'    => 0,
        'tax_rate_class'    => '',
    ]);

    remember_cleanup_callback(function () use ($rate_id): void {
        WC_Tax::_delete_tax_rate($rate_id);
    });

    return $rate_id;
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
 *   products        : array of WC_Product or [WC_Product, int $quantity]
 *   coupons         : coupon code string or array of codes
 *   address         : partial address overrides (merged over the Danish default)
 *   billing_address : partial billing overrides (defaults to `address`)
 *   status          : order status (default 'processing')
 *   shipping_method : Smart Send method code (e.g. 'postnord_agent'); adds a
 *                     smart_send_shipping order item like checkout does
 *   shipping_total  : shipping cost for that item (default '0')
 *   return_method   : value for the smart_send_return_method item meta
 *   auto_return     : 'yes'/'no' for smart_send_auto_generate_return_label
 *   fees            : array of [name, amount] fee lines
 *   customer_note   : the order's customer note
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

    $billing_address = array_merge($address, [
        'email' => 'integration-test@smartsend.io',
        'phone' => '+4512345678',
    ], $args['billing_address'] ?? []);

    $order->set_address($billing_address, 'billing');
    $order->set_address($address, 'shipping');

    if (!empty($args['shipping_method'])) {
        $shipping_item = new WC_Order_Item_Shipping();
        $shipping_item->set_method_title('Smart Send');
        $shipping_item->set_method_id('smart_send_shipping');
        $shipping_item->set_instance_id(1);
        $shipping_item->set_total((string) ($args['shipping_total'] ?? '0'));
        $shipping_item->add_meta_data('smart_send_shipping_method', $args['shipping_method'], true);
        $shipping_item->add_meta_data('smart_send_return_method', $args['return_method'] ?? 'postnord_returndropoff', true);
        $shipping_item->add_meta_data('smart_send_auto_generate_return_label', $args['auto_return'] ?? 'no', true);
        $order->add_item($shipping_item);
    }

    foreach ($args['fees'] ?? [] as [$fee_name, $fee_amount]) {
        $fee = new WC_Order_Item_Fee();
        $fee->set_name($fee_name);
        $fee->set_amount((string) $fee_amount);
        $fee->set_total((string) $fee_amount);
        $order->add_item($fee);
    }

    if (isset($args['customer_note'])) {
        $order->set_customer_note($args['customer_note']);
    }

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
