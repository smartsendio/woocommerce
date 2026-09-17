<?php

/*
 * Tests for the booking stage (#177): \Smart_Send\Booking\Booking_Service::book()
 * takes the order plus the delivery details fulfillment decided on, runs
 * smart_send_booking_request on the built \Smart_Send\Booking\Shipment, sends it,
 * fires smart_send_booking_completed with the typed result - or fires
 * smart_send_booking_failed and throws \Smart_Send\Booking\Exceptions\Booking_Exception
 * (validation errors on errors(), the API exception as previous). Booking
 * never reads order meta or resolves methods itself.
 */

/**
 * An order that can have a label booked for it.
 */
function create_booking_order(array $args = []): WC_Order
{
    $product = create_simple_product(['price' => 100, 'weight' => 1]);

    return create_order(array_merge([
        'products'        => [$product],
        'shipping_method' => 'postnord_agent',
        'shipping_total'  => '39',
    ], $args));
}

/**
 * Delivery details built by hand - the booking stage takes them as given.
 */
function handmade_details(string $method = 'postnord_agent'): \Smart_Send\Delivery\Delivery_Details
{
    $details = new \Smart_Send\Delivery\Delivery_Details();

    return $details->set_shipping_method($method);
}

beforeEach(function (): void {
    with_ss_settings();
});

it('books with the delivery details it is given, without reading order meta or resolving the method', function () {
    // The order carries a postnord_agent shipping item and a stored pickup
    // point; the details handed to booking say otherwise, and win.
    $order = create_booking_order();
    save_order_pickup_point($order->get_id(), sample_agent());
    $capture = mock_smart_send_api(function () {
        return ss_api_response(200, ['data' => ss_api_shipment_data(['shipment_id' => 'shipment-given'])]);
    });

    $booked = (new \Smart_Send\Booking\Booking_Service())->book($order, handmade_details('gls_shop'), false);

    expect($booked)->toBeInstanceOf(\Smart_Send\Booking\Booked_Shipment::class)
        ->and($booked->get_shipment_id())->toBe('shipment-given')
        ->and($booked->is_return())->toBeFalse();

    $payload = json_decode(end($capture->requests)['body'], true);
    expect($payload['shipping_carrier'])->toBe('gls')
        ->and($payload['shipping_method'])->toBe('shop')
        ->and($payload['agent'])->toBeNull();
});

it('throws a \Smart_Send\Booking\Exceptions\Booking_Exception before any request when the details carry no shipping method', function () {
    $order   = create_booking_order();
    $capture = mock_smart_send_api();
    $failed  = capture_hook_args('smart_send_booking_failed');

    expect(fn () => (new \Smart_Send\Booking\Booking_Service())->book($order, new \Smart_Send\Delivery\Delivery_Details(), false))
        ->toThrow(\Smart_Send\Booking\Exceptions\Booking_Exception::class, 'No shipping method set');

    expect($capture->requests)->toBe([])
        ->and($failed->calls)->toBe([]);
});

it('runs smart_send_booking_request on the built shipment and books what the filter returns', function () {
    $order   = create_booking_order(['auto_return' => 'yes']);
    $capture = mock_smart_send_api();

    $seen = [];
    $filter = function ($shipment, $filtered_order, $is_return) use (&$seen, $order) {
        expect($shipment)->toBeInstanceOf(\Smart_Send\Booking\Shipment::class)
            ->and($filtered_order)->toBeInstanceOf(WC_Order::class)
            ->and($filtered_order->get_id())->toBe($order->get_id())
            ->and($is_return)->toBeBool();
        $seen[] = [$shipment->get_shipping_method(), $is_return];

        $receiver          = $shipment->get_receiver();
        $receiver['email'] = 'filtered@example.test';

        return $shipment->set_receiver($receiver);
    };
    add_filter('smart_send_booking_request', $filter, 10, 3);
    remember_cleanup_callback(function () use ($filter): void {
        remove_filter('smart_send_booking_request', $filter, 10);
    });

    $result = SS_SHIPPING_WC()->fulfillment()->fulfill_outbound($order->get_id());

    expect($result->is_successful())->toBeTrue()
        ->and($seen)->toBe([['agent', false], ['returndropoff', true]]);

    foreach ($capture->requests as $request) {
        $payload = json_decode($request['body'], true);
        expect($payload['receiver']['email'])->toBe('filtered@example.test');
    }
});

