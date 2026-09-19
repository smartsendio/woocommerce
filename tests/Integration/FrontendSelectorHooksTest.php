<?php

/*
 * Tests for the checkout pickup point selector extension hooks: the renderer-independent hooks
 * added in v9 (#73) - smart_send_pickup_point_search_params,
 * smart_send_pickup_point_list, smart_send_pickup_point_label and
 * smart_send_pickup_point_timeout,
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
    (new \Smart_Send\Frontend\Checkout())->display_ss_pickup_points($rate, 0);

    return ob_get_clean();
}


/**
 * Replace the logger's WC_Logger with a spy that records every entry.
 * Restored automatically after the test. (Local twin of the LoggerTest
 * helper so this file does not depend on test-file load order.)
 */
function spy_on_logger_for_selector_hooks(): object
{
    $spy = new class {
        public array $entries = [];

        public function log($level, $message, $context = []): void
        {
            $this->entries[] = ['level' => $level, 'message' => $message, 'context' => $context];
        }

        public function __call(string $level, array $args): void
        {
            $this->log($level, $args[0], $args[1] ?? []);
        }
    };

    \Smart_Send\Support\Logger::$logger = $spy;

    remember_cleanup_callback(function (): void {
        \Smart_Send\Support\Logger::$logger = null;
    });

    return $spy;
}

