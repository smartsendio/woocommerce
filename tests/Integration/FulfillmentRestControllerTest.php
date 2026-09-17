<?php

/*
 * Tests for the fulfillment REST controller (#182 PR 2,
 * \Smart_Send\Admin\Fulfillment_REST_Controller): the smart-send/v1 routes below
 * orders/{id}, their permission callback (capability + order existence),
 * the request schema (generated from request_schema(), which must mirror
 * \Smart_Send\Delivery\Delivery_Details::from_array()), the request-level error
 * codes, the mapping of a JSON body onto the booking through the mocked
 * API, the with_return semantics and the response shape (section 3.2 of
 * the issue) - driven in-process through WP_REST_Request + rest_do_request()
 * like BlockStoreApiTest.php.
 */

/**
 * Run the rest of the test as a user with (or without) the edit_shop_orders
 * capability; the previous user is restored afterwards.
 */
function as_rest_user(string $role = 'shop_manager'): int
{
    $previous = get_current_user_id();
    $user_id  = wp_insert_user([
        'user_login' => 'ss-rest-' . uniqid(),
        'user_pass'  => wp_generate_password(),
        'role'       => $role,
    ]);
    expect($user_id)->toBeInt();

    remember_cleanup_callback(function () use ($user_id, $previous): void {
        wp_set_current_user($previous);
        require_once ABSPATH . 'wp-admin/includes/user.php';
        wp_delete_user($user_id);
    });

    wp_set_current_user($user_id);

    return $user_id;
}

function fulfillment_get(int $order_id): WP_REST_Response
{
    return rest_do_request(new WP_REST_Request('GET', '/smart-send/v1/orders/' . $order_id . '/fulfillment'));
}

function fulfillment_post(int $order_id, array $body): WP_REST_Response
{
    $request = new WP_REST_Request('POST', '/smart-send/v1/orders/' . $order_id . '/fulfillment');
    $request->set_header('Content-Type', 'application/json');
    $request->set_body(wp_json_encode($body));

    return rest_do_request($request);
}

function pickup_point_lookup_get(int $order_id, string $agent_no, ?string $method = null): WP_REST_Response
{
    $request = new WP_REST_Request('GET', '/smart-send/v1/orders/' . $order_id . '/pickup-points/' . $agent_no);
    if ($method !== null) {
        $request->set_param('method', $method);
    }

    return rest_do_request($request);
}

/**
 * An order that can have a label generated for it.
 */
function create_rest_order(array $args = []): WC_Order
{
    $product = create_simple_product(['price' => 100, 'weight' => 1]);

    return create_order(array_merge([
        'products'        => [$product],
        'shipping_method' => 'postnord_agent',
        'shipping_total'  => '39',
    ], $args));
}

/**
 * The mocked API answering the agent lookup with the given agent and every
 * booking with a success.
 */
function mock_api_with_agent_lookup(?object $agent): object
{
    return mock_smart_send_api(function ($url) use ($agent) {
        if (strpos($url, 'agents/') !== false) {
            return $agent === null
                ? ss_api_response(404, ['code' => 'NoResults', 'message' => 'The agent was not found.'])
                : ss_api_response(200, ['data' => $agent]);
        }

        return ss_api_response(200, ['data' => ss_api_shipment_data()]);
    });
}

beforeEach(function (): void {
    with_ss_settings();
});

it('registers the fulfillment and pickup point routes under smart-send/v1', function () {
    $routes = rest_get_server()->get_routes('smart-send/v1');

    expect($routes)->toHaveKey('/smart-send/v1/orders/(?P<id>\d+)/fulfillment')
        ->and($routes)->toHaveKey('/smart-send/v1/orders/(?P<id>\d+)/pickup-points/(?P<agent_no>[^/]+)');

    $methods = array_map(function (array $endpoint): array {
        return array_keys($endpoint['methods']);
    }, $routes['/smart-send/v1/orders/(?P<id>\d+)/fulfillment']);

    expect(array_merge(...$methods))->toContain('GET')->toContain('POST');

    expect(has_action('rest_api_init', [SS_SHIPPING_WC()->fulfillment_controller(), 'register_routes']))->not->toBeFalse();
});

