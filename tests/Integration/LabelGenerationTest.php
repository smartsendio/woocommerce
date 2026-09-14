<?php

/*
 * Characterization tests for label generation via SS_Shipping_Fulfillment_Service with
 * the Smart Send API mocked through pre_http_request: the success path
 * (order meta, order note, status change), the API error path, auto return
 * labels, and the bulk order actions with the admin notices they produce.
 */

/**
 * Fulfill an order and return the legacy AJAX response shape. The only
 * public entry points (the AJAX handler and the bulk handler) wrap the
 * fulfillment service the same way; the AJAX handler cannot run in-process
 * because it ends with wp_send_json() + wp_die().
 */
function create_labels_for(int $order_id, bool $return = false, bool $save_order_note = true): array
{
    $fulfillment = SS_SHIPPING_WC()->fulfillment();

    $result = $return
        ? $fulfillment->fulfill_return($order_id, $save_order_note)
        : $fulfillment->fulfill_outbound($order_id, $save_order_note);

    return $result->to_legacy_response_array();
}

/**
 * Clear pending admin notices for the current user and leave a clean state
 * (no pending transient, no marker query parameter) after the test.
 */
function with_empty_flash_messages(): SS_Shipping_Admin_Notices
{
    $notices = SS_SHIPPING_WC()->admin_notices();

    $notices->clear();

    remember_cleanup_callback(function () use ($notices): void {
        $notices->clear();
        unset($_GET[SS_Shipping_Admin_Notices::QUERY_ARG]);
    });

    return $notices;
}

/**
 * An order that can have a label generated for it.
 */
function create_labelable_order(array $args = []): WC_Order
{
    $product = create_simple_product(['price' => 100, 'weight' => 1]);

    return create_order(array_merge([
        'products'        => [$product],
        'shipping_method' => 'postnord_agent',
        'shipping_total'  => '39',
    ], $args));
}

beforeEach(function (): void {
    with_ss_settings();
});

it('registers the AJAX label generation handler', function () {
    expect(has_action('wp_ajax_ss_shipping_generate_label'))->not->toBeFalse();
});

it('creates a label, saves the shipment id and adds an order note on success', function () {
    $order   = create_labelable_order();
    $capture = mock_smart_send_api(function () {
        return ss_api_response(200, ['data' => ss_api_shipment_data(['shipment_id' => 'shipment-abc'])]);
    });

    $fired = [];
    $listener = function ($order_id, SS_Shipping_Label_Entry $entry) use (&$fired): void {
        $fired[] = $order_id;
    };
    add_action('smart_send_shipping_label_created', $listener, 10, 2);
    remember_cleanup_callback(function () use ($listener): void {
        remove_action('smart_send_shipping_label_created', $listener, 10);
    });

    $response = create_labels_for($order->get_id());

    expect($response)->toHaveCount(1)
        ->and($response[0])->toHaveKey('success')
        ->and($response[0]['success']->shipment_id)->toBe('shipment-abc')
        ->and($response[0]['success']->woocommerce['label_url'])->toBe('https://api.example.test/labels/label.pdf')
        ->and($response[0]['success']->woocommerce['return'])->toBeFalse()
        ->and($capture->requests)->toHaveCount(1)
        ->and($fired)->toBe([$order->get_id()]);

    $fresh = wc_get_order($order->get_id());
    expect($fresh->get_meta('_ss_shipping_label_id', true))->toBe('shipment-abc')
        ->and($fresh->get_status())->toBe('processing');

    $notes = wc_get_order_notes(['order_id' => $order->get_id()]);
    $note_contents = implode("\n", wp_list_pluck($notes, 'content'));
    expect($note_contents)->toContain('Shipping label')
        ->toContain('https://api.example.test/labels/label.pdf')
        ->toContain('TRACK-1234')
        ->toContain('https://tracking.example.test/TRACK-1234');
});

