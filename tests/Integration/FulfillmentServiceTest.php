<?php

/*
 * Focused tests for the fulfillment stage (#177): SS_Shipping_Fulfillment_Service
 * decides the delivery details (smart_send_delivery_details), calls the
 * booking stage, runs the side-effect steps on success (documents, order
 * meta, order note, tracking, status - each behind its
 * smart_send_fulfillment_* filter), records a booking failure on the
 * result and fires smart_send_order_fulfilled once per run; the stateless
 * SS_Shipping_Booking_Service handles different orders sequentially
 * without state bleed.
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
 * Record every smart_send_order_fulfilled firing during the test.
 */
function capture_order_fulfilled_action(): object
{
    $fired = new class {
        public array $order_ids = [];
    };

    $listener = function (WC_Order $order, SS_Shipping_Fulfillment_Result $result) use ($fired): void {
        $fired->order_ids[] = $order->get_id();
    };
    add_action('smart_send_order_fulfilled', $listener, 10, 2);
    remember_cleanup_callback(function () use ($listener): void {
        remove_action('smart_send_order_fulfilled', $listener, 10);
    });

    return $fired;
}

/**
 * Register a filter for the duration of the test.
 */
function with_filter(string $hook, callable $callback, int $accepted_args = 3): void
{
    add_filter($hook, $callback, 10, $accepted_args);
    remember_cleanup_callback(function () use ($hook, $callback): void {
        remove_filter($hook, $callback, 10);
    });
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
    $fired = capture_order_fulfilled_action();

    $result = fulfillment_service()->fulfill_outbound($order->get_id());

    expect($result)->toBeInstanceOf(SS_Shipping_Fulfillment_Result::class)
        ->and($result->is_successful())->toBeTrue()
        ->and($result->get_first_error_message())->toBeNull()
        ->and($result->get_outbound_error())->toBeNull()
        ->and($result->get_return_error())->toBeNull()
        ->and($result->get_outbound_shipment())->not->toBeNull()
        ->and($result->get_return_shipment())->toBeNull()
        ->and($result->to_legacy_response_array())->toHaveCount(1)
        ->and($result->to_legacy_response_array()[0])->toHaveKey('success')
        ->and($fired->order_ids)->toBe([$order->get_id()]);

    // Every side-effect step ran with its default.
    expect($result->get_steps($result->get_outbound_shipment()))->toBe([
        'save_documents' => false,
        'order_note'     => true,
        'tracking'       => true,
        'order_status'   => 'wc-completed',
    ]);

    $fresh = wc_get_order($order->get_id());
    expect($fresh->get_meta('_ss_shipping_label_id', true))->toBe('shipment-fulfill')
        ->and($fresh->get_status())->toBe('completed');

    $notes = wc_get_order_notes(['order_id' => $order->get_id()]);
    expect(implode("\n", wp_list_pluck($notes, 'content')))->toContain('Shipping label')
        ->toContain('TRACK-1234');
});