it('pins that every request-schema property round-trips through the delivery details DTO', function () {
    $schema = SS_SHIPPING_WC()->fulfillment_controller()->request_schema();

    // Top-level request shape (section 3.1).
    expect(array_keys($schema))->toBe(['flow', 'with_return', 'return_method', 'confirm_rebook', 'delivery_details'])
        ->and($schema['flow']['enum'])->toBe(['outbound', 'return']);

    $details_properties = $schema['delivery_details']['properties'];
    $spec_properties    = $details_properties['parcel_plan']['properties']['specs']['items']['properties'];
    $item_properties    = $spec_properties['items']['items']['properties'];

    // Property for property, the schema mirrors the DTOs' to_array() keys.
    expect(array_keys($details_properties))->toBe(array_keys((new \Smart_Send\Delivery\Delivery_Details())->to_array()))
        ->and(array_keys($details_properties['parcel_plan']['properties']))->toBe(array_keys((new \Smart_Send\Delivery\Parcel_Plan())->to_array()))
        ->and(array_keys($spec_properties))->toBe(array_keys((new \Smart_Send\Delivery\Parcel_Spec())->to_array()))
        ->and(array_keys($item_properties))->toBe(array_keys((new \Smart_Send\Delivery\Parcel_Spec())->add_item(1)->get_items()[0]));

    // A payload using every schema property survives from_array()->to_array()
    // byte for byte (the DTO types scalars: reference string, dimensions float).
    $payload = [
        'shipping_method' => 'postnord_agent',
        'pickup_point'    => ['agent_no' => '1234'],
        'parcel_plan'     => [
            'specs' => [
                ['reference' => '1', 'weight' => null, 'length' => 40.0, 'width' => 30.0, 'height' => 20.0, 'items' => [['id' => 812, 'quantity' => 2, 'name' => 'Hoodie']]],
                ['reference' => '2', 'weight' => 3.5, 'length' => null, 'width' => null, 'height' => null, 'items' => []],
            ],
        ],
        'addons'          => [],
    ];

    expect(rest_validate_value_from_schema($payload, $schema['delivery_details'], 'delivery_details'))->toBeTrue()
        ->and(\Smart_Send\Delivery\Delivery_Details::from_array($payload)->to_array())->toBe($payload);

    $cleared = ['pickup_point' => ['clear' => true]];
    expect(rest_validate_value_from_schema($cleared, $schema['delivery_details'], 'delivery_details'))->toBeTrue()
        ->and(\Smart_Send\Delivery\Delivery_Details::from_array($cleared)->is_pickup_point_cleared())->toBeTrue();
});

it('rejects a shop customer with 403 on every route', function () {
    $order = create_rest_order();
    as_rest_user('customer');

    $get = fulfillment_get($order->get_id());
    expect($get->get_status())->toBe(403)
        ->and($get->get_data()['code'])->toBe('rest_forbidden');

    $post = fulfillment_post($order->get_id(), ['flow' => 'outbound']);
    expect($post->get_status())->toBe(403)
        ->and($post->get_data()['code'])->toBe('rest_forbidden');

    $lookup = pickup_point_lookup_get($order->get_id(), '1234');
    expect($lookup->get_status())->toBe(403)
        ->and($lookup->get_data()['code'])->toBe('rest_forbidden');
});

it('returns 404 smart_send_order_not_found for an unknown order', function () {
    as_rest_user();

    $response = fulfillment_get(999999999);

    expect($response->get_status())->toBe(404)
        ->and($response->get_data()['code'])->toBe('smart_send_order_not_found');
});

it('returns the state on GET, matching the presenter', function () {
    $order = create_rest_order(['auto_return' => 'yes']);
    save_order_pickup_point($order->get_id(), sample_agent());
    as_rest_user();

    $response = fulfillment_get($order->get_id());

    expect($response->get_status())->toBe(200);

    $state = $response->get_data();
    expect($state)->toHaveKeys(['order_id', 'connected', 'screen', 'order', 'delivery_details', 'methods', 'return', 'outbound_shipment', 'return_shipment', 'debug', 'urls'])
        ->and($state['order_id'])->toBe($order->get_id())
        ->and($state['connected'])->toBeTrue()
        ->and($state['screen'])->toBeIn(['hpos', 'legacy'])
        ->and($state['order']['weight_kg'])->toBe(1.0)
        ->and($state['order']['shipping_country'])->toBe('DK')
        ->and($state['order']['units'])->toHaveCount(1)
        ->and($state['order']['units'][0]['unit_weight'])->toBe(1.0)
        ->and($state['order']['units'][0])->toHaveKey('sku')
        ->and($state['delivery_details']['shipping_method'])->toBe('postnord_agent')
        ->and($state['delivery_details']['pickup_point']['agent_no'])->toBe('1234')
        ->and($state['delivery_details']['pickup_point']['display_html'])->toContain('Corner Shop')
        ->and($state['delivery_details']['parcel_plan'])->toBeNull()
        ->and($state['methods']['outbound'][0]['code'])->toBe('postnord')
        ->and($state['methods']['outbound'][0]['name'])->toBe('PostNord')
        ->and($state['methods']['outbound'][0]['services'][0])->toBe(['code' => 'agent', 'name' => 'PostNord: Select pickup point (MyPack Collect)', 'addons' => []])
        ->and($state['methods']['return'][0]['services'][0]['code'])->toBe('returndropoff')
        ->and($state['return'])->toBe(['method' => 'postnord_returndropoff', 'auto_default' => true, 'uses_stored_pickup_point' => false])
        ->and($state['outbound_shipment'])->toBeNull()
        ->and($state['return_shipment'])->toBeNull()
        ->and($state['debug']['shipping_items'])->toBe(['smart_send_shipping:1'])
        ->and($state['urls']['rest'])->toBe('/smart-send/v1/orders/' . $order->get_id() . '/fulfillment')
        ->and($state['urls']['settings'])->toContain('section=smart_send_shipping');

    expect($state)->toBe(SS_SHIPPING_WC()->fulfillment_presenter()->state(wc_get_order($order->get_id())));
});

