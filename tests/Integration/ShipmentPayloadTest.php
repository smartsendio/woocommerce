<?php

/*
 * Characterization ("golden") tests for the booking payload that
 * SS_Shipping_Booking_Service sends to the Smart Send API. Each test builds
 * a representative order, captures the JSON body posted to the (mocked)
 * API, and compares it to the complete expected payload. If any field of
 * the booking request changes, these tests fail.
 *
 * IMPORTANT: these tests assert CURRENT v8 behaviour, including known
 * oddities (marked "v8 oddity"). Do not "fix" an expectation here without a
 * deliberate behaviour change in the plugin.
 */

/**
 * Send the order through SS_Shipping_Booking_Service against a mocked API
 * and return the decoded JSON payload of the createShipmentAndLabels
 * request.
 */
function capture_shipment_payload(WC_Order $order, bool $return = false): array
{
    $capture = mock_smart_send_api();

    $booking_service = new SS_Shipping_Booking_Service(new SS_Shipping_Order_Meta());
    $booking         = $return ? $booking_service->book_return($order) : $booking_service->book_outbound($order);
    expect($booking->is_successful())->toBeTrue();

    $request = end($capture->requests);
    expect($request['url'])->toContain('shipments/labels');

    return json_decode($request['body'], true);
}

/**
 * The full expected payload for a plain domestic order: one parcel with one
 * item line per product. $overrides is array_replace_recursive'd over the
 * base so each scenario states exactly what it changes.
 */
function expected_payload(WC_Order $order, array $overrides = []): array
{
    $order_id = (string) $order->get_id();

    $base = [
        'internal_id'        => $order_id,
        'internal_reference' => $order_id,
        'shipping_carrier'   => 'postnord',
        'shipping_method'    => 'agent',
        'shipping_date'      => date('Y-m-d'),
        'sender'             => null,
        'receiver'           => [
            'internal_id'        => $order_id,
            'internal_reference' => $order_id,
            'company'            => null,
            'name_line1'         => 'Test',
            'name_line2'         => 'Customer',
            'address_line1'      => 'Islands Brygge 39',
            'address_line2'      => null,
            'postal_code'        => '2300',
            'city'               => 'Copenhagen',
            'country'            => 'DK',
            'sms'                => '+4512345678',
            'email'              => 'integration-test@smartsend.io',
        ],
        'agent'              => null,
        'parcels'            => [],
        'services'           => [
            'email_notification' => 'integration-test@smartsend.io',
            'sms_notification'   => '+4512345678',
            'flex_delivery'      => null,
        ],
        'subtotal_price_excluding_tax' => null,
        'subtotal_price_including_tax' => null,
        'shipping_price_excluding_tax' => null,
        'shipping_price_including_tax' => null,
        'total_price_excluding_tax'    => null,
        'total_price_including_tax'    => null,
        'total_tax_amount'             => null,
        'currency'                     => 'DKK',
    ];

    return array_replace_recursive($base, $overrides);
}

/**
 * A full expected item line for a simple product.
 */
function expected_item(WC_Product $product, array $overrides = []): array
{
    $product_id = (string) $product->get_id();

    return array_replace([
        'internal_id'        => $product_id,
        'internal_reference' => $product_id,
        'sku'                => $product->get_sku() ? $product->get_sku() : $product_id,
        'name'               => $product->get_name(),
        'description'        => null,
        'hs_code'            => null,
        'country_of_origin'  => null,
        'image_url'          => null,
        'unit_weight'        => 1,
        'unit_price_excluding_tax' => 100,
        'unit_price_including_tax' => 100,
        'quantity'           => 1,
        'total_price_excluding_tax' => 100,
        'total_price_including_tax' => 100,
        'total_tax_amount'   => null,
    ], $overrides);
}

beforeEach(function (): void {
    with_ss_settings();
});

