<?php

/*
 * 9.0.0 removes the Smart Send "Generate Labels" / "Generate Return Labels"
 * bulk actions from the Orders screen (#173): neither the legacy nor the
 * HPOS list offers them, and a request still carrying one of the old action
 * names is left to WordPress untouched. Merchants are told through the
 * Orders screen notice covered in AdminNoticesTest.php.
 */

dataset('orders_screens', [
    'legacy' => 'edit-shop_order',
    'HPOS'   => 'woocommerce_page_wc-orders',
]);

it('no longer ships the bulk actions component', function () {
    expect(class_exists('SS_Shipping_Order_Bulk_Actions'))->toBeFalse()
        ->and(method_exists(SS_SHIPPING_WC(), 'bulk_actions'))->toBeFalse();
});

it('offers no Smart Send bulk action on the Orders screen', function (string $screen_id) {
    $actions = apply_filters('bulk_actions-' . $screen_id, ['mark_processing' => 'Change status to processing']);

    $smart_send_actions = array_filter(
        array_keys($actions),
        fn (string $action): bool => str_starts_with($action, 'ss_shipping')
    );

    expect($smart_send_actions)->toBe([]);
})->with('orders_screens');

it('leaves a request for a removed Smart Send bulk action untouched and books nothing', function (string $screen_id, string $action) {
    $order   = create_labelable_order();
    $capture = mock_smart_send_api();

    $sendback = apply_filters('handle_bulk_actions-' . $screen_id, '/wp-admin/edit.php?post_type=shop_order', $action, [$order->get_id()]);

    expect($sendback)->toBe('/wp-admin/edit.php?post_type=shop_order')
        ->and($capture->requests)->toBe([])
        ->and(wc_get_order($order->get_id())->get_meta('_ss_shipping_label_id', true))->toBe('')
        ->and(wc_get_order($order->get_id())->get_meta('_ss_shipping_return_label_id', true))->toBe('');
})->with('orders_screens')->with(['ss_shipping_label_bulk', 'ss_shipping_return_bulk']);