it('rejects a bad flow, a non-numeric weight, a parcel without items and an item id not on the order with 400 rest_invalid_param', function () {
    $order = create_rest_order();
    as_rest_user();
    $capture = mock_smart_send_api();

    $bad_flow = fulfillment_post($order->get_id(), ['flow' => 'sideways']);
    expect($bad_flow->get_status())->toBe(400)
        ->and($bad_flow->get_data()['code'])->toBe('rest_invalid_param')
        ->and($bad_flow->get_data()['data']['params'])->toHaveKey('flow');

    $bad_weight = fulfillment_post($order->get_id(), [
        'flow'             => 'outbound',
        'delivery_details' => ['parcel_plan' => ['specs' => [['weight' => 'heavy', 'items' => []]]]],
    ]);
    expect($bad_weight->get_status())->toBe(400)
        ->and($bad_weight->get_data()['code'])->toBe('rest_invalid_param')
        ->and($bad_weight->get_data()['data']['params'])->toHaveKey('delivery_details')
        ->and($bad_weight->get_data()['data']['params']['delivery_details'])->toContain('weight');

    // A parcel without items: the meta box can no longer produce one (an
    // emptied box is removed), so the schema path rejects it - the DTO and
    // the smart_send_delivery_details filter path still allow box-only specs.
    $no_items = fulfillment_post($order->get_id(), [
        'flow'             => 'outbound',
        'delivery_details' => ['parcel_plan' => ['specs' => [
            ['weight' => 1.5, 'items' => [['id' => $order->get_items()[array_key_first($order->get_items())]->get_product_id(), 'quantity' => 1]]],
            ['weight' => 2.5, 'items' => []],
        ]]],
    ]);
    expect($no_items->get_status())->toBe(400)
        ->and($no_items->get_data()['code'])->toBe('rest_invalid_param')
        ->and($no_items->get_data()['data']['params']['delivery_details'])->toContain('parcel_plan.specs[1].items: A parcel must contain at least one item.');

    $unknown_item = fulfillment_post($order->get_id(), [
        'flow'             => 'outbound',
        'delivery_details' => ['parcel_plan' => ['specs' => [['items' => [['id' => 999999, 'quantity' => 1]]]]]],
    ]);
    expect($unknown_item->get_status())->toBe(400)
        ->and($unknown_item->get_data()['code'])->toBe('rest_invalid_param')
        ->and($unknown_item->get_data()['data']['params']['delivery_details'])->toContain('parcel_plan.specs[0].items[0].id')
        ->toContain('999999');

    // Nothing reached the API.
    expect($capture->requests)->toBe([]);
});

