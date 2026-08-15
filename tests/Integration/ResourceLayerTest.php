<?php

/*
 * Tests for the #112 Client/Resource split of the Smart Send API client:
 * Smartsend\Api now exposes focused resource accessors (bookings(),
 * pickupPoints()) instead of one monolithic method-per-endpoint surface.
 * Each resource method is exercised here directly (success + one error
 * pass-through per resource, proving the Response object surfaces the
 * #38/#85 error taxonomy - the taxonomy itself is covered exhaustively
 * by HttpClientErrorTest), proving the split preserved both the wire
 * format and the error handling - complementary to the wire-format
 * golden tests in ShipmentPayloadTest.
 */

use Smartsend\Api;
use Smartsend\Models\Error;
use Smartsend\Models\Shipment;
use Smartsend\Resources\BookingResource;
use Smartsend\Resources\PickupPointResource;

/**
 * A minimal but fully-shaped internal shipment representation (the plain
 * array shape used to fill a SS_Shipping_Shipment value object below). See
 * ShipmentPayloadTest for the exhaustive, order-driven coverage of every
 * field.
 */
function sample_representation_data(array $overrides = []): array
{
    return array_replace_recursive([
        'internal_id'         => '123',
        'internal_reference'  => '123',
        'shipping_carrier'    => 'postnord',
        'shipping_method'     => 'agent',
        'shipping_date'       => date('Y-m-d'),
        'receiver'            => [
            'company'       => null,
            'name_line1'    => 'Test',
            'name_line2'    => 'Customer',
            'address_line1' => 'Islands Brygge 39',
            'address_line2' => null,
            'postal_code'   => '2300',
            'city'          => 'Copenhagen',
            'country'       => 'DK',
            'phone'         => '+4512345678',
            'email'         => 'integration-test@smartsend.io',
        ],
        'pickup_point'        => null,
        'parcels'             => [
            [
                'internal_id'        => '123',
                'internal_reference' => '123',
                'weight'             => 1.5,
                'height'             => null,
                'width'              => null,
                'length'             => null,
                'freetext'           => null,
                'items'              => [
                    [
                        'id'                => '456',
                        'sku'               => 'SKU-1',
                        'name'              => 'Sample Product',
                        'description'       => null,
                        'hs_code'           => null,
                        'country_of_origin' => null,
                        'unit_weight'       => 1.5,
                        'quantity'          => 1,
                        'total_net_amount'  => 100,
                        'total_tax_amount'  => null,
                    ],
                ],
                'total_net_amount' => 100,
                'total_tax_amount' => null,
            ],
        ],
        'subtotal_net_amount' => 100,
        'subtotal_tax_amount' => null,
        'shipping_net_amount' => 39,
        'shipping_tax_amount' => null,
        'total_net_amount'    => 139,
        'total_tax_amount'    => null,
        'currency'            => 'DKK',
    ], $overrides);
}

/**
 * A minimal but fully-shaped SS_Shipping_Shipment, the internal
 * representation SS_Shipping_Shipment_Builder returns. See
 * ShipmentPayloadTest for the exhaustive, order-driven coverage of every
 * field.
 */
