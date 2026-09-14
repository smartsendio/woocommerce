<?php

/*
 * Focused tests for the fulfillment layer: SS_Shipping_Fulfillment_Service
 * runs the right post-booking steps on success (order note, order meta,
 * smart_send_shipment_booked, status change) and writes nothing on a
 * failed booking; the stateless SS_Shipping_Booking_Service handles
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
 * Record every smart_send_shipment_booked firing during the test.
 */
function capture_shipment_booked_action(): object
{
    $fired = new class {
        public array $order_ids = [];
    };

    $listener = function ($order_id, SS_Shipping_Booked_Shipment $shipment, SS_Shipping_Fulfillment_Result $result) use ($fired): void {
        $fired->order_ids[] = $order_id;
    };
    add_action('smart_send_shipment_booked', $listener, 10, 3);
    remember_cleanup_callback(function () use ($listener): void {
        remove_action('smart_send_shipment_booked', $listener, 10);
    });

    return $fired;
}

/**
 * Capture the raw argument list of every firing of a hook, so a test can
 * assert exactly what the action passes.
 */
function capture_hook_args(string $hook): object
{
    $captured = new class {
        public array $calls = [];
    };
    $listener = function (...$args) use ($captured): void {
        $captured->calls[] = $args;
    };
    add_action($hook, $listener, 10, 10);
    remember_cleanup_callback(function () use ($hook, $listener): void {
        remove_action($hook, $listener, 10);
    });

    return $captured;
}

/**
 * Walk a value's whole object graph (arrays, public and non-public
 * properties) and fail when a raw API object is reachable: a stdClass
 * (the decoded API response) or any Smartsend\ lib model.
 */