it('passes smart_send_order_fulfilled the order and the result - no raw API response (#177)', function () {
    // Deliberate v9 contract (#139, #170, #177): smart_send_order_fulfilled
    // hands listeners (WC_Order, SS_Shipping_Fulfillment_Result) and
    // nothing else, once per run. The raw API booking response is
    // API-version shaped and is not reachable from the result.
    $order = create_fulfillable_order();
    mock_smart_send_api(function () {
        return ss_api_response(200, ['data' => ss_api_shipment_data(['shipment_id' => 'shipment-clean'])]);
    });
    $captured = capture_hook_args('smart_send_order_fulfilled');

    $result = fulfillment_service()->fulfill_outbound($order->get_id());

    expect($result->is_successful())->toBeTrue()
        ->and($captured->calls)->toHaveCount(1)
        ->and($captured->calls[0])->toHaveCount(2);

    [$passed_order, $passed_result] = $captured->calls[0];
    expect($passed_order)->toBeInstanceOf(WC_Order::class)
        ->and($passed_order->get_id())->toBe($order->get_id())
        ->and($passed_result)->toBe($result);

    $shipment = $passed_result->get_outbound_shipment();
    expect($shipment)->toBeInstanceOf(SS_Shipping_Booked_Shipment::class)
        ->and($shipment->get_shipment_id())->toBe('shipment-clean')
        ->and($shipment->is_return())->toBeFalse()
        ->and($shipment->label_document()->download_url())->toBe('https://api.example.test/labels/label.pdf')
        ->and($passed_result->get_order_note($shipment))->toContain('TRACK-1234')
        ->and($passed_result->get_order_note($shipment))->toContain('https://api.example.test/labels/label.pdf')
        ->and($passed_result->shipments())->toBe([$shipment]);

    // Nothing v1-shaped is reachable from the result, and the old
    // booking wrapper is gone.
    assert_no_raw_api_objects($passed_result);
    expect(class_exists('SS_Shipping_Booking'))->toBeFalse()
        ->and(method_exists($result, 'get_outbound_booking'))->toBeFalse();

    // The result is a plain serializable DTO (no WC_Order inside).
    expect(unserialize(serialize($result))->get_outbound_shipment()->to_array())->toBe($shipment->to_array());

    // The legacy AJAX entry keeps the frozen woocommerce.* shape.
    $legacy = $result->to_legacy_response_array()[0]['success'];
    expect($legacy->shipment_id)->toBe('shipment-clean')
        ->and($legacy->woocommerce['label_url'])->toBe('https://api.example.test/labels/label.pdf')
        ->and($legacy->woocommerce['return'])->toBeFalse()
        ->and($legacy->woocommerce['order_note'])->toBe($result->get_order_note($shipment))
        ->and($legacy->woocommerce['outputs_html'])->toBe('<a href="https://api.example.test/labels/label.pdf" target="_blank">Download shipping label</a>')
        // ...and is derived from the DTO, not the API response: no inline PDF blob.
        ->and(property_exists($legacy, 'pdf'))->toBeFalse()
        ->and($legacy->documents[0])->not->toHaveKey('inline_content')
        ->and($legacy->documents[0]['url'])->toBe('https://api.example.test/labels/label.pdf');
});

it('no longer fires smart_send_shipment_booked or smart_send_shipping_label_created (#177)', function () {
    $order = create_fulfillable_order();
    mock_smart_send_api();

    $old   = capture_hook_args('smart_send_shipment_booked');
    $older = capture_hook_args('smart_send_shipping_label_created');
    $new   = capture_hook_args('smart_send_order_fulfilled');

    $result = fulfillment_service()->fulfill_outbound($order->get_id());

    expect($result->is_successful())->toBeTrue()
        ->and($new->calls)->toHaveCount(1)
        ->and($old->calls)->toBe([])
        ->and($older->calls)->toBe([]);
});

it('fires smart_send_order_fulfilled once per run with both labels when the return label is auto-generated (#177)', function () {
    $order = create_fulfillable_order(['auto_return' => 'yes']);
    mock_smart_send_api();
    $captured = capture_hook_args('smart_send_order_fulfilled');

    $result = fulfillment_service()->fulfill_outbound($order->get_id());

    expect($result->is_successful())->toBeTrue()
        ->and($captured->calls)->toHaveCount(1)
        ->and($captured->calls[0][0]->get_id())->toBe($order->get_id())
        ->and($captured->calls[0][1])->toBe($result);

    [$outbound, $return] = $result->shipments();
    expect($outbound->is_return())->toBeFalse()
        ->and($result->get_order_note($outbound))->toContain('Download shipping label')
        ->and($return->is_return())->toBeTrue()
        ->and($result->get_order_note($return))->toContain('Return shipping label')
        ->and($return->get_service_code())->toBe('returndropoff')
        ->and($result->get_outbound_shipment())->toBe($outbound)
        ->and($result->get_return_shipment())->toBe($return);

    // Tracking push and order status are outbound-only by default.
    expect($result->get_steps($outbound)['tracking'])->toBeTrue()
        ->and($result->get_steps($return)['tracking'])->toBeFalse()
        ->and($result->get_steps($return)['order_status'])->toBeFalse();
});

