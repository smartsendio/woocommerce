<?php

use Smart_Send\Delivery\Delivery_Details;
use Smart_Send\Delivery\Order_Meta;
use Smart_Send\Delivery\Parcel_Plan;
use Smart_Send\Delivery\Parcel_Spec;

beforeEach(function () {
    with_ss_settings();
});

it('requires an explicit reset of legacy parcel rows while preserving pickup metadata and label access', function () {
    $product = create_simple_product(['price' => 50, 'weight' => 1]);
    $order = create_order(['products' => [$product], 'shipping_method' => 'postnord_homedelivery']);
    save_order_pickup_point($order->get_id(), sample_agent());
    $legacy = [['id' => $product->get_id(), 'name' => 'Old row', 'value' => '2']];
    $order->update_meta_data(Order_Meta::META_PARCELS, $legacy);
    $order->update_meta_data(Order_Meta::META_LABEL_ID, 'existing-label');
    $order->save();
    $capture = mock_smart_send_api();

    $result = SS_SHIPPING_WC()->fulfillment()->fulfill_outbound($order, false);
    expect($result->is_successful())->toBeFalse()
        ->and($result->get_outbound_error())->toContain('unsupported format')
        ->and($capture->requests)->toBe([])
        ->and(SS_SHIPPING_WC()->shipment_ids()->get($order, false))->toBe('existing-label')
        ->and($order->get_meta(Order_Meta::META_PARCELS, true))->toBe($legacy)
        ->and(SS_SHIPPING_WC()->fulfillment_presenter()->state($order)['parcel_plan_error'])->toContain('Reset');

    $reset = (new Delivery_Details())->set_parcel_plan(new Parcel_Plan());
    $result = SS_SHIPPING_WC()->fulfillment()->fulfill_outbound($order, false, $reset);
    $fresh = new WC_Order($order->get_id());
    expect($result->is_successful())->toBeTrue()
        ->and($capture->requests)->toHaveCount(1)
        ->and($fresh->get_meta(Order_Meta::META_PARCELS, true))->toBe(['specs' => []])
        ->and($fresh->get_meta(Order_Meta::META_AGENT_NO, true))->toBe('1234')
        ->and(SS_SHIPPING_WC()->order_meta()->parcel_plan_error($fresh))->toBeNull();
});

it('keeps historical labels accessible after an allocated order line is removed and rejects a new booking', function () {
    $product = create_simple_product(['price' => 50, 'weight' => 1]);
    $order = create_order(['products' => [$product], 'shipping_method' => 'postnord_homedelivery']);
    $line_id = order_item_id_for_product($order, $product);
    $plan = (new Parcel_Plan())->add_spec((new Parcel_Spec())->add_item($line_id));
    SS_SHIPPING_WC()->order_meta()->write($order, (new Delivery_Details())->set_parcel_plan($plan));
    SS_SHIPPING_WC()->shipment_ids()->save($order, 'historic-shipment', false);
    $order->remove_item($line_id);
    $order->save();
    $fresh = new WC_Order($order->get_id());
    $capture = mock_smart_send_api();

    $state = SS_SHIPPING_WC()->fulfillment_presenter()->state($fresh);
    expect($state['order']['units'])->toBe([])
        ->and($state['timeline'][0]['shipment_id'])->toBe('historic-shipment')
        ->and($state['timeline'][0]['app_url'])->toContain('historic-shipment')
        ->and($state['parcel_plan_error'])->toContain('no longer matches');
    $result = SS_SHIPPING_WC()->fulfillment()->fulfill_outbound($fresh, false);
    expect($result->is_successful())->toBeFalse()->and($capture->requests)->toBe([]);
});

it('requires a supplied weight for deleted products and books their surviving financial order data in either direction', function (bool $is_return) {
    $product = create_simple_product(['price' => 25, 'weight' => 1]);
    $order = create_order([
        'products' => [[$product, 2]],
        'shipping_method' => 'postnord_homedelivery',
        'return_method' => 'postnord_returndropoff',
    ]);
    $line_id = order_item_id_for_product($order, $product);
    $product->delete(true);
    $order = new WC_Order($order->get_id());
    $capture = mock_smart_send_api();
    $fulfillment = SS_SHIPPING_WC()->fulfillment();
    $book = static function (?Delivery_Details $details) use ($fulfillment, $order, $is_return) {
        return $is_return ? $fulfillment->fulfill_return($order, false, $details) : $fulfillment->fulfill_outbound($order, false, $details);
    };

    $result = $book(null);
    expect($result->is_successful())->toBeFalse()
        ->and($result->get_first_error_message())->toContain('parcel weight')
        ->and($capture->requests)->toBe([]);

    $plan = (new Parcel_Plan())->add_spec((new Parcel_Spec())->set_weight(1.5));
    $result = $book((new Delivery_Details())->set_parcel_plan($plan));
    expect($result->is_successful())->toBeTrue()->and($capture->requests)->toHaveCount(1);
    $payload = json_decode($capture->requests[0]['body'], true);
    expect($payload['parcels'][0]['weight'])->toEqual(1.5)
        ->and($payload['parcels'][0]['items'][0]['internal_id'])->toBe((string) $line_id)
        ->and($payload['parcels'][0]['items'][0]['name'])->toBe('Deleted')
        ->and($payload['parcels'][0]['items'][0]['sku'])->toBeNull()
        ->and($payload['parcels'][0]['items'][0]['quantity'])->toEqual(2)
        ->and($payload['parcels'][0]['items'][0]['total_price_excluding_tax'])->toEqual(50);
    $unit = SS_SHIPPING_WC()->fulfillment_presenter()->state($order)['order']['units'][0];
    expect($unit['name'])->toBe('Deleted')->and($unit['sku'])->toBe('')->and($unit['unit_weight'])->toBeNull();
})->with([false, true]);