it('books a simple domestic agent order with the full expected payload', function () {
    $product = create_simple_product(['name' => 'Simple Product', 'price' => 100, 'weight' => 1.5, 'sku' => 'SIMPLE-' . uniqid()]);
    $order   = create_order([
        'products'        => [[$product, 2]],
        'shipping_method' => 'postnord_agent',
        'shipping_total'  => '39',
    ]);
    SS_SHIPPING_WC()->order_meta()->save_ss_shipping_order_agent($order->get_id(), sample_agent());

    $payload  = capture_shipment_payload($order);
    $order_id = (string) $order->get_id();

    expect($payload)->toEqual(expected_payload($order, [
        'agent' => [
            'internal_id'        => '7',
            'internal_reference' => '7',
            'agent_no'           => '1234',
            'company'            => 'Corner Shop',
            'name_line1'         => null,
            'name_line2'         => null,
            'address_line1'      => 'Main Street 1',
            'address_line2'      => null,
            'postal_code'        => '2300',
            'city'               => 'Copenhagen',
            'country'            => 'DK',
            'sms'                => null,
            'email'              => null,
        ],
        'parcels' => [
            [
                'internal_id'        => $order_id,
                'internal_reference' => $order_id,
                'weight'             => 3,
                'height'             => null,
                'width'              => null,
                'length'             => null,
                'freetext'           => null,
                'items'              => [
                    expected_item($product, [
                        'unit_weight'               => 1.5,
                        'quantity'                  => 2,
                        'total_price_excluding_tax' => 200,
                        'total_price_including_tax' => 200,
                    ]),
                ],
                'total_price_excluding_tax' => 200,
                'total_price_including_tax' => 200,
                'total_tax_amount'          => null,
            ],
        ],
        'subtotal_price_excluding_tax' => 200,
        'subtotal_price_including_tax' => 200,
        'shipping_price_excluding_tax' => 39,
        'shipping_price_including_tax' => 39,
        'total_price_excluding_tax'    => 239,
        'total_price_including_tax'    => 239,
    ]));
});

it('allocates an order-level percentage coupon discount down to the item lines (#128)', function () {
    // Deliberate behaviour change from the v8 oddity this test used to
    // assert: item lines used to be priced from the pre-discount line
    // subtotal, so an order-level coupon discount only showed up in the
    // parcel/order totals and sum(item totals) > order subtotal. #128 fixes
    // this by pricing item lines from the post-discount line total instead
    // (WC_Order_Item_Product::get_total(), which WooCommerce itself prorates
    // order-level coupons into) so item lines and totals now reconcile
    // exactly, without relying on the #54 clamp to hide the mismatch.
    $product = create_simple_product(['name' => 'Couponed Product', 'price' => 100, 'weight' => 1]);
    $coupon  = create_coupon(['type' => 'percent', 'amount' => 10]);
    $order   = create_order([
        'products'        => [[$product, 2]],
        'coupons'         => $coupon->get_code(),
        'shipping_method' => 'postnord_homedelivery',
        'shipping_total'  => '39',
    ]);
    $order_id = (string) $order->get_id();

    $payload = capture_shipment_payload($order);

    expect($payload)->toEqual(expected_payload($order, [
        'shipping_method' => 'homedelivery',
        'parcels'         => [
            [
                'internal_id'        => $order_id,
                'internal_reference' => $order_id,
                'weight'             => 2,
                'height'             => null,
                'width'              => null,
                'length'             => null,
                'freetext'           => null,
                'items'              => [
                    expected_item($product, [
                        'unit_price_excluding_tax'  => 90,
                        'unit_price_including_tax'  => 90,
                        'quantity'                  => 2,
                        'total_price_excluding_tax' => 180,
                        'total_price_including_tax' => 180,
                    ]),
                ],
                'total_price_excluding_tax' => 180,
                'total_price_including_tax' => 180,
                'total_tax_amount'          => null,
            ],
        ],
        'subtotal_price_excluding_tax' => 180,
        'subtotal_price_including_tax' => 180,
        'shipping_price_excluding_tax' => 39,
        'shipping_price_including_tax' => 39,
        'total_price_excluding_tax'    => 219,
        'total_price_including_tax'    => 219,
    ]));
});

