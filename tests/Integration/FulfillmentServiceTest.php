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
    return SS_SHIPPING_WC()->get_ss_shipping_wc_order()->fulfillment();
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

    $booking_service = new SS_Shipping_Booking_Service(SS_SHIPPING_WC()->get_ss_shipping_wc_order());

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
