<?php

/*
 * Coverage for the new internal shipment representation introduced by
 * #113 (SS_Shipping_Shipment_Builder) and the #128 discount-allocation fix
 * built on top of it. ShipmentPayloadTest.php proves the v1 wire payload
 * translated from this representation is unchanged (or, for the two
 * scenarios #128 deliberately fixes, correctly changed); this file asserts
 * the representation itself: a single net + tax amount per level, no
 * excl/incl pairs, and that item-line totals reconcile with the order
 * subtotal for every native WooCommerce coupon type.
 */

/**
 * Build the internal shipment representation for an order the same way
 * SS_Shipping_Shipment does, without going through the (mocked) API call.
 */
function build_shipment_representation(WC_Order $order, bool $return = false): array
{
    $order_data = new SS_Shipping_Order_Data($order);
    $builder    = new SS_Shipping_Shipment_Builder($order, $order_data, SS_SHIPPING_WC()->get_ss_shipping_wc_order());

    return $builder->build($return);
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

    // Shipment level: single net/tax pair, no excluding/including-tax keys.
    expect($representation)->toHaveKeys([
        'subtotal_net_amount',
        'subtotal_tax_amount',
        'shipping_net_amount',
        'shipping_tax_amount',
        'total_net_amount',
        'total_tax_amount',
    ]);
    foreach (array_keys($representation) as $key) {
        expect($key)->not->toContain('excluding_tax')
            ->and($key)->not->toContain('including_tax');
    }

    // Parcel level.
    expect($representation['parcels'])->toHaveCount(1);
    $parcel = $representation['parcels'][0];
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

    expect($representation['pickup_point'])->toHaveKey('service_point_code')
        ->and($representation['pickup_point']['service_point_code'])->toBe('1234')
        ->and($representation['pickup_point'])->not->toHaveKey('agent_no');
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

    $item_sum = array_sum(array_column($representation['parcels'][0]['items'], 'total_net_amount'));

    expect($item_sum)->toEqual($representation['subtotal_net_amount'])
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

    $item_sum = array_sum(array_column($representation['parcels'][0]['items'], 'total_net_amount'));

    // 100 order subtotal, 20 fixed-cart discount prorated across both lines.
    expect($item_sum)->toEqual($representation['subtotal_net_amount'])
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

    $items    = $representation['parcels'][0]['items'];
    $item_sum = array_sum(array_column($items, 'total_net_amount'));

    // Only product A's line is discounted, by exactly the fixed amount.
    $product_a_id = $product_a->get_id();
    $line_a       = current(array_filter($items, fn ($item) => $item['id'] === $product_a_id));

    expect($line_a['total_net_amount'])->toEqual(45.0)
        ->and($item_sum)->toEqual($representation['subtotal_net_amount'])
        ->and($item_sum)->toEqual(85.0);
});
