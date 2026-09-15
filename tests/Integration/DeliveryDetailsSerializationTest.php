<?php

/*
 * The canonical JSON form of the delivery-details DTOs (#182):
 * SS_Shipping_Delivery_Details, SS_Shipping_Parcel_Plan and
 * SS_Shipping_Parcel_Spec round-trip through to_array()/from_array(),
 * including partial details (absent = keep stored/derived), box-only
 * specs, a pickup point submitted as a bare agent number, and the
 * array( 'clear' => true ) sentinel that distinguishes "clear the pickup
 * point" from "not specified". This is the shape the fulfillment REST
 * request (#182 PR 2) submits, so every property here must survive a
 * from_array()->to_array() round-trip byte for byte.
 */

/**
 * The complete request-shaped array of section 3.1 of #182.
 */
function full_delivery_details_array(): array
{
    return [
        'shipping_method' => 'postnord_agent',
        'pickup_point'    => [
            'id'            => '7', // SS_Shipping_Pickup_Point types the internal id as a string.
            'agent_no'      => '1234',
            'company'       => 'Corner Shop',
            'address_line1' => 'Main Street 1',
            'address_line2' => null,
            'postal_code'   => '2300',
            'city'          => 'Copenhagen',
            'country'       => 'DK',
            'distance'      => 0.5,
        ],
        'parcel_plan'     => [
            'specs' => [
                [
                    'reference' => '1',
                    'weight'    => null,
                    'length'    => 40.0,
                    'width'     => 30.0,
                    'height'    => 20.0,
                    'items'     => [
                        ['id' => 812, 'quantity' => 2, 'name' => 'Hoodie'],
                        ['id' => 815, 'quantity' => 1, 'name' => null],
                    ],
                ],
                [
                    'reference' => '2',
                    'weight'    => 3.5,
                    'length'    => null,
                    'width'     => null,
                    'height'    => null,
                    'items'     => [],
                ],
            ],
        ],
        'addons'          => [],
    ];
}

it('round-trips a parcel spec through to_array()/from_array()', function () {
    $spec = (new SS_Shipping_Parcel_Spec())
        ->set_reference('2')
        ->set_weight('3.5')
        ->set_length(40)
        ->set_width(30)
        ->set_height(20)
        ->add_item(812, 2, 'Hoodie')
        ->add_item(815);

    $array = $spec->to_array();

    expect($array)->toBe([
        'reference' => '2',
        'weight'    => 3.5,
        'length'    => 40.0,
        'width'     => 30.0,
        'height'    => 20.0,
        'items'     => [
            ['id' => 812, 'quantity' => 2, 'name' => 'Hoodie'],
            ['id' => 815, 'quantity' => 1, 'name' => null],
        ],
    ]);

    $rebuilt = SS_Shipping_Parcel_Spec::from_array($array);
    expect($rebuilt->to_array())->toBe($array)
        ->and($rebuilt->get_weight())->toBe(3.5)
        ->and($rebuilt->has_items())->toBeTrue();
});

it('treats absent, null and empty spec fields as "not set" and defaults item quantity to 1', function () {
    // Weight/dimensions absent, '' or null all mean "compute / none"; an
    // item row without an id is dropped; quantity defaults to 1.
    $spec = SS_Shipping_Parcel_Spec::from_array([
        'weight' => '',
        'length' => null,
        'items'  => [
            ['id' => 812],
            ['name' => 'orphan without id'],
            'not-a-row',
        ],
    ]);

    expect($spec->to_array())->toBe([
        'reference' => null,
        'weight'    => null,
        'length'    => null,
        'width'     => null,
        'height'    => null,
        'items'     => [
            ['id' => 812, 'quantity' => 1, 'name' => null],
        ],
    ]);

    // A box-only spec (no items at all) round-trips too.
    $box_only = SS_Shipping_Parcel_Spec::from_array(['weight' => 2, 'length' => 10, 'width' => 10, 'height' => 10]);
    expect($box_only->has_items())->toBeFalse()
        ->and($box_only->get_weight())->toBe(2.0)
        ->and(SS_Shipping_Parcel_Spec::from_array($box_only->to_array())->to_array())->toBe($box_only->to_array());
});

