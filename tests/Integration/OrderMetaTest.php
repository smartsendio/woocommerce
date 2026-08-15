<?php

/*
 * Characterization tests for the SS_Shipping_Order_Meta repository (#139):
 * read()/write() over SS_Shipping_Delivery_Details, the frozen meta keys
 * and stored formats (a plain agent object under _ss_shipping_order_agent,
 * the agent number under ss_shipping_order_agent_no, the id/name/value
 * parcel rows under ss_shipping_order_parcels), the vConnect fallback, and
 * the missing-order guards. The same assertions run against both order
 * storage backends: legacy post meta and HPOS (the custom orders table),
 * toggled through the woocommerce_custom_orders_table_enabled option for
 * the duration of the test.
 */

/**
 * The shared repository assertions, storage-agnostic.
 */
function assert_order_meta_repository_roundtrip(bool $hpos): void
{
    $repository  = SS_SHIPPING_WC()->order_meta();
    $fulfillment = SS_SHIPPING_WC()->fulfillment();
    $product = create_simple_product(['price' => 100, 'weight' => 1]);
    $order   = create_order(['products' => [$product], 'shipping_method' => 'postnord_agent']);
    $order_id = $order->get_id();

    // An unconfigured order reads as empty details.
    $details = $repository->read($order_id);
    expect($details)->toBeInstanceOf(SS_Shipping_Delivery_Details::class)
        ->and($details->get_pickup_point())->toBeNull()
        ->and($details->get_parcel_plan())->toBeNull()
        ->and($details->get_shipping_method())->toBeNull()
        ->and($details->get_addons())->toBe([]);

    // Pickup point: write() persists the frozen keys/formats...
    save_order_pickup_point($order_id, sample_agent());

    $fresh = wc_get_order($order_id);
    $stored_agent = $fresh->get_meta('_ss_shipping_order_agent', true);
    expect($fresh->get_meta('ss_shipping_order_agent_no', true))->toBe('1234')
        ->and($stored_agent)->toBeObject()
        ->and($stored_agent->agent_no)->toBe('1234')
        ->and($stored_agent->company)->toBe('Corner Shop')
        ->and($stored_agent->address_line1)->toBe('Main Street 1')
        ->and($stored_agent->address_line2)->toBeNull()
        ->and($stored_agent->distance)->toEqual(0.5);

    // ...and read() materializes them back.
    $pickup_point = $repository->read($order_id)->get_pickup_point();
    expect($pickup_point)->toBeInstanceOf(SS_Shipping_Pickup_Point::class)
        ->and($pickup_point->get_agent_no())->toBe('1234')
        ->and($pickup_point->get_company())->toBe('Corner Shop')
        ->and($pickup_point->get_internal_id())->toBe('7');

    // v8 oddity: delete_pickup_point() calls delete_meta_data()
    // but never saves the order, so the delete is not persisted. What a
    // subsequent read sees then DIFFERS per backend: under legacy storage a
    // fresh wc_get_order() reloads from the database and still finds the
    // agent; under HPOS the in-process order cache returns the same object
    // instance, whose in-memory meta was already deleted.
    $repository->delete_pickup_point($order_id);
    if ($hpos) {
        expect($repository->read($order_id)->get_pickup_point())->toBeNull();
    } else {
        expect($repository->read($order_id)->get_pickup_point())->not->toBeNull();
    }

    // Parcel plan: write() persists the frozen row shape...
    $rows = [['id' => $product->get_id(), 'name' => 'Integration Test Product', 'value' => '1']];
    save_order_parcels($order_id, $rows);
    expect(wc_get_order($order_id)->get_meta('ss_shipping_order_parcels', true))->toEqual($rows);

    // ...read() materializes the plan, preserving the box reference...
    $plan = $repository->read($order_id)->get_parcel_plan();
    expect($plan)->toBeInstanceOf(SS_Shipping_Parcel_Plan::class)
        ->and($plan->get_specs())->toHaveCount(1)
        ->and($plan->get_specs()[0]->get_reference())->toBe('1')
        ->and($plan->to_box_rows())->toEqual($rows);

    // ...and an explicitly empty plan clears the stored split.
    save_order_parcels($order_id, []);
    expect(wc_get_order($order_id)->get_meta('ss_shipping_order_parcels', true))->toEqual([])
        ->and($repository->read($order_id)->get_parcel_plan())->toBeNull();

    // A partial write (parcel plan only) leaves the pickup point meta alone.
    expect(wc_get_order($order_id)->get_meta('ss_shipping_order_agent_no', true))->toBe('1234');

    // Shipment/label ids for both normal and return labels (the separate
    // booking-outcome accessor, see SS_Shipping_Shipment_Ids).
    $shipment_ids = SS_SHIPPING_WC()->shipment_ids();
    $shipment_ids->save($order_id, 'shipment-123', false);
    $shipment_ids->save($order_id, 'return-456', true);
    $fresh = wc_get_order($order_id);
    expect($fresh->get_meta('_ss_shipping_label_id', true))->toBe('shipment-123')
        ->and($fresh->get_meta('_ss_shipping_return_label_id', true))->toBe('return-456')
        ->and($shipment_ids->get($order_id, false))->toBe('shipment-123')
        ->and($shipment_ids->get($order_id, true))->toBe('return-456');

    // Label URLs are derived from the shipment id and the uploads dir.
    expect($fulfillment->get_label_url_from_order_id($order_id, false))
        ->toContain('smart-send-label-shipment-123.pdf')
        ->and($fulfillment->get_label_url_from_order_id($order_id, true))
        ->toContain('smart-send-label-return-456.pdf');
}

