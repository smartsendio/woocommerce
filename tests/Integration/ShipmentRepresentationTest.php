<?php

/*
 * Coverage for the internal shipment representation introduced by #113
 * (SS_Shipping_Shipment_Builder / SS_Shipping_Shipment) and the #128
 * discount-allocation fix built on top of it. ShipmentPayloadTest.php
 * proves the v1 wire payload translated from this representation is
 * unchanged (or, for the two scenarios #128 deliberately fixes, correctly
 * changed); this file asserts the representation itself: a single net +
 * tax amount per level, no excl/incl pairs, and that item-line totals
 * reconcile with the order subtotal for every native WooCommerce coupon
 * type.
 */

/**
 * Build the internal shipment representation for an order the same way
 * SS_Shipping_Booking_Service does, without going through the (mocked) API
 * call.
 */
function build_shipment_representation(WC_Order $order, bool $return = false): SS_Shipping_Shipment
{
    $order_reader = new SS_Shipping_Order_Reader($order);
    $builder      = new SS_Shipping_Shipment_Builder($order, $order_reader, new SS_Shipping_Order_Meta());

    return $return ? $builder->build_return() : $builder->build_outbound();
}

/**
 * The public get_* method names of SS_Shipping_Shipment - used to assert
 * the shipment level exposes a single net + tax amount per level, with no
 * excl/incl pair getters.
 */
function shipment_getter_names(): array
{
    $methods = (new ReflectionClass(SS_Shipping_Shipment::class))->getMethods(ReflectionMethod::IS_PUBLIC);

    return array_values(array_filter(
        array_map(fn ($method) => $method->getName(), $methods),
        fn ($name) => str_starts_with($name, 'get_')
    ));
}

beforeEach(function (): void {
    with_ss_settings();
});

it('represents shipment, parcel and item totals as a single net + tax amount, with no excl/incl pair', function () {
    $product = create_simple_product(['name' => 'Representation Product', 'price' => 100, 'weight' => 1]);
    $order   = create_order([
        'products'        => [[$product, 2]],
        'shipping_method' => 'postnord_homedelivery',
        'shipping_total'  => '39',
    ]);

    $representation = build_shipment_representation($order);

    // Shipment level: single net/tax pair, no excluding/including-tax getters.
    $getters = shipment_getter_names();
    expect($getters)->toContain(
        'get_subtotal_net_amount',
        'get_subtotal_tax_amount',
        'get_shipping_net_amount',
        'get_shipping_tax_amount',
        'get_total_net_amount',
        'get_total_tax_amount',
    );
    foreach ($getters as $getter) {
        expect($getter)->not->toContain('excluding_tax')
            ->and($getter)->not->toContain('including_tax');
    }

    // Parcel level.
    expect($representation->get_parcels())->toHaveCount(1);
    $parcel = $representation->get_parcels()[0];
    expect($parcel)->toHaveKeys(['total_net_amount', 'total_tax_amount']);
    foreach (array_keys($parcel) as $key) {
        expect($key)->not->toContain('excluding_tax')
            ->and($key)->not->toContain('including_tax');
    }

    // Item level.
    expect($parcel['items'])->toHaveCount(1);
    $item = $parcel['items'][0];
    expect($item)->toHaveKeys(['total_net_amount', 'total_tax_amount'])
        ->and($item['total_net_amount'])->toEqual(200.0)
        ->and($item['total_tax_amount'])->toEqual(0.0);
    foreach (array_keys($item) as $key) {
        expect($key)->not->toContain('excluding_tax')
            ->and($key)->not->toContain('including_tax')
            ->and($key)->not->toContain('unit_price'); // No per-unit price redundancy either.
    }
});

it('names the pickup-point section after v2\'s service_point_code, not v1\'s agent_no', function () {
    $product = create_simple_product(['price' => 100, 'weight' => 1]);
    $order   = create_order([
        'products'        => [$product],
        'shipping_method' => 'postnord_agent',
    ]);
    SS_SHIPPING_WC()->get_ss_shipping_wc_order()->save_ss_shipping_order_agent($order->get_id(), sample_agent());

    $representation = build_shipment_representation($order);

    expect($representation->get_pickup_point())->toHaveKey('service_point_code')
        ->and($representation->get_pickup_point()['service_point_code'])->toBe('1234')
        ->and($representation->get_pickup_point())->not->toHaveKey('agent_no');
});

