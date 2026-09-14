<?php

/*
 * The merchant fulfillment journey: generating shipping labels from the
 * order screen meta box (outbound, return, the auto-return method setting
 * and the API-failure path) and through the Orders screen bulk action
 * (single order, and the 9.0 more-than-one-order limit, #173).
 *
 * Split from the historic SmartSendFlowsTest.php (#146); the customer
 * checkout side lives in CheckoutFlowTest.php. Store fixtures and the API
 * mock are managed through the shared helpers in
 * tests/Browser/Support/SmartSendStore.php.
 */

beforeAll(function (): void {
    if (!ss_browser_store_manageable()) {
        return;
    }

    // Five orders: outbound label, return label, auto-return method,
    // booking failure, and the single-order bulk action (the multi-order
    // rejection test reuses the first two without booking anything).
    ss_browser_seed_store(['orders' => [[], [], ['auto_return' => true], [], []]]);
});

afterAll(function (): void {
    if (!ss_browser_store_manageable()) {
        return;
    }

    ss_browser_cleanup_store();
});

beforeEach(function (): void {
    ss_browser_skip_unless_store_manageable($this);
});

it('generates a shipping label from the order meta box', function () {
    $state = ss_browser_state();

    login_as_admin()
        ->navigate(base_url(ss_browser_order_edit_path($state['orders'][0])))
        ->assertSee('Smart Send Shipping')
        ->assertSee('Pickup Point')
        ->assertSee('Browser Test Shop')
        ->click('#ss-shipping-label-button')
        ->assertSeeIn('#ss-label-created', 'Download shipping label');
});

it('generates a return label from the order meta box', function () {
    $state = ss_browser_state();
    $order_id = $state['orders'][1];

    login_as_admin()
        ->navigate(base_url(ss_browser_order_edit_path($order_id)))
        ->assertSee('Smart Send Shipping')
        ->click('#ss-shipping-return-label-button')
        ->assertSeeIn('#ss-label-created', 'Download return label');

    // The return shipment id was stored on the order (and no outbound
    // shipment was booked by the return-only click).
    $meta = ss_browser_wp_eval(<<<PHP
\$order = wc_get_order({$order_id});
echo json_encode(array(
    'return_label_id' => \$order ? \$order->get_meta('_ss_shipping_return_label_id', true) : null,
    'label_id'        => \$order ? \$order->get_meta('_ss_shipping_label_id', true) : null,
));
PHP);

    expect($meta['return_label_id'])->toStartWith('browser-shipment-')
        ->and($meta['label_id'])->toBe('');
});

it('auto-generates a return label when the method setting is on', function () {
    $state = ss_browser_state();
    $order_id = $state['orders'][2];

    // The order's shipping method carries
    // smart_send_auto_generate_return_label = yes: one outbound click must
    // report both labels.
    login_as_admin()
        ->navigate(base_url(ss_browser_order_edit_path($order_id)))
        ->assertSee('Smart Send Shipping')
        ->click('#ss-shipping-label-button')
        ->assertSee('Download shipping label')
        ->assertSee('Download return label');

    $meta = ss_browser_wp_eval(<<<PHP
\$order = wc_get_order({$order_id});
echo json_encode(array(
    'label_id'        => \$order ? \$order->get_meta('_ss_shipping_label_id', true) : null,
    'return_label_id' => \$order ? \$order->get_meta('_ss_shipping_return_label_id', true) : null,
));
PHP);

    expect($meta['label_id'])->toStartWith('browser-shipment-')
        ->and($meta['return_label_id'])->toStartWith('browser-shipment-');
});

it('shows the API error message when booking fails', function () {
    $state = ss_browser_state();
    $order_id = $state['orders'][3];

    ss_browser_set_api_scenarios(array('booking' => '422-wrong-zip'));

    try {
        // The mocked 422 validation error must surface in the meta box.
        login_as_admin()
            ->navigate(base_url(ss_browser_order_edit_path($order_id)))
            ->assertSee('Smart Send Shipping')
            ->click('#ss-shipping-label-button')
            ->assertSeeIn('#ss-shipping-error', 'The given data was invalid')
            ->assertSeeIn('#ss-shipping-error', 'The receiver zip code does not match the receiver country');
    } finally {
        ss_browser_set_api_scenarios(null);
    }

    // Nothing was written on the failed booking.
    $meta = ss_browser_wp_eval(<<<PHP
\$order = wc_get_order({$order_id});
echo json_encode(array('label_id' => \$order ? \$order->get_meta('_ss_shipping_label_id', true) : null));
PHP);

    expect($meta['label_id'])->toBe('');
});

it('rejects the bulk label action for more than one order', function () {
    // Bulk printing of multiple orders is not available in 9.0 (#173) -
    // selecting more than one order must explain that, link to the last
    // 8.x release and book nothing.
    $state = ss_browser_state();
    $before = ss_browser_label_ids([$state['orders'][0], $state['orders'][1]]);

    $page = login_as_admin()->navigate(base_url($state['orders_list_path']));

    $checkbox_name = $state['hpos'] ? 'id[]' : 'post[]';
    $page->check($checkbox_name, (string) $state['orders'][0])
        ->check($checkbox_name, (string) $state['orders'][1])
        ->select('action', 'ss_shipping_label_bulk')
        // Explicit selector: the Apply button is an input[type=submit], which
        // text-based lookups cannot find.
        ->click('#doaction');

    $page->assertSee('Bulk printing of multiple orders is not available in version 9.0.0')
        ->assertSeeLink('downgrade to version 8.1.3');

    // Nothing was booked: the selected orders' shipment ids are unchanged
    // (the meta box tests above may already have labelled them).
    expect(ss_browser_label_ids([$state['orders'][0], $state['orders'][1]]))->toBe($before);
});

/**
 * Outbound and return shipment ids stored on the given orders.
 */
function ss_browser_label_ids(array $order_ids): array
{
    $ids = implode(',', array_map('intval', $order_ids));

    // ss_browser_wp_eval() only picks up a JSON object line.
    return ss_browser_wp_eval(<<<PHP
\$label_ids = array();
foreach (array({$ids}) as \$id) {
    \$order = wc_get_order(\$id);
    \$label_ids[] = array(
        'label'  => \$order ? \$order->get_meta('_ss_shipping_label_id', true) : null,
        'return' => \$order ? \$order->get_meta('_ss_shipping_return_label_id', true) : null,
    );
}
echo json_encode(array('orders' => \$label_ids));
PHP)['orders'];
}

it('generates a label for a single order through the bulk action', function () {
    $state = ss_browser_state();

    $page = login_as_admin()->navigate(base_url($state['orders_list_path']));

    $checkbox_name = $state['hpos'] ? 'id[]' : 'post[]';
    $page->check($checkbox_name, (string) $state['orders'][4])
        ->select('action', 'ss_shipping_label_bulk')
        // Explicit selector: the Apply button is an input[type=submit], which
        // text-based lookups cannot find.
        ->click('#doaction');

    $page->assertSee('Shipping label created by Smart Send');
});