it('roundtrips order meta through the repository on legacy post storage', function () {
    with_option('woocommerce_custom_orders_table_enabled', 'no');

    assert_order_meta_repository_roundtrip(false);

    // Delete the fixtures while legacy storage is still active.
    cleanup_created_objects();
});

it('roundtrips order meta through the repository on HPOS storage', function () {
    with_option('woocommerce_custom_orders_table_enabled', 'yes');

    assert_order_meta_repository_roundtrip(true);

    // Delete the fixtures while HPOS is still active.
    cleanup_created_objects();
});

it('falls back to the vConnect meta for the pickup point', function () {
    $product = create_simple_product(['price' => 100, 'weight' => 1]);
    $order   = create_order(['products' => [$product]]);
    $order->update_meta_data('_vc_aio_options', [
        'addressId'   => ['value' => '9876'],
        'name'        => ['value' => 'vConnect Shop'],
        'addressText' => ['value' => 'Side Street 2'],
        'city'        => ['value' => 'Aarhus'],
        'postcode'    => ['value' => '8000'],
        'country'     => ['value' => 'DK'],
    ]);
    $order->save();

    $pickup_point = SS_SHIPPING_WC()->order_meta()->read($order->get_id())->get_pickup_point();

    expect($pickup_point->get_agent_no())->toBe('9876')
        ->and($pickup_point->get_company())->toBe('vConnect Shop')
        ->and($pickup_point->get_address_line1())->toBe('Side Street 2')
        ->and($pickup_point->get_city())->toBe('Aarhus')
        ->and($pickup_point->get_postal_code())->toBe('8000')
        ->and($pickup_point->get_country())->toBe('DK');
});

it('round-trips the stored agent object losslessly, keeping unknown properties', function () {
    $product = create_simple_product(['price' => 100, 'weight' => 1]);
    $order   = create_order(['products' => [$product], 'shipping_method' => 'postnord_agent']);

    // The API may deliver properties the value object does not model
    // (e.g. opening hours); the stored object must keep them.
    $agent = sample_agent(['opening_hours' => ['mon' => '8-16']]);
    save_order_pickup_point($order->get_id(), $agent);

    $stored = wc_get_order($order->get_id())->get_meta('_ss_shipping_order_agent', true);
    expect($stored->opening_hours)->toEqual(['mon' => '8-16'])
        ->and(array_keys(get_object_vars($stored)))->toEqual(array_keys(get_object_vars($agent)));
});

it('handles a missing order in every repository entry point without fatals', function () {
    // Regression for #60: wc_get_order() returns false for order IDs that do
    // not exist (e.g. WooCommerce's email preview placeholder); every
    // entry point must guard instead of calling methods on false.
    $repository  = SS_SHIPPING_WC()->order_meta();
    $fulfillment = SS_SHIPPING_WC()->fulfillment();
    $missing = 999999999;

    $details = $repository->read($missing);
    expect($details->get_pickup_point())->toBeNull()
        ->and($details->get_parcel_plan())->toBeNull()
        ->and($fulfillment->get_label_url_from_order_id($missing, false))->toBe('')
        ->and($fulfillment->get_label_url_from_order_id($missing, true))->toBe('');

    // The writers no-op instead of fataling.
    save_order_pickup_point($missing, sample_agent());
    save_order_parcels($missing, []);
    $repository->store_pickup_point_object($missing, sample_agent());
    SS_SHIPPING_WC()->shipment_ids()->save($missing, 'shipment-1', false);
    expect(SS_SHIPPING_WC()->shipment_ids()->get($missing, false))->toBe('');

    expect($repository->read($missing)->get_pickup_point())->toBeNull();
});
