<?php

/*
 * Tests for \Smart_Send\Delivery\Pickup_Point as the typed pickup point contract
 * (#170): it models every field the Smart Send API delivers for a pickup
 * point (identity, carrier, name/address lines, distance, coordinates,
 * opening hours), round-trips the frozen order meta object losslessly,
 * offers to_object()/to_array() read conveniences, and is what the lookup
 * hands out - mapped at the API boundary, cached in the session in plain
 * serializable form, read back as value objects.
 */

/**
 * A pickup point as the Smart Send API v1 closest-agents lookup returns
 * it, with every documented optional field present.
 */
function full_api_agent(array $overrides = []): object
{
    return json_decode(json_encode(array_merge([
        'id'            => '0f3b2c9a-1111-4222-8333-444455556666',
        'agent_no'      => '1234',
        'carrier'       => 'postnord',
        'company'       => 'Corner Shop',
        'name_line1'    => 'Attention Name',
        'name_line2'    => null,
        'address_line1' => 'Main Street 1',
        'address_line2' => 'Rear entrance',
        'postal_code'   => '2300',
        'city'          => 'Copenhagen',
        'country'       => 'DK',
        'type'          => 'agent',
        'distance'      => 0.42,
        'coordinates'   => ['latitude' => 55.6631, 'longitude' => 12.5814],
        'opening_hours' => [
            ['day' => 'monday', 'opens' => '08:00:00', 'closes' => '20:00:00'],
            ['day' => 'saturday', 'opens' => '10:00:00', 'closes' => '16:00:00'],
        ],
    ], $overrides)));
}

beforeEach(function (): void {
    with_ss_settings();
});

it('exposes every API-delivered pickup point field as a typed getter', function () {
    $pickup_point = \Smart_Send\Delivery\Pickup_Point::from_object(full_api_agent());

    expect($pickup_point->get_internal_id())->toBe('0f3b2c9a-1111-4222-8333-444455556666')
        ->and($pickup_point->get_agent_no())->toBe('1234')
        ->and($pickup_point->get_carrier())->toBe('postnord')
        ->and($pickup_point->get_company())->toBe('Corner Shop')
        ->and($pickup_point->get_name_line1())->toBe('Attention Name')
        ->and($pickup_point->get_name_line2())->toBeNull()
        ->and($pickup_point->get_address_line1())->toBe('Main Street 1')
        ->and($pickup_point->get_address_line2())->toBe('Rear entrance')
        ->and($pickup_point->get_postal_code())->toBe('2300')
        ->and($pickup_point->get_city())->toBe('Copenhagen')
        ->and($pickup_point->get_country())->toBe('DK')
        ->and($pickup_point->get_distance())->toBe(0.42)
        ->and($pickup_point->get_latitude())->toBe(55.6631)
        ->and($pickup_point->get_longitude())->toBe(12.5814)
        ->and($pickup_point->get_opening_hours())->toBe([
            ['day' => 'monday', 'opens' => '08:00:00', 'closes' => '20:00:00'],
            ['day' => 'saturday', 'opens' => '10:00:00', 'closes' => '16:00:00'],
        ]);
});

it('round-trips the full API object losslessly through to_object()', function () {
    $agent = full_api_agent();

    $reproduced = \Smart_Send\Delivery\Pickup_Point::from_object($agent)->to_object();

    // Byte-identical: same properties, same order, same nested objects -
    // the frozen order meta format is untouched by the new typed fields.
    expect(serialize($reproduced))->toBe(serialize($agent))
        ->and($reproduced->type)->toBe('agent');
});

it('offers a plain-array view via to_array() and accepts it back in from_object()', function () {
    $pickup_point = \Smart_Send\Delivery\Pickup_Point::from_object(full_api_agent());

    $array = $pickup_point->to_array();

    expect($array['agent_no'])->toBe('1234')
        ->and($array['coordinates'])->toBe(['latitude' => 55.6631, 'longitude' => 12.5814])
        ->and($array['opening_hours'][0])->toBe(['day' => 'monday', 'opens' => '08:00:00', 'closes' => '20:00:00']);

    // Fed back in, the typed fields are parsed again and the array view is
    // reproduced (the nested rows stay arrays, as given - lossless either way).
    $again = \Smart_Send\Delivery\Pickup_Point::from_object($array);
    expect($again->get_latitude())->toBe(55.6631)
        ->and($again->get_opening_hours())->toHaveCount(2)
        ->and($again->to_array())->toBe($array);
});