it('folds an order fee into the parcel totals but not into any item line', function () {
    $product = create_simple_product(['name' => 'Product With Fee', 'price' => 100, 'weight' => 1]);
    $order   = create_order([
        'products'        => [$product],
        'fees'            => [['Handling fee', 25]],
        'shipping_method' => 'postnord_homedelivery',
        'shipping_total'  => '39',
    ]);
    $order_id = (string) $order->get_id();

    $payload = capture_shipment_payload($order);

    expect($payload)->toEqual(expected_payload($order, [
        'shipping_method' => 'homedelivery',
        'parcels'         => [
            [
                'internal_id'        => $order_id,
                'internal_reference' => $order_id,
                'weight'             => 1,
                'height'             => null,
                'width'              => null,
                'length'             => null,
                'freetext'           => null,
                'items'              => [expected_item($product)],
                // Fee is part of the order subtotal, so the parcel totals
                // exceed the sum of the item lines.
                'total_price_excluding_tax' => 125,
                'total_price_including_tax' => 125,
                'total_tax_amount'          => null,
            ],
        ],
        'subtotal_price_excluding_tax' => 125,
        'subtotal_price_including_tax' => 125,
        'shipping_price_excluding_tax' => 39,
        'shipping_price_including_tax' => 39,
        'total_price_excluding_tax'    => 164,
        'total_price_including_tax'    => 164,
    ]));
});

it('books a taxed order with the v8 tax semantics', function () {
    with_option('woocommerce_calc_taxes', 'yes');
    create_tax_rate(['rate' => '25.0000', 'country' => 'DK', 'shipping' => 1]);

    $product = create_simple_product(['name' => 'Taxed Product', 'price' => 100, 'weight' => 1]);
    $order   = create_order([
        'products'        => [$product],
        'shipping_method' => 'postnord_homedelivery',
        'shipping_total'  => '39',
    ]);
    $order_id = (string) $order->get_id();

    $payload = capture_shipment_payload($order);

    expect($payload)->toEqual(expected_payload($order, [
        'shipping_method' => 'homedelivery',
        'parcels'         => [
            [
                'internal_id'        => $order_id,
                'internal_reference' => $order_id,
                'weight'             => 1,
                'height'             => null,
                'width'              => null,
                'length'             => null,
                'freetext'           => null,
                'items'              => [
                    expected_item($product, [
                        'unit_price_including_tax'  => 125,
                        'total_price_including_tax' => 125,
                        'total_tax_amount'          => 25,
                    ]),
                ],
                'total_price_excluding_tax' => 100,
                'total_price_including_tax' => 125,
                'total_tax_amount'          => 25,
            ],
        ],
        // Totals reconcile: subtotal + shipping = total on both bases.
        'subtotal_price_excluding_tax' => 100,
        'subtotal_price_including_tax' => 125,
        'shipping_price_excluding_tax' => 39,
        'shipping_price_including_tax' => 48.75,
        'total_price_excluding_tax'    => 139,
        'total_price_including_tax'    => 173.75,
        'total_tax_amount'             => 34.75,
    ]));
});

it('includes customs data on the item lines for an international order', function () {
    $product = create_simple_product([
        'name'              => 'Customs Product',
        'price'             => 100,
        'weight'            => 1,
        'hs_code'           => '61091000',
        'customs_desc'      => 'Cotton t-shirt',
        'country_of_origin' => 'DK',
    ]);
    $order = create_order([
        'products'        => [$product],
        'address'         => [
            'address_1' => '350 Fifth Avenue',
            'city'      => 'New York',
            'postcode'  => '10118',
            'country'   => 'US',
        ],
        'shipping_method' => 'postnord_commercial',
        'shipping_total'  => '150',
    ]);
    $order_id = (string) $order->get_id();

    $payload = capture_shipment_payload($order);

    expect($payload)->toEqual(expected_payload($order, [
        'shipping_method' => 'commercial',
        'receiver'        => [
            'address_line1' => '350 Fifth Avenue',
            'postal_code'   => '10118',
            'city'          => 'New York',
            'country'       => 'US',
        ],
        'parcels' => [
            [
                'internal_id'        => $order_id,
                'internal_reference' => $order_id,
                'weight'             => 1,
                'height'             => null,
                'width'              => null,
                'length'             => null,
                'freetext'           => null,
                'items'              => [
                    expected_item($product, [
                        'description'       => 'Cotton t-shirt',
                        'hs_code'           => '61091000',
                        'country_of_origin' => 'DK',
                    ]),
                ],
                'total_price_excluding_tax' => 100,
                'total_price_including_tax' => 100,
                'total_tax_amount'          => null,
            ],
        ],
        'subtotal_price_excluding_tax' => 100,
        'subtotal_price_including_tax' => 100,
        'shipping_price_excluding_tax' => 150,
        'shipping_price_including_tax' => 150,
        'total_price_excluding_tax'    => 250,
        'total_price_including_tax'    => 250,
    ]));
});

