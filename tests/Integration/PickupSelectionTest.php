<?php

use Smart_Send\Delivery\Pickup_Point;
use Smart_Send\Delivery_Options\Exceptions\Pickup_Point_Not_Found_Exception;
use Smart_Send\Delivery_Options\Pickup_Point_Lookup;

beforeEach(function (): void {
    with_ss_settings();
    if (null === WC()->session) {
        wc_load_cart();
    }
    foreach ([Pickup_Point_Lookup::SESSION_KEY, Pickup_Point_Lookup::SESSION_CONTEXT, Pickup_Point_Lookup::SESSION_SELECTION] as $key) {
        $previous = WC()->session->get($key);
        WC()->session->set($key, null);
        remember_cleanup_callback(fn () => WC()->session->set($key, $previous));
    }
});

it('uses a scoped cached result while preserving exact agent-number identity', function () {
    $capture = mock_smart_send_api(function ($url) {
        return str_contains($url, '/agents/closest/')
            ? ss_api_response(200, ['data' => [sample_agent(['agent_no' => '0007', 'carrier' => 'postnord'])]])
            : ss_api_response(404, ['message' => 'Unknown agent.']);
    });
    $lookup = new Pickup_Point_Lookup();
    $lookup->find_closest_by_address('postnord', 'DK', '2300', 'Copenhagen', 'Main Street 1');

    expect($lookup->resolve_selection('POSTNORD', 'dk', '0007')->get_agent_no())->toBe('0007')
        ->and($capture->requests)->toHaveCount(1)
        ->and(fn () => $lookup->resolve_selection('postnord', 'DK', '7'))->toThrow(Pickup_Point_Not_Found_Exception::class)
        ->and($capture->requests)->toHaveCount(2);
});

it('re-resolves the same number when carrier or country changes', function (string $carrier, string $country) {
    $capture = mock_smart_send_api(function ($url) use ($carrier, $country) {
        return str_contains($url, '/agents/closest/')
            ? ss_api_response(200, ['data' => [sample_agent(['carrier' => 'postnord', 'country' => 'DK', 'company' => 'Original point'])]])
            : ss_api_response(200, ['data' => sample_agent(['carrier' => $carrier, 'country' => $country, 'company' => 'Verified new context'])]);
    });
    $lookup = new Pickup_Point_Lookup();
    $lookup->find_closest_by_address('postnord', 'DK', '2300', 'Copenhagen', 'Main Street 1');
    $lookup->select('postnord', 'DK', '1234');

    expect($lookup->get_selected($carrier, $country))->toBeNull()
        ->and($lookup->is_selection_explicit())->toBeFalse();
    $resolved = $lookup->resolve_selection($carrier, $country, '1234');
    expect($resolved->get_company())->toBe('Verified new context')
        ->and($resolved->get_carrier())->toBe($carrier)
        ->and($resolved->get_country())->toBe($country)
        ->and($capture->requests)->toHaveCount(2)
        ->and($capture->requests[1]['url'])->toContain('/carrier/' . $carrier . '/country/' . $country . '/agentno/1234');
})->with(['carrier changed' => ['gls', 'DK'], 'country changed' => ['postnord', 'SE']]);

it('does not trust a legacy result list without its server lookup context', function () {
    WC()->session->set(Pickup_Point_Lookup::SESSION_KEY, [sample_agent(['company' => 'Unscoped cached point'])]);
    $capture = mock_smart_send_api(fn () => ss_api_response(404, ['message' => 'Unknown point.']));
    $lookup = new Pickup_Point_Lookup();

    expect($lookup->find_cached_by_agent_no('postnord', 'DK', '1234'))->toBeNull()
        ->and(fn () => $lookup->resolve_selection('postnord', 'DK', '1234'))->toThrow(Pickup_Point_Not_Found_Exception::class)
        ->and($capture->requests)->toHaveCount(1);
});

it('retains a compatible explicit selection outside refreshed nearest results', function () {
    $capture = mock_smart_send_api(function ($url) {
        return str_contains($url, '/agents/closest/')
            ? ss_api_response(200, ['data' => [sample_agent(['agent_no' => '5678', 'company' => 'New nearest point'])]])
            : ss_api_response(200, ['data' => sample_agent(['company' => 'Explicit chosen point'])]);
    });
    $lookup = new Pickup_Point_Lookup();
    $lookup->select('postnord', 'DK', '1234');
    $lookup->find_closest_by_address('postnord', 'DK', '8000', 'Aarhus', 'Other Street 2');

    expect($lookup->get_session_pickup_points()[0]->get_agent_no())->toBe('5678')
        ->and($lookup->get_selected('postnord', 'DK', true)->get_company())->toBe('Explicit chosen point')
        ->and($lookup->resolve_selection('postnord', 'DK', '1234')->get_agent_no())->toBe('1234')
        ->and($lookup->is_selection_explicit())->toBeTrue()
        ->and($capture->requests)->toHaveCount(2);
});

it('keeps a trusted explicit choice when a nearest lookup fails and scopes empty evidence to its address', function () {
    $capture = mock_smart_send_api(function ($url) {
        return str_contains($url, '/agents/closest/')
            ? ss_api_response(500, ['message' => 'Temporarily unavailable'])
            : ss_api_response(200, ['data' => sample_agent()]);
    });
    $lookup = new Pickup_Point_Lookup();
    $lookup->select('postnord', 'DK', '1234');
    expect(fn () => $lookup->find_closest_by_address('postnord', 'DK', '2300', 'Copenhagen', 'Main Street 1'))->toThrow(\Smart_Send\API\Exceptions\HTTP_Client_Exception::class);

    expect($lookup->get_selected('postnord', 'DK', true)->get_agent_no())->toBe('1234')
        ->and($lookup->no_pickup_points_for_address('postnord', 'DK', '2300', 'Copenhagen', 'Main Street 1'))->toBeTrue()
        ->and($lookup->no_pickup_points_for_address('postnord', 'DK', '2300', 'Copenhagen', 'Other Street 2'))->toBeFalse()
        ->and($lookup->no_pickup_points_for_address('gls', 'DK', '2300', 'Copenhagen', 'Main Street 1'))->toBeFalse()
        ->and($lookup->no_pickup_points_for_address('postnord', 'SE', '2300', 'Copenhagen', 'Main Street 1'))->toBeFalse()
        ->and($capture->requests)->toHaveCount(2);
});

