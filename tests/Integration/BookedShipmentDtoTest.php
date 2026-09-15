<?php

/*
 * Tests for SS_Shipping_Booked_Shipment as the typed booking result (#177):
 * the API v1 create-shipment response is mapped into it by
 * SS_Shipping_Booking_Service (single parcel, multi parcel, missing
 * tracking, return), it round-trips through to_array()/from_array() and
 * PHP serialization, every fulfillment step (shipment id meta, order note,
 * tracking push, order-screen rendering) reads from it, and the rendering
 * handles any number of documents and codes - never one assumed PDF.
 */

/**
 * An order that can have a label generated for it.
 */
function create_bookable_order(array $args = []): WC_Order
{
    $product = create_simple_product(['price' => 100, 'weight' => 1]);

    return create_order(array_merge([
        'products'        => [$product],
        'shipping_method' => 'postnord_agent',
        'shipping_total'  => '39',
    ], $args));
}

function booking_service(): SS_Shipping_Booking_Service
{
    return new SS_Shipping_Booking_Service();
}

/**
 * Book the order through SS_Shipping_Booking_Service::book() with the
 * delivery details the fulfillment service would decide on.
 */
function book_order(WC_Order $order, bool $is_return = false, ?SS_Shipping_Booking_Service $service = null): SS_Shipping_Booked_Shipment
{
    $details = SS_SHIPPING_WC()->fulfillment()->resolve_delivery_details($order, $is_return);

    return ($service ?? booking_service())->book($order, $details, $is_return);
}

/**
 * A fully populated booked shipment, built directly (the shape API v2 will
 * deliver: several documents, a code, several parcels).
 */
function full_booked_shipment(): SS_Shipping_Booked_Shipment
{
    $shipment = new SS_Shipping_Booked_Shipment('ship-full');
    $shipment
        ->set_carrier('gls')
        ->set_service_code('shop')
        ->set_is_return(false)
        ->set_state(SS_Shipping_Booked_Shipment::STATE_BOOKED)
        ->set_booked_at('2026-09-15T10:00:00+00:00')
        ->set_tracking('SHIP-TRACK', 'https://track.example.test/SHIP-TRACK')
        ->add_parcel(new SS_Shipping_Booked_Parcel('p-1', 'PARCEL-1', 'https://track.example.test/PARCEL-1'))
        ->add_parcel(new SS_Shipping_Booked_Parcel('p-2', 'PARCEL-2', null))
        ->add_document(new SS_Shipping_Shipment_Document(SS_Shipping_Shipment_Document::TYPE_LABEL, SS_Shipping_Shipment_Document::FORMAT_PDF, 'https://docs.example.test/label.pdf', 'A4'))
        ->add_document(
            (new SS_Shipping_Shipment_Document(SS_Shipping_Shipment_Document::TYPE_CUSTOMS_DECLARATION, SS_Shipping_Shipment_Document::FORMAT_ZPL, 'https://docs.example.test/customs.zpl', '100x150mm'))
                ->set_local_copy('/var/www/uploads/customs.zpl', 'https://shop.example.test/uploads/customs.zpl')
        )
        ->add_code(new SS_Shipping_Shipment_Code(SS_Shipping_Shipment_Code::TYPE_QR_CODE, 'QR-123', 'https://docs.example.test/qr.png', '2026-10-01T00:00:00+00:00', 'Show this code at the parcel shop'))
        ->add_code(new SS_Shipping_Shipment_Code(SS_Shipping_Shipment_Code::TYPE_LABEL_CODE, 'LC-9'));

    return $shipment;
}

beforeEach(function (): void {
    with_ss_settings();
});