it('books from the JSON body: the delivery details reach the booking payload and get persisted, and the response carries the run plus the state', function () {
    $product_a = create_simple_product(['name' => 'Rest Box One', 'price' => 100, 'weight' => 1]);
    $product_b = create_simple_product(['name' => 'Rest Box Two', 'price' => 50, 'weight' => 2]);
    $order     = create_order([
        'products'        => [$product_a, $product_b],
        'shipping_method' => 'postnord_agent',
        'shipping_total'  => '39',
    ]);
    save_order_pickup_point($order->get_id(), sample_agent());
    as_rest_user();

    // One response parcel per request parcel, as the API answers a split.
    $capture = mock_smart_send_api(function () {
        return ss_api_response(200, ['data' => ss_api_shipment_data([
            'shipment_id' => 'rest-shipment-1',
            'parcels'     => [
                ['parcel_internal_id' => 1, 'tracking_code' => 'TRACK-1', 'tracking_link' => 'https://tracking.example.test/1'],
                ['parcel_internal_id' => 2, 'tracking_code' => 'TRACK-2', 'tracking_link' => 'https://tracking.example.test/2'],
            ],
        ])]);
    });

    $response = fulfillment_post($order->get_id(), [
        'flow'             => 'outbound',
        'with_return'      => false,
        'delivery_details' => [
            'shipping_method' => 'postnord_homedelivery',
            'parcel_plan'     => [
                'specs' => [
                    ['reference' => '1', 'weight' => null, 'items' => [['id' => $product_a->get_id(), 'quantity' => 1]]],
                    ['reference' => '2', 'weight' => 7.5, 'length' => 40, 'width' => 30, 'height' => 20, 'items' => [['id' => $product_b->get_id(), 'quantity' => 1]]],
                ],
            ],
        ],
    ]);

    expect($response->get_status())->toBe(200);

    // The booking reflects the submitted method and plan (the server filled
    // the item names from the order lines).
    expect($capture->requests)->toHaveCount(1);
    $payload = json_decode($capture->requests[0]['body'], true);
    expect($payload['shipping_method'])->toBe('homedelivery')
        ->and($payload['parcels'])->toHaveCount(2)
        ->and($payload['parcels'][0]['items'][0]['name'])->toBe('Rest Box One')
        ->and((float) $payload['parcels'][0]['weight'])->toBe(1.0)
        ->and((float) $payload['parcels'][1]['weight'])->toBe(7.5)
        ->and($payload['parcels'][1]['items'][0]['name'])->toBe('Rest Box Two');

    // The split was persisted (persist-after-success) in the frozen row shape.
    expect(wc_get_order($order->get_id())->get_meta('ss_shipping_order_parcels', true))->toEqual([
        ['id' => $product_a->get_id(), 'name' => 'Rest Box One', 'value' => '1'],
        ['id' => $product_b->get_id(), 'name' => 'Rest Box Two', 'value' => '2'],
    ]);

    // Response shape (section 3.2).
    $data = $response->get_data();
    expect($data)->toHaveKeys(['order_id', 'flow', 'success', 'shipments', 'state'])
        ->and($data['order_id'])->toBe($order->get_id())
        ->and($data['flow'])->toBe('outbound')
        ->and($data['success'])->toBeTrue()
        ->and($data['shipments'])->toHaveCount(1)
        ->and($data['shipments'][0]['direction'])->toBe('outbound')
        ->and($data['shipments'][0]['status'])->toBe('fulfilled')
        ->and($data['shipments'][0]['shipment']['shipment_id'])->toBe('rest-shipment-1')
        // The booked shipment is enriched for the box: the link into the
        // Smart Send app and, per parcel, the weight the parcel was booked
        // with in the store's unit (the two-box split above).
        ->and($data['shipments'][0]['shipment']['app_url'])->toBe('https://app.smartsend.io/shipments/rest-shipment-1')
        ->and($data['shipments'][0]['shipment']['parcels'][0]['weight_display'])->toBe('1 kg')
        ->and($data['shipments'][0]['shipment']['parcels'][0]['dimensions_display'])->toBeNull()
        ->and($data['shipments'][0]['shipment']['parcels'][1]['weight_display'])->toBe('7.5 kg')
        ->and($data['shipments'][0]['shipment']['parcels'][1]['dimensions_display'])->toBe('40 × 30 × 20 cm')
        ->and($data['shipments'][0]['steps']['order_note'])->toBeTrue();

    // order_note.id is the note actually added, and .html is WooCommerce's
    // own order-note list item for it.
    $note_id = $data['shipments'][0]['order_note']['id'];
    expect($note_id)->toBeInt();
    $notes = wc_get_order_notes(['order_id' => $order->get_id()]);
    expect(array_map('intval', wp_list_pluck($notes, 'id')))->toContain($note_id);
    expect($data['shipments'][0]['order_note']['html'])->toStartWith('<li rel="' . $note_id . '"')
        ->toContain('class="note"')
        ->toContain('<div class="note_content">')
        ->toContain('https://api.example.test/labels/label.pdf')
        ->toContain('delete_note')
        ->toEndWith('</li>');

    // The state after the run equals a subsequent GET and now shows the shipment.
    expect($data['state']['outbound_shipment'])->toBe([
        'shipment_id' => 'rest-shipment-1',
        // The link into the Smart Send app is built from the resolved API
        // host, so it works from the stored id alone after a reload.
        'app_url'     => 'https://app.smartsend.io/shipments/rest-shipment-1',
    ])
        ->and($data['state'])->toBe(fulfillment_get($order->get_id())->get_data());
});