it('reconciles item-line totals with the order subtotal for a percentage coupon', function () {
    $product = create_simple_product(['name' => 'Percent Coupon Product', 'price' => 100, 'weight' => 1]);
    $coupon  = create_coupon(['type' => 'percent', 'amount' => 20]);
    $order   = create_order([
        'products'        => [[$product, 3]],
        'coupons'         => $coupon->get_code(),
        'shipping_method' => 'postnord_homedelivery',
    ]);

    $representation = build_shipment_representation($order);

    $item_sum = array_sum(array_column($representation->get_parcels()[0]['items'], 'total_net_amount'));

    expect($item_sum)->toEqual($representation->get_subtotal_net_amount())
        ->and($item_sum)->toEqual(240.0); // 3 x 100, 20% off.
});

it('reconciles item-line totals with the order subtotal for a fixed-cart coupon', function () {
    $product_a = create_simple_product(['name' => 'Fixed Cart Product A', 'price' => 60, 'weight' => 1]);
    $product_b = create_simple_product(['name' => 'Fixed Cart Product B', 'price' => 40, 'weight' => 1]);
    $coupon    = create_coupon(['type' => 'fixed_cart', 'amount' => 20]);
    $order     = create_order([
        'products'        => [$product_a, $product_b],
        'coupons'         => $coupon->get_code(),
        'shipping_method' => 'postnord_homedelivery',
    ]);

    $representation = build_shipment_representation($order);

    $item_sum = array_sum(array_column($representation->get_parcels()[0]['items'], 'total_net_amount'));

    // 100 order subtotal, 20 fixed-cart discount prorated across both lines.
    expect($item_sum)->toEqual($representation->get_subtotal_net_amount())
        ->and($item_sum)->toEqual(80.0);
});

it('reconciles item-line totals with the order subtotal for a fixed-product coupon', function () {
    $product_a = create_simple_product(['name' => 'Fixed Product Coupon Target', 'price' => 60, 'weight' => 1]);
    $product_b = create_simple_product(['name' => 'Fixed Product Coupon Bystander', 'price' => 40, 'weight' => 1]);
    $coupon    = create_coupon(['type' => 'fixed_product', 'amount' => 15]);
    $coupon->set_product_ids([$product_a->get_id()]);
    $coupon->save();
    $order = create_order([
        'products'        => [$product_a, $product_b],
        'coupons'         => $coupon->get_code(),
        'shipping_method' => 'postnord_homedelivery',
    ]);

    $representation = build_shipment_representation($order);

    $items    = $representation->get_parcels()[0]['items'];
    $item_sum = array_sum(array_column($items, 'total_net_amount'));

    // Only product A's line is discounted, by exactly the fixed amount.
    $product_a_id = $product_a->get_id();
    $line_a       = current(array_filter($items, fn ($item) => $item['id'] === $product_a_id));

    expect($line_a['total_net_amount'])->toEqual(45.0)
        ->and($item_sum)->toEqual($representation->get_subtotal_net_amount())
        ->and($item_sum)->toEqual(85.0);
});

it('diverges shipping-method/agent selection between build_outbound() and build_return()', function () {
    $product = create_simple_product(['price' => 100, 'weight' => 1]);
    $order   = create_order([
        'products'        => [$product],
        'shipping_method' => 'postnord_agent',
        'return_method'   => 'postnord_returndropoff',
    ]);
    SS_SHIPPING_WC()->get_ss_shipping_wc_order()->save_ss_shipping_order_agent($order->get_id(), sample_agent());

    $outbound = build_shipment_representation($order, false);
    $return   = build_shipment_representation($order, true);

    // Outbound uses the order's Smart Send shipping method and selects the
    // stored pick-up point.
    expect($outbound->get_shipping_method())->toBe('agent')
        ->and($outbound->get_pickup_point())->not->toBeNull();

    // Return uses the configured return method and skips pick-up point
    // selection entirely.
    expect($return->get_shipping_method())->toBe('returndropoff')
        ->and($return->get_pickup_point())->toBeNull();
});

it('throws a SS_Shipping_Booking_Exception from build_return() when no return method is configured', function () {
    $product = create_simple_product(['price' => 100, 'weight' => 1]);
    $order   = create_order([
        'products'        => [$product],
        'shipping_method' => 'postnord_agent',
        'return_method'   => '',
    ]);

    expect(fn () => build_shipment_representation($order, true))
        ->toThrow(SS_Shipping_Booking_Exception::class, 'No return method set');
});
