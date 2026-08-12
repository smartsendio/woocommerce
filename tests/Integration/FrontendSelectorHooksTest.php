<?php

/*
 * Tests for the checkout pickup point selector extension hooks: the four
 * added in v9 (#73) - smart_send_pickup_point_search_params,
 * smart_send_pickup_points_found, smart_send_pickup_point_option_label and
 * smart_send_default_selected_pickup_point - plus smart_send_pickup_point_timeout,
 * which shipped in 8.2.0 (as smart_send_agent_timeout) and was renamed
 * alongside the others in #105.
 *
 * Each test renders the pickup point block the way checkout does (mocked
 * Smart Send API) and asserts the hook fires with the documented params and
 * that its return value is respected.
 *
 * NOTE: this file must run before the WC_DOING_AJAX-defining test in
 * ShippingDebugModeTest.php (alphabetical file order guarantees this).
 */

/**
 * Render the pickup point block for a Smart Send agent rate the way
 * checkout does: is_checkout() forced true, a posted shipping address, the
 * rate chosen in the session.
 */
function selector_hooks_render(): string
{
    if (is_null(WC()->cart)) {
        wc_load_cart();
    }

    add_filter('woocommerce_is_checkout', '__return_true');
    $_POST = array_merge($_POST, [
        's_country'  => 'DK',
        's_postcode' => '2300',
        's_city'     => 'Copenhagen',
        's_address'  => 'Islands Brygge 39',
    ]);
    WC()->session->set('chosen_shipping_methods', ['smart_send_shipping:1']);

    remember_cleanup_callback(function (): void {
        remove_filter('woocommerce_is_checkout', '__return_true');
        unset($_POST['s_country'], $_POST['s_postcode'], $_POST['s_city'], $_POST['s_address']);
        WC()->session->set('chosen_shipping_methods', null);
        WC()->session->set('ss_shipping_agents', null);
    });

    $rate = new WC_Shipping_Rate('smart_send_shipping:1', 'Smart Send', 49.0, [], 'smart_send_shipping', 1);
    $rate->add_meta_data('smart_send_shipping_method', 'postnord_agent');

    ob_start();
    (new SS_Shipping_Frontend())->display_ss_pickup_points($rate, 0);

    return ob_get_clean();
}

beforeEach(function (): void {
    with_ss_settings();
});

it('lets smart_send_pickup_point_timeout change the timeout used for the pickup point lookup request', function () {
    // This hook shipped in 8.2.0 as smart_send_agent_timeout and was renamed
    // to smart_send_pickup_point_timeout in #105 - a breaking change for
    // merchants already hooking the old name (see the v9 upgrade guide).
    $capture = mock_smart_send_api(function () {
        return ss_api_response(200, ['data' => [sample_agent()]]);
    });

    $filter = function ($timeout) {
        expect($timeout)->toBe(4);

        return 9;
    };
    add_filter('smart_send_pickup_point_timeout', $filter);
    remember_cleanup_callback(function () use ($filter): void {
        remove_filter('smart_send_pickup_point_timeout', $filter);
    });

    selector_hooks_render();

    $request = end($capture->requests);
    expect($request['timeout'])->toBe(9);
});

it('passes the documented params to smart_send_pickup_point_search_params and uses the filtered values', function () {
    $capture = mock_smart_send_api(function () {
        return ss_api_response(200, ['data' => [sample_agent()]]);
    });

    $filter = function (array $search_params) {
        expect($search_params)->toBe([
            'carrier'     => 'postnord',
            'country'     => 'DK',
            'postal_code' => '2300',
            'city'        => 'Copenhagen',
            'street'      => 'Islands Brygge 39',
        ]);

        $search_params['postal_code'] = '8000';
        $search_params['city']        = 'Aarhus';

        return $search_params;
    };
    add_filter('smart_send_pickup_point_search_params', $filter);
    remember_cleanup_callback(function () use ($filter): void {
        remove_filter('smart_send_pickup_point_search_params', $filter);
    });

    $output = selector_hooks_render();

    $request = end($capture->requests);
    expect($request['url'])->toContain('/postalcode/8000')
        ->and($request['url'])->toContain('/city/Aarhus')
        ->and($output)->toContain('ss_shipping_store_pickup');
});

it('lets smart_send_pickup_points_found trim the list before rendering and caching', function () {
    mock_smart_send_api(function () {
        return ss_api_response(200, ['data' => [
            sample_agent(['agent_no' => '1111', 'company' => 'First Shop']),
            sample_agent(['agent_no' => '2222', 'company' => 'Second Shop']),
        ]]);
    });

    $filter = function (array $ss_agents, array $search_params) {
        expect($ss_agents)->toHaveCount(2)
            ->and($search_params['carrier'])->toBe('postnord');

        // Keep only the second pickup point.
        return [$ss_agents[1]];
    };
    add_filter('smart_send_pickup_points_found', $filter, 10, 2);
    remember_cleanup_callback(function () use ($filter): void {
        remove_filter('smart_send_pickup_points_found', $filter, 10);
    });

    $output = selector_hooks_render();

    expect($output)->toContain('Second Shop')
        ->and($output)->not->toContain('First Shop')
        ->and(WC()->session->get('ss_shipping_agents'))->toHaveCount(1);
});

it('lets smart_send_pickup_point_option_label rewrite the drop-down option label', function () {
    mock_smart_send_api(function () {
        return ss_api_response(200, ['data' => [sample_agent()]]);
    });

    $filter = function (string $label, object $agent) {
        // The default label follows the "Dropdown display format" setting.
        expect($label)->toContain('Corner Shop')
            ->and($agent->agent_no)->toBe('1234');

        return 'Custom Label ' . $agent->agent_no;
    };
    add_filter('smart_send_pickup_point_option_label', $filter, 10, 2);
    remember_cleanup_callback(function () use ($filter): void {
        remove_filter('smart_send_pickup_point_option_label', $filter, 10);
    });

    $output = selector_hooks_render();

    expect($output)->toContain('Custom Label 1234')
        ->and($output)->not->toContain('Corner Shop');
});

it('lets smart_send_default_selected_pickup_point pre-select a pickup point', function () {
    mock_smart_send_api(function () {
        return ss_api_response(200, ['data' => [
            sample_agent(['agent_no' => '1111', 'company' => 'First Shop']),
            sample_agent(['agent_no' => '2222', 'company' => 'Second Shop']),
        ]]);
    });

    $filter = function (string $default_agent_no, array $ss_agents) {
        expect($default_agent_no)->toBe('')
            ->and($ss_agents)->toHaveCount(2);

        return '2222';
    };
    add_filter('smart_send_default_selected_pickup_point', $filter, 10, 2);
    remember_cleanup_callback(function () use ($filter): void {
        remove_filter('smart_send_default_selected_pickup_point', $filter, 10);
    });

    $output = selector_hooks_render();

    expect($output)->toMatch('/value="2222"[^>]*selected/')
        ->and($output)->not->toMatch('/value="1111"[^>]*selected/');
});

it('renders the drop-down unchanged when no selector hooks are registered', function () {
    mock_smart_send_api(function () {
        return ss_api_response(200, ['data' => [sample_agent()]]);
    });

    $output = selector_hooks_render();

    // Placeholder first, no option pre-selected, default-formatted label.
    expect($output)->toContain('ss_shipping_store_pickup')
        ->and($output)->toContain('- Select Pickup Point -')
        ->and($output)->not->toMatch('/value="1234"[^>]*selected/')
        ->and($output)->toContain('Corner Shop, Main Street 1, 2300 Copenhagen');
});