it('books one leg with with_return false on an auto-return order, and follows the setting when with_return is null', function () {
    as_rest_user();

    $order_a   = create_rest_order(['auto_return' => 'yes']);
    $capture_a = mock_smart_send_api();

    $response = fulfillment_post($order_a->get_id(), ['flow' => 'outbound', 'with_return' => false]);
    expect($response->get_status())->toBe(200)
        ->and($response->get_data()['shipments'])->toHaveCount(1)
        ->and($capture_a->requests)->toHaveCount(1)
        ->and(wc_get_order($order_a->get_id())->get_meta('_ss_shipping_return_label_id', true))->toBe('');

    $order_b   = create_rest_order(['auto_return' => 'yes']);
    $capture_b = mock_smart_send_api();

    $response = fulfillment_post($order_b->get_id(), ['flow' => 'outbound', 'with_return' => null]);
    expect($response->get_status())->toBe(200)
        ->and($response->get_data()['shipments'])->toHaveCount(2)
        ->and($response->get_data()['shipments'][1]['direction'])->toBe('return')
        ->and($capture_b->requests)->toHaveCount(2)
        ->and($response->get_data()['state']['return_shipment']['shipment_id'])->not->toBe('');

    // with_return true on an order whose method has the setting off books both.
    $order_c   = create_rest_order(['auto_return' => 'no']);
    $capture_c = mock_smart_send_api();

    $response = fulfillment_post($order_c->get_id(), ['flow' => 'outbound', 'with_return' => true]);
    expect($response->get_status())->toBe(200)
        ->and($response->get_data()['shipments'])->toHaveCount(2)
        ->and($capture_c->requests)->toHaveCount(2);
});

it('creates only the return label for flow=return', function () {
    $order = create_rest_order();
    as_rest_user();
    $capture = mock_smart_send_api();

    $response = fulfillment_post($order->get_id(), ['flow' => 'return']);

    expect($response->get_status())->toBe(200)
        ->and($response->get_data()['flow'])->toBe('return')
        ->and($response->get_data()['shipments'][0]['direction'])->toBe('return')
        ->and($capture->requests)->toHaveCount(1);

    $payload = json_decode($capture->requests[0]['body'], true);
    expect($payload['shipping_method'])->toBe('returndropoff');

    $fresh = wc_get_order($order->get_id());
    expect($fresh->get_meta('_ss_shipping_return_label_id', true))->not->toBe('')
        ->and($fresh->get_meta('_ss_shipping_label_id', true))->toBe('');
});

it('returns 409 smart_send_already_booked without confirm_rebook when a shipment exists for the flow, and books again with it', function () {
    $order = create_rest_order();
    SS_SHIPPING_WC()->shipment_ids()->save($order, 'shipment-old', false);
    as_rest_user();
    $capture = mock_smart_send_api(function () {
        return ss_api_response(200, ['data' => ss_api_shipment_data(['shipment_id' => 'shipment-new'])]);
    });

    $response = fulfillment_post($order->get_id(), ['flow' => 'outbound']);
    expect($response->get_status())->toBe(409)
        ->and($response->get_data()['code'])->toBe('smart_send_already_booked')
        ->and($response->get_data()['data']['shipment_id'])->toBe('shipment-old')
        ->and($capture->requests)->toBe([]);

    // The return flow is guarded separately: no return shipment exists yet.
    $return = fulfillment_post($order->get_id(), ['flow' => 'return']);
    expect($return->get_status())->toBe(200);

    $rebook = fulfillment_post($order->get_id(), ['flow' => 'outbound', 'confirm_rebook' => true, 'with_return' => false]);
    expect($rebook->get_status())->toBe(200)
        ->and($rebook->get_data()['shipments'][0]['shipment']['shipment_id'])->toBe('shipment-new')
        ->and(wc_get_order($order->get_id())->get_meta('_ss_shipping_label_id', true))->toBe('shipment-new');
});