it('emits the new fields on a directly constructed pickup point only when set', function () {
    $minimal = (new \Smart_Send\Delivery\Pickup_Point())->set_agent_no('1')->set_company('Shop');
    $object  = $minimal->to_object();

    expect(property_exists($object, 'carrier'))->toBeFalse()
        ->and(property_exists($object, 'coordinates'))->toBeFalse()
        ->and(property_exists($object, 'opening_hours'))->toBeFalse()
        ->and(property_exists($object, 'name_line1'))->toBeFalse();

    $full = (new \Smart_Send\Delivery\Pickup_Point())
        ->set_agent_no('1')
        ->set_carrier('gls')
        ->set_name_line1('Name')
        ->set_latitude_longitude('55.5', '12.5')
        ->set_opening_hours([
            (object) ['day' => 'monday', 'opens' => '08:00:00', 'closes' => '17:00:00'],
            ['day' => 'tuesday', 'opens' => '08:00:00'], // incomplete row: dropped
        ]);
    $object = $full->to_object();

    expect($object->carrier)->toBe('gls')
        ->and($object->name_line1)->toBe('Name')
        ->and($object->name_line2)->toBeNull()
        ->and($object->coordinates)->toEqual((object) ['latitude' => 55.5, 'longitude' => 12.5])
        ->and($object->opening_hours)->toEqual([(object) ['day' => 'monday', 'opens' => '08:00:00', 'closes' => '17:00:00']]);

    // Clearing works too.
    $full->set_latitude_longitude(null, null)->set_opening_hours(null);
    expect($full->get_coordinates())->toBeNull()
        ->and($full->get_opening_hours())->toBe([]);
});

it('stores a pickup point with coordinates and opening hours on the order byte-identically to the API object', function () {
    $order = create_order(['shipping_method' => 'postnord_agent']);
    $agent = full_api_agent();

    save_order_pickup_point($order->get_id(), $agent);

    $fresh = wc_get_order($order->get_id());
    expect(serialize($fresh->get_meta(\Smart_Send\Delivery\Order_Meta::META_AGENT, true)))->toBe(serialize($agent));

    $read = SS_SHIPPING_WC()->order_meta()->read($order->get_id())->get_pickup_point();
    expect($read->get_latitude())->toBe(55.6631)
        ->and($read->get_opening_hours())->toHaveCount(2)
        ->and($read->get_carrier())->toBe('postnord');
});

it('maps the lookup result to value objects at the API boundary and caches them serializably', function () {
    if (is_null(WC()->cart)) {
        wc_load_cart();
    }
    remember_cleanup_callback(function (): void {
        WC()->session->set('ss_shipping_agents', null);
    });
    mock_smart_send_api(function () {
        return ss_api_response(200, ['data' => [full_api_agent(), full_api_agent(['agent_no' => '5678', 'company' => 'Second Shop', 'distance' => 1.2])]]);
    });

    $lookup = new \Smart_Send\Delivery_Options\Pickup_Point_Lookup();
    $found  = $lookup->find_closest_by_address('postnord', 'DK', '2300', 'Copenhagen', 'Islands Brygge 39');

    expect($found)->toHaveCount(2)
        ->and($found[0])->toBeInstanceOf(\Smart_Send\Delivery\Pickup_Point::class)
        ->and($found[0]->get_opening_hours())->toHaveCount(2)
        ->and($found[1]->get_agent_no())->toBe('5678');

    // Session: plain objects (the same shape the order meta stores), so the
    // session never holds plugin class instances; a full serialize/unserialize
    // cycle of the session value reproduces the pickup points.
    $cached = WC()->session->get('ss_shipping_agents');
    expect($cached[0])->toBeInstanceOf(stdClass::class)
        ->and(serialize($cached[0]))->toBe(serialize(full_api_agent()));
    $thawed = unserialize(serialize($cached));
    expect(\Smart_Send\Delivery\Pickup_Point::from_object($thawed[1])->get_company())->toBe('Second Shop');

    // Read back as value objects, resolvable by agent number.
    $from_session = $lookup->get_session_pickup_points();
    expect($from_session)->toHaveCount(2)
        ->and($from_session[1])->toBeInstanceOf(\Smart_Send\Delivery\Pickup_Point::class)
        ->and($lookup->find_cached_by_agent_no('5678')->get_company())->toBe('Second Shop')
        ->and($lookup->find_cached_by_agent_no('5678')->get_latitude())->toBe(55.6631)
        ->and($lookup->find_cached_by_agent_no('0000'))->toBeNull();
});

it('reports null before any lookup and an empty list after a lookup that found nothing', function () {
    if (is_null(WC()->cart)) {
        wc_load_cart();
    }
    remember_cleanup_callback(function (): void {
        WC()->session->set('ss_shipping_agents', null);
    });
    WC()->session->set('ss_shipping_agents', null);

    $lookup = new \Smart_Send\Delivery_Options\Pickup_Point_Lookup();
    expect($lookup->get_session_pickup_points())->toBeNull();

    mock_smart_send_api(function () {
        return ss_api_response(200, ['data' => []]);
    });
    expect($lookup->find_closest_by_address('postnord', 'DK', '2300', 'Copenhagen', 'Islands Brygge 39'))->toBe([])
        ->and($lookup->get_session_pickup_points())->toBe([]);
});