it('applies smart_send_delivery_details in fulfillment, before booking, with the documented arguments', function () {
    $order = create_fulfillable_order(['auto_return' => 'yes']);
    $capture = mock_smart_send_api();

    $seen = [];
    with_filter('smart_send_delivery_details', function ($details, $order_arg, $is_return) use (&$seen, $order) {
        expect($details)->toBeInstanceOf(SS_Shipping_Delivery_Details::class)
            ->and($order_arg)->toBeInstanceOf(WC_Order::class)
            ->and($order_arg->get_id())->toBe($order->get_id())
            ->and($is_return)->toBeBool();
        $seen[] = [$details->get_shipping_method(), $is_return];

        // The filter's return value is what gets booked.
        return $details->set_shipping_method($is_return ? 'gls_returndropoff' : 'gls_shop');
    });

    $result = fulfillment_service()->fulfill_outbound($order->get_id());

    expect($result->is_successful())->toBeTrue()
        ->and($seen)->toBe([['postnord_agent', false], ['postnord_returndropoff', true]]);

    $outbound_payload = json_decode($capture->requests[0]['body'], true);
    $return_payload   = json_decode($capture->requests[1]['body'], true);
    expect($outbound_payload['shipping_carrier'])->toBe('gls')
        ->and($outbound_payload['shipping_method'])->toBe('shop')
        ->and($return_payload['shipping_carrier'])->toBe('gls')
        ->and($return_payload['shipping_method'])->toBe('returndropoff');
});

it('lets smart_send_fulfillment_save_documents turn the uploads copy on and off', function () {
    // Setting off, filter on: a copy is stored.
    $order = create_fulfillable_order();
    mock_smart_send_api(function () {
        return ss_api_response(200, ['data' => ss_api_shipment_data(['shipment_id' => 'shipment-filter-save'])]);
    });
    remember_cleanup_callback(function (): void {
        $upload_dir = wp_upload_dir();
        $file       = $upload_dir['path'] . '/smart-send-label-shipment-filter-save.pdf';
        if (file_exists($file)) {
            unlink($file);
        }
    });

    $args = [];
    $on   = function ($save, $shipment, $order_arg) use (&$args) {
        $args = [$save, $shipment, $order_arg];

        return true;
    };
    with_filter('smart_send_fulfillment_save_documents', $on);

    $result = fulfillment_service()->fulfill_outbound($order->get_id());

    expect($result->is_successful())->toBeTrue()
        ->and($args[0])->toBeFalse()
        ->and($args[1])->toBeInstanceOf(SS_Shipping_Booked_Shipment::class)
        ->and($args[2])->toBeInstanceOf(WC_Order::class)
        ->and($result->get_steps($result->get_outbound_shipment())['save_documents'])->toBeTrue()
        ->and($result->get_outbound_shipment()->label_document()->has_local_copy())->toBeTrue()
        ->and($result->get_outbound_shipment()->label_document()->get_local_path())->toEndWith('/smart-send-label-shipment-filter-save.pdf');

    remove_filter('smart_send_fulfillment_save_documents', $on, 10);

    // Setting on, filter off: no copy, and the API link is used.
    with_ss_settings(['save_shipping_labels_in_uploads' => 'yes']);
    with_filter('smart_send_fulfillment_save_documents', '__return_false');

    $order_b  = create_fulfillable_order();
    $result_b = fulfillment_service()->fulfill_outbound($order_b->get_id());

    expect($result_b->is_successful())->toBeTrue()
        ->and($result_b->get_steps($result_b->get_outbound_shipment())['save_documents'])->toBeFalse()
        ->and($result_b->get_outbound_shipment()->label_document()->has_local_copy())->toBeFalse()
        ->and($result_b->to_legacy_response_array()[0]['success']->woocommerce['label_url'])->toBe('https://api.example.test/labels/label.pdf');
});

