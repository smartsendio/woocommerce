<?php

/*
 * Characterization tests for label generation in SS_Shipping_WC_Order with
 * the Smart Send API mocked through pre_http_request: the success path
 * (order meta, order note, status change), the API error path, auto return
 * labels, and the bulk order actions with the admin notices they produce.
 */

/**
 * Invoke the protected create_label_for_single_order_maybe_return().
 * The only public entry points (the AJAX handler and the bulk handler) wrap
 * this method; the AJAX handler cannot run in-process because it ends with
 * wp_send_json() + wp_die().
 */
function create_labels_for(int $order_id, bool $return = false, bool $save_order_note = true): array
{
    $method = new ReflectionMethod(SS_Shipping_WC_Order::class, 'create_label_for_single_order_maybe_return');

    return $method->invoke(SS_SHIPPING_WC()->get_ss_shipping_wc_order(), $order_id, $return, $save_order_note);
}

/**
 * Clear pending admin notices for the current user and leave a clean state
 * (no pending transient, no marker query parameter) after the test.
 */
function with_empty_flash_messages(): SS_Shipping_Admin_Notices
{
    $notices = SS_SHIPPING_WC()->get_ss_shipping_wc_order()->get_admin_notices();

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
    $listener = function ($order_id, $response) use (&$fired): void {
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
        return ss_api_response(422, ss_api_error_body('The given data was invalid.'));
    });

    $response = create_labels_for($order->get_id());

    expect($response)->toHaveCount(1)
        ->and($response[0])->toHaveKey('error')
        ->and($response[0]['error'])->toContain('The given data was invalid.')
        ->toContain('Read more here')
        ->toContain('Unique ID: test-error-id')
        ->toContain('The postal code is invalid.');

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

it('generates labels for two orders via the bulk action and flashes a combined-pdf notice', function () {
    $notices = with_empty_flash_messages();

    $order_a = create_labelable_order();
    $order_b = create_labelable_order();

    mock_smart_send_api(function ($url) {
        if (strpos($url, 'shipments/labels/combine') !== false) {
            return ss_api_response(200, ['data' => [
                'pdf' => ['link' => 'https://api.example.test/labels/combined.pdf', 'base_64_encoded' => base64_encode('%PDF-combo')],
            ]]);
        }

        return ss_api_response(200, ['data' => ss_api_shipment_data()]);
    });

    $handler  = SS_SHIPPING_WC()->get_ss_shipping_wc_order();
    $sendback = $handler->handle_bulk_order_actions('/wp-admin/edit.php', 'ss_shipping_label_bulk', [
        $order_a->get_id(),
        $order_b->get_id(),
    ]);

    // The redirect URL is marked so the next request looks up the notices.
    expect($sendback)->toBe('/wp-admin/edit.php?ss_shipping_notices=1');

    // The notices are stored in a per-user transient until rendered.
    $messages = $notices->get_pending();
    expect($messages)->toHaveCount(1)
        ->and($messages[0]['type'])->toBe('success')
        ->and($messages[0]['message'])->toContain('Shipping labels created by Smart Send for 2 orders')
        ->toContain('https://api.example.test/labels/combined.pdf')
        ->toContain('Download combined pdf');

    // maybe_render() prints the notices and clears the transient when the
    // marker query parameter is present.
    $_GET[SS_Shipping_Admin_Notices::QUERY_ARG] = '1';
    ob_start();
    $notices->maybe_render();
    $output = ob_get_clean();

    expect($output)->toContain('notice-success')
        ->toContain('Download combined pdf');
    expect($notices->get_pending())->toBe([])
        ->and(get_transient(SS_Shipping_Admin_Notices::TRANSIENT_PREFIX . get_current_user_id()))->toBeFalse();
});

it('flashes an error for bulk label generation on an order without a Smart Send method', function () {
    $notices = with_empty_flash_messages();

    $product = create_simple_product(['price' => 100, 'weight' => 1]);
    $order   = create_order(['products' => [$product]]);

    mock_smart_send_api();

    SS_SHIPPING_WC()->get_ss_shipping_wc_order()
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

    SS_SHIPPING_WC()->get_ss_shipping_wc_order()
        ->handle_bulk_order_actions('/wp-admin/edit.php', 'ss_shipping_label_bulk', [$order->get_id()]);

    $messages = $notices->get_pending();
    expect($messages)->toHaveCount(1)
        ->and($messages[0]['type'])->toBe('error')
        ->and($messages[0]['message'])->toContain('Order #' . $order->get_order_number())
        ->toContain('The given data was invalid.');
});

it('rejects bulk label generation for more than five orders', function () {
    $notices = with_empty_flash_messages();

    $capture = mock_smart_send_api();

    SS_SHIPPING_WC()->get_ss_shipping_wc_order()
        ->handle_bulk_order_actions('/wp-admin/edit.php', 'ss_shipping_label_bulk', [1, 2, 3, 4, 5, 6]);

    $messages = $notices->get_pending();
    expect($messages)->toHaveCount(1)
        ->and($messages[0]['type'])->toBe('error')
        ->and($messages[0]['message'])->toContain('not possible to create labels for more than 5 orders')
        ->and($capture->requests)->toBe([]);
});

it('ignores bulk actions that are not Smart Send actions', function () {
    $notices = with_empty_flash_messages();

    $result = SS_SHIPPING_WC()->get_ss_shipping_wc_order()
        ->handle_bulk_order_actions('/wp-admin/edit.php', 'mark_processing', [1]);

    // v8 oddity: for foreign actions the handler returns null instead of
    // passing $sendback through.
    expect($result)->toBeNull()
        ->and($notices->get_pending())->toBe([]);
});
