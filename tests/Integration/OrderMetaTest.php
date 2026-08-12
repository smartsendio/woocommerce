<?php

/*
 * Characterization tests for the SS_Shipping_WC_Order meta accessors (agent
 * no, agent object, parcels, label/shipment ids) and for the Smart Send
 * method detection on an order. The same accessor assertions run against
 * both order storage backends: legacy post meta and HPOS (the custom orders
 * table), toggled through the woocommerce_custom_orders_table_enabled
 * option for the duration of the test.
 */

/**
 * The shared accessor assertions, storage-agnostic.
 */
function assert_order_meta_accessors_roundtrip(bool $hpos): void
{
    $handler = SS_SHIPPING_WC()->get_ss_shipping_wc_order();
    $product = create_simple_product(['price' => 100, 'weight' => 1]);
    $order   = create_order(['products' => [$product], 'shipping_method' => 'postnord_agent']);
    $order_id = $order->get_id();

    // Agent no.
    expect($handler->get_ss_shipping_order_agent_no($order_id))->toBeNull();
    $handler->save_ss_shipping_order_agent_no($order_id, '1234');
    expect($handler->get_ss_shipping_order_agent_no($order_id))->toBe('1234');

    // Agent object (stored under the private _ss_shipping_order_agent key).
    expect($handler->get_ss_shipping_order_agent($order_id))->toBeNull();
    $handler->save_ss_shipping_order_agent($order_id, sample_agent());
    $agent = $handler->get_ss_shipping_order_agent($order_id);
    expect($agent)->toBeObject()
        ->and($agent->agent_no)->toBe('1234')
        ->and($agent->company)->toBe('Corner Shop');
    expect(wc_get_order($order_id)->get_meta('_ss_shipping_order_agent', true))->toBeObject();

    // v8 oddity: delete_ss_shipping_order_agent() calls delete_meta_data()
    // but never saves the order, so the delete is not persisted. What a
    // subsequent read sees then DIFFERS per backend: under legacy storage a
    // fresh wc_get_order() reloads from the database and still finds the
    // agent; under HPOS the in-process order cache returns the same object
    // instance, whose in-memory meta was already deleted.
    $handler->delete_ss_shipping_order_agent($order_id);
    if ($hpos) {
        expect($handler->get_ss_shipping_order_agent($order_id))->toBeNull();
    } else {
        expect($handler->get_ss_shipping_order_agent($order_id))->toBeObject();
    }

    // Parcels.
    expect($handler->get_ss_shipping_order_parcels($order_id))->toBe('');
    $parcels = [['id' => $product->get_id(), 'name' => 'Integration Test Product', 'value' => '1']];
    $handler->save_ss_shipping_order_parcels($order_id, $parcels);
    expect($handler->get_ss_shipping_order_parcels($order_id))->toEqual($parcels);

    // Shipment/label ids for both normal and return labels.
    $handler->save_ss_shipment_id_in_order_meta($order_id, 'shipment-123', false);
    $handler->save_ss_shipment_id_in_order_meta($order_id, 'return-456', true);
    $fresh = wc_get_order($order_id);
    expect($fresh->get_meta('_ss_shipping_label_id', true))->toBe('shipment-123')
        ->and($fresh->get_meta('_ss_shipping_return_label_id', true))->toBe('return-456');

    // Label URLs are derived from the shipment id and the uploads dir.
    expect($handler->get_label_url_from_order_id($order_id, false))
        ->toContain('smart-send-label-shipment-123.pdf')
        ->and($handler->get_label_url_from_order_id($order_id, true))
        ->toContain('smart-send-label-return-456.pdf');
}

it('roundtrips order meta through the accessors on legacy post storage', function () {
    with_option('woocommerce_custom_orders_table_enabled', 'no');

    assert_order_meta_accessors_roundtrip(false);

    // Delete the fixtures while legacy storage is still active.
    cleanup_created_objects();
});

it('roundtrips order meta through the accessors on HPOS storage', function () {
    with_option('woocommerce_custom_orders_table_enabled', 'yes');

    assert_order_meta_accessors_roundtrip(true);

    // Delete the fixtures while HPOS is still active.
    cleanup_created_objects();
});

it('falls back to the vConnect meta for agent no and agent object', function () {
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

    $handler = SS_SHIPPING_WC()->get_ss_shipping_wc_order();

    expect($handler->get_ss_shipping_order_agent_no($order->get_id()))->toBe('9876');

    $agent = $handler->get_ss_shipping_order_agent($order->get_id());
    expect($agent->agent_no)->toBe('9876')
        ->and($agent->company)->toBe('vConnect Shop')
        ->and($agent->address_line1)->toBe('Side Street 2')
        ->and($agent->city)->toBe('Aarhus')
        ->and($agent->postal_code)->toBe('8000')
        ->and($agent->country)->toBe('DK');
});

it('detects the Smart Send shipping method on an order', function () {
    $handler = SS_SHIPPING_WC()->get_ss_shipping_wc_order();
    $product = create_simple_product(['price' => 100, 'weight' => 1]);

    $ss_order = create_order([
        'products'        => [$product],
        'shipping_method' => 'postnord_agent',
        'return_method'   => 'postnord_returndropoff',
        'auto_return'     => 'yes',
    ]);
    expect($handler->get_smart_send_method_id($ss_order->get_id()))->toBe('postnord_agent')
        ->and($handler->get_smart_send_method_id($ss_order->get_id(), true))->toEqual([
            'smart_send_return_method'              => 'postnord_returndropoff',
            'smart_send_auto_generate_return_label' => 'yes',
        ]);

    $plain_order = create_order(['products' => [$product]]);
    expect($handler->get_smart_send_method_id($plain_order->get_id()))->toBe('');

    expect($handler->get_smart_send_method_id(0))->toBe('');
});

it('maps WooCommerce free shipping to the configured Smart Send method', function () {
    with_ss_settings(['shipping_method_for_free_shipping' => 'gls_agent']);

    $product = create_simple_product(['price' => 100, 'weight' => 1]);
    $order   = create_order(['products' => [$product]]);

    $shipping_item = new WC_Order_Item_Shipping();
    $shipping_item->set_method_title('Free shipping');
    $shipping_item->set_method_id('free_shipping');
    $shipping_item->set_instance_id(2);
    $shipping_item->set_total('0');
    $order->add_item($shipping_item);
    $order->save();

    expect(SS_SHIPPING_WC()->get_ss_shipping_wc_order()->get_smart_send_method_id($order->get_id()))
        ->toBe('gls_agent');
});