it('maps a single-parcel v1 response into the booked shipment', function () {
    $order = create_bookable_order();
    mock_smart_send_api(function () {
        return ss_api_response(200, ['data' => ss_api_shipment_data(['shipment_id' => 'shipment-single'])]);
    });

    $shipment = book_order($order);

    expect($shipment)->toBeInstanceOf(SS_Shipping_Booked_Shipment::class)
        ->and($shipment->get_shipment_id())->toBe('shipment-single')
        ->and($shipment->get_carrier())->toBe('postnord')
        ->and($shipment->get_service_code())->toBe('agent')
        ->and($shipment->is_return())->toBeFalse()
        ->and($shipment->get_state())->toBe('booked')
        ->and($shipment->get_booked_at())->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/')
        // v1 tracks per parcel: shipment-level tracking is the first parcel's.
        ->and($shipment->get_tracking_code())->toBe('TRACK-1234')
        ->and($shipment->get_tracking_url())->toBe('https://tracking.example.test/TRACK-1234')
        ->and($shipment->parcels())->toHaveCount(1)
        ->and($shipment->parcels()[0]->get_parcel_id())->toBe('1')
        ->and($shipment->parcels()[0]->get_tracking_code())->toBe('TRACK-1234')
        ->and($shipment->parcels()[0]->get_tracking_url())->toBe('https://tracking.example.test/TRACK-1234')
        // Exactly one "label" document in "pdf" format, and no codes.
        ->and($shipment->documents())->toHaveCount(1)
        ->and($shipment->documents()[0]->get_type())->toBe('label')
        ->and($shipment->documents()[0]->get_format())->toBe('pdf')
        ->and($shipment->documents()[0]->get_layout())->toBeNull()
        ->and($shipment->documents()[0]->get_url())->toBe('https://api.example.test/labels/label.pdf')
        ->and($shipment->documents()[0]->has_local_copy())->toBeFalse()
        ->and($shipment->label_document())->toBe($shipment->documents()[0])
        ->and($shipment->codes())->toBe([]);

    // The inline PDF bytes v1 delivers ride on the document as the
    // transient v1 bridge: never in to_array(), never serialized.
    $label = $shipment->documents()[0];
    expect($label->get_inline_content())->toBe(base64_encode('%PDF-fake'))
        ->and($label->to_array())->not->toHaveKey('inline_content')
        ->and($shipment->to_array())->not->toHaveKey('pdf')
        ->and(unserialize(serialize($label))->get_inline_content())->toBeNull()
        ->and(unserialize(serialize($label))->to_array())->toBe($label->to_array())
        ->and(SS_Shipping_Shipment_Document::from_array($label->to_array())->get_inline_content())->toBeNull();
});

it('maps every parcel of a multi-parcel v1 response and keeps the outputs on the shipment', function () {
    $order = create_bookable_order();
    mock_smart_send_api(function () {
        return ss_api_response(200, ['data' => ss_api_shipment_data([
            'shipment_id' => 'shipment-multi',
            'parcels'     => [
                ['parcel_internal_id' => 11, 'tracking_code' => 'MULTI-1', 'tracking_link' => 'https://tracking.example.test/MULTI-1'],
                ['parcel_internal_id' => 12, 'tracking_code' => 'MULTI-2', 'tracking_link' => 'https://tracking.example.test/MULTI-2'],
                ['parcel_internal_id' => 13, 'tracking_code' => 'MULTI-3', 'tracking_link' => 'https://tracking.example.test/MULTI-3'],
            ],
        ])]);
    });

    $shipment = book_order($order);

    expect($shipment->parcels())->toHaveCount(3)
        ->and(array_map(fn (SS_Shipping_Booked_Parcel $p) => $p->get_parcel_id(), $shipment->parcels()))->toBe(['11', '12', '13'])
        ->and(array_map(fn (SS_Shipping_Booked_Parcel $p) => $p->get_tracking_code(), $shipment->parcels()))->toBe(['MULTI-1', 'MULTI-2', 'MULTI-3'])
        ->and($shipment->get_tracking_code())->toBe('MULTI-1')
        // Three parcels, still ONE combined label document.
        ->and($shipment->documents())->toHaveCount(1)
        ->and($shipment->codes())->toBe([]);
});

it('maps missing tracking to nulls and prefers shipment-level tracking when the API gives it', function () {
    $order = create_bookable_order();

    $responses = [
        ss_api_shipment_data([
            'shipment_id' => 'shipment-no-tracking',
            'parcels'     => [['parcel_internal_id' => 1]],
        ]),
        ss_api_shipment_data([
            'shipment_id'   => 'shipment-level-tracking',
            'tracking_code' => 'LEVEL-1',
            'tracking_link' => 'https://tracking.example.test/LEVEL-1',
            'parcels'       => [['parcel_internal_id' => 1, 'tracking_code' => 'PARCEL-1', 'tracking_link' => 'https://tracking.example.test/PARCEL-1']],
        ]),
        ss_api_shipment_data([
            'shipment_id' => 'shipment-no-parcels',
            'parcels'     => [],
        ]),
    ];
    mock_smart_send_api(function () use (&$responses) {
        return ss_api_response(200, ['data' => array_shift($responses)]);
    });

    $service = booking_service();

    $no_tracking = book_order($order, false, $service);
    expect($no_tracking->parcels())->toHaveCount(1)
        ->and($no_tracking->parcels()[0]->get_tracking_code())->toBeNull()
        ->and($no_tracking->parcels()[0]->get_tracking_url())->toBeNull()
        ->and($no_tracking->get_tracking_code())->toBeNull()
        ->and($no_tracking->get_tracking_url())->toBeNull();

    $level = book_order($order, false, $service);
    expect($level->get_tracking_code())->toBe('LEVEL-1')
        ->and($level->get_tracking_url())->toBe('https://tracking.example.test/LEVEL-1')
        ->and($level->parcels()[0]->get_tracking_code())->toBe('PARCEL-1');

    $no_parcels = book_order($order, false, $service);
    expect($no_parcels->parcels())->toBe([])
        ->and($no_parcels->get_tracking_code())->toBeNull();
});

