<?php

/*
 * Focused tests for the fulfillment layer: SS_Shipping_Fulfillment_Service
 * runs the right post-booking steps on success (order note, order meta,
 * smart_send_shipping_label_created, status change) and writes nothing on
 * a failed booking; the stateless SS_Shipping_Booking_Service handles
 * different orders sequentially without state bleed.
 */

function fulfillment_service(): SS_Shipping_Fulfillment_Service
{
    return SS_SHIPPING_WC()->fulfillment();
}

/**
 * An order that can have a label generated for it.
 */
function create_fulfillable_order(array $args = []): WC_Order
{
    $product = create_simple_product(['price' => 100, 'weight' => 1]);

    return create_order(array_merge([
        'products'        => [$product],
        'shipping_method' => 'postnord_agent',
        'shipping_total'  => '39',
    ], $args));
}

/**
 * Record every smart_send_shipping_label_created firing during the test.
 */
function capture_label_created_action(): object
{
    $fired = new class {
        public array $order_ids = [];
    };

    $listener = function ($order_id, $response) use ($fired): void {
        $fired->order_ids[] = $order_id;
    };
    add_action('smart_send_shipping_label_created', $listener, 10, 2);
    remember_cleanup_callback(function () use ($listener): void {
        remove_action('smart_send_shipping_label_created', $listener, 10);
    });

    return $fired;
}

beforeEach(function (): void {
    with_ss_settings();
});

it('runs the full workflow on a successful outbound fulfillment', function () {
    with_ss_settings(['order_status' => 'wc-completed']);

    $order = create_fulfillable_order();
    mock_smart_send_api(function () {
        return ss_api_response(200, ['data' => ss_api_shipment_data(['shipment_id' => 'shipment-fulfill'])]);
    });
    $fired = capture_label_created_action();

    $result = fulfillment_service()->fulfill_outbound($order->get_id());

    expect($result)->toBeInstanceOf(SS_Shipping_Fulfillment_Result::class)
        ->and($result->is_successful())->toBeTrue()
        ->and($result->get_first_error_message())->toBeNull()
        ->and($result->get_outbound_booking()->is_successful())->toBeTrue()
        ->and($result->get_return_booking())->toBeNull()
        ->and($result->to_legacy_response_array())->toHaveCount(1)
        ->and($result->to_legacy_response_array()[0])->toHaveKey('success')
        ->and($fired->order_ids)->toBe([$order->get_id()]);

    $fresh = wc_get_order($order->get_id());
    expect($fresh->get_meta('_ss_shipping_label_id', true))->toBe('shipment-fulfill')
        ->and($fresh->get_status())->toBe('completed');

    $notes = wc_get_order_notes(['order_id' => $order->get_id()]);
    expect(implode("\n", wp_list_pluck($notes, 'content')))->toContain('Shipping label')
        ->toContain('TRACK-1234');
});

it('passes listeners a pristine response and a typed entry (v9 fix: clean action payload)', function () {
    // Deliberate v9 breaking change (#139): the raw API response handed to
    // smart_send_shipping_label_created is no longer mutated with the
    // ->woocommerce presentation array; that data travels in the typed
    // SS_Shipping_Label_Entry third argument instead.
    $order = create_fulfillable_order();
    mock_smart_send_api(function () {
        return ss_api_response(200, ['data' => ss_api_shipment_data(['shipment_id' => 'shipment-clean'])]);
    });

    $captured = new class {
        public array $args = [];
    };
    $listener = function ($order_id, $response, $entry) use ($captured): void {
        $captured->args = [$order_id, $response, $entry];
    };
    add_action('smart_send_shipping_label_created', $listener, 10, 3);
    remember_cleanup_callback(function () use ($listener): void {
        remove_action('smart_send_shipping_label_created', $listener, 10);
    });

    $result = fulfillment_service()->fulfill_outbound($order->get_id());

    expect($result->is_successful())->toBeTrue();

    [$order_id, $response, $entry] = $captured->args;
    expect($order_id)->toBe($order->get_id())
        ->and(property_exists($response, 'woocommerce'))->toBeFalse()
        ->and($response->shipment_id)->toBe('shipment-clean')
        ->and($entry)->toBeInstanceOf(SS_Shipping_Label_Entry::class)
        ->and($entry->get_order_id())->toBe($order->get_id())
        ->and($entry->is_return())->toBeFalse()
        ->and($entry->get_label_url())->toBe('https://api.example.test/labels/label.pdf')
        ->and($entry->get_order_note())->toContain('TRACK-1234')
        ->and($entry->get_response())->toBe($response);

    // The legacy AJAX entry keeps the frozen woocommerce.* shape.
    $legacy = $result->to_legacy_response_array()[0]['success'];
    expect($legacy->woocommerce['label_url'])->toBe('https://api.example.test/labels/label.pdf')
        ->and($legacy->woocommerce['return'])->toBeFalse();
});

it('links the uploads copy of the label when saving labels in uploads is enabled (v9 fix)', function () {
    // Deliberate v9 fix (#139): with save_shipping_labels_in_uploads
    // enabled, the computed uploads URL used to be discarded and the API
    // link used everywhere; the note/response now link the local copy.
    with_ss_settings(['save_shipping_labels_in_uploads' => 'yes']);

    $order = create_fulfillable_order();
    mock_smart_send_api(function () {
        return ss_api_response(200, ['data' => ss_api_shipment_data(['shipment_id' => 'shipment-uploads'])]);
    });

    remember_cleanup_callback(function (): void {
        $upload_dir = wp_upload_dir();
        $file       = $upload_dir['path'] . '/smart-send-label-shipment-uploads.pdf';
        if (file_exists($file)) {
            unlink($file);
        }
    });

    $result = fulfillment_service()->fulfill_outbound($order->get_id());

    expect($result->is_successful())->toBeTrue();

    $legacy = $result->to_legacy_response_array()[0]['success'];
    expect($legacy->woocommerce['label_url'])->toContain('smart-send-label-shipment-uploads.pdf')
        ->and($legacy->woocommerce['label_url'])->not->toBe('https://api.example.test/labels/label.pdf');

    $notes = wc_get_order_notes(['order_id' => $order->get_id()]);
    expect(implode("\n", wp_list_pluck($notes, 'content')))->toContain('smart-send-label-shipment-uploads.pdf');
});