it('drops the receiver phone when billing and shipping countries differ', function () {
    $product = create_simple_product(['price' => 100, 'weight' => 1]);
    $order   = create_order([
        'products'        => [$product],
        'address'         => [
            'address_1' => 'Karl Johans gate 1',
            'city'      => 'Oslo',
            'postcode'  => '0154',
            'country'   => 'NO',
        ],
        'billing_address' => [
            'address_1' => 'Islands Brygge 39',
            'city'      => 'Copenhagen',
            'postcode'  => '2300',
            'country'   => 'DK',
        ],
        'shipping_method' => 'postnord_homedelivery',
    ]);

    $payload = capture_shipment_payload($order);

    // Only local phone numbers are accepted by the API, so the billing
    // phone is dropped when the shipping country differs from billing.
    expect($payload['receiver']['sms'])->toBeNull()
        ->and($payload['receiver']['country'])->toBe('NO')
        ->and($payload['services']['sms_notification'])->toBeNull()
        ->and($payload['services']['email_notification'])->toBe('integration-test@smartsend.io');
});

it('uses the variation id, sku and weight for variable products', function () {
    [$parent, $variation] = create_variable_product([
        'name'   => 'Variable Product',
        'price'  => 100,
        'weight' => 2.5,
        'sku'    => 'VAR-L-' . uniqid(),
    ]);
    $order = create_order([
        'products'        => [$variation],
        'shipping_method' => 'postnord_agent',
    ]);
    $order_id     = (string) $order->get_id();
    $variation_id = (string) $variation->get_id();

    $payload = capture_shipment_payload($order);

    expect($payload)->toEqual(expected_payload($order, [
        'parcels' => [
            [
                'internal_id'        => $order_id,
                'internal_reference' => $order_id,
                'weight'             => 2.5,
                'height'             => null,
                'width'              => null,
                'length'             => null,
                'freetext'           => null,
                'items'              => [
                    [
                        'internal_id'        => $variation_id,
                        'internal_reference' => $variation_id,
                        'sku'                => $variation->get_sku(),
                        'name'               => 'Variable Product',
                        'description'        => null,
                        'hs_code'            => null,
                        'country_of_origin'  => null,
                        'image_url'          => null,
                        'unit_weight'        => 2.5,
                        'unit_price_excluding_tax' => 100,
                        'unit_price_including_tax' => 100,
                        'quantity'           => 1,
                        'total_price_excluding_tax' => 100,
                        'total_price_including_tax' => 100,
                        'total_tax_amount'   => null,
                    ],
                ],
                'total_price_excluding_tax' => 100,
                'total_price_including_tax' => 100,
                'total_tax_amount'          => null,
            ],
        ],
        'subtotal_price_excluding_tax' => 100,
        'subtotal_price_including_tax' => 100,
        'total_price_excluding_tax'    => 100,
        'total_price_including_tax'    => 100,
    ]));
});