it('maps a return booking with the return flag and the return method, falling back to the requested carrier', function () {
    $order = create_bookable_order();
    mock_smart_send_api(function () {
        // A response that does not repeat the carrier code.
        $data = ss_api_shipment_data(['shipment_id' => 'shipment-return']);
        unset($data['carrier_code']);

        return ss_api_response(200, ['data' => $data]);
    });

    $shipment = book_order($order, true);

    expect($shipment->get_shipment_id())->toBe('shipment-return')
        ->and($shipment->is_return())->toBeTrue()
        ->and($shipment->get_service_code())->toBe('returndropoff')
        ->and($shipment->get_carrier())->toBe('postnord')
        ->and($shipment->documents())->toHaveCount(1)
        ->and($shipment->to_array()['is_return'])->toBeTrue();
});

it('round-trips through to_array() / from_array() and PHP serialization', function () {
    $shipment = full_booked_shipment();
    $array    = $shipment->to_array();

    expect(array_keys($array))->toBe(['shipment_id', 'carrier', 'service_code', 'is_return', 'state', 'booked_at', 'tracking_code', 'tracking_url', 'parcels', 'documents', 'codes'])
        ->and($array['parcels'][1])->toBe(['parcel_id' => 'p-2', 'tracking_code' => 'PARCEL-2', 'tracking_url' => null])
        ->and($array['documents'][1])->toBe([
            'type'       => 'customs_declaration',
            'format'     => 'zpl',
            'layout'     => '100x150mm',
            'url'        => 'https://docs.example.test/customs.zpl',
            'local_path' => '/var/www/uploads/customs.zpl',
            'local_url'  => 'https://shop.example.test/uploads/customs.zpl',
        ])
        ->and($array['codes'][0])->toBe([
            'type'         => 'qr_code',
            'value'        => 'QR-123',
            'image_url'    => 'https://docs.example.test/qr.png',
            'expires_at'   => '2026-10-01T00:00:00+00:00',
            'instructions' => 'Show this code at the parcel shop',
        ]);

    $rebuilt = SS_Shipping_Booked_Shipment::from_array($array);
    expect($rebuilt)->not->toBe($shipment)
        ->and($rebuilt->to_array())->toBe($array)
        ->and($rebuilt->documents()[1]->download_url())->toBe('https://shop.example.test/uploads/customs.zpl')
        ->and($rebuilt->documents()[0]->download_url())->toBe('https://docs.example.test/label.pdf')
        ->and($rebuilt->documents_of_type('customs_declaration'))->toHaveCount(1)
        ->and($rebuilt->label_document()->get_layout())->toBe('A4')
        ->and($rebuilt->codes()[1]->get_image_url())->toBeNull();

    // JSON (what a queue or the AJAX response would carry) and PHP serialization.
    expect(SS_Shipping_Booked_Shipment::from_array(json_decode(json_encode($array), true))->to_array())->toBe($array)
        ->and(unserialize(serialize($shipment))->to_array())->toBe($array);

    // A minimal array is enough.
    $minimal = SS_Shipping_Booked_Shipment::from_array(['shipment_id' => 'min']);
    expect($minimal->get_shipment_id())->toBe('min')
        ->and($minimal->get_state())->toBe('booked')
        ->and($minimal->is_return())->toBeFalse()
        ->and($minimal->parcels())->toBe([])
        ->and($minimal->documents())->toBe([])
        ->and($minimal->codes())->toBe([])
        ->and($minimal->label_document())->toBeNull();
});