it('lets smart_send_fulfillment_order_note rewrite or suppress the order note', function () {
    $order = create_fulfillable_order();
    mock_smart_send_api();

    $args = [];
    with_filter('smart_send_fulfillment_order_note', function ($note, $shipment, $order_arg) use (&$args) {
        $args = [$note, $shipment, $order_arg];

        return 'Custom note for ' . $shipment->get_shipment_id();
    });

    $result   = fulfillment_service()->fulfill_outbound($order->get_id());
    $shipment = $result->get_outbound_shipment();

    expect($result->is_successful())->toBeTrue()
        ->and($args[0])->toContain('Download shipping label')
        ->and($args[1])->toBe($shipment)
        ->and($args[2]->get_id())->toBe($order->get_id())
        // The filtered note is what is saved AND what the AJAX response carries.
        ->and($result->get_order_note($shipment))->toBe('Custom note for ' . $shipment->get_shipment_id())
        ->and($result->to_legacy_response_array()[0]['success']->woocommerce['order_note'])->toBe('Custom note for ' . $shipment->get_shipment_id())
        ->and($result->get_steps($shipment)['order_note'])->toBeTrue();

    $notes = wp_list_pluck(wc_get_order_notes(['order_id' => $order->get_id()]), 'content');
    expect($notes)->toContain('Custom note for ' . $shipment->get_shipment_id());

    // An empty string adds no note.
    with_filter('smart_send_fulfillment_order_note', '__return_empty_string');

    $order_b  = create_fulfillable_order();
    $result_b = fulfillment_service()->fulfill_outbound($order_b->get_id());

    expect($result_b->is_successful())->toBeTrue()
        ->and($result_b->get_steps($result_b->get_outbound_shipment())['order_note'])->toBeFalse()
        ->and($result_b->get_order_note($result_b->get_outbound_shipment()))->toBe('');
    $notes_b = implode("\n", wp_list_pluck(wc_get_order_notes(['order_id' => $order_b->get_id()]), 'content'));
    expect($notes_b)->not->toContain('Shipping label');
});

it('lets smart_send_fulfillment_tracking skip the tracking push', function () {
    $order = create_fulfillable_order();
    $calls = &shipment_tracking_calls();
    mock_smart_send_api();

    $args = [];
    with_filter('smart_send_fulfillment_tracking', function ($push, $shipment, $order_arg) use (&$args) {
        $args = [$push, $shipment, $order_arg];

        return false;
    });

    $result = fulfillment_service()->fulfill_outbound($order->get_id());

    expect($result->is_successful())->toBeTrue()
        ->and($args[0])->toBeTrue()
        ->and($args[1])->toBe($result->get_outbound_shipment())
        ->and($args[2]->get_id())->toBe($order->get_id())
        ->and($result->get_steps($result->get_outbound_shipment())['tracking'])->toBeFalse()
        ->and($calls)->toBe([]);
});