it('requires an exact server search context before allowing an empty result fallback', function () {
    $lookup = new Pickup_Point_Lookup();
    WC()->session->set(Pickup_Point_Lookup::SESSION_KEY, []);
    expect($lookup->no_pickup_points_for_address('postnord', 'DK', '2300', 'Copenhagen', 'Main Street 1'))->toBeFalse();
    mock_smart_send_api(fn () => ss_api_response(200, ['data' => []]));
    $lookup->find_closest_by_address('postnord', 'DK', '2300', 'Copenhagen', 'Main Street 1');

    expect($lookup->no_pickup_points_for_address('postnord', 'DK', '2300', 'Copenhagen', 'Main Street 1'))->toBeTrue()
        ->and($lookup->no_pickup_points_for_address('postnord', 'DK', '2400', 'Copenhagen', 'Main Street 1'))->toBeFalse();
});

it('distinguishes automatic defaults from explicit choices and can clear either', function () {
    mock_smart_send_api(fn () => ss_api_response(200, ['data' => sample_agent()]));
    $lookup = new Pickup_Point_Lookup();
    $lookup->select('postnord', 'DK', '1234', false);

    expect($lookup->get_selected('postnord', 'DK')->get_agent_no())->toBe('1234')
        ->and($lookup->get_selected('postnord', 'DK', true))->toBeNull()
        ->and($lookup->is_selection_explicit())->toBeFalse();
    $lookup->select('postnord', 'DK', '1234', true);
    expect($lookup->is_selection_explicit())->toBeTrue();
    $lookup->clear_selection();
    expect($lookup->get_selected('postnord', 'DK'))->toBeNull();
});

it('recognizes an empty search after WooCommerce formats the checkout postcode', function () {
    mock_smart_send_api(fn () => ss_api_response(200, ['data' => []]));
    $lookup = new Pickup_Point_Lookup();
    $lookup->find_closest_by_address('postnord', 'GB', 'sw1a1aa', 'London', 'Main Street 1');

    expect($lookup->no_pickup_points_for_address('postnord', 'GB', 'SW1A 1AA', 'London', 'Main Street 1'))->toBeTrue()
        ->and($lookup->no_pickup_points_for_address('postnord', 'GB', 'SW1A 2AA', 'London', 'Main Street 1'))->toBeFalse();
});

it('rejects an API response whose identity does not match the requested point', function (array $override) {
    mock_smart_send_api(fn () => ss_api_response(200, ['data' => sample_agent($override)]));
    $lookup = new Pickup_Point_Lookup();
    expect(fn () => $lookup->select('postnord', 'DK', '1234'))->toThrow(Pickup_Point_Not_Found_Exception::class)
        ->and($lookup->get_selected('postnord', 'DK'))->toBeNull();
})->with([
    'another agent' => [['agent_no' => '5678']],
    'another carrier' => [['carrier' => 'gls']],
    'another country' => [['country' => 'SE']],
    'no agent identity' => [['agent_no' => null]],
    'malformed identity' => [['agent_no' => ['1234']]],
]);

it('stamps omitted API scope before lossless DTO serialization and preserves extra server fields', function () {
    $agent = sample_agent(['custom_extra' => 'preserved']);
    unset($agent->carrier, $agent->country);
    mock_smart_send_api(fn () => ss_api_response(200, ['data' => $agent]));
    $lookup = new Pickup_Point_Lookup();
    $point = $lookup->find_by_agent_no('postnord', 'DK', '1234');

    expect($point->to_array())->toMatchArray(['carrier' => 'postnord', 'country' => 'DK', 'custom_extra' => 'preserved'])
        ->and($lookup->matches_context($point, 'POSTNORD', 'dk'))->toBeTrue()
        ->and($lookup->matches_context(Pickup_Point::from_object($agent), 'postnord', 'DK'))->toBeFalse();
});

it('binds filtered search scope without losing the original customer address context', function () {
    mock_smart_send_api(fn () => ss_api_response(200, ['data' => []]));
    $filter = function (array $params): array {
        $params['postal_code'] = '8000';
        $params['street'] = 'Search override';
        return $params;
    };
    add_filter('smart_send_pickup_point_search_params', $filter);
    remember_cleanup_callback(fn () => remove_filter('smart_send_pickup_point_search_params', $filter));
    $lookup = new Pickup_Point_Lookup();
    $lookup->find_closest_by_address('postnord', 'DK', '2300', 'Copenhagen', 'Main Street 1');
    expect($lookup->no_pickup_points_for_address('postnord', 'DK', '2300', 'Copenhagen', 'Main Street 1'))->toBeTrue();
});

it('encodes pickup reference and address route segments without changing their meaning', function () {
    $reference = 'AB/12 ?#';
    $capture = mock_smart_send_api(fn () => ss_api_response(200, ['data' => sample_agent(['agent_no' => $reference])]));
    $lookup = new Pickup_Point_Lookup();
    expect($lookup->find_by_agent_no('postnord', 'DK', $reference)->get_agent_no())->toBe($reference)
        ->and($capture->requests[0]['url'])->toContain('/agentno/AB%2F12%20%3F%23');
});