it('retains submitted parcel measurements in a combined deleted-product booking without overriding return choices', function (bool $separate_return_plan) {
    $product = create_simple_product(['price' => 25, 'weight' => 1]);
    $order = create_order([
        'products' => [[$product, 2]],
        'shipping_method' => 'postnord_agent',
        'return_method' => 'postnord_returndropoff',
        'auto_return' => 'yes',
    ]);
    $product->delete(true);
    $order = new WC_Order($order->get_id());
    $capture = mock_smart_send_api();
    $outbound_plan = (new Parcel_Plan())->add_spec((new Parcel_Spec())->set_weight(1.5)->set_length(30));
    $outbound = (new Delivery_Details())->set_shipping_method('gls_agent')
        ->set_pickup_point(\Smart_Send\Delivery\Pickup_Point::from_object(sample_agent()))
        ->set_parcel_plan($outbound_plan);
    $return = null;
    if ($separate_return_plan) {
        $return = (new Delivery_Details())->set_shipping_method('gls_returndropoff')
            ->set_pickup_point(\Smart_Send\Delivery\Pickup_Point::from_object(sample_agent(['agent_no' => '5678'])))
            ->set_parcel_plan((new Parcel_Plan())->add_spec((new Parcel_Spec())->set_weight(2.5)->set_length(50)));
    }

    $result = SS_SHIPPING_WC()->fulfillment()->fulfill_outbound($order, false, $outbound, null, $return);
    expect($result->is_successful())->toBeTrue()
        ->and($result->get_outbound_error())->toBeNull()
        ->and($result->get_return_error())->toBeNull()
        ->and($capture->requests)->toHaveCount(2);
    $outbound_payload = json_decode($capture->requests[0]['body'], true);
    $return_payload = json_decode($capture->requests[1]['body'], true);
    expect($outbound_payload['shipping_carrier'])->toBe('gls')
        ->and($outbound_payload['shipping_method'])->toBe('agent')
        ->and($outbound_payload['agent']['agent_no'])->toBe('1234')
        ->and($outbound_payload['parcels'][0]['weight'])->toEqual(1.5)
        ->and($outbound_payload['parcels'][0]['length'])->toEqual(30)
        ->and($return_payload['shipping_carrier'])->toBe($separate_return_plan ? 'gls' : 'postnord')
        ->and($return_payload['shipping_method'])->toBe('returndropoff')
        ->and($return_payload['parcels'][0]['weight'])->toEqual($separate_return_plan ? 2.5 : 1.5)
        ->and($return_payload['parcels'][0]['length'])->toEqual($separate_return_plan ? 50 : 30)
        ->and($outbound_plan->get_specs()[0]->get_weight())->toBe(1.5);
    if ($separate_return_plan) {
        expect($return_payload['agent']['agent_no'])->toBe('5678');
    } else {
        expect($return_payload['agent'])->toBeNull();
    }
    // Measurements remain per-run input rather than becoming persistent order data.
    $stored = SS_SHIPPING_WC()->order_meta()->read($order)->get_parcel_plan();
    expect($stored->get_specs()[0]->get_weight())->toBeNull()
        ->and($stored->get_specs()[0]->get_length())->toBeNull();
})->with([false, true]);

it('merges the outbound parcel plan into return overrides without mutating the supplied return object', function () {
    $product = create_simple_product();
    $order = create_order(['products' => [$product], 'shipping_method' => 'postnord_homedelivery']);
    $outbound = (new Delivery_Details())->set_parcel_plan((new Parcel_Plan())->add_spec((new Parcel_Spec())->set_weight(3)));
    $return = (new Delivery_Details())->set_shipping_method('gls_returndropoff');
    $capture = mock_smart_send_api();

    $result = SS_SHIPPING_WC()->fulfillment()->fulfill_outbound($order, false, $outbound, true, $return);
    expect($result->is_successful())->toBeTrue()
        ->and($capture->requests)->toHaveCount(2)
        ->and($return->get_parcel_plan())->toBeNull()
        ->and(json_decode($capture->requests[1]['body'], true)['parcels'][0]['weight'])->toEqual(3);
});
