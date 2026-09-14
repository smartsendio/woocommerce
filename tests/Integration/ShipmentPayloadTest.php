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

    $booking_service = new SS_Shipping_Booking_Service(new SS_Shipping_Order_Meta(), new SS_Shipping_Method_Resolver());
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
    save_order_pickup_point($order->get_id(), sample_agent());

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
    // Pin the price-entry mode this scenario's numbers assume (100 excl ->
    // 125 incl) - the store default is configurable via the setup script's
    // --prices-tax / WP_PRICES_TAX.
    with_option('woocommerce_prices_include_tax', 'no');
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
    save_order_parcels($order->get_id(), [
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

it('lets the per-section payload filters adjust receiver, items and totals', function () {
    // The kept WC-derived-data filters (smart_send_payload_receiver/_items/
    // _totals). The unreleased smart_send_payload_parcels filter is removed
    // in v9 (#139), superseded by the typed parcel plan on the
    // smart_send_delivery_details filter.
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
    add_filter('smart_send_payload_receiver', $receiver_filter, 10, 2);
    add_filter('smart_send_payload_items', $items_filter, 10, 2);
    add_filter('smart_send_payload_totals', $totals_filter, 10, 2);
    remember_cleanup_callback(function () use ($receiver_filter, $items_filter, $totals_filter): void {
        remove_filter('smart_send_payload_receiver', $receiver_filter, 10);
        remove_filter('smart_send_payload_items', $items_filter, 10);
        remove_filter('smart_send_payload_totals', $totals_filter, 10);
    });

    $payload = capture_shipment_payload($order);

    expect($payload['receiver']['company'])->toBe('Filtered Company')
        ->and($payload['parcels'][0]['items'][0]['name'])->toBe('Filtered Item Name')
        ->and($payload['total_price_including_tax'])->toEqual(999);
});

it('lets the smart_send_delivery_details filter override the shipping method', function () {
    $product = create_simple_product(['price' => 100, 'weight' => 1]);
    $order   = create_order([
        'products'        => [$product],
        'shipping_method' => 'postnord_homedelivery',
    ]);

    $filter = function (SS_Shipping_Delivery_Details $details, WC_Order $filtered_order, bool $is_return) use ($order) {
        expect($details->get_shipping_method())->toBe('postnord_homedelivery')
            ->and($filtered_order->get_id())->toBe($order->get_id())
            ->and($is_return)->toBeFalse();

        return $details->set_shipping_method('gls_homedelivery');
    };
    add_filter('smart_send_delivery_details', $filter, 10, 3);
    remember_cleanup_callback(function () use ($filter): void {
        remove_filter('smart_send_delivery_details', $filter, 10);
    });

    $payload = capture_shipment_payload($order);

    expect($payload['shipping_carrier'])->toBe('gls')
        ->and($payload['shipping_method'])->toBe('homedelivery');
});

it('lets the smart_send_delivery_details filter declare a parcel plan with item allocations', function () {
    $product_a = create_simple_product(['name' => 'Filter Box One', 'price' => 100, 'weight' => 1]);
    $product_b = create_simple_product(['name' => 'Filter Box Two', 'price' => 50, 'weight' => 2]);
    $order     = create_order([
        'products'        => [$product_a, $product_b],
        'shipping_method' => 'postnord_homedelivery',
        'shipping_total'  => '39',
    ]);

    $filter = function (SS_Shipping_Delivery_Details $details) use ($product_a, $product_b) {
        // No split is stored on the order, so the filter receives no plan.
        expect($details->get_parcel_plan())->toBeNull();

        $plan = new SS_Shipping_Parcel_Plan();
        $plan->add_spec((new SS_Shipping_Parcel_Spec())->add_item($product_a->get_id()))
            ->add_spec((new SS_Shipping_Parcel_Spec())->add_item($product_b->get_id()));

        return $details->set_parcel_plan($plan);
    };
    add_filter('smart_send_delivery_details', $filter);
    remember_cleanup_callback(function () use ($filter): void {
        remove_filter('smart_send_delivery_details', $filter);
    });

    $payload = capture_shipment_payload($order);

    expect($payload['parcels'])->toHaveCount(2)
        ->and($payload['parcels'][0]['items'][0]['name'])->toBe('Filter Box One')
        ->and($payload['parcels'][0]['weight'])->toEqual(1)
        ->and($payload['parcels'][1]['items'][0]['name'])->toBe('Filter Box Two')
        ->and($payload['parcels'][1]['weight'])->toEqual(2);
});

it('lets the smart_send_delivery_details filter replace or clear the pickup point', function () {
    $product = create_simple_product(['price' => 100, 'weight' => 1]);
    $order   = create_order([
        'products'        => [$product],
        'shipping_method' => 'postnord_agent',
    ]);
    save_order_pickup_point($order->get_id(), sample_agent());

    // Replace the stored pickup point.
    $replace = function (SS_Shipping_Delivery_Details $details) {
        expect($details->get_pickup_point())->not->toBeNull()
            ->and($details->get_pickup_point()->get_agent_no())->toBe('1234');

        $pickup_point = new SS_Shipping_Pickup_Point();
        $pickup_point->set_agent_no('9999')->set_company('Override Shop');

        return $details->set_pickup_point($pickup_point);
    };
    add_filter('smart_send_delivery_details', $replace);

    $payload = capture_shipment_payload($order);

    remove_filter('smart_send_delivery_details', $replace);

    expect($payload['agent']['agent_no'])->toBe('9999')
        ->and($payload['agent']['company'])->toBe('Override Shop')
        // Without a Smart Send internal id, the agent number doubles as the
        // internal reference (matching the historic behaviour for filter-built
        // pickup points).
        ->and($payload['agent']['internal_id'])->toBe('9999');

    // Clear the pickup point entirely.
    $clear = function (SS_Shipping_Delivery_Details $details) {
        return $details->set_pickup_point(null);
    };
    add_filter('smart_send_delivery_details', $clear);
    remember_cleanup_callback(function () use ($clear): void {
        remove_filter('smart_send_delivery_details', $clear);
    });

    $payload = capture_shipment_payload($order);

    expect($payload['agent'])->toBeNull();
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

it('lets an explicit spec weight win over the item-sum when a plan declares one', function () {
    // Replaces the removed smart_send_parcel_weight filter: packaging
    // weight is declared on the SS_Shipping_Parcel_Spec itself.
    $product_a = create_simple_product(['name' => 'Weight Box One', 'price' => 100, 'weight' => 1]);
    $product_b = create_simple_product(['name' => 'Weight Box Two', 'price' => 50, 'weight' => 2]);
    $order     = create_order([
        'products'        => [$product_a, $product_b],
        'shipping_method' => 'postnord_homedelivery',
    ]);

    $filter = function (SS_Shipping_Delivery_Details $details) use ($product_a, $product_b) {
        $plan = new SS_Shipping_Parcel_Plan();
        $plan->add_spec((new SS_Shipping_Parcel_Spec())->set_weight(9.5)->add_item($product_a->get_id()))
            ->add_spec((new SS_Shipping_Parcel_Spec())->add_item($product_b->get_id()));

        return $details->set_parcel_plan($plan);
    };
    add_filter('smart_send_delivery_details', $filter);
    remember_cleanup_callback(function () use ($filter): void {
        remove_filter('smart_send_delivery_details', $filter);
    });

    $payload = capture_shipment_payload($order);

    // The explicit weight wins on the first parcel; the second keeps its item-sum.
    expect($payload['parcels'][0]['weight'])->toEqual(9.5)
        ->and($payload['parcels'][1]['weight'])->toEqual(2);
});

it('books an item-less two-parcel plan declared via smart_send_delivery_details with the full expected payload', function () {
    // The "declare 2 parcels of size X/Y/Z and weight W with no item info
    // at all" capability (#139), pinned as a full golden payload: item-less
    // parcels carry dimensions and weight but no item rows (serialized as
    // null, the v1 wire convention for "not set") and no amounts of their
    // own - the declared amounts live at shipment level only.
    $product = create_simple_product(['name' => 'Simple Product', 'price' => 100, 'weight' => 1.5, 'sku' => 'SIMPLE-' . uniqid()]);
    $order   = create_order([
        'products'        => [[$product, 2]],
        'shipping_method' => 'postnord_homedelivery',
        'shipping_total'  => '39',
    ]);
    $order_id = (string) $order->get_id();

    $filter = function (SS_Shipping_Delivery_Details $details) {
        $plan = new SS_Shipping_Parcel_Plan();
        $plan->add_spec((new SS_Shipping_Parcel_Spec())->set_weight(4)->set_length(30)->set_width(20)->set_height(10))
            ->add_spec((new SS_Shipping_Parcel_Spec())->set_weight(2.5)->set_length(15)->set_width(15)->set_height(15));

        return $details->set_parcel_plan($plan);
    };
    add_filter('smart_send_delivery_details', $filter);
    remember_cleanup_callback(function () use ($filter): void {
        remove_filter('smart_send_delivery_details', $filter);
    });

    $payload = capture_shipment_payload($order);

    expect($payload)->toEqual(expected_payload($order, [
        'shipping_method' => 'homedelivery',
        'parcels'         => [
            [
                'internal_id'        => $order_id,
                'internal_reference' => $order_id,
                'weight'             => 4,
                'height'             => 10,
                'width'              => 20,
                'length'             => 30,
                'freetext'           => null,
                'items'              => null,
                'total_price_excluding_tax' => null,
                'total_price_including_tax' => null,
                'total_tax_amount'          => null,
            ],
            [
                'internal_id'        => $order_id,
                'internal_reference' => $order_id,
                'weight'             => 2.5,
                'height'             => 15,
                'width'              => 15,
                'length'             => 15,
                'freetext'           => null,
                'items'              => null,
                'total_price_excluding_tax' => null,
                'total_price_including_tax' => null,
                'total_tax_amount'          => null,
            ],
        ],
        // The declared amounts live at shipment level only.
        'subtotal_price_excluding_tax' => 200,
        'subtotal_price_including_tax' => 200,
        'shipping_price_excluding_tax' => 39,
        'shipping_price_including_tax' => 39,
        'total_price_excluding_tax'    => 239,
        'total_price_including_tax'    => 239,
    ]));
});