it('reads customs meta from the variation, falling back to the parent product', function () {
    [$parent, $variation] = create_variable_product([
        'name'   => 'Variable Customs Product',
        'price'  => 100,
        'weight' => 1,
    ]);
    $parent->update_meta_data('_ss_hs_code', '61091000');
    $parent->update_meta_data('_ss_customs_desc', 'Cotton t-shirt');
    $parent->update_meta_data('_ss_country_of_origin', 'DK');
    $parent->save();

    // The variation overrides only the HS code.
    $variation->update_meta_data('_ss_hs_code', '62052000');
    $variation->save();

    $order = create_order([
        'products'        => [$variation],
        'shipping_method' => 'postnord_agent',
    ]);

    $payload = capture_shipment_payload($order);

    expect($payload['parcels'][0]['items'][0]['hs_code'])->toBe('62052000')
        ->and($payload['parcels'][0]['items'][0]['description'])->toBe('Cotton t-shirt')
        ->and($payload['parcels'][0]['items'][0]['country_of_origin'])->toBe('DK');
});

it('sends a null parcel weight when products have no weight', function () {
    $product = create_simple_product(['name' => 'Weightless Product', 'price' => 50, 'weight' => null]);
    $order   = create_order([
        'products'        => [$product],
        'shipping_method' => 'postnord_homedelivery',
    ]);

    $payload = capture_shipment_payload($order);

    expect($payload['parcels'][0]['weight'])->toBeNull()
        ->and($payload['parcels'][0]['items'][0]['unit_weight'])->toBeNull()
        // v8 oddity: a zero shipping total is sent as null, not 0.
        ->and($payload['shipping_price_excluding_tax'])->toBeNull()
        ->and($payload['shipping_price_including_tax'])->toBeNull();
});

it('splits the shipment into one parcel per box when parcels meta is set', function () {
    $product_a = create_simple_product(['name' => 'Box One Product', 'price' => 100, 'weight' => 1]);
    $product_b = create_simple_product(['name' => 'Box Two Product', 'price' => 50, 'weight' => 2]);
    $order     = create_order([
        'products'        => [$product_a, $product_b],
        'shipping_method' => 'postnord_agent',
        'shipping_total'  => '39',
    ]);
    SS_SHIPPING_WC()->order_meta()->save_ss_shipping_order_parcels($order->get_id(), [
        ['id' => $product_a->get_id(), 'name' => 'Box One Product', 'value' => '1'],
        ['id' => $product_b->get_id(), 'name' => 'Box Two Product', 'value' => '2'],
    ]);

    $payload = capture_shipment_payload($order);

    expect($payload['parcels'])->toHaveCount(2)
        ->and($payload['parcels'][0]['items'][0]['name'])->toBe('Box One Product')
        ->and($payload['parcels'][0]['weight'])->toEqual(1)
        ->and($payload['parcels'][0]['total_price_excluding_tax'])->toEqual(100)
        ->and($payload['parcels'][0]['total_price_including_tax'])->toEqual(100)
        ->and($payload['parcels'][1]['items'][0]['name'])->toBe('Box Two Product')
        ->and($payload['parcels'][1]['weight'])->toEqual(2)
        ->and($payload['parcels'][1]['total_price_excluding_tax'])->toEqual(50)
        ->and($payload['parcels'][1]['total_price_including_tax'])->toEqual(50);
});

it('includes the customer note as parcel freetext when enabled in settings', function () {
    with_ss_settings(['include_order_comment' => 'yes']);

    $product = create_simple_product(['price' => 100, 'weight' => 1]);
    $order   = create_order([
        'products'        => [$product],
        'shipping_method' => 'postnord_homedelivery',
        'customer_note'   => 'Please leave at the back door',
    ]);

    $payload = capture_shipment_payload($order);

    expect($payload['parcels'][0]['freetext'])->toBe('Please leave at the back door');
});

it('reconciles totals with the order total when a gift card partially covers the items', function () {
    $product = create_simple_product(['name' => 'Partially Gifted Product', 'price' => 100, 'weight' => 1]);
    $order   = create_order([
        'products'        => [$product],
        'fees'            => [['Gift card', -50]],
        'shipping_method' => 'postnord_homedelivery',
        'shipping_total'  => '39',
    ]);

    $payload = capture_shipment_payload($order);

    // total = 100 - 50 + 39 = 89; subtotal + shipping = total on both bases.
    expect($payload['parcels'][0]['total_price_excluding_tax'])->toEqual(50)
        ->and($payload['parcels'][0]['total_price_including_tax'])->toEqual(50)
        ->and($payload['subtotal_price_excluding_tax'])->toEqual(50)
        ->and($payload['subtotal_price_including_tax'])->toEqual(50)
        ->and($payload['shipping_price_excluding_tax'])->toEqual(39)
        ->and($payload['shipping_price_including_tax'])->toEqual(39)
        ->and($payload['total_price_excluding_tax'])->toEqual(89)
        ->and($payload['total_price_including_tax'])->toEqual(89);
});