it('round-trips a parcel plan and keeps the empty plan empty', function () {
    $plan = SS_Shipping_Parcel_Plan::from_array(full_delivery_details_array()['parcel_plan']);

    expect($plan->is_empty())->toBeFalse()
        ->and($plan->get_specs())->toHaveCount(2)
        ->and($plan->get_specs()[0]->get_reference())->toBe('1')
        ->and($plan->get_specs()[0]->get_weight())->toBeNull()
        ->and($plan->get_specs()[1]->get_weight())->toBe(3.5)
        ->and($plan->get_specs()[1]->has_items())->toBeFalse()
        ->and($plan->to_array())->toBe(full_delivery_details_array()['parcel_plan']);

    // specs: [] is the empty plan (one parcel containing everything) -
    // what "Reset to one parcel" submits.
    expect(SS_Shipping_Parcel_Plan::from_array(['specs' => []])->is_empty())->toBeTrue()
        ->and(SS_Shipping_Parcel_Plan::from_array([])->to_array())->toBe(['specs' => []]);

    // The frozen box-row form and the canonical form agree on the items.
    $rows = [
        ['id' => 812, 'name' => 'Hoodie', 'value' => '1'],
        ['id' => 812, 'name' => 'Hoodie', 'value' => '1'],
        ['id' => 815, 'name' => 'Beanie', 'value' => '2'],
    ];
    $from_rows = SS_Shipping_Parcel_Plan::from_box_rows($rows);
    expect(SS_Shipping_Parcel_Plan::from_array($from_rows->to_array())->to_box_rows())->toBe($rows);
});

it('round-trips complete delivery details byte for byte', function () {
    $array   = full_delivery_details_array();
    $details = SS_Shipping_Delivery_Details::from_array($array);

    expect($details->get_shipping_method())->toBe('postnord_agent')
        ->and($details->get_pickup_point())->toBeInstanceOf(SS_Shipping_Pickup_Point::class)
        ->and($details->get_pickup_point()->get_agent_no())->toBe('1234')
        ->and($details->get_pickup_point()->get_company())->toBe('Corner Shop')
        ->and($details->get_pickup_point()->is_agent_no_only())->toBeFalse()
        ->and($details->is_pickup_point_cleared())->toBeFalse()
        ->and($details->get_parcel_plan())->toBeInstanceOf(SS_Shipping_Parcel_Plan::class)
        ->and($details->get_addons())->toBe([])
        ->and($details->to_array())->toBe($array);

    // A directly built details object produces the same shape.
    $built = (new SS_Shipping_Delivery_Details())
        ->set_shipping_method('postnord_agent')
        ->set_pickup_point(SS_Shipping_Pickup_Point::from_object($array['pickup_point']))
        ->set_parcel_plan(SS_Shipping_Parcel_Plan::from_array($array['parcel_plan']));
    expect($built->to_array())->toBe($array);

    // PHP serialization (Phase 7 queueing) keeps the canonical form intact.
    expect(unserialize(serialize($details))->to_array())->toBe($array);
});

it('keeps partial details partial: absent fields stay null (keep stored/derived)', function () {
    $empty = SS_Shipping_Delivery_Details::from_array([]);

    expect($empty->to_array())->toBe([
        'shipping_method' => null,
        'pickup_point'    => null,
        'parcel_plan'     => null,
        'addons'          => [],
    ])
        ->and($empty->is_pickup_point_cleared())->toBeFalse();

    // Explicit nulls and an empty method mean the same as absent.
    $nulls = SS_Shipping_Delivery_Details::from_array([
        'shipping_method' => '',
        'pickup_point'    => null,
        'parcel_plan'     => null,
    ]);
    expect($nulls->to_array())->toBe($empty->to_array());

    // Only the parcel plan submitted (what the v8 meta box posts).
    $plan_only = SS_Shipping_Delivery_Details::from_array(['parcel_plan' => ['specs' => []]]);
    expect($plan_only->get_shipping_method())->toBeNull()
        ->and($plan_only->get_pickup_point())->toBeNull()
        ->and($plan_only->get_parcel_plan()->is_empty())->toBeTrue()
        ->and($plan_only->to_array()['parcel_plan'])->toBe(['specs' => []]);
});

