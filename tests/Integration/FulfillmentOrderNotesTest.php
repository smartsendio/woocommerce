<?php

use Smart_Send\Booking\Booked_Shipment;

/*
 * Order-history notes belong to WooCommerce: fulfillment writes them once,
 * while serializing or refreshing Smart Send's own result never writes or
 * renders another history entry. Run each scenario against both stores.
 */

/** Read only notes created after the fixture's own status-history entries. */
function fulfillment_notes_since(WC_Order $order, array $before): array
{
    return array_values(array_filter(
        wc_get_order_notes(['order_id' => $order->get_id()]),
        fn ($note) => ! in_array((int) $note->id, $before, true)
    ));
}

beforeEach(function (): void {
    with_ss_settings();
});

it('stores one native order note per successfully booked direction', function (bool $hpos, string $flow) {
    with_option('woocommerce_custom_orders_table_enabled', $hpos ? 'yes' : 'no');
    $order = create_order([
        'products' => [create_simple_product(['price' => 100, 'weight' => 1])],
        'shipping_method' => 'postnord_agent',
    ]);
    $existing_ids = array_map('intval', wp_list_pluck(wc_get_order_notes(['order_id' => $order->get_id()]), 'id'));

    $filter_calls = [];
    $filter = function (string $content, Booked_Shipment $shipment, WC_Order $filtered_order) use (&$filter_calls, $order): string {
        expect($filtered_order->get_id())->toBe($order->get_id())
            ->and($content)->toContain('TRACK-1234')
            ->toContain('https://api.example.test/labels/label.pdf');
        $direction = $shipment->is_return() ? 'return' : 'outbound';
        $filter_calls[] = $direction;
        return $content . '<br>Custom ' . $direction . ' note';
    };
    add_filter('smart_send_fulfillment_order_note', $filter, 10, 3);
    remember_cleanup_callback(fn () => remove_filter('smart_send_fulfillment_order_note', $filter, 10));

    $bookings = 0;
    $capture = mock_smart_send_api(function () use (&$bookings, $flow) {
        $bookings++;
        if ($flow === 'partial' && $bookings === 2) {
            return ss_api_response(422, ['message' => 'Return booking rejected.']);
        }
        return ss_api_response(200, ['data' => ss_api_shipment_data(['shipment_id' => 'note-shipment-' . $bookings])]);
    });

    $result = $flow === 'return'
        ? SS_SHIPPING_WC()->fulfillment()->fulfill_return($order)
        : SS_SHIPPING_WC()->fulfillment()->fulfill_outbound($order, true, null, in_array($flow, ['combined', 'partial'], true));
    $directions = $flow === 'combined' ? ['outbound', 'return'] : [$flow === 'return' ? 'return' : 'outbound'];
    $notes = fulfillment_notes_since($order, $existing_ids);
    $note_ids = array_map('intval', wp_list_pluck($notes, 'id'));

    expect($notes)->toHaveCount(count($directions))
        ->and($filter_calls)->toBe($directions)
        ->and($result->is_successful())->toBe($flow !== 'partial')
        ->and($capture->requests)->toHaveCount(in_array($flow, ['combined', 'partial'], true) ? 2 : 1);

    foreach ($result->shipments() as $shipment) {
        $note_id = $result->get_order_note_id($shipment);
        expect($note_id)->toBeInt()
            ->and($note_ids)->toContain($note_id)
            ->and($result->get_steps($shipment)['order_note'])->toBeTrue();
        $content = implode('\n', wp_list_pluck($notes, 'content'));
        expect($content)->toContain($shipment->is_return() ? 'Custom return note' : 'Custom outbound note');
    }

    // The result keeps useful persistence metadata but no history markup.
    // Presenting the same result twice must leave WooCommerce's notes alone.
    for ($attempt = 0; $attempt < 2; $attempt++) {
        $response = SS_SHIPPING_WC()->fulfillment_presenter()->response($result, $order);
        foreach ($response['shipments'] as $row) {
            if ($row['status'] === 'fulfilled') {
                expect(array_keys($row['order_note']))->toBe(['id'])
                    ->and($note_ids)->toContain($row['order_note']['id'])
                    ->and($row['steps']['order_note'])->toBeTrue();
            } else {
                expect($row)->not->toHaveKey('order_note');
            }
        }
        expect(array_map('intval', wp_list_pluck(fulfillment_notes_since($order, $existing_ids), 'id')))->toBe($note_ids);
    }

    // Fixtures must be removed before switching the order storage option.
    cleanup_created_objects();
})->with(['HPOS' => true, 'posts' => false])->with(['outbound', 'return', 'combined', 'partial']);

it('stores no notes when disabled or suppressed by the content filter', function (bool $hpos, bool $empty_filter) {
    with_option('woocommerce_custom_orders_table_enabled', $hpos ? 'yes' : 'no');
    $order = create_order([
        'products' => [create_simple_product(['price' => 100, 'weight' => 1])],
        'shipping_method' => 'postnord_agent',
    ]);
    $existing_ids = array_map('intval', wp_list_pluck(wc_get_order_notes(['order_id' => $order->get_id()]), 'id'));
    mock_smart_send_api();
    if ($empty_filter) {
        add_filter('smart_send_fulfillment_order_note', '__return_empty_string');
        remember_cleanup_callback(fn () => remove_filter('smart_send_fulfillment_order_note', '__return_empty_string'));
    }

    $result = SS_SHIPPING_WC()->fulfillment()->fulfill_outbound($order, $empty_filter, null, true);
    expect($result->is_successful())->toBeTrue()
        ->and($result->shipments())->toHaveCount(2)
        ->and(fulfillment_notes_since($order, $existing_ids))->toBe([]);

    $response = SS_SHIPPING_WC()->fulfillment_presenter()->response($result, $order);
    foreach ($result->shipments() as $shipment) {
        expect($result->get_order_note_id($shipment))->toBeNull()
            ->and($result->get_steps($shipment)['order_note'])->toBeFalse();
    }
    foreach ($response['shipments'] as $row) {
        expect($row['order_note'])->toBe(['id' => null])
            ->and($row['steps']['order_note'])->toBeFalse();
    }
    expect(fulfillment_notes_since($order, $existing_ids))->toBe([]);
    cleanup_created_objects();
})->with(['HPOS' => true, 'posts' => false])->with(['disabled' => false, 'empty filter' => true]);