it('returns 409 smart_send_not_connected when no API token is configured', function () {
    with_ss_settings(['api_token' => '']);
    $order = create_rest_order();
    as_rest_user();
    $capture = mock_smart_send_api();

    $response = fulfillment_post($order->get_id(), ['flow' => 'outbound']);
    expect($response->get_status())->toBe(409)
        ->and($response->get_data()['code'])->toBe('smart_send_not_connected')
        ->and($capture->requests)->toBe([]);

    $lookup = pickup_point_lookup_get($order->get_id(), '1234');
    expect($lookup->get_status())->toBe(409)
        ->and($lookup->get_data()['code'])->toBe('smart_send_not_connected');

    // The state says so too.
    expect(fulfillment_get($order->get_id())->get_data()['connected'])->toBeFalse();
});

it('returns 409 smart_send_no_return_method when a return is requested and none is configured or submitted', function () {
    $order = create_rest_order(['return_method' => '']);
    as_rest_user();
    $capture = mock_smart_send_api();

    $return = fulfillment_post($order->get_id(), ['flow' => 'return']);
    expect($return->get_status())->toBe(409)
        ->and($return->get_data()['code'])->toBe('smart_send_no_return_method');

    $both = fulfillment_post($order->get_id(), ['flow' => 'outbound', 'with_return' => true]);
    expect($both->get_status())->toBe(409)
        ->and($both->get_data()['code'])->toBe('smart_send_no_return_method')
        ->and($capture->requests)->toBe([]);

    // A submitted return method makes the missing configured one a non-issue.
    $submitted = fulfillment_post($order->get_id(), ['flow' => 'return', 'delivery_details' => ['shipping_method' => 'postnord_returnpickup']]);
    expect($submitted->get_status())->toBe(200)
        ->and(json_decode($capture->requests[0]['body'], true)['shipping_method'])->toBe('returnpickup');

    // The state exposes the missing return method as null.
    expect(fulfillment_get($order->get_id())->get_data()['return']['method'])->toBeNull();
});

it('resolves a submitted pickup point override through the shared lookup and books with the resolved point', function () {
    $order = create_rest_order();
    save_order_pickup_point($order->get_id(), sample_agent());
    as_rest_user();
    $capture = mock_api_with_agent_lookup(sample_agent(['agent_no' => '5678', 'company' => 'Looked Up Shop']));

    $response = fulfillment_post($order->get_id(), [
        'flow'             => 'outbound',
        'delivery_details' => ['pickup_point' => ['agent_no' => '5678']],
    ]);

    expect($response->get_status())->toBe(200)
        ->and($capture->requests)->toHaveCount(2)
        ->and($capture->requests[0]['url'])->toContain('/agents/carrier/postnord/country/DK/agentno/5678');

    $payload = json_decode($capture->requests[1]['body'], true);
    expect($payload['agent']['agent_no'])->toBe('5678')
        ->and($payload['agent']['company'])->toBe('Looked Up Shop');

    // The resolved point is what got stored and what the state now shows.
    expect(wc_get_order($order->get_id())->get_meta('ss_shipping_order_agent_no', true))->toBe('5678')
        ->and($response->get_data()['state']['delivery_details']['pickup_point']['company'])->toBe('Looked Up Shop');
});

it('returns 422 smart_send_pickup_point_not_found with the form field when the override cannot be resolved, booking nothing', function () {
    $order = create_rest_order();
    save_order_pickup_point($order->get_id(), sample_agent());
    as_rest_user();
    $capture = mock_api_with_agent_lookup(null);

    $response = fulfillment_post($order->get_id(), [
        'flow'             => 'outbound',
        'delivery_details' => ['pickup_point' => ['agent_no' => '9999']],
    ]);

    expect($response->get_status())->toBe(422)
        ->and($response->get_data()['code'])->toBe('smart_send_pickup_point_not_found')
        ->and($response->get_data()['data']['form_fields'])->toBe(['pickup_point.agent_no' => ['The agent number entered, 9999, was not found.']])
        ->and($capture->requests)->toHaveCount(1)
        ->and($capture->requests[0]['url'])->toContain('agents/');

    $fresh = wc_get_order($order->get_id());
    expect($fresh->get_meta('_ss_shipping_label_id', true))->toBe('')
        ->and($fresh->get_meta('ss_shipping_order_agent_no', true))->toBe('1234');
});