it('renders every document and every code for the order screen, not one assumed PDF', function () {
    $html = SS_SHIPPING_WC()->fulfillment()->get_shipment_outputs_html(full_booked_shipment());

    expect($html)->toBe(
        '<a href="https://docs.example.test/label.pdf" target="_blank">Download shipping label</a>'
        . '<br><a href="https://shop.example.test/uploads/customs.zpl" target="_blank">Download customs declaration (ZPL)</a>'
        . '<br><label>QR code: </label><strong>QR-123</strong><br><img src="https://docs.example.test/qr.png" alt="QR code"><br>Show this code at the parcel shop'
        . '<br><label>Label code: </label><strong>LC-9</strong>'
    );

    // The bulk notice joins the same outputs inline.
    expect(SS_SHIPPING_WC()->fulfillment()->get_shipment_outputs_html(full_booked_shipment(), ', '))
        ->toStartWith('<a href="https://docs.example.test/label.pdf" target="_blank">Download shipping label</a>, <a href=');

    // A return label keeps its own historic link texts: the meta box's short
    // one and the bulk notice's / order note's long one.
    $return_shipment = full_booked_shipment()->set_is_return(true);
    expect(SS_SHIPPING_WC()->fulfillment()->get_shipment_outputs_html($return_shipment))
        ->toContain('>Download return label<')
        ->and(SS_SHIPPING_WC()->fulfillment()->get_shipment_outputs_html($return_shipment, ', ', false))
        ->toContain('>Download return shipping label<');
});

it('stores the shipment id under the meta key matching the shipment direction', function () {
    $order = create_bookable_order();
    $ids   = SS_SHIPPING_WC()->shipment_ids();

    $ids->save_booked($order, new SS_Shipping_Booked_Shipment('outbound-id'));
    $ids->save_booked($order, (new SS_Shipping_Booked_Shipment('return-id'))->set_is_return(true));

    $fresh = wc_get_order($order->get_id());
    expect($fresh->get_meta('_ss_shipping_label_id', true))->toBe('outbound-id')
        ->and($fresh->get_meta('_ss_shipping_return_label_id', true))->toBe('return-id')
        ->and($ids->get($fresh, false))->toBe('outbound-id')
        ->and($ids->get($fresh, true))->toBe('return-id');
});

it('writes the order note, tracking push and shipment id meta from the DTO byte for byte as before', function () {
    $order = create_bookable_order();
    $calls = &shipment_tracking_calls();
    mock_smart_send_api(function () {
        return ss_api_response(200, ['data' => ss_api_shipment_data([
            'shipment_id' => 'shipment-pinned',
            'parcels'     => [
                ['parcel_internal_id' => 1, 'tracking_code' => 'PIN-1', 'tracking_link' => 'https://tracking.example.test/PIN-1'],
                ['parcel_internal_id' => 2, 'tracking_code' => 'PIN-2', 'tracking_link' => 'https://tracking.example.test/PIN-2'],
            ],
        ])]);
    });

    $result = SS_SHIPPING_WC()->fulfillment()->fulfill_outbound($order->get_id());

    expect($result->is_successful())->toBeTrue();

    // Order note: the historic single-label format, one tracking line per parcel.
    $expected_note = '<label>Shipping label: </label><a href="https://api.example.test/labels/label.pdf" target="_blank">Download shipping label</a>'
        . '<br><label>Tracking number: </label><a href="https://tracking.example.test/PIN-1" target="_blank">PIN-1</a>'
        . '<br><label>Tracking number: </label><a href="https://tracking.example.test/PIN-2" target="_blank">PIN-2</a>';
    $notes = wc_get_order_notes(['order_id' => $order->get_id()]);
    expect(wp_list_pluck($notes, 'content'))->toContain($expected_note)
        ->and($result->get_order_note($result->get_outbound_shipment()))->toBe($expected_note);

    // Shipment Tracking push: one per parcel, carrier display name as provider.
    expect($calls)->toBe([
        [$order->get_id(), 'PIN-1', 'PostNord', null, 'https://tracking.example.test/PIN-1'],
        [$order->get_id(), 'PIN-2', 'PostNord', null, 'https://tracking.example.test/PIN-2'],
    ]);

    // Shipment id meta.
    expect(wc_get_order($order->get_id())->get_meta('_ss_shipping_label_id', true))->toBe('shipment-pinned');

    // Return labels never push tracking.
    $calls = &shipment_tracking_calls();
    $return_result = SS_SHIPPING_WC()->fulfillment()->fulfill_return($order->get_id());
    expect($return_result->is_successful())->toBeTrue()
        ->and($calls)->toBe([])
        ->and($return_result->get_order_note($return_result->get_return_shipment()))
            ->toStartWith('<label>Return shipping label: </label><a href="https://api.example.test/labels/label.pdf" target="_blank">Download return shipping label</a>');
});