it('persists submitted delivery overrides through the repository before booking', function () {
    // The AJAX controller translates the posted parcel rows into a typed
    // SS_Shipping_Parcel_Plan and hands it into the flow as partial
    // delivery details (#139); the fulfillment service persists them in
    // the frozen meta format before booking (save-before-book preserved).
    $product_a = create_simple_product(['name' => 'Override Box One', 'price' => 100, 'weight' => 1]);
    $product_b = create_simple_product(['name' => 'Override Box Two', 'price' => 50, 'weight' => 2]);
    $order     = create_order([
        'products'        => [$product_a, $product_b],
        'shipping_method' => 'postnord_agent',
        'shipping_total'  => '39',
    ]);

    $rows = [
        ['id' => $product_a->get_id(), 'name' => 'Override Box One', 'value' => '1'],
        ['id' => $product_b->get_id(), 'name' => 'Override Box Two', 'value' => '2'],
    ];
    $overrides = new SS_Shipping_Delivery_Details();
    $overrides->set_parcel_plan(SS_Shipping_Parcel_Plan::from_box_rows($rows));

    $capture = mock_smart_send_api();

    $result = SS_SHIPPING_WC()->fulfillment()->fulfill_outbound($order->get_id(), false, $overrides);

    expect($result->is_successful())->toBeTrue();

    // The booking used the submitted plan...
    $payload = json_decode(end($capture->requests)['body'], true);
    expect($payload['parcels'])->toHaveCount(2)
        ->and($payload['parcels'][0]['items'][0]['name'])->toBe('Override Box One')
        ->and($payload['parcels'][1]['items'][0]['name'])->toBe('Override Box Two');

    // ...and the split was persisted in the frozen meta row shape.
    expect(wc_get_order($order->get_id())->get_meta('ss_shipping_order_parcels', true))->toEqual($rows);
});

it('updates the order status after label generation when configured', function () {
    with_ss_settings(['order_status' => 'wc-completed']);

    $order = create_labelable_order();
    mock_smart_send_api();

    $response = create_labels_for($order->get_id());

    expect($response[0])->toHaveKey('success')
        ->and(wc_get_order($order->get_id())->get_status())->toBe('completed');
});

it('returns the formatted API error message when the API rejects the shipment', function () {
    $order = create_labelable_order();
    mock_smart_send_api(function () {
        return ss_api_response(422, ss_api_error_body('The given data was invalid.'), 'test-response-id');
    });

    $response = create_labels_for($order->get_id());

    expect($response)->toHaveCount(1)
        ->and($response[0])->toHaveKey('error')
        ->and($response[0]['error'])->toContain('The given data was invalid.')
        ->toContain('The postal code is invalid.')
        ->toContain('Response ID: test-response-id');

    expect(wc_get_order($order->get_id())->get_meta('_ss_shipping_label_id', true))->toBe('');
});

it('auto-generates the return label after a successful normal label', function () {
    $order   = create_labelable_order(['auto_return' => 'yes']);
    $capture = mock_smart_send_api();

    $response = create_labels_for($order->get_id());

    expect($response)->toHaveCount(2)
        ->and($response[0])->toHaveKey('success')
        ->and($response[0]['success']->woocommerce['return'])->toBeFalse()
        ->and($response[1])->toHaveKey('success')
        ->and($response[1]['success']->woocommerce['return'])->toBeTrue()
        ->and($capture->requests)->toHaveCount(2);

    // The second request books the configured return method.
    $return_payload = json_decode($capture->requests[1]['body'], true);
    expect($return_payload['shipping_carrier'])->toBe('postnord')
        ->and($return_payload['shipping_method'])->toBe('returndropoff')
        // Return shipments never include a pickup point agent.
        ->and($return_payload['agent'])->toBeNull();

    $fresh = wc_get_order($order->get_id());
    expect($fresh->get_meta('_ss_shipping_label_id', true))->not->toBe('')
        ->and($fresh->get_meta('_ss_shipping_return_label_id', true))->not->toBe('');
});

it('creates only a return label when explicitly requested', function () {
    $order = create_labelable_order();
    mock_smart_send_api();

    $response = create_labels_for($order->get_id(), true);

    expect($response)->toHaveCount(1)
        ->and($response[0])->toHaveKey('success')
        ->and($response[0]['success']->woocommerce['return'])->toBeTrue();

    $fresh = wc_get_order($order->get_id());
    expect($fresh->get_meta('_ss_shipping_return_label_id', true))->not->toBe('')
        ->and($fresh->get_meta('_ss_shipping_label_id', true))->toBe('');

    $notes = wc_get_order_notes(['order_id' => $order->get_id()]);
    expect(implode("\n", wp_list_pluck($notes, 'content')))->toContain('Return shipping label');
});

