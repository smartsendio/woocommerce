<?php

/*
 * Characterization tests for the \Smart_Send\Delivery\Order_Meta repository (#139):
 * read()/write() over \Smart_Send\Delivery\Delivery_Details, the frozen meta keys
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
function assert_order_meta_repository_roundtrip(): void
{
    $repository = SS_SHIPPING_WC()->order_meta();
    $product = create_simple_product(['price' => 100, 'weight' => 1]);
    $order   = create_order(['products' => [$product], 'shipping_method' => 'postnord_agent']);
    $order_id = $order->get_id();

    // An unconfigured order reads as empty details.
    $details = $repository->read($order_id);
    expect($details)->toBeInstanceOf(\Smart_Send\Delivery\Delivery_Details::class)
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
    expect($pickup_point)->toBeInstanceOf(\Smart_Send\Delivery\Pickup_Point::class)
        ->and($pickup_point->get_agent_no())->toBe('1234')
        ->and($pickup_point->get_company())->toBe('Corner Shop')
        ->and($pickup_point->get_internal_id())->toBe('7');

    // The companion deletion must survive a fresh storage read on both backends.
    // The admin handler remains responsible for deleting the number row.
    $repository->delete_pickup_point($order_id);
    $reloaded = new WC_Order($order_id);
    $reloaded->read_meta_data(true);
    expect($reloaded->get_meta(\Smart_Send\Delivery\Order_Meta::META_AGENT, true))->toBe('');

    // Parcel plan: write() persists the canonical order-item plan shape...
    $rows = (new \Smart_Send\Delivery\Parcel_Plan())->add_spec((new \Smart_Send\Delivery\Parcel_Spec())->set_reference('1')->add_item(order_item_id_for_product($order, $product), 1, 'Integration Test Product'))->to_array();
    save_order_parcels($order_id, $rows);
    expect(wc_get_order($order_id)->get_meta('ss_shipping_order_parcels', true))->toEqual($rows);

    // ...read() materializes the plan, preserving the box reference...
    $plan = $repository->read($order_id)->get_parcel_plan();
    expect($plan)->toBeInstanceOf(\Smart_Send\Delivery\Parcel_Plan::class)
        ->and($plan->get_specs())->toHaveCount(1)
        ->and($plan->get_specs()[0]->get_reference())->toBe('1')
        ->and($plan->to_array())->toEqual($rows);

    // ...and an explicitly empty plan clears the stored split.
    save_order_parcels($order_id, []);
    expect(wc_get_order($order_id)->get_meta('ss_shipping_order_parcels', true))->toEqual(['specs' => []])
        ->and($repository->read($order_id)->get_parcel_plan())->toBeNull();

    // A partial write (parcel plan only) leaves the pickup point meta alone.
    expect(wc_get_order($order_id)->get_meta('ss_shipping_order_agent_no', true))->toBe('1234');

    // Shipment/label ids for both normal and return labels (the separate
    // booking-outcome accessor, see \Smart_Send\Fulfillment\Shipment_IDs).
    $shipment_ids = SS_SHIPPING_WC()->shipment_ids();
    $shipment_ids->save($order_id, 'shipment-123', false);
    $shipment_ids->save($order_id, 'return-456', true);
    $fresh = wc_get_order($order_id);
    expect($fresh->get_meta('_ss_shipping_label_id', true))->toBe('shipment-123')
        ->and($fresh->get_meta('_ss_shipping_return_label_id', true))->toBe('return-456')
        ->and($shipment_ids->get($order_id, false))->toBe('shipment-123')
        ->and($shipment_ids->get($order_id, true))->toBe('return-456');
}

it('roundtrips order meta through the repository on legacy post storage', function () {
    with_option('woocommerce_custom_orders_table_enabled', 'no');

    assert_order_meta_repository_roundtrip();

    // Delete the fixtures while legacy storage is still active.
    cleanup_created_objects();
});

it('roundtrips order meta through the repository on HPOS storage', function () {
    with_option('woocommerce_custom_orders_table_enabled', 'yes');

    assert_order_meta_repository_roundtrip();

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

    // A stored object may carry properties the value object does not model,
    // or modeled ones in a non-canonical shape (opening hours here); the
    // stored object must keep them verbatim (#170).
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
    $repository = SS_SHIPPING_WC()->order_meta();
    $missing = 999999999;

    $details = $repository->read($missing);
    expect($details->get_pickup_point())->toBeNull()
        ->and($details->get_parcel_plan())->toBeNull();

    // The writers no-op instead of fataling.
    save_order_pickup_point($missing, sample_agent());
    save_order_parcels($missing, []);
    $repository->store_pickup_point_object($missing, sample_agent());
    SS_SHIPPING_WC()->shipment_ids()->save($missing, 'shipment-1', false);
    expect(SS_SHIPPING_WC()->shipment_ids()->get($missing, false))->toBe('');

    expect($repository->read($missing)->get_pickup_point())->toBeNull();
});

/*
 * The append-only booked-labels list (#182 review, 2026-09-16): the two
 * frozen id keys keep holding the LATEST id per direction, and every
 * booked label is also appended to _ss_shipping_labels, which is what the
 * order screen's "Booked shipments" timeline renders.
 */