function sample_representation(array $overrides = []): SS_Shipping_Shipment
{
    $data = sample_representation_data($overrides);

    // Parcels are typed SS_Shipping_Parcel value objects (#139).
    $parcels = array_map(function (array $parcel_row): SS_Shipping_Parcel {
        $parcel = new SS_Shipping_Parcel();
        $parcel->set_internal_id($parcel_row['internal_id'])
            ->set_internal_reference($parcel_row['internal_reference'])
            ->set_weight($parcel_row['weight'])
            ->set_height($parcel_row['height'])
            ->set_width($parcel_row['width'])
            ->set_length($parcel_row['length'])
            ->set_freetext($parcel_row['freetext'])
            ->set_items($parcel_row['items'])
            ->set_total_net_amount($parcel_row['total_net_amount'])
            ->set_total_tax_amount($parcel_row['total_tax_amount']);

        return $parcel;
    }, $data['parcels']);

    $shipment = new SS_Shipping_Shipment();
    $shipment->set_internal_id($data['internal_id'])
        ->set_internal_reference($data['internal_reference'])
        ->set_shipping_carrier($data['shipping_carrier'])
        ->set_shipping_method($data['shipping_method'])
        ->set_shipping_date($data['shipping_date'])
        ->set_receiver($data['receiver'])
        ->set_pickup_point($data['pickup_point'])
        ->set_parcels($parcels)
        ->set_subtotal_net_amount($data['subtotal_net_amount'])
        ->set_subtotal_tax_amount($data['subtotal_tax_amount'])
        ->set_shipping_net_amount($data['shipping_net_amount'])
        ->set_shipping_tax_amount($data['shipping_tax_amount'])
        ->set_total_net_amount($data['total_net_amount'])
        ->set_total_tax_amount($data['total_tax_amount'])
        ->set_currency($data['currency']);

    return $shipment;
}

function pickup_point_api_data(): array
{
    return [
        'id'            => 7,
        'agent_no'      => '1234',
        'company'       => 'Corner Shop',
        'address_line1' => 'Main Street 1',
        'address_line2' => null,
        'postal_code'   => '2300',
        'city'          => 'Copenhagen',
        'country'       => 'DK',
        'distance'      => 0.5,
    ];
}

beforeEach(function (): void {
    with_ss_settings();
});

it('accepts a Shipment through the client and returns a successful Response', function () {
    $api = new Api('secret-token-123', 'example.test', true);
    $capture = mock_smart_send_api();

    $resource = $api->bookings();
    expect($resource)->toBeInstanceOf(BookingResource::class)
        // The accessor is memoized: the same instance is returned every call.
        ->and($api->bookings())->toBe($resource);

    $shipment = $resource->fromShipment(sample_representation());
    expect($resource->create($shipment)->isSuccessful())->toBeTrue();

    $request = end($capture->requests);
    expect($request['url'])->toContain('shipments/labels');
    expect($request['url'])->not->toContain('shipments/labels/combine');
});

it('surfaces the existing error taxonomy through BookingResource::create()', function () {
    $api = new Api('secret-token-123', 'example.test', true);
    mock_smart_send_api(function () {
        return ss_api_response(422, ss_api_error_body('The receiver postal code is invalid.'));
    });

    $shipment = $api->bookings()->fromShipment(sample_representation());
    $response = $api->bookings()->create($shipment);

    expect($response->isSuccessful())->toBeFalse();

    $error = $response->error();
    expect($error)->toBeInstanceOf(Error::class);
    expect($error->code)->toBe('ValidationException');
    expect($error->message)->toBe('The receiver postal code is invalid.');
});

it('builds the v1 wire Shipment model from the internal representation (BookingResource::fromShipment)', function () {
    $api = new Api('secret-token-123', 'example.test', true);

    $shipment = $api->bookings()->fromShipment(sample_representation());

    expect($shipment)->toBeInstanceOf(Shipment::class);

    $payload = json_decode(json_encode($shipment), true);
    expect($payload['internal_id'])->toBe('123');
    expect($payload['shipping_carrier'])->toBe('postnord');
    expect($payload['receiver']['name_line1'])->toBe('Test');
    expect($payload['parcels'][0]['items'][0]['sku'])->toBe('SKU-1');
    expect($payload['subtotal_price_excluding_tax'])->toBe(100);
    expect($payload['total_price_excluding_tax'])->toBe(139);
});