beforeEach(function (): void {
    with_ss_settings();
    if (is_null(WC()->cart)) {
        wc_load_cart();
    }
    $post = $_POST;
    $_POST = [];
    (new \Smart_Send\Delivery_Options\Pickup_Point_Lookup())->clear_selection();
    remember_cleanup_callback(function () use ($post): void {
        $_POST = $post;
        WC()->session->set('ss_shipping_agents_context', null);
        (new \Smart_Send\Delivery_Options\Pickup_Point_Lookup())->clear_selection();
    });
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

it('lets smart_send_pickup_point_list trim the list before rendering and caching', function () {
    mock_smart_send_api(function () {
        return ss_api_response(200, ['data' => [
            sample_agent(['agent_no' => '1111', 'company' => 'First Shop']),
            sample_agent(['agent_no' => '2222', 'company' => 'Second Shop']),
        ]]);
    });

    $filter = function (array $ss_pickup_points, array $search_params) {
        // Typed value objects, not raw API objects (#170).
        expect($ss_pickup_points)->toHaveCount(2)
            ->and($ss_pickup_points[0])->toBeInstanceOf(\Smart_Send\Delivery\Pickup_Point::class)
            ->and($ss_pickup_points[0]->get_agent_no())->toBe('1111')
            ->and($ss_pickup_points[1]->get_company())->toBe('Second Shop')
            ->and($search_params['carrier'])->toBe('postnord');

        // Keep only the second pickup point.
        return [$ss_pickup_points[1]];
    };
    add_filter('smart_send_pickup_point_list', $filter, 10, 2);
    remember_cleanup_callback(function () use ($filter): void {
        remove_filter('smart_send_pickup_point_list', $filter, 10);
    });

    $output = selector_hooks_render();

    expect($output)->toContain('Second Shop')
        ->and($output)->not->toContain('First Shop');

    // The session cache holds the filtered list in its plain serializable
    // form (never class instances), and reads back as value objects.
    $cached = WC()->session->get('ss_shipping_agents');
    expect($cached)->toHaveCount(1)
        ->and($cached[0])->toBeInstanceOf(stdClass::class)
        ->and($cached[0]->agent_no)->toBe('2222');
    $read_back = (new \Smart_Send\Delivery_Options\Pickup_Point_Lookup())->get_session_pickup_points();
    expect($read_back)->toHaveCount(1)
        ->and($read_back[0])->toBeInstanceOf(\Smart_Send\Delivery\Pickup_Point::class)
        ->and($read_back[0]->get_agent_no())->toBe('2222');
});

it('lets smart_send_pickup_point_list add a pickup point built directly from the value object', function () {
    mock_smart_send_api(function () {
        return ss_api_response(200, ['data' => [sample_agent()]]);
    });

    $filter = function (array $ss_pickup_points) {
        $own = (new \Smart_Send\Delivery\Pickup_Point())
            ->set_agent_no('9000')
            ->set_company('Own Shop')
            ->set_address_line1('Custom Road 1')
            ->set_postal_code('2300')
            ->set_city('Copenhagen')
            ->set_country('DK');

        return array_merge([$own], $ss_pickup_points);
    };
    add_filter('smart_send_pickup_point_list', $filter, 10, 2);
    remember_cleanup_callback(function () use ($filter): void {
        remove_filter('smart_send_pickup_point_list', $filter, 10);
    });

    $output = selector_hooks_render();

    expect($output)->toContain('value="9000"')
        ->and($output)->toContain('Own Shop, Custom Road 1, 2300 Copenhagen')
        ->and($output)->toContain('Corner Shop');

    // The directly-built point resolves at checkout submission like any other.
    $cached = (new \Smart_Send\Delivery_Options\Pickup_Point_Lookup())->find_cached_by_agent_no('postnord', 'DK', '9000');
    expect($cached)->not->toBeNull()
        ->and($cached->get_company())->toBe('Own Shop');
});

it('drops entries returned by smart_send_pickup_point_list that are not value objects, with a warning', function () {
    $spy = spy_on_logger_for_selector_hooks();
    mock_smart_send_api(function () {
        return ss_api_response(200, ['data' => [sample_agent()]]);
    });

    $filter = function (array $ss_pickup_points) {
        // A raw object or array is no longer accepted (#170).
        return array_merge($ss_pickup_points, [sample_agent(['agent_no' => '5555', 'company' => 'Raw Shop'])]);
    };
    add_filter('smart_send_pickup_point_list', $filter, 10, 2);
    remember_cleanup_callback(function () use ($filter): void {
        remove_filter('smart_send_pickup_point_list', $filter, 10);
    });

    $output = selector_hooks_render();

    expect($output)->toContain('Corner Shop')
        ->and($output)->not->toContain('Raw Shop')
        ->and(WC()->session->get('ss_shipping_agents'))->toHaveCount(1);

    $warnings = array_values(array_filter($spy->entries, fn ($entry) => $entry['level'] === 'warning'));
    expect($warnings)->toHaveCount(1)
        ->and($warnings[0]['message'])->toContain('smart_send_pickup_point_list')
        ->and($warnings[0]['context']['entry_type'])->toBe('stdClass');
});

it('lets smart_send_pickup_point_label rewrite the drop-down option label', function () {
    mock_smart_send_api(function () {
        return ss_api_response(200, ['data' => [sample_agent()]]);
    });

    $filter = function (string $label, \Smart_Send\Delivery\Pickup_Point $pickup_point) {
        // The default label follows the "Dropdown display format" setting;
        // the pickup point is the typed value object (#170).
        expect($label)->toContain('Corner Shop')
            ->and($pickup_point->get_agent_no())->toBe('1234')
            ->and($pickup_point->get_distance())->toBe(0.5);

        return 'Custom Label ' . $pickup_point->get_agent_no();
    };
    add_filter('smart_send_pickup_point_label', $filter, 10, 2);
    remember_cleanup_callback(function () use ($filter): void {
        remove_filter('smart_send_pickup_point_label', $filter, 10);
    });

    $output = selector_hooks_render();

    expect($output)->toContain('Custom Label 1234')
        ->and($output)->not->toContain('Corner Shop');
});

it('pre-selects the first pickup point only when the merchant setting enables it', function () {
    with_ss_settings(['default_select_agent' => 'yes']);
    mock_smart_send_api(fn () => ss_api_response(200, ['data' => [
        sample_agent(['agent_no' => '1111', 'company' => 'First Shop']),
        sample_agent(['agent_no' => '2222', 'company' => 'Second Shop']),
    ]]));
    $output = selector_hooks_render();
    expect($output)->toMatch('/value="1111"[^>]*selected/')
        ->and($output)->not->toContain('- Select Pickup Point -')
        ->and((new \Smart_Send\Delivery_Options\Pickup_Point_Lookup())->is_selection_explicit())->toBeFalse();
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