it('fires smart_send_booking_completed with the booked shipment, the request shipment and the order', function () {
    $order = create_booking_order();
    mock_smart_send_api(function () {
        return ss_api_response(200, ['data' => ss_api_shipment_data(['shipment_id' => 'shipment-completed'])]);
    });
    $completed = capture_hook_args('smart_send_booking_completed');
    $failed    = capture_hook_args('smart_send_booking_failed');

    $booked = (new \Smart_Send\Booking\Booking_Service())->book($order, handmade_details(), false);

    expect($completed->calls)->toHaveCount(1)
        ->and($completed->calls[0])->toHaveCount(3)
        ->and($completed->calls[0][0])->toBe($booked)
        ->and($completed->calls[0][0]->get_shipment_id())->toBe('shipment-completed')
        ->and($completed->calls[0][1])->toBeInstanceOf(\Smart_Send\Booking\Shipment::class)
        ->and($completed->calls[0][1]->get_internal_id())->toBe((string) $order->get_id())
        ->and($completed->calls[0][2])->toBeInstanceOf(WC_Order::class)
        ->and($completed->calls[0][2]->get_id())->toBe($order->get_id())
        ->and($failed->calls)->toBe([]);

    // Booking writes nothing to the order: that is fulfillment's job.
    expect(wc_get_order($order->get_id())->get_meta('_ss_shipping_label_id', true))->toBe('');
});