it('generates a label for a single order via the bulk action and flashes a success notice', function () {
    $notices = with_empty_flash_messages();

    $order = create_labelable_order();
    mock_smart_send_api();

    $handler  = SS_SHIPPING_WC()->bulk_actions();
    $sendback = $handler->handle_bulk_order_actions('/wp-admin/edit.php', 'ss_shipping_label_bulk', [
        $order->get_id(),
    ]);

    // The redirect URL is marked so the next request looks up the notices.
    expect($sendback)->toBe('/wp-admin/edit.php?ss_shipping_notices=1');

    // The notices are stored in a per-user transient until rendered.
    $messages = $notices->get_pending();
    expect($messages)->toHaveCount(1)
        ->and($messages[0]['type'])->toBe('success')
        ->and($messages[0]['message'])->toContain('Order #' . $order->get_order_number())
        ->toContain('Shipping label created by Smart Send')
        ->toContain('https://api.example.test/labels/label.pdf');

    expect(wc_get_order($order->get_id())->get_meta('_ss_shipping_label_id', true))->not->toBe('');

    // maybe_render() prints the notices and clears the transient when the
    // marker query parameter is present.
    $_GET[SS_Shipping_Admin_Notices::QUERY_ARG] = '1';
    ob_start();
    $notices->maybe_render();
    $output = ob_get_clean();

    expect($output)->toContain('notice-success')
        ->toContain('Shipping label created by Smart Send');
    expect($notices->get_pending())->toBe([])
        ->and(get_transient(SS_Shipping_Admin_Notices::TRANSIENT_PREFIX . get_current_user_id()))->toBeFalse();
});

it('flashes an error for bulk label generation on an order without a Smart Send method', function () {
    $notices = with_empty_flash_messages();

    $product = create_simple_product(['price' => 100, 'weight' => 1]);
    $order   = create_order(['products' => [$product]]);

    mock_smart_send_api();

    SS_SHIPPING_WC()->bulk_actions()
        ->handle_bulk_order_actions('/wp-admin/edit.php', 'ss_shipping_label_bulk', [$order->get_id()]);

    $messages = $notices->get_pending();
    expect($messages)->toHaveCount(1)
        ->and($messages[0]['type'])->toBe('error')
        // (sic) "Send Smart" typo is current v8 behaviour.
        ->and($messages[0]['message'])->toContain('The selected order did not include a Send Smart shipping method');
});

it('flashes the API error per order when bulk label generation fails', function () {
    $notices = with_empty_flash_messages();

    $order = create_labelable_order();
    mock_smart_send_api(function () {
        return ss_api_response(422, ss_api_error_body('The given data was invalid.'));
    });

    SS_SHIPPING_WC()->bulk_actions()
        ->handle_bulk_order_actions('/wp-admin/edit.php', 'ss_shipping_label_bulk', [$order->get_id()]);

    $messages = $notices->get_pending();
    expect($messages)->toHaveCount(1)
        ->and($messages[0]['type'])->toBe('error')
        ->and($messages[0]['message'])->toContain('Order #' . $order->get_order_number())
        ->toContain('The given data was invalid.');
});

/*
 * The 9.0 single-order limit (#173), exercised through the Orders screen
 * hook WordPress fires - legacy (edit-shop_order) and HPOS
 * (woocommerce_page_wc-orders) - for both Smart Send bulk actions.
 */

dataset('bulk_orders_screens', [
    'legacy' => 'edit-shop_order',
    'HPOS'   => 'woocommerce_page_wc-orders',
]);

dataset('bulk_label_actions', [
    'outbound' => ['ss_shipping_label_bulk', 'Shipping label created by Smart Send', '_ss_shipping_label_id'],
    'return'   => ['ss_shipping_return_bulk', 'Return label created by Smart Send', '_ss_shipping_return_label_id'],
]);

