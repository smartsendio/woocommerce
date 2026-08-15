<?php

/*
 * Characterization tests for SS_Shipping_Method_Resolver (#139), the
 * dedicated home of the shipping method resolution that used to live on
 * SS_Shipping_Order_Meta::get_smart_send_method_id(): the Smart Send
 * shipping item, the free-shipping mapping, and the honest return-side
 * questions (return method, auto-return flag, the v8 pickup-point
 * fallback oddity).
 */

function method_resolver(): SS_Shipping_Method_Resolver
{
    return SS_SHIPPING_WC()->method_resolver();
}

beforeEach(function (): void {
    with_ss_settings();
});

it('resolves the Smart Send shipping method of an order', function () {
    $product = create_simple_product(['price' => 100, 'weight' => 1]);

    $ss_order = create_order([
        'products'        => [$product],
        'shipping_method' => 'postnord_agent',
        'return_method'   => 'postnord_returndropoff',
        'auto_return'     => 'yes',
    ]);
    expect(method_resolver()->resolve_outbound($ss_order->get_id()))->toBe('postnord_agent')
        ->and(method_resolver()->resolve_return($ss_order->get_id()))->toBe('postnord_returndropoff')
        ->and(method_resolver()->is_auto_return_enabled($ss_order->get_id()))->toBeTrue()
        // A dedicated return method never selects a pickup point.
        ->and(method_resolver()->return_uses_stored_pickup_point($ss_order->get_id()))->toBeFalse();

    $plain_order = create_order(['products' => [$product]]);
    expect(method_resolver()->resolve_outbound($plain_order->get_id()))->toBe('')
        ->and(method_resolver()->resolve_return($plain_order->get_id()))->toBe('')
        ->and(method_resolver()->is_auto_return_enabled($plain_order->get_id()))->toBeFalse();

    expect(method_resolver()->resolve_outbound(0))->toBe('');
});

it('throws a SS_Shipping_Booking_Exception when the return method is configured empty', function () {
    $product = create_simple_product(['price' => 100, 'weight' => 1]);
    $order   = create_order([
        'products'        => [$product],
        'shipping_method' => 'postnord_agent',
        'return_method'   => '',
    ]);

    expect(fn () => method_resolver()->resolve_return($order->get_id()))
        ->toThrow(SS_Shipping_Booking_Exception::class, 'No return method set');
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

    expect(method_resolver()->resolve_outbound($order->get_id()))->toBe('gls_agent')
        // v8 oddity: free-shipping orders never resolve a dedicated return
        // method, so a return label falls back to the same concrete method
        // and keeps the stored pickup point selection.
        ->and(method_resolver()->resolve_return($order->get_id()))->toBe('gls_agent')
        ->and(method_resolver()->return_uses_stored_pickup_point($order->get_id()))->toBeTrue()
        ->and(method_resolver()->is_auto_return_enabled($order->get_id()))->toBeFalse();
});