it('does not look up an agent number equal to the stored one, nor one submitted for a non-agent method', function () {
    $order = create_rest_order();
    save_order_pickup_point($order->get_id(), sample_agent());
    as_rest_user();
    $capture = mock_smart_send_api(function ($url) {
        if (strpos($url, 'agents/') !== false) {
            throw new RuntimeException('No lookup expected.');
        }

        return ss_api_response(200, ['data' => ss_api_shipment_data()]);
    });

    $same = fulfillment_post($order->get_id(), ['flow' => 'outbound', 'with_return' => false, 'delivery_details' => ['pickup_point' => ['agent_no' => '1234']]]);
    expect($same->get_status())->toBe(200)
        ->and(json_decode($capture->requests[0]['body'], true)['agent']['company'])->toBe('Corner Shop');

    $home = fulfillment_post($order->get_id(), ['flow' => 'outbound', 'with_return' => false, 'confirm_rebook' => true, 'delivery_details' => ['shipping_method' => 'postnord_homedelivery', 'pickup_point' => ['agent_no' => '5678']]]);
    // No lookup ran (the responder would have thrown) and the submitted
    // number was not persisted: the stored point is untouched.
    expect($home->get_status())->toBe(200)
        ->and($capture->requests)->toHaveCount(2)
        ->and(json_decode($capture->requests[1]['body'], true)['shipping_method'])->toBe('homedelivery')
        ->and(wc_get_order($order->get_id())->get_meta('ss_shipping_order_agent_no', true))->toBe('1234');
});

it('resolves an agent number on the pickup point route, or answers 404 with the form field', function () {
    $order = create_rest_order();
    as_rest_user();
    $capture = mock_api_with_agent_lookup(sample_agent(['agent_no' => '5678', 'company' => 'Looked Up Shop']));

    $response = pickup_point_lookup_get($order->get_id(), '5678');
    expect($response->get_status())->toBe(200)
        ->and($response->get_data()['agent_no'])->toBe('5678')
        ->and($response->get_data()['company'])->toBe('Looked Up Shop')
        ->and($response->get_data()['display_html'])->toContain('Looked Up Shop')
        ->and($capture->requests[0]['url'])->toContain('/agents/carrier/postnord/country/DK/agentno/5678');

    // The carrier follows an explicitly given method.
    pickup_point_lookup_get($order->get_id(), '5678', 'gls_agent');
    expect($capture->requests[1]['url'])->toContain('/agents/carrier/gls/country/DK/agentno/5678');

    mock_api_with_agent_lookup(null);
    $missing = pickup_point_lookup_get($order->get_id(), '9999');
    expect($missing->get_status())->toBe(404)
        ->and($missing->get_data()['code'])->toBe('smart_send_pickup_point_not_found')
        ->and($missing->get_data()['data']['form_fields'])->toHaveKey('pickup_point.agent_no');
});

it('reports a failed leg as a 200 with the structured error and the mapped form fields', function () {
    $order = create_rest_order();
    as_rest_user();
    mock_smart_send_api(function () {
        return ss_api_response(422, [
            'message' => 'The given data was invalid.',
            'errors'  => [
                'agent_no'          => ['The agent is invalid.'],
                'parcels.1.weight'  => ['Too heavy.'],
                'receiver.zip_code' => ['The receiver zip code does not match the receiver country'],
            ],
        ], 'resp-rest');
    });

    $response = fulfillment_post($order->get_id(), ['flow' => 'outbound']);

    expect($response->get_status())->toBe(200);

    $data = $response->get_data();
    expect($data['success'])->toBeFalse()
        ->and($data['shipments'][0]['status'])->toBe('failed')
        ->and($data['shipments'][0]['error']['message'])->toBe('The given data was invalid.')
        ->and($data['shipments'][0]['error']['response_id'])->toBe('resp-rest')
        ->and($data['shipments'][0]['error']['fields'])->toHaveKeys(['agent_no', 'parcels.1.weight', 'receiver.zip_code'])
        ->and($data['shipments'][0]['error']['form_fields'])->toBe([
            'pickup_point.agent_no'       => ['The agent is invalid.'],
            'parcel_plan.specs[1].weight' => ['Too heavy.'],
        ])
        ->and($data['shipments'][0]['error']['html'])->toContain('Response ID: resp-rest')
        ->and($data['state']['outbound_shipment'])->toBeNull();
});

it('books an order without a Smart Send method when the request submits one (state B)', function () {
    $product = create_simple_product(['price' => 100, 'weight' => 1]);
    $order   = create_order(['products' => [$product]]);
    as_rest_user();
    $capture = mock_smart_send_api();

    expect(fulfillment_get($order->get_id())->get_data()['delivery_details']['shipping_method'])->toBeNull();

    $response = fulfillment_post($order->get_id(), ['flow' => 'outbound', 'delivery_details' => ['shipping_method' => 'gls_homedelivery']]);

    expect($response->get_status())->toBe(200)
        ->and($response->get_data()['success'])->toBeTrue();

    $payload = json_decode($capture->requests[0]['body'], true);
    expect($payload['shipping_carrier'])->toBe('gls')
        ->and($payload['shipping_method'])->toBe('homedelivery');
});