it('clamps negative values to zero when a gift card exceeds the item value', function () {
    $product = create_simple_product(['name' => 'Gifted Product', 'price' => 100, 'weight' => 1]);
    $order   = create_order([
        'products'        => [$product],
        'fees'            => [['Gift card', -120]],
        'shipping_method' => 'postnord_homedelivery',
        'shipping_total'  => '39',
    ]);

    $payload = capture_shipment_payload($order);

    // total = 100 - 120 + 39 = 19. The non-shipping subtotal would be
    // 19 - 39 = -20 (the #54 failure mode); it is clamped to zero and a
    // zero amount is sent as null. No negative value appears anywhere.
    expect($payload['parcels'][0]['total_price_excluding_tax'])->toBeNull()
        ->and($payload['parcels'][0]['total_price_including_tax'])->toBeNull()
        ->and($payload['parcels'][0]['items'][0]['total_price_excluding_tax'])->toEqual(100)
        ->and($payload['subtotal_price_excluding_tax'])->toBeNull()
        ->and($payload['subtotal_price_including_tax'])->toBeNull()
        ->and($payload['shipping_price_excluding_tax'])->toEqual(39)
        ->and($payload['shipping_price_including_tax'])->toEqual(39)
        ->and($payload['total_price_excluding_tax'])->toEqual(19)
        ->and($payload['total_price_including_tax'])->toEqual(19);
});

it('normalizes the receiver phone by trimming surrounding whitespace', function () {
    $product = create_simple_product(['price' => 100, 'weight' => 1]);
    $order   = create_order([
        'products'        => [$product],
        'billing_address' => ['phone' => '  +4512345678  '],
        'shipping_method' => 'postnord_homedelivery',
    ]);

    $payload = capture_shipment_payload($order);

    expect($payload['receiver']['sms'])->toBe('+4512345678')
        ->and($payload['services']['sms_notification'])->toBe('+4512345678');
});

it('lets the smart_send_receiver_phone filter adjust the receiver phone', function () {
    $product = create_simple_product(['price' => 100, 'weight' => 1]);
    $order   = create_order([
        'products'        => [$product],
        'shipping_method' => 'postnord_homedelivery',
    ]);

    $filter = function ($phone, $filtered_order) use ($order) {
        expect($phone)->toBe('+4512345678')
            ->and($filtered_order)->toBeInstanceOf(WC_Order::class)
            ->and($filtered_order->get_id())->toBe($order->get_id());

        return '+4587654321';
    };
    add_filter('smart_send_receiver_phone', $filter, 10, 2);
    remember_cleanup_callback(function () use ($filter): void {
        remove_filter('smart_send_receiver_phone', $filter, 10);
    });

    $payload = capture_shipment_payload($order);

    expect($payload['receiver']['sms'])->toBe('+4587654321')
        ->and($payload['services']['sms_notification'])->toBe('+4587654321');
});