it('also fulfills the return label when auto-generate-return-label is enabled', function () {
    $order = create_fulfillable_order(['auto_return' => 'yes']);
    $capture = mock_smart_send_api();

    $result = fulfillment_service()->fulfill_outbound($order->get_id());

    expect($result->is_successful())->toBeTrue()
        ->and($result->get_outbound_booking()->is_successful())->toBeTrue()
        ->and($result->get_return_booking())->not->toBeNull()
        ->and($result->get_return_booking()->is_successful())->toBeTrue()
        ->and($result->to_legacy_response_array())->toHaveCount(2)
        ->and($capture->requests)->toHaveCount(2);
});

it('still attempts the auto-return label when the outbound PDF save fails after a successful booking', function () {
    // Deliberate behaviour change (PR #135): the historic flow gated the
    // auto-return label on the whole outbound entry, so a successful API
    // booking whose local PDF save failed silently skipped the configured
    // auto-return label. The gate is now the outbound BOOKING outcome; the
    // PDF-save failure and the return-label success are reported side by side.
    with_ss_settings(['save_shipping_labels_in_uploads' => 'yes']);

    $order = create_fulfillable_order(['auto_return' => 'yes']);

    $requests = 0;
    mock_smart_send_api(function () use (&$requests) {
        $requests++;

        // First (outbound) booking succeeds at the API but delivers no label
        // data, which makes the local PDF save throw "Label data empty".
        if ($requests === 1) {
            return ss_api_response(200, ['data' => ss_api_shipment_data(['pdf' => ['link' => '', 'base_64_encoded' => '']])]);
        }

        return ss_api_response(200, ['data' => ss_api_shipment_data()]);
    });

    $result = fulfillment_service()->fulfill_outbound($order->get_id());

    expect($result->get_outbound_booking()->is_successful())->toBeTrue()
        ->and($result->get_return_booking())->not->toBeNull()
        ->and($result->get_return_booking()->is_successful())->toBeTrue()
        ->and($result->is_successful())->toBeFalse()
        ->and($result->get_first_error_message())->toContain('Label data empty')
        ->and($result->to_legacy_response_array())->toHaveCount(2)
        ->and($result->to_legacy_response_array()[0])->toHaveKey('error')
        ->and($result->to_legacy_response_array()[1])->toHaveKey('success');

    $fresh = wc_get_order($order->get_id());
    expect($fresh->get_meta('_ss_shipping_label_id', true))->toBe('')
        ->and($fresh->get_meta('_ss_shipping_return_label_id', true))->not->toBe('');
});

it('writes nothing when the booking fails', function () {
    with_ss_settings(['order_status' => 'wc-completed']);

    $order = create_fulfillable_order();
    mock_smart_send_api(function () {
        return ss_api_response(422, ss_api_error_body('The given data was invalid.'));
    });
    $fired = capture_label_created_action();

    // Passing the WC_Order object (instead of the id) is supported too.
    $result = fulfillment_service()->fulfill_outbound($order);

    expect($result->is_successful())->toBeFalse()
        ->and($result->get_first_error_message())->toContain('The given data was invalid.')
        ->and($result->get_outbound_booking()->is_successful())->toBeFalse()
        ->and($result->get_return_booking())->toBeNull()
        ->and($fired->order_ids)->toBe([]);

    $fresh = wc_get_order($order->get_id());
    expect($fresh->get_meta('_ss_shipping_label_id', true))->toBe('')
        ->and($fresh->get_status())->toBe('processing');

    $notes = wc_get_order_notes(['order_id' => $order->get_id()]);
    expect(implode("\n", wp_list_pluck($notes, 'content')))->not->toContain('Shipping label');
});

it('returns a failed result when the order cannot be found', function () {
    mock_smart_send_api();

    $result = fulfillment_service()->fulfill_outbound(999999999);

    expect($result->is_successful())->toBeFalse()
        ->and($result->get_first_error_message())->toContain('The order could not be found')
        ->and($result->get_outbound_booking())->toBeNull()
        ->and($result->get_return_booking())->toBeNull()
        ->and($result->to_legacy_response_array())->toHaveCount(1)
        ->and($result->to_legacy_response_array()[0])->toHaveKey('error');
});

it('books two different orders through one stateless booking service without state bleed', function () {
    $order_a = create_fulfillable_order();
    $order_b = create_fulfillable_order();

    $capture = mock_smart_send_api();

    $booking_service = new SS_Shipping_Booking_Service(new SS_Shipping_Order_Meta(), new SS_Shipping_Method_Resolver());

    $booking_a = $booking_service->book_outbound($order_a);
    $booking_b = $booking_service->book_outbound($order_b);

    expect($booking_a->is_successful())->toBeTrue()
        ->and($booking_b->is_successful())->toBeTrue()
        ->and($capture->requests)->toHaveCount(2);

    $payload_a = json_decode($capture->requests[0]['body'], true);
    $payload_b = json_decode($capture->requests[1]['body'], true);

    expect($payload_a['internal_id'])->toBe((string) $order_a->get_id())
        ->and($payload_b['internal_id'])->toBe((string) $order_b->get_id())
        ->and($payload_a['internal_id'])->not->toBe($payload_b['internal_id']);
});