/**
 * Fire an Orders screen's handle_bulk_actions filter the way WordPress does
 * after "Apply". The component only registers the screen matching the store's
 * HPOS setting, so the other screen's hook is wired here for the duration of
 * the test - with the same callback and arguments register_hooks() uses
 * (pinned per HPOS branch by BulkActionsHookRegistrationTest.php). The HPOS
 * option itself is not toggled: WooCommerce refuses to switch order storage
 * while test orders are out of sync.
 */
function run_bulk_action_on_screen(string $screen_id, string $action, array $order_ids): string
{
    $hook     = 'handle_bulk_actions-' . $screen_id;
    $callback = [SS_SHIPPING_WC()->bulk_actions(), 'handle_bulk_order_actions'];

    if (false === has_filter($hook, $callback)) {
        add_filter($hook, $callback, 10, 3);
        remember_cleanup_callback(function () use ($hook, $callback): void {
            remove_filter($hook, $callback, 10);
        });
    }

    return apply_filters($hook, '/wp-admin/edit.php', $action, $order_ids);
}

it('books a single selected order through the bulk action on the Orders screen', function (string $screen_id, string $action, string $message, string $meta_key) {
    $notices = with_empty_flash_messages();

    $order   = create_labelable_order();
    $capture = mock_smart_send_api();

    $sendback = run_bulk_action_on_screen($screen_id, $action, [$order->get_id()]);

    expect($sendback)->toBe('/wp-admin/edit.php?ss_shipping_notices=1')
        ->and($capture->requests)->not->toBe([]);

    $messages = $notices->get_pending();
    expect($messages)->toHaveCount(1)
        ->and($messages[0]['type'])->toBe('success')
        ->and($messages[0]['message'])->toContain('Order #' . $order->get_order_number())
        ->toContain($message);

    expect(wc_get_order($order->get_id())->get_meta($meta_key, true))->not->toBe('');
})->with('bulk_orders_screens')->with('bulk_label_actions');

it('books nothing and explains the single-order limit when more than one order is selected', function (string $screen_id, string $action) {
    $notices = with_empty_flash_messages();

    $order_a = create_labelable_order();
    $order_b = create_labelable_order();
    $capture = mock_smart_send_api();

    $sendback = run_bulk_action_on_screen($screen_id, $action, [$order_a->get_id(), $order_b->get_id()]);

    expect($sendback)->toBe('/wp-admin/edit.php?ss_shipping_notices=1');

    $messages = $notices->get_pending();
    expect($messages)->toHaveCount(1)
        ->and($messages[0]['type'])->toBe('error')
        ->and($messages[0]['message'])->toBe(
            'Bulk printing of multiple orders is not available in version 9.0.0. We are building a much better version, and it is coming soon. Please select a single order, or create the label from the order page. If you need the old bulk printing, you can <a href="https://wordpress.org/plugins/smart-send-logistics/advanced/" target="_blank">downgrade to version 8.x</a>.'
        )
        ->and($capture->requests)->toBe([]);

    foreach ([$order_a, $order_b] as $order) {
        $fresh = wc_get_order($order->get_id());
        expect($fresh->get_meta('_ss_shipping_label_id', true))->toBe('')
            ->and($fresh->get_meta('_ss_shipping_return_label_id', true))->toBe('');
    }

    // The link survives the notice renderer's wp_kses_post().
    $_GET[SS_Shipping_Admin_Notices::QUERY_ARG] = '1';
    ob_start();
    $notices->maybe_render();
    expect(ob_get_clean())->toContain('notice-error')
        ->toContain('<a href="https://wordpress.org/plugins/smart-send-logistics/advanced/" target="_blank">downgrade to version 8.x</a>');
})->with('bulk_orders_screens')->with(['ss_shipping_label_bulk', 'ss_shipping_return_bulk']);

it('ignores bulk actions that are not Smart Send actions', function () {
    $notices = with_empty_flash_messages();

    $result = SS_SHIPPING_WC()->bulk_actions()
        ->handle_bulk_order_actions('/wp-admin/edit.php', 'mark_processing', [1]);

    // v8 oddity: for foreign actions the handler returns null instead of
    // passing $sendback through.
    expect($result)->toBeNull()
        ->and($notices->get_pending())->toBe([]);
});