it('lets the per-section payload filters adjust receiver, items, parcels and totals', function () {
    $product = create_simple_product(['name' => 'Filtered Product', 'price' => 100, 'weight' => 1]);
    $order   = create_order([
        'products'        => [$product],
        'shipping_method' => 'postnord_homedelivery',
        'shipping_total'  => '39',
    ]);

    $receiver_filter = function (array $receiver_data, WC_Order $filtered_order) {
        $receiver_data['company'] = 'Filtered Company';

        return $receiver_data;
    };
    $items_filter = function (array $items, WC_Order $filtered_order) {
        $items[0]['name'] = 'Filtered Item Name';

        return $items;
    };
    $totals_filter = function (array $totals, WC_Order $filtered_order) {
        $totals['total_net_amount'] = 999;

        return $totals;
    };
    // As of #113/#111 this filter operates on the internal representation's
    // plain parcel arrays (each with an 'items' array of item rows), not on
    // assembled Smartsend\Models\Shipment\Parcel objects - a deliberate
    // signature change, safe because the filter is still unreleased (#73).
    $parcels_filter = function (array $parcels, WC_Order $filtered_order) {
        foreach ($parcels as &$parcel) {
            expect($parcel)->toBeArray();
            $parcel['weight'] = 42;
        }
        unset($parcel);

        return $parcels;
    };

    add_filter('smart_send_payload_receiver', $receiver_filter, 10, 2);
    add_filter('smart_send_payload_items', $items_filter, 10, 2);
    add_filter('smart_send_payload_totals', $totals_filter, 10, 2);
    add_filter('smart_send_payload_parcels', $parcels_filter, 10, 2);
    remember_cleanup_callback(function () use ($receiver_filter, $items_filter, $totals_filter, $parcels_filter): void {
        remove_filter('smart_send_payload_receiver', $receiver_filter, 10);
        remove_filter('smart_send_payload_items', $items_filter, 10);
        remove_filter('smart_send_payload_totals', $totals_filter, 10);
        remove_filter('smart_send_payload_parcels', $parcels_filter, 10);
    });

    $payload = capture_shipment_payload($order);

    expect($payload['receiver']['company'])->toBe('Filtered Company')
        ->and($payload['parcels'][0]['items'][0]['name'])->toBe('Filtered Item Name')
        ->and($payload['parcels'][0]['weight'])->toEqual(42)
        ->and($payload['total_price_including_tax'])->toEqual(999);
});

it('lets the smart_send_shipping_label_args filter override carrier and method', function () {
    $product = create_simple_product(['price' => 100, 'weight' => 1]);
    $order   = create_order([
        'products'        => [$product],
        'shipping_method' => 'postnord_homedelivery',
    ]);

    $filter = function (array $ss_args) {
        $ss_args['ss_carrier'] = 'gls';
        $ss_args['ss_type']    = 'homedelivery';

        return $ss_args;
    };
    add_filter('smart_send_shipping_label_args', $filter);
    remember_cleanup_callback(function () use ($filter): void {
        remove_filter('smart_send_shipping_label_args', $filter);
    });

    $payload = capture_shipment_payload($order);

    expect($payload['shipping_carrier'])->toBe('gls')
        ->and($payload['shipping_method'])->toBe('homedelivery');
});

it('lets the smart_send_order_parcels filter inject a parcel split (#73)', function () {
    $product_a = create_simple_product(['name' => 'Filter Box One', 'price' => 100, 'weight' => 1]);
    $product_b = create_simple_product(['name' => 'Filter Box Two', 'price' => 50, 'weight' => 2]);
    $order     = create_order([
        'products'        => [$product_a, $product_b],
        'shipping_method' => 'postnord_homedelivery',
        'shipping_total'  => '39',
    ]);

    $filter = function ($parcels, $order_id, $is_return) use ($order, $product_a, $product_b) {
        // No split is stored on the order, so the filter receives an empty value.
        expect(empty($parcels))->toBeTrue()
            ->and($order_id)->toBe($order->get_id())
            ->and($is_return)->toBeFalse();

        return [
            ['id' => $product_a->get_id(), 'name' => 'Filter Box One', 'value' => '1'],
            ['id' => $product_b->get_id(), 'name' => 'Filter Box Two', 'value' => '2'],
        ];
    };
    add_filter('smart_send_order_parcels', $filter, 10, 3);
    remember_cleanup_callback(function () use ($filter): void {
        remove_filter('smart_send_order_parcels', $filter, 10);
    });

    $payload = capture_shipment_payload($order);

    expect($payload['parcels'])->toHaveCount(2)
        ->and($payload['parcels'][0]['items'][0]['name'])->toBe('Filter Box One')
        ->and($payload['parcels'][1]['items'][0]['name'])->toBe('Filter Box Two');
});