it('accepts a pickup point submitted as a bare agent number and reports it as agent-number-only', function () {
    $details = SS_Shipping_Delivery_Details::from_array(['pickup_point' => ['agent_no' => '5678']]);

    $pickup_point = $details->get_pickup_point();
    expect($pickup_point)->toBeInstanceOf(SS_Shipping_Pickup_Point::class)
        ->and($pickup_point->get_agent_no())->toBe('5678')
        ->and($pickup_point->is_agent_no_only())->toBeTrue()
        ->and($details->to_array()['pickup_point'])->toBe(['agent_no' => '5678']);

    // Anything beyond the number makes it a full (as-submitted) point.
    expect(SS_Shipping_Pickup_Point::from_object(['agent_no' => '5678', 'country' => 'DK'])->is_agent_no_only())->toBeFalse()
        ->and((new SS_Shipping_Pickup_Point())->set_agent_no('5678')->is_agent_no_only())->toBeTrue()
        ->and((new SS_Shipping_Pickup_Point())->set_agent_no('5678')->set_company('Shop')->is_agent_no_only())->toBeFalse()
        ->and((new SS_Shipping_Pickup_Point())->is_agent_no_only())->toBeFalse();
});

it('distinguishes clearing the pickup point from not specifying it with the clear sentinel', function () {
    $cleared = SS_Shipping_Delivery_Details::from_array(['pickup_point' => ['clear' => true]]);

    expect($cleared->get_pickup_point())->toBeNull()
        ->and($cleared->is_pickup_point_cleared())->toBeTrue()
        ->and($cleared->to_array()['pickup_point'])->toBe(['clear' => true]);

    // The sentinel survives a round-trip and PHP serialization...
    expect(SS_Shipping_Delivery_Details::from_array($cleared->to_array())->is_pickup_point_cleared())->toBeTrue()
        ->and(unserialize(serialize($cleared))->is_pickup_point_cleared())->toBeTrue();

    // ...clear_pickup_point() is its programmatic twin, and setting a
    // point (or null) afterwards resets it to "specified"/"unspecified".
    $details = (new SS_Shipping_Delivery_Details())->clear_pickup_point();
    expect($details->is_pickup_point_cleared())->toBeTrue()
        ->and($details->to_array()['pickup_point'])->toBe(['clear' => true]);

    $details->set_pickup_point(null);
    expect($details->is_pickup_point_cleared())->toBeFalse()
        ->and($details->to_array()['pickup_point'])->toBeNull();

    // clear: false is not a clear - and not a point either.
    expect(SS_Shipping_Delivery_Details::from_array(['pickup_point' => ['clear' => false]])->to_array()['pickup_point'])->toBe(['clear' => false]);
});

it('writes a cleared pickup point through the repository as a deletion, and a bare agent number is not stored', function () {
    $product = create_simple_product(['price' => 100, 'weight' => 1]);
    $order   = create_order(['products' => [$product], 'shipping_method' => 'postnord_agent']);
    save_order_pickup_point($order->get_id(), sample_agent());

    expect(wc_get_order($order->get_id())->get_meta(SS_Shipping_Order_Meta::META_AGENT_NO, true))->toBe('1234');

    // Not specified: the stored point stays.
    SS_SHIPPING_WC()->order_meta()->write($order->get_id(), new SS_Shipping_Delivery_Details());
    expect(wc_get_order($order->get_id())->get_meta(SS_Shipping_Order_Meta::META_AGENT_NO, true))->toBe('1234');

    // Cleared: both pickup point keys are deleted and saved.
    SS_SHIPPING_WC()->order_meta()->write($order->get_id(), (new SS_Shipping_Delivery_Details())->clear_pickup_point());

    $fresh = wc_get_order($order->get_id());
    expect($fresh->get_meta(SS_Shipping_Order_Meta::META_AGENT_NO, true))->toBe('')
        ->and($fresh->get_meta(SS_Shipping_Order_Meta::META_AGENT, true))->toBe('')
        ->and(SS_SHIPPING_WC()->order_meta()->read($order->get_id())->get_pickup_point())->toBeNull();
});