it('lets smart_send_fulfillment_order_status change or skip the status update', function () {
    with_ss_settings(['order_status' => 'wc-completed']);
    $order = create_fulfillable_order();
    mock_smart_send_api();

    $args = [];
    with_filter('smart_send_fulfillment_order_status', function ($status, $shipment, $order_arg) use (&$args) {
        $args = [$status, $shipment, $order_arg];

        return 'wc-on-hold';
    });

    $result = fulfillment_service()->fulfill_outbound($order->get_id());

    expect($result->is_successful())->toBeTrue()
        ->and($args[0])->toBe('wc-completed')
        ->and($args[1])->toBe($result->get_outbound_shipment())
        ->and($args[2]->get_id())->toBe($order->get_id())
        ->and($result->get_steps($result->get_outbound_shipment())['order_status'])->toBe('wc-on-hold')
        ->and(wc_get_order($order->get_id())->get_status())->toBe('on-hold');

    // false leaves the status alone even though the setting is configured.
    with_filter('smart_send_fulfillment_order_status', '__return_false');

    $order_b  = create_fulfillable_order();
    $result_b = fulfillment_service()->fulfill_outbound($order_b->get_id());

    expect($result_b->is_successful())->toBeTrue()
        ->and($result_b->get_steps($result_b->get_outbound_shipment())['order_status'])->toBeFalse()
        ->and(wc_get_order($order_b->get_id())->get_status())->toBe('processing');
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
        ->and($result->get_outbound_shipment())->not->toBeNull()
        ->and($result->get_return_shipment())->not->toBeNull()
        ->and($result->get_return_shipment()->is_return())->toBeTrue()
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
    $booked   = capture_hook_args('smart_send_booking_completed');
    $captured = capture_hook_args('smart_send_order_fulfilled');

    $result = fulfillment_service()->fulfill_outbound($order->get_id());

    // Both bookings succeeded at the API...
    expect($booked->calls)->toHaveCount(2)
        ->and($booked->calls[0][0]->is_return())->toBeFalse()
        ->and($booked->calls[1][0]->is_return())->toBeTrue();

    // ...the outbound side effects failed, the return leg was fulfilled.
    expect($result->is_successful())->toBeFalse()
        ->and($result->get_outbound_error())->toContain('Label data empty')
        ->and($result->get_return_error())->toBeNull()
        ->and($result->get_first_error_message())->toContain('Label data empty')
        ->and($result->to_legacy_response_array())->toHaveCount(2)
        ->and($result->to_legacy_response_array()[0])->toHaveKey('error')
        ->and($result->to_legacy_response_array()[1])->toHaveKey('success');

    // Only the fully fulfilled (return) shipment is reported; the run is
    // announced once with both outcomes side by side.
    expect($result->get_outbound_shipment())->toBeNull()
        ->and($result->get_return_shipment())->not->toBeNull()
        ->and($captured->calls)->toHaveCount(1)
        ->and($captured->calls[0][1]->get_return_shipment()->is_return())->toBeTrue();

    $fresh = wc_get_order($order->get_id());
    expect($fresh->get_meta('_ss_shipping_label_id', true))->toBe('')
        ->and($fresh->get_meta('_ss_shipping_return_label_id', true))->not->toBe('');
});

it('writes nothing when the booking fails', function () {
    with_ss_settings(['order_status' => 'wc-completed']);

    $order = create_fulfillable_order();
    mock_smart_send_api(function () {
        return ss_api_response(422, ss_api_error_body('The given data was invalid.'), 'resp-fail-1');
    });
    $fired  = capture_order_fulfilled_action();
    $failed = capture_hook_args('smart_send_booking_failed');

    // Passing the WC_Order object (instead of the id) is supported too.
    $result = fulfillment_service()->fulfill_outbound($order);

    // The booking exception is recorded on the result as data: the
    // HTML-formatted message (rendered by fulfillment, not booking) and
    // the per-field validation errors.
    expect($result->is_successful())->toBeFalse()
        ->and($result->get_first_error_message())->toContain('The given data was invalid.')
        ->and($result->get_outbound_error())->toBe('The given data was invalid.<br>- receiver.postal_code: The postal code is invalid.<br>Response ID: resp-fail-1')
        ->and($result->get_validation_errors(false))->toBe(['receiver.postal_code' => ['The postal code is invalid.']])
        ->and($result->get_return_error())->toBeNull()
        ->and($result->get_outbound_shipment())->toBeNull()
        ->and($result->shipments())->toBe([])
        ->and($result->to_legacy_response_array())->toBe([['error' => $result->get_outbound_error()]])
        ->and($fired->order_ids)->toBe([])
        ->and($failed->calls)->toHaveCount(1)
        ->and($failed->calls[0][0])->toBeInstanceOf(SS_Shipping_Booking_Exception::class);

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
        ->and($result->get_outbound_error())->toContain('The order could not be found')
        ->and($result->get_return_error())->toBeNull()
        ->and($result->shipments())->toBe([])
        ->and($result->to_legacy_response_array())->toHaveCount(1)
        ->and($result->to_legacy_response_array()[0])->toHaveKey('error');
});