it('lets the smart_send_order_pickup_point filter replace the pickup point', function () {
    $product = create_simple_product(['price' => 100, 'weight' => 1]);
    $order   = create_order([
        'products'        => [$product],
        'shipping_method' => 'postnord_agent',
    ]);
    SS_SHIPPING_WC()->order_meta()->save_ss_shipping_order_agent($order->get_id(), sample_agent());

    $filter = function ($ss_agent, $order_id) use ($order) {
        expect($ss_agent->agent_no)->toBe('1234')
            ->and($order_id)->toBe($order->get_id());

        return sample_agent(['agent_no' => '9999', 'company' => 'Override Shop']);
    };
    add_filter('smart_send_order_pickup_point', $filter, 10, 2);
    remember_cleanup_callback(function () use ($filter): void {
        remove_filter('smart_send_order_pickup_point', $filter, 10);
    });

    $payload = capture_shipment_payload($order);

    expect($payload['agent']['agent_no'])->toBe('9999')
        ->and($payload['agent']['company'])->toBe('Override Shop');
});

it('lets the smart_send_order_receiver filter adjust the shipping address', function () {
    $product = create_simple_product(['price' => 100, 'weight' => 1]);
    $order   = create_order([
        'products'        => [$product],
        'shipping_method' => 'postnord_homedelivery',
    ]);

    $filter = function (array $shipping_address, $order_id) use ($order) {
        expect($order_id)->toBe($order->get_id());
        $shipping_address['city'] = 'Aarhus';

        return $shipping_address;
    };
    add_filter('smart_send_order_receiver', $filter, 10, 2);
    remember_cleanup_callback(function () use ($filter): void {
        remove_filter('smart_send_order_receiver', $filter, 10);
    });

    $payload = capture_shipment_payload($order);

    expect($payload['receiver']['city'])->toBe('Aarhus');
});

it('lets the smart_send_order_note filter rewrite the parcel freetext', function () {
    with_ss_settings(['include_order_comment' => 'yes']);

    $product = create_simple_product(['price' => 100, 'weight' => 1]);
    $order   = create_order([
        'products'        => [$product],
        'shipping_method' => 'postnord_homedelivery',
        'customer_note'   => 'Original note',
    ]);

    $filter = function ($order_note, $filtered_order) use ($order) {
        expect($order_note)->toBe('Original note')
            ->and($filtered_order->get_id())->toBe($order->get_id());

        return 'Filtered note';
    };
    add_filter('smart_send_order_note', $filter, 10, 2);
    remember_cleanup_callback(function () use ($filter): void {
        remove_filter('smart_send_order_note', $filter, 10);
    });

    $payload = capture_shipment_payload($order);

    expect($payload['parcels'][0]['freetext'])->toBe('Filtered note');
});

it('lets the smart_send_parcel_weight filter override split parcel weights', function () {
    $product_a = create_simple_product(['name' => 'Weight Box One', 'price' => 100, 'weight' => 1]);
    $product_b = create_simple_product(['name' => 'Weight Box Two', 'price' => 50, 'weight' => 2]);
    $order     = create_order([
        'products'        => [$product_a, $product_b],
        'shipping_method' => 'postnord_homedelivery',
    ]);
    SS_SHIPPING_WC()->order_meta()->save_ss_shipping_order_parcels($order->get_id(), [
        ['id' => $product_a->get_id(), 'name' => 'Weight Box One', 'value' => '1'],
        ['id' => $product_b->get_id(), 'name' => 'Weight Box Two', 'value' => '2'],
    ]);

    $filter = function ($parcel_weight, $order_id) use ($order) {
        expect($order_id)->toBe($order->get_id());

        return 9.5;
    };
    add_filter('smart_send_parcel_weight', $filter, 10, 2);
    remember_cleanup_callback(function () use ($filter): void {
        remove_filter('smart_send_parcel_weight', $filter, 10);
    });

    $payload = capture_shipment_payload($order);

    expect($payload['parcels'][0]['weight'])->toEqual(9.5)
        ->and($payload['parcels'][1]['weight'])->toEqual(9.5);
});