it('combines labels for multiple shipments into a single request body', function () {
    $api = new Api('secret-token-123', 'example.test', true);
    $capture = mock_smart_send_api();

    $response = $api->bookings()->combine(['shipment-1', 'shipment-2']);

    expect($response->isSuccessful())->toBeTrue();
    $request = end($capture->requests);
    expect($request['url'])->toContain('shipments/labels/combine');

    $body = json_decode($request['body'], true);
    expect($body)->toBe([
        'shipments' => [
            ['shipment_id' => 'shipment-1'],
            ['shipment_id' => 'shipment-2'],
        ],
    ]);
});

it('looks up a pickup point by agent number', function () {
    $api = new Api('secret-token-123', 'example.test', true);
    $capture = mock_smart_send_api(function () {
        return ss_api_response(200, ['data' => pickup_point_api_data()]);
    });

    $resource = $api->pickupPoints();
    expect($resource)->toBeInstanceOf(PickupPointResource::class)
        ->and($api->pickupPoints())->toBe($resource);

    $response = $resource->findByAgentNo('postnord', 'DK', '1234');

    expect($response->isSuccessful())->toBeTrue();
    expect($response->data()->agent_no)->toBe('1234');

    $request = end($capture->requests);
    expect($request['url'])->toContain('agents/carrier/postnord/country/DK/agentno/1234');
});

it('surfaces the existing error taxonomy through PickupPointResource::findByAgentNo()', function () {
    $api = new Api('secret-token-123', 'example.test', true);
    mock_smart_send_api(function () {
        return ss_api_response(404, ss_api_error_body('Agent number not found.'));
    });

    $response = $api->pickupPoints()->findByAgentNo('postnord', 'DK', '9999');

    expect($response->isSuccessful())->toBeFalse();
    expect($response->error())->toBeInstanceOf(Error::class);
    expect($response->error()->message)->toBe('Agent number not found.');
});

it('finds the closest pickup points to an address, including the city segment', function () {
    $api = new Api('secret-token-123', 'example.test', true);
    $capture = mock_smart_send_api(function () {
        return ss_api_response(200, ['data' => [pickup_point_api_data()]]);
    });

    $response = $api->pickupPoints()->findClosestByAddress('postnord', 'DK', '2300', 'Copenhagen', 'Islands Brygge 39');

    expect($response->isSuccessful())->toBeTrue();
    expect($response->data())->toHaveCount(1);

    $request = end($capture->requests);
    expect($request['url'])->toContain('agents/closest/carrier/postnord/country/DK/postalcode/2300/city/Copenhagen/street/Islands Brygge 39');
});

it('omits the city segment from the closest-address lookup when no city is given', function () {
    $api = new Api('secret-token-123', 'example.test', true);
    $capture = mock_smart_send_api(function () {
        return ss_api_response(200, ['data' => [pickup_point_api_data()]]);
    });

    $api->pickupPoints()->findClosestByAddress('postnord', 'DK', '2300', null, 'Islands Brygge 39');

    $request = end($capture->requests);
    expect($request['url'])->toContain('agents/closest/carrier/postnord/country/DK/postalcode/2300/street/Islands Brygge 39');
    expect($request['url'])->not->toContain('/city/');
});

it('applies the pickup point lookup timeout to both pickup point resource calls', function () {
    $api = new Api('secret-token-123', 'example.test', true);
    $capture = mock_smart_send_api(function () {
        return ss_api_response(200, ['data' => pickup_point_api_data()]);
    });

    with_smart_send_pickup_point_timeout_filter(2.5);

    $api->pickupPoints()->findByAgentNo('postnord', 'DK', '1234');

    $request = end($capture->requests);
    expect($request['timeout'])->toBe(2.5);
});

/**
 * Add a smart_send_pickup_point_timeout filter callback, removed after the
 * test.
 */
function with_smart_send_pickup_point_timeout_filter(float $timeout): void
{
    $callback = function () use ($timeout) {
        return $timeout;
    };

    add_filter('smart_send_pickup_point_timeout', $callback);

    remember_cleanup_callback(function () use ($callback): void {
        remove_filter('smart_send_pickup_point_timeout', $callback);
    });
}