it('books two different orders through one stateless booking service without state bleed', function () {
    $order_a = create_fulfillable_order();
    $order_b = create_fulfillable_order();

    $capture = mock_smart_send_api();

    $booking_service = new SS_Shipping_Booking_Service();

    $booked_a = $booking_service->book($order_a, fulfillment_service()->resolve_delivery_details($order_a, false), false);
    $booked_b = $booking_service->book($order_b, fulfillment_service()->resolve_delivery_details($order_b, false), false);

    expect($booked_a)->toBeInstanceOf(SS_Shipping_Booked_Shipment::class)
        ->and($booked_b)->toBeInstanceOf(SS_Shipping_Booked_Shipment::class)
        ->and($capture->requests)->toHaveCount(2);

    $payload_a = json_decode($capture->requests[0]['body'], true);
    $payload_b = json_decode($capture->requests[1]['body'], true);

    expect($payload_a['internal_id'])->toBe((string) $order_a->get_id())
        ->and($payload_b['internal_id'])->toBe((string) $order_b->get_id())
        ->and($payload_a['internal_id'])->not->toBe($payload_b['internal_id']);
});

it('records a failed auto-return leg next to the fulfilled outbound shipment', function () {
    $order = create_fulfillable_order(['auto_return' => 'yes']);

    $requests = 0;
    mock_smart_send_api(function () use (&$requests) {
        $requests++;

        // Outbound books, the return booking is rejected by the API.
        if ($requests === 1) {
            return ss_api_response(200, ['data' => ss_api_shipment_data(['shipment_id' => 'shipment-out-ok'])]);
        }

        return ss_api_response(422, ss_api_error_body('Return not possible.'), 'resp-return');
    });
    $failed   = capture_hook_args('smart_send_booking_failed');
    $captured = capture_hook_args('smart_send_order_fulfilled');

    $result = fulfillment_service()->fulfill_outbound($order->get_id());

    expect($requests)->toBe(2)
        ->and($result->is_successful())->toBeFalse()
        ->and($result->get_outbound_shipment()->get_shipment_id())->toBe('shipment-out-ok')
        ->and($result->get_outbound_error())->toBeNull()
        ->and($result->get_return_shipment())->toBeNull()
        ->and($result->get_return_error())->toContain('Return not possible.')
        ->and($result->get_return_error())->toContain('Response ID: resp-return')
        ->and($result->get_validation_errors(true))->toBe(['receiver.postal_code' => ['The postal code is invalid.']])
        ->and($result->get_error_messages())->toHaveCount(1)
        ->and($result->to_legacy_response_array()[0])->toHaveKey('success')
        ->and($result->to_legacy_response_array()[1])->toHaveKey('error');

    // The booking failure was announced with the shipment that was sent...
    expect($failed->calls)->toHaveCount(1)
        ->and($failed->calls[0][1])->toBeInstanceOf(SS_Shipping_Shipment::class)
        ->and($failed->calls[0][1]->get_shipping_method())->toBe('returndropoff');

    // ...and the run was announced once, because the outbound shipment was fulfilled.
    expect($captured->calls)->toHaveCount(1)
        ->and($captured->calls[0][1]->get_return_error())->toContain('Return not possible.');

    $fresh = wc_get_order($order->get_id());
    expect($fresh->get_meta('_ss_shipping_label_id', true))->toBe('shipment-out-ok')
        ->and($fresh->get_meta('_ss_shipping_return_label_id', true))->toBe('');
});

it('records a missing return method as a failed return leg without calling the API', function () {
    $order   = create_fulfillable_order(['return_method' => '']);
    $capture = mock_smart_send_api();
    $failed  = capture_hook_args('smart_send_booking_failed');
    $fired   = capture_order_fulfilled_action();

    $result = fulfillment_service()->fulfill_return($order->get_id());

    // The resolver's SS_Shipping_Booking_Exception is caught by
    // fulfillment like a booking failure; no shipment was ever sent, so
    // smart_send_booking_failed does not fire.
    expect($result->is_successful())->toBeFalse()
        ->and($result->get_return_error())->toBe('No return method set')
        ->and($result->get_validation_errors(true))->toBe([])
        ->and($result->get_outbound_error())->toBeNull()
        ->and($capture->requests)->toBe([])
        ->and($failed->calls)->toBe([])
        ->and($fired->order_ids)->toBe([]);
});