function assert_no_raw_api_objects($value, string $path = '$'): void
{
    static $seen = null;
    if ($path === '$') {
        $seen = new SplObjectStorage();
    }

    if (is_array($value)) {
        foreach ($value as $key => $item) {
            assert_no_raw_api_objects($item, $path . '[' . $key . ']');
        }

        return;
    }

    if (! is_object($value)) {
        return;
    }

    if (isset($seen[$value])) {
        return;
    }
    $seen[$value] = true;

    $class = get_class($value);
    expect($class)->not->toBe(stdClass::class, "raw stdClass reachable at {$path}");
    expect(strpos($class, 'Smartsend\\'))->not->toBe(0, "Smartsend lib object {$class} reachable at {$path}");

    if ($value instanceof Closure) {
        return;
    }

    $reflection = new ReflectionObject($value);
    do {
        foreach ($reflection->getProperties() as $property) {
            if (! $property->isInitialized($value)) {
                continue;
            }
            assert_no_raw_api_objects($property->getValue($value), $path . '->' . $property->getName());
        }
        $reflection = $reflection->getParentClass();
    } while ($reflection);
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
    $fired = capture_shipment_booked_action();

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

it('passes listeners the order id, the typed booked shipment and the result - no raw API response (#177)', function () {
    // Deliberate v9 contract (#139, #170, #177): smart_send_shipment_booked
    // hands listeners ($order_id, SS_Shipping_Booked_Shipment, SS_Shipping_Fulfillment_Result)
    // and nothing else. The raw API booking response is API-version shaped
    // and is not reachable from any argument.
    $order = create_fulfillable_order();
    mock_smart_send_api(function () {
        return ss_api_response(200, ['data' => ss_api_shipment_data(['shipment_id' => 'shipment-clean'])]);
    });
    $captured = capture_hook_args('smart_send_shipment_booked');

    $result = fulfillment_service()->fulfill_outbound($order->get_id());

    expect($result->is_successful())->toBeTrue()
        ->and($captured->calls)->toHaveCount(1)
        ->and($captured->calls[0])->toHaveCount(3);

    [$order_id, $shipment, $passed_result] = $captured->calls[0];
    expect($order_id)->toBe($order->get_id())
        ->and($shipment)->toBeInstanceOf(SS_Shipping_Booked_Shipment::class)
        ->and($passed_result)->toBe($result)
        ->and($shipment->get_shipment_id())->toBe('shipment-clean')
        ->and($shipment->is_return())->toBeFalse()
        ->and($shipment->label_document()->download_url())->toBe('https://api.example.test/labels/label.pdf')
        ->and($passed_result->get_order_note($shipment))->toContain('TRACK-1234')
        ->and($passed_result->get_order_note($shipment))->toContain('https://api.example.test/labels/label.pdf')
        ->and($passed_result->get_outbound_shipment())->toBe($shipment)
        ->and($passed_result->shipments())->toBe([$shipment]);

    // Nothing v1-shaped is reachable from either object argument.
    assert_no_raw_api_objects($shipment);
    assert_no_raw_api_objects($passed_result);
    expect(method_exists($result->get_outbound_booking(), 'get_data'))->toBeFalse()
        ->and(method_exists($result->get_outbound_booking(), 'get_wire_shipment'))->toBeFalse();

    // The shipment is a plain serializable DTO.
    expect(unserialize(serialize($shipment))->to_array())->toBe($shipment->to_array());

    // The legacy AJAX entry keeps the frozen woocommerce.* shape.
    $legacy = $result->to_legacy_response_array()[0]['success'];
    expect($legacy->shipment_id)->toBe('shipment-clean')
        ->and($legacy->woocommerce['label_url'])->toBe('https://api.example.test/labels/label.pdf')
        ->and($legacy->woocommerce['return'])->toBeFalse()
        ->and($legacy->woocommerce['order_note'])->toBe($result->get_order_note($shipment))
        ->and($legacy->woocommerce['outputs_html'])->toBe('<a href="https://api.example.test/labels/label.pdf" target="_blank">Download shipping label</a>')
        // ...and is derived from the DTO, not the API response: no inline PDF blob.
        ->and(property_exists($legacy, 'pdf'))->toBeFalse()
        ->and($legacy->documents[0]['url'])->toBe('https://api.example.test/labels/label.pdf');
});

it('keeps smart_send_shipping_label_created firing as a deprecated alias with the same arguments (#177)', function () {
    $order = create_fulfillable_order();
    mock_smart_send_api();

    $current = capture_hook_args('smart_send_shipment_booked');
    $alias   = capture_hook_args('smart_send_shipping_label_created');

    // The alias goes through do_action_deprecated(): record the deprecation
    // signal instead of letting WordPress raise E_USER_DEPRECATED.
    $deprecations = [];
    $on_deprecated = function ($hook, $replacement) use (&$deprecations): void {
        $deprecations[] = [$hook, $replacement];
    };
    add_action('deprecated_hook_run', $on_deprecated, 10, 2);
    add_filter('deprecated_hook_trigger_error', '__return_false');
    remember_cleanup_callback(function () use ($on_deprecated): void {
        remove_action('deprecated_hook_run', $on_deprecated, 10);
        remove_filter('deprecated_hook_trigger_error', '__return_false');
    });

    $result = fulfillment_service()->fulfill_outbound($order->get_id());

    expect($result->is_successful())->toBeTrue()
        ->and($current->calls)->toHaveCount(1)
        ->and($alias->calls)->toHaveCount(1)
        ->and($alias->calls[0])->toHaveCount(3)
        ->and($alias->calls[0][0])->toBe($current->calls[0][0])
        ->and($alias->calls[0][1])->toBe($current->calls[0][1])
        ->and($alias->calls[0][2])->toBe($current->calls[0][2])
        ->and($deprecations)->toBe([['smart_send_shipping_label_created', 'smart_send_shipment_booked']]);
});

it('fires the action once per label with the return shipment flagged as return (#177)', function () {
    $order = create_fulfillable_order(['auto_return' => 'yes']);
    mock_smart_send_api();
    $captured = capture_hook_args('smart_send_shipment_booked');

    $result = fulfillment_service()->fulfill_outbound($order->get_id());

    expect($result->is_successful())->toBeTrue()
        ->and($captured->calls)->toHaveCount(2)
        ->and($captured->calls[0][0])->toBe($order->get_id())
        ->and($captured->calls[1][0])->toBe($order->get_id());

    [$outbound, $return] = [$captured->calls[0][1], $captured->calls[1][1]];
    expect($outbound->is_return())->toBeFalse()
        ->and($result->get_order_note($outbound))->toContain('Download shipping label')
        ->and($return->is_return())->toBeTrue()
        ->and($result->get_order_note($return))->toContain('Return shipping label')
        ->and($return->get_service_code())->toBe('returndropoff');

    // Both firings carry the completed run: each listener sees both labels.
    expect($captured->calls[0][2])->toBe($result)
        ->and($captured->calls[1][2])->toBe($result)
        ->and($result->shipments())->toBe([$outbound, $return])
        ->and($result->get_outbound_shipment())->toBe($outbound)
        ->and($result->get_return_shipment())->toBe($return);
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

    // The uploads copy lives on the shipment's label document (#177): the
    // Smart Send URL stays, the local copy is recorded next to it.
    $label = $result->get_outbound_shipment()->label_document();
    expect($label->get_url())->toBe('https://api.example.test/labels/label.pdf')
        ->and($label->has_local_copy())->toBeTrue()
        ->and($label->get_local_url())->toBe($legacy->woocommerce['label_url'])
        ->and($label->get_local_path())->toEndWith('/smart-send-label-shipment-uploads.pdf')
        ->and(file_get_contents($label->get_local_path()))->toBe('%PDF-fake')
        ->and($label->download_url())->toBe($label->get_local_url());

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
    $captured = capture_hook_args('smart_send_shipment_booked');

    $result = fulfillment_service()->fulfill_outbound($order->get_id());

    expect($result->get_outbound_booking()->is_successful())->toBeTrue()
        ->and($result->get_return_booking())->not->toBeNull()
        ->and($result->get_return_booking()->is_successful())->toBeTrue()
        ->and($result->is_successful())->toBeFalse()
        ->and($result->get_first_error_message())->toContain('Label data empty')
        ->and($result->to_legacy_response_array())->toHaveCount(2)
        ->and($result->to_legacy_response_array()[0])->toHaveKey('error')
        ->and($result->to_legacy_response_array()[1])->toHaveKey('success');

    // Only the fully fulfilled (return) shipment is reported and announced.
    expect($result->get_outbound_shipment())->toBeNull()
        ->and($result->get_return_shipment())->not->toBeNull()
        ->and($captured->calls)->toHaveCount(1)
        ->and($captured->calls[0][1]->is_return())->toBeTrue();

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
    $fired = capture_shipment_booked_action();

    // Passing the WC_Order object (instead of the id) is supported too.
    $result = fulfillment_service()->fulfill_outbound($order);

    expect($result->is_successful())->toBeFalse()
        ->and($result->get_first_error_message())->toContain('The given data was invalid.')
        ->and($result->get_outbound_booking()->is_successful())->toBeFalse()
        ->and($result->get_outbound_booking()->shipment())->toBeNull()
        ->and($result->get_return_booking())->toBeNull()
        ->and($result->shipments())->toBe([])
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
