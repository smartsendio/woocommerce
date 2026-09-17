<?php

/*
 * The canonical JSON form of the delivery-details DTOs (#182):
 * \Smart_Send\Delivery\Delivery_Details, \Smart_Send\Delivery\Parcel_Plan and
 * \Smart_Send\Delivery\Parcel_Spec round-trip through to_array()/from_array(),
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
            'id'            => '7', // \Smart_Send\Delivery\Pickup_Point types the internal id as a string.
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
                        ['order_item_id' => 812, 'quantity' => 2, 'name' => 'Hoodie'],
                        ['order_item_id' => 815, 'quantity' => 1, 'name' => null],
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
    $spec = (new \Smart_Send\Delivery\Parcel_Spec())
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
            ['order_item_id' => 812, 'quantity' => 2, 'name' => 'Hoodie'],
            ['order_item_id' => 815, 'quantity' => 1, 'name' => null],
        ],
    ]);

    $rebuilt = \Smart_Send\Delivery\Parcel_Spec::from_array($array);
    expect($rebuilt->to_array())->toBe($array)
        ->and($rebuilt->get_weight())->toBe(3.5)
        ->and($rebuilt->has_items())->toBeTrue();
});

it('keeps optional measures empty and rejects malformed or legacy item allocations', function () {
    $spec = \Smart_Send\Delivery\Parcel_Spec::from_array(['weight' => '', 'length' => null]);
    expect($spec->has_items())->toBeFalse()->and($spec->get_weight())->toBeNull();
    foreach ([['id' => 812, 'quantity' => 1], ['order_item_id' => 812], ['name' => 'orphan'], 'not-a-row'] as $row) {
        expect(fn () => \Smart_Send\Delivery\Parcel_Spec::from_array(['items' => [$row]]))->toThrow(InvalidArgumentException::class);
    }
    $manual = \Smart_Send\Delivery\Parcel_Spec::from_array(['weight' => 2, 'length' => 10]);
    expect(\Smart_Send\Delivery\Parcel_Spec::from_array($manual->to_array())->to_array())->toBe($manual->to_array());
});

it('round-trips a parcel plan and keeps the empty plan empty', function () {
    $plan = \Smart_Send\Delivery\Parcel_Plan::from_array(full_delivery_details_array()['parcel_plan']);

    expect($plan->is_empty())->toBeFalse()
        ->and($plan->get_specs())->toHaveCount(2)
        ->and($plan->get_specs()[0]->get_reference())->toBe('1')
        ->and($plan->get_specs()[0]->get_weight())->toBeNull()
        ->and($plan->get_specs()[1]->get_weight())->toBe(3.5)
        ->and($plan->get_specs()[1]->has_items())->toBeFalse()
        ->and($plan->to_array())->toBe(full_delivery_details_array()['parcel_plan']);

    // specs: [] is the empty plan (one parcel containing everything) -
    // what "Reset to one parcel" submits.
    expect(\Smart_Send\Delivery\Parcel_Plan::from_array(['specs' => []])->is_empty())->toBeTrue()
        ->and(\Smart_Send\Delivery\Parcel_Plan::from_array([])->to_array())->toBe(['specs' => []]);

    expect(fn () => \Smart_Send\Delivery\Parcel_Plan::from_array([
        ['id' => 812, 'name' => 'Old product row', 'value' => '1'],
    ]))->toThrow(InvalidArgumentException::class);

});

it('round-trips complete delivery details byte for byte', function () {
    $array   = full_delivery_details_array();
    $details = \Smart_Send\Delivery\Delivery_Details::from_array($array);

    expect($details->get_shipping_method())->toBe('postnord_agent')
        ->and($details->get_pickup_point())->toBeInstanceOf(\Smart_Send\Delivery\Pickup_Point::class)
        ->and($details->get_pickup_point()->get_agent_no())->toBe('1234')
        ->and($details->get_pickup_point()->get_company())->toBe('Corner Shop')
        ->and($details->get_pickup_point()->is_agent_no_only())->toBeFalse()
        ->and($details->is_pickup_point_cleared())->toBeFalse()
        ->and($details->get_parcel_plan())->toBeInstanceOf(\Smart_Send\Delivery\Parcel_Plan::class)
        ->and($details->get_addons())->toBe([])
        ->and($details->to_array())->toBe($array);

    // A directly built details object produces the same shape.
    $built = (new \Smart_Send\Delivery\Delivery_Details())
        ->set_shipping_method('postnord_agent')
        ->set_pickup_point(\Smart_Send\Delivery\Pickup_Point::from_object($array['pickup_point']))
        ->set_parcel_plan(\Smart_Send\Delivery\Parcel_Plan::from_array($array['parcel_plan']));
    expect($built->to_array())->toBe($array);

    // PHP serialization (Phase 7 queueing) keeps the canonical form intact.
    expect(unserialize(serialize($details))->to_array())->toBe($array);
});

it('keeps partial details partial: absent fields stay null (keep stored/derived)', function () {
    $empty = \Smart_Send\Delivery\Delivery_Details::from_array([]);

    expect($empty->to_array())->toBe([
        'shipping_method' => null,
        'pickup_point'    => null,
        'parcel_plan'     => null,
        'addons'          => [],
    ])
        ->and($empty->is_pickup_point_cleared())->toBeFalse();

    // Explicit nulls and an empty method mean the same as absent.
    $nulls = \Smart_Send\Delivery\Delivery_Details::from_array([
        'shipping_method' => '',
        'pickup_point'    => null,
        'parcel_plan'     => null,
    ]);
    expect($nulls->to_array())->toBe($empty->to_array());

    // Only the parcel plan submitted (what the v8 meta box posts).
    $plan_only = \Smart_Send\Delivery\Delivery_Details::from_array(['parcel_plan' => ['specs' => []]]);
    expect($plan_only->get_shipping_method())->toBeNull()
        ->and($plan_only->get_pickup_point())->toBeNull()
        ->and($plan_only->get_parcel_plan()->is_empty())->toBeTrue()
        ->and($plan_only->to_array()['parcel_plan'])->toBe(['specs' => []]);
});

it('accepts a pickup point submitted as a bare agent number and reports it as agent-number-only', function () {
    $details = \Smart_Send\Delivery\Delivery_Details::from_array(['pickup_point' => ['agent_no' => '5678']]);

    $pickup_point = $details->get_pickup_point();
    expect($pickup_point)->toBeInstanceOf(\Smart_Send\Delivery\Pickup_Point::class)
        ->and($pickup_point->get_agent_no())->toBe('5678')
        ->and($pickup_point->is_agent_no_only())->toBeTrue()
        ->and($details->to_array()['pickup_point'])->toBe(['agent_no' => '5678']);

    // Anything beyond the number makes it a full (as-submitted) point.
    expect(\Smart_Send\Delivery\Pickup_Point::from_object(['agent_no' => '5678', 'country' => 'DK'])->is_agent_no_only())->toBeFalse()
        ->and((new \Smart_Send\Delivery\Pickup_Point())->set_agent_no('5678')->is_agent_no_only())->toBeTrue()
        ->and((new \Smart_Send\Delivery\Pickup_Point())->set_agent_no('5678')->set_company('Shop')->is_agent_no_only())->toBeFalse()
        ->and((new \Smart_Send\Delivery\Pickup_Point())->is_agent_no_only())->toBeFalse();
});

it('distinguishes clearing the pickup point from not specifying it with the clear sentinel', function () {
    $cleared = \Smart_Send\Delivery\Delivery_Details::from_array(['pickup_point' => ['clear' => true]]);

    expect($cleared->get_pickup_point())->toBeNull()
        ->and($cleared->is_pickup_point_cleared())->toBeTrue()
        ->and($cleared->to_array()['pickup_point'])->toBe(['clear' => true]);

    // The sentinel survives a round-trip and PHP serialization...
    expect(\Smart_Send\Delivery\Delivery_Details::from_array($cleared->to_array())->is_pickup_point_cleared())->toBeTrue()
        ->and(unserialize(serialize($cleared))->is_pickup_point_cleared())->toBeTrue();

    // ...clear_pickup_point() is its programmatic twin, and setting a
    // point (or null) afterwards resets it to "specified"/"unspecified".
    $details = (new \Smart_Send\Delivery\Delivery_Details())->clear_pickup_point();
    expect($details->is_pickup_point_cleared())->toBeTrue()
        ->and($details->to_array()['pickup_point'])->toBe(['clear' => true]);

    $details->set_pickup_point(null);
    expect($details->is_pickup_point_cleared())->toBeFalse()
        ->and($details->to_array()['pickup_point'])->toBeNull();

    // clear: false is not a clear - and not a point either.
    expect(\Smart_Send\Delivery\Delivery_Details::from_array(['pickup_point' => ['clear' => false]])->to_array()['pickup_point'])->toBe(['clear' => false]);
});

it('writes a cleared pickup point through the repository as a deletion, and a bare agent number is not stored', function () {
    $product = create_simple_product(['price' => 100, 'weight' => 1]);
    $order   = create_order(['products' => [$product], 'shipping_method' => 'postnord_agent']);
    save_order_pickup_point($order->get_id(), sample_agent());

    expect(wc_get_order($order->get_id())->get_meta(\Smart_Send\Delivery\Order_Meta::META_AGENT_NO, true))->toBe('1234');

    // Not specified: the stored point stays.
    SS_SHIPPING_WC()->order_meta()->write($order->get_id(), new \Smart_Send\Delivery\Delivery_Details());
    expect(wc_get_order($order->get_id())->get_meta(\Smart_Send\Delivery\Order_Meta::META_AGENT_NO, true))->toBe('1234');

    // Cleared: both pickup point keys are deleted and saved.
    SS_SHIPPING_WC()->order_meta()->write($order->get_id(), (new \Smart_Send\Delivery\Delivery_Details())->clear_pickup_point());

    $fresh = wc_get_order($order->get_id());
    expect($fresh->get_meta(\Smart_Send\Delivery\Order_Meta::META_AGENT_NO, true))->toBe('')
        ->and($fresh->get_meta(\Smart_Send\Delivery\Order_Meta::META_AGENT, true))->toBe('')
        ->and(SS_SHIPPING_WC()->order_meta()->read($order->get_id())->get_pickup_point())->toBeNull();
});