it('books outbound and return in one run for an order without a Smart Send method when a return method is submitted', function () {
    $product = create_simple_product(['price' => 100, 'weight' => 1]);
    $order   = create_order(['products' => [$product]]);
    as_rest_user();
    $capture = mock_smart_send_api();

    // Without a return method the explicit return request is a 409...
    $refused = fulfillment_post($order->get_id(), [
        'flow'             => 'outbound',
        'with_return'      => true,
        'delivery_details' => ['shipping_method' => 'gls_homedelivery'],
    ]);

    expect($refused->get_status())->toBe(409)
        ->and($refused->get_data()['code'])->toBe('smart_send_no_return_method')
        ->and($capture->requests)->toBe([]);

    // ...with one, both legs book in the same run, the return leg with it.
    $response = fulfillment_post($order->get_id(), [
        'flow'             => 'outbound',
        'with_return'      => true,
        'return_method'    => 'gls_returndropoff',
        'delivery_details' => ['shipping_method' => 'gls_homedelivery'],
    ]);

    expect($response->get_status())->toBe(200)
        ->and($response->get_data()['success'])->toBeTrue()
        ->and($response->get_data()['shipments'])->toHaveCount(2)
        ->and($response->get_data()['shipments'][1]['direction'])->toBe('return')
        ->and($capture->requests)->toHaveCount(2);

    $return_payload = json_decode($capture->requests[1]['body'], true);
    expect($return_payload['shipping_carrier'])->toBe('gls')
        ->and($return_payload['shipping_method'])->toBe('returndropoff');

    $fresh = wc_get_order($order->get_id());
    expect($fresh->get_meta('_ss_shipping_label_id', true))->not->toBe('')
        ->and($fresh->get_meta('_ss_shipping_return_label_id', true))->not->toBe('');
});

it('maps API v1 field names onto form fields in one place', function () {
    $presenter = SS_SHIPPING_WC()->fulfillment_presenter();

    expect($presenter->map_api_field('agent_no'))->toBe('pickup_point.agent_no')
        ->and($presenter->map_api_field('agent.agent_no'))->toBe('pickup_point.agent_no')
        ->and($presenter->map_api_field('agent.company'))->toBe('pickup_point.agent_no')
        ->and($presenter->map_api_field('parcels'))->toBe('parcel_plan')
        ->and($presenter->map_api_field('parcels.0'))->toBe('parcel_plan.specs[0]')
        ->and($presenter->map_api_field('parcels.2.weight'))->toBe('parcel_plan.specs[2].weight')
        ->and($presenter->map_api_field('parcels.0.items.1.quantity'))->toBe('parcel_plan.specs[0].items[1].quantity')
        ->and($presenter->map_api_field('shipping_method'))->toBe('shipping_method')
        ->and($presenter->map_api_field('shipping_carrier'))->toBe('shipping_method')
        ->and($presenter->map_api_field('receiver.zip_code'))->toBeNull()
        ->and($presenter->map_api_field('sender.name_line1'))->toBeNull();
});

it('books a method the smart_send_fulfillment_shipping_methods filter hides', function () {
    // The filter narrows what the meta box OFFERS; it is not an
    // authorisation boundary (#182), so a submitted method it hides is
    // still booked.
    $order   = create_rest_order(['shipping_method' => null]);
    $capture = mock_smart_send_api();
    as_rest_user();

    $hide_everything = fn () => [];
    add_filter('smart_send_fulfillment_shipping_methods', $hide_everything, 10, 3);
    remember_cleanup_callback(function () use ($hide_everything): void {
        remove_filter('smart_send_fulfillment_shipping_methods', $hide_everything, 10);
    });

    $response = fulfillment_post($order->get_id(), [
        'flow'             => 'outbound',
        'with_return'      => false,
        'delivery_details' => ['shipping_method' => 'postnord_homedelivery'],
    ]);

    expect($response->get_status())->toBe(200)
        ->and($response->get_data()['success'])->toBeTrue()
        ->and(json_decode($capture->requests[0]['body'], true)['shipping_method'])->toBe('homedelivery')
        // ... while the drop-downs the response carries stay empty.
        ->and($response->get_data()['state']['methods']['outbound'])->toBe([]);
});