it('fires smart_send_booking_failed and throws with the validation errors and the API exception as previous', function () {
    $order = create_booking_order();
    mock_smart_send_api(function () {
        return ss_api_response(422, [
            'message' => 'The given data was invalid.',
            'errors'  => [
                'receiver.postal_code' => ['The postal code is invalid.'],
                'parcels.0.weight'     => ['The weight must be at least 0.1.', 'The weight must be a number.'],
            ],
        ], 'resp-422');
    });
    $completed = capture_hook_args('smart_send_booking_completed');
    $failed    = capture_hook_args('smart_send_booking_failed');

    $thrown = null;
    try {
        (new \Smart_Send\Booking\Booking_Service())->book($order, handmade_details(), true);
    } catch (\Smart_Send\Booking\Exceptions\Booking_Exception $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(\Smart_Send\Booking\Exceptions\Booking_Exception::class)
        ->and($thrown->getMessage())->toBe('The given data was invalid.')
        ->and($thrown->errors())->toBe([
            'receiver.postal_code' => ['The postal code is invalid.'],
            'parcels.0.weight'     => ['The weight must be at least 0.1.', 'The weight must be a number.'],
        ])
        ->and($thrown->response_id())->toBe('resp-422')
        ->and($thrown->getPrevious())->toBeInstanceOf(\Smart_Send\API\Exceptions\Validation_Exception::class);

    // The action fired right before the throw, with the same exception.
    expect($failed->calls)->toHaveCount(1)
        ->and($failed->calls[0])->toHaveCount(3)
        ->and($failed->calls[0][0])->toBe($thrown)
        ->and($failed->calls[0][1])->toBeInstanceOf(\Smart_Send\Booking\Shipment::class)
        ->and($failed->calls[0][1]->get_shipping_method())->toBe('agent')
        ->and($failed->calls[0][2])->toBeInstanceOf(WC_Order::class)
        ->and($failed->calls[0][2]->get_id())->toBe($order->get_id())
        ->and($completed->calls)->toBe([]);
});

it('wraps non-validation API failures with empty errors() and the API exception as previous', function () {
    $order = create_booking_order();
    mock_smart_send_api(function () {
        return ss_api_response(500, ['message' => 'Server error'], 'resp-500');
    });

    $thrown = null;
    try {
        (new \Smart_Send\Booking\Booking_Service())->book($order, handmade_details(), false);
    } catch (\Smart_Send\Booking\Exceptions\Booking_Exception $e) {
        $thrown = $e;
    }

    expect($thrown)->not->toBeNull()
        ->and($thrown->errors())->toBe([])
        ->and($thrown->response_id())->toBe('resp-500')
        ->and($thrown->getPrevious())->toBeInstanceOf(\Smart_Send\API\Exceptions\HTTP_Client_Exception::class);
});

it('renders a multi-message validation failure in the fulfillment error the merchant sees', function () {
    // format_booking_error() lives in fulfillment: message, one line per
    // field error (a field with several messages gets a heading), the
    // Response-ID last.
    $order = create_booking_order();
    mock_smart_send_api(function () {
        return ss_api_response(422, [
            'message' => 'The given data was invalid.',
            'errors'  => [
                'receiver.postal_code' => ['The postal code is invalid.'],
                'parcels.0.weight'     => ['Too light.', 'Not a number.'],
            ],
        ], 'resp-multi');
    });

    $result = SS_SHIPPING_WC()->fulfillment()->fulfill_outbound($order->get_id());

    expect($result->get_outbound_error())->toBe(
        'The given data was invalid.'
        . '<br>- receiver.postal_code: The postal code is invalid.'
        . '<br>parcels.0.weight:'
        . '<br>- Too light.'
        . '<br>- Not a number.'
        . '<br>Response ID: resp-multi'
    );
});

it('carries the weight, dimensions and reference of each request parcel onto the booked parcel at the same index', function () {
    $product = create_simple_product(['price' => 100, 'weight' => 1]);
    $order   = create_order(['products' => [[$product, 3]], 'shipping_method' => 'postnord_agent']);

    // Two boxes: the second with explicit measures.
    $plan = new \Smart_Send\Delivery\Parcel_Plan();
    $plan->add_spec((new \Smart_Send\Delivery\Parcel_Spec())->add_item($product->get_id(), 2));
    $plan->add_spec(
        (new \Smart_Send\Delivery\Parcel_Spec())
            ->add_item($product->get_id(), 1)
            ->set_weight(2.5)
            ->set_length(40)
            ->set_width(30)
            ->set_height(20)
    );

    $details = handmade_details()->set_parcel_plan($plan);

    mock_smart_send_api(function () {
        return ss_api_response(200, ['data' => ss_api_shipment_data([
            'shipment_id' => 'shipment-split',
            'parcels'     => [
                ['parcel_internal_id' => 1, 'tracking_code' => 'TRACK-1', 'tracking_link' => 'https://tracking.example.test/1'],
                ['parcel_internal_id' => 2, 'tracking_code' => 'TRACK-2', 'tracking_link' => 'https://tracking.example.test/2'],
            ],
        ])]);
    });

    $booked  = (new \Smart_Send\Booking\Booking_Service())->book($order, $details, false);
    $parcels = $booked->parcels();

    expect($parcels)->toHaveCount(2)
        // The first box has no explicit weight: the item sum is what was booked.
        ->and($parcels[0]->get_tracking_code())->toBe('TRACK-1')
        ->and($parcels[0]->get_weight())->toEqual(2.0)
        ->and($parcels[0]->has_dimensions())->toBeFalse()
        ->and($parcels[1]->get_tracking_code())->toBe('TRACK-2')
        ->and($parcels[1]->get_weight())->toEqual(2.5)
        ->and($parcels[1]->get_length())->toEqual(40.0)
        ->and($parcels[1]->get_width())->toEqual(30.0)
        ->and($parcels[1]->get_height())->toEqual(20.0)
        ->and($parcels[1]->has_dimensions())->toBeTrue()
        // The reference is the request parcel's internal reference (the
        // order number the builder puts on every parcel).
        ->and($parcels[0]->get_reference())->toBe((string) $order->get_order_number())
        ->and($parcels[1]->get_reference())->toBe((string) $order->get_order_number());

    // Round-trips through the serializable form.
    expect(\Smart_Send\Booking\Booked_Shipment::from_array($booked->to_array())->parcels()[1]->to_array())
        ->toBe($parcels[1]->to_array());
});

it('degrades gracefully when the API answers with a different number of parcels than were booked', function () {
    $product = create_simple_product(['price' => 100, 'weight' => 1]);
    $order   = create_order(['products' => [[$product, 2]], 'shipping_method' => 'postnord_agent']);

    $plan = new \Smart_Send\Delivery\Parcel_Plan();
    $plan->add_spec((new \Smart_Send\Delivery\Parcel_Spec())->add_item($product->get_id(), 1)->set_weight(1.5));
    $plan->add_spec((new \Smart_Send\Delivery\Parcel_Spec())->add_item($product->get_id(), 1)->set_weight(2.5));

    mock_smart_send_api(function () {
        return ss_api_response(200, ['data' => ss_api_shipment_data([
            'shipment_id' => 'shipment-mismatch',
            'parcels'     => [
                ['parcel_internal_id' => 1, 'tracking_code' => 'TRACK-ONLY', 'tracking_link' => null],
            ],
        ])]);
    });

    $entries  = [];
    $previous = \Smart_Send\Support\Logger::$logger;
    \Smart_Send\Support\Logger::$logger = new class ($entries) {
        public function __construct(public array &$entries)
        {
        }

        public function log($level, $message, $context = []): void
        {
            $this->entries[] = ['level' => $level, 'message' => $message];
        }

        public function __call(string $level, array $args): void
        {
            $this->log($level, $args[0], $args[1] ?? []);
        }
    };

    try {
        $booked = (new \Smart_Send\Booking\Booking_Service())->book($order, handmade_details()->set_parcel_plan($plan), false);
    } finally {
        \Smart_Send\Support\Logger::$logger = $previous;
    }

    // The booking stands; only the measures are left out.
    expect($booked->get_shipment_id())->toBe('shipment-mismatch')
        ->and($booked->parcels())->toHaveCount(1)
        ->and($booked->parcels()[0]->get_tracking_code())->toBe('TRACK-ONLY')
        ->and($booked->parcels()[0]->get_weight())->toBeNull()
        ->and($booked->parcels()[0]->get_reference())->toBeNull();

    $warnings = array_column(array_filter($entries, fn ($entry) => 'warning' === $entry['level']), 'message');
    expect($warnings)->toContain('The Smart Send API returned a different number of parcels than were booked - the booked parcels carry no weight, dimensions or reference.');
});