it('appends every booked label to the labels list while the frozen id keys keep the latest id', function () {
    $shipment_ids = SS_SHIPPING_WC()->shipment_ids();
    $product = create_simple_product(['price' => 100, 'weight' => 1]);
    $order   = create_order(['products' => [$product], 'shipping_method' => 'postnord_agent']);

    expect($shipment_ids->labels($order))->toBe([]);

    $shipment_ids->save($order, 'shipment-1', false, '2026-09-16T11:25:01+00:00');
    $shipment_ids->save($order, 'return-1', true, '2026-09-16T11:25:04+00:00');
    $shipment_ids->save($order, 'shipment-2', false, '2026-09-16T12:00:00+00:00');

    $fresh = wc_get_order($order->get_id());

    // Oldest first, one row per booking, with its direction and timestamp.
    expect($shipment_ids->labels($fresh))->toBe([
        ['direction' => 'outbound', 'shipment_id' => 'shipment-1', 'booked_at' => '2026-09-16T11:25:01+00:00'],
        ['direction' => 'return', 'shipment_id' => 'return-1', 'booked_at' => '2026-09-16T11:25:04+00:00'],
        ['direction' => 'outbound', 'shipment_id' => 'shipment-2', 'booked_at' => '2026-09-16T12:00:00+00:00'],
    ]);

    // The frozen keys are untouched in behaviour: the latest id per direction.
    expect($fresh->get_meta('_ss_shipping_label_id', true))->toBe('shipment-2')
        ->and($fresh->get_meta('_ss_shipping_return_label_id', true))->toBe('return-1')
        ->and($shipment_ids->get($fresh, false))->toBe('shipment-2');
});

it('stamps a label booked without a timestamp with the current time, in ISO 8601 UTC', function () {
    $product = create_simple_product(['price' => 100, 'weight' => 1]);
    $order   = create_order(['products' => [$product], 'shipping_method' => 'postnord_agent']);

    SS_SHIPPING_WC()->shipment_ids()->save($order, 'shipment-now', false);

    $labels = SS_SHIPPING_WC()->shipment_ids()->labels($order);
    expect($labels)->toHaveCount(1)
        ->and($labels[0]['booked_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/')
        ->and(abs(strtotime($labels[0]['booked_at']) - time()))->toBeLessThan(120);
});

it('caps the labels list at MAX_LABELS, keeping the newest entries', function () {
    $shipment_ids = SS_SHIPPING_WC()->shipment_ids();
    $product = create_simple_product(['price' => 100, 'weight' => 1]);
    $order   = create_order(['products' => [$product], 'shipping_method' => 'postnord_agent']);

    $total = \Smart_Send\Fulfillment\Shipment_IDs::MAX_LABELS + 5;
    for ($i = 1; $i <= $total; $i++) {
        $shipment_ids->save($order, 'shipment-' . $i, false, '2026-09-16T11:00:00+00:00');
    }

    $labels = $shipment_ids->labels($order);
    expect($labels)->toHaveCount(\Smart_Send\Fulfillment\Shipment_IDs::MAX_LABELS)
        ->and($labels[0]['shipment_id'])->toBe('shipment-6')
        ->and(end($labels)['shipment_id'])->toBe('shipment-' . $total);
});

it('reads the labels list of a missing order as empty instead of fataling', function () {
    expect(SS_SHIPPING_WC()->shipment_ids()->labels(999999999))->toBe([]);
});
