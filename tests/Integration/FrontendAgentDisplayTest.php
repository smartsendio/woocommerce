<?php

/*
 * Characterization tests for \Smart_Send\Frontend\Checkout: the pickup point block
 * on the thank-you page and in order emails, the checkout validation and
 * agent persistence, and the invalid-order guard from #60.
 */

/**
 * Fresh frontend instance to call the hook callbacks directly. Construction
 * has no side effects (hooks are wired separately via register_hooks()), so
 * this does not disturb the plugin singleton's own registered instance.
 */
function frontend(): \Smart_Send\Frontend\Checkout
{
    return new \Smart_Send\Frontend\Checkout();
}

function capture_agent_display(WC_Order $order): string
{
    ob_start();
    frontend()->display_ss_shipping_agent($order);

    return ob_get_clean();
}

beforeEach(function (): void {
    with_ss_settings();
    if (is_null(WC()->cart)) {
        wc_load_cart();
    }
    $post = $_POST;
    $_POST = [];
    foreach (['ss_shipping_agents', 'ss_shipping_agents_context', 'ss_shipping_selected_pickup_point'] as $key) {
        WC()->session->set($key, null);
    }
    remember_cleanup_callback(function () use ($post): void {
        $_POST = $post;
        foreach (['ss_shipping_agents', 'ss_shipping_agents_context', 'ss_shipping_selected_pickup_point'] as $key) {
            WC()->session->set($key, null);
        }
    });
});

it('hooks the agent display into the thank-you page and order emails', function () {
    expect(has_action('woocommerce_order_details_after_order_table'))->not->toBeFalse()
        ->and(has_action('woocommerce_email_after_order_table'))->not->toBeFalse()
        ->and(has_action('woocommerce_after_shipping_rate'))->not->toBeFalse()
        ->and(has_action('woocommerce_after_checkout_validation'))->not->toBeFalse()
        ->and(has_action('woocommerce_checkout_create_order'))->not->toBeFalse();
});

it('renders the pickup point block for an order with a selected agent', function () {
    $product = create_simple_product(['price' => 100, 'weight' => 1]);
    $order   = create_order(['products' => [$product], 'shipping_method' => 'postnord_agent']);

    save_order_pickup_point($order->get_id(), sample_agent());

    $output = capture_agent_display($order);

    expect($output)->toContain('Pickup Point')
        ->toContain('Corner Shop')
        ->toContain('Main Street 1')
        ->toContain('DK 2300 Copenhagen')
        ->toContain('<address>');
});

it('renders nothing for an order without an agent', function () {
    $product = create_simple_product(['price' => 100, 'weight' => 1]);
    $order   = create_order(['products' => [$product], 'shipping_method' => 'postnord_homedelivery']);

    expect(capture_agent_display($order))->toBe('');
});

it('renders nothing for an order that does not exist in the database', function () {
    // Regression for #60: WooCommerce's email preview fires the order-details
    // hooks (woocommerce_email_after_order_table etc.) with a placeholder
    // order that has no database row, so wc_get_order() returns false inside
    // the meta accessors. This used to fatal with
    // "Call to a member function get_meta() on bool".
    $ghost = new WC_Order(); // Unsaved: get_id() is 0 and wc_get_order(0) is false.

    expect(capture_agent_display($ghost))->toBe('');

    // Fire the actual hooks the way the email templates do.
    ob_start();
    do_action('woocommerce_order_details_after_order_table', $ghost);
    do_action('woocommerce_email_after_order_table', $ghost, false);
    expect(ob_get_clean())->toBe('');
});

it('survives deleting the agent meta of an order that no longer exists', function () {
    // Invalid-order guard (#60): the deleted_post_meta hook can fire for
    // orders that were already removed; the handler must not fatal.
    SS_SHIPPING_WC()->order_meta()->delete_pickup_point(999999999);
    SS_SHIPPING_WC()->pickup_point_validator()
        ->action_deleted_agent_meta([1], 999999999, 'ss_shipping_order_agent_no', '1234');

    expect(true)->toBeTrue();
});

/** Checkout validation fixtures isolate selection rules; browsers cover rate selection. */
function frontend_checkout_validation(string $method_code = 'postnord_agent', array $address = []): WP_Error
{
    $frontend = new class($method_code) extends \Smart_Send\Frontend\Checkout {
        public function __construct(private string $method_code)
        {
            parent::__construct();
        }

        protected function chosen_agent_method_code(): ?\Smart_Send\Shipping_Method\Method_Code
        {
            return $this->method_code === '' ? null : new \Smart_Send\Shipping_Method\Method_Code($this->method_code);
        }
    };
    $errors = new WP_Error();
    $frontend->validate_agent_selected(array_merge([
        'shipping_country' => 'DK',
        'shipping_postcode' => '2300',
        'shipping_city' => 'Copenhagen',
        'shipping_address_1' => 'Islands Brygge 39',
    ], $address), $errors);
    return $errors;
}

it('rejects a missing selection even when the submitted form omits the dropdown', function (bool $present) {
    if ($present) {
        $_POST['ss_shipping_store_pickup'] = '';
    }
    expect(frontend_checkout_validation()->get_error_message())->toBe('A pickup point must be selected.');
})->with([true, false]);

it('re-resolves a posted point after the session cache expires and stages it until WooCommerce saves', function () {
    $capture = mock_smart_send_api(fn () => ss_api_response(200, ['data' => sample_agent()]));
    $_POST['ss_shipping_store_pickup'] = '1234';
    expect(frontend_checkout_validation()->has_errors())->toBeFalse();

    $order = create_order(['shipping_method' => 'postnord_agent']);
    frontend()->process_ss_pickup_points($order, []);
    expect($order->get_meta('ss_shipping_order_agent_no', true))->toBe('1234')
        ->and(wc_get_order($order->get_id())->get_meta('ss_shipping_order_agent_no', true))->toBe('');
    $order->save();
    expect(wc_get_order($order->get_id())->get_meta('_ss_shipping_order_agent', true)->company)->toBe('Corner Shop')
        ->and($capture->requests)->toHaveCount(1);
});

it('rejects an explicit selection that the API cannot verify without changing the order', function () {
    mock_smart_send_api(fn () => ss_api_response(404, ['message' => 'Point not found.']));
    $_POST['ss_shipping_store_pickup'] = '9999';
    expect(frontend_checkout_validation()->get_error_message())->toContain('could not be verified');
    $order = create_order(['shipping_method' => 'postnord_agent']);
    expect(fn () => frontend()->process_ss_pickup_points($order, []))->toThrow(Exception::class, 'could not be verified');
    expect(wc_get_order($order->get_id())->get_meta('ss_shipping_order_agent_no', true))->toBe('');
});

it('rejects an explicit choice from another carrier or destination country', function (string $carrier, string $country) {
    $capture = mock_smart_send_api(fn () => ss_api_response(200, ['data' => sample_agent()]));
    $_POST = [
        'ss_shipping_store_pickup' => '1234',
        'ss_shipping_pickup_origin' => 'explicit',
        'ss_shipping_pickup_carrier' => $carrier,
        'ss_shipping_pickup_country' => $country,
    ];
    expect(frontend_checkout_validation()->get_error_message())->toContain('could not be verified')
        ->and($capture->requests)->toBe([]);
})->with([['gls', 'DK'], ['postnord', 'SE']]);

it('ignores a stray pickup selection when the chosen rate is not an agent method', function () {
    $capture = mock_smart_send_api();
    $_POST['ss_shipping_store_pickup'] = '9999';
    expect(frontend_checkout_validation('')->has_errors())->toBeFalse();
    $order = create_order(['shipping_method' => 'postnord_homedelivery']);
    frontend()->process_ss_pickup_points($order, []);
    expect($order->get_meta('ss_shipping_order_agent_no', true))->toBe('')
        ->and($capture->requests)->toBe([]);
});

it('does not require a checkout selector for mapped free shipping', function () {
    with_ss_settings(['shipping_method_for_free_shipping' => 'postnord_agent']);
    $capture = mock_smart_send_api();
    $order = create_order();
    $shipping_item = new WC_Order_Item_Shipping();
    $shipping_item->set_method_id('free_shipping');
    $order->add_item($shipping_item);
    frontend()->process_ss_pickup_points($order, []);
    expect($order->get_meta('ss_shipping_order_agent_no', true))->toBe('')
        ->and($capture->requests)->toBe([]);
});

it('clears a resumed checkout orders old pickup point when the shopper changes to home delivery', function (bool $hpos) {
    with_option('woocommerce_custom_orders_table_enabled', $hpos ? 'yes' : 'no');
    $order = create_order(['shipping_method' => 'postnord_homedelivery']);
    save_order_pickup_point($order->get_id(), sample_agent());
    $order = wc_get_order($order->get_id());
    frontend()->process_ss_pickup_points($order, []);
    expect($order->get_meta('ss_shipping_order_agent_no', true))->toBe('')
        ->and($order->get_meta('_ss_shipping_order_agent', true))->toBe('');
    $order->save();
    expect(SS_SHIPPING_WC()->order_meta()->read($order->get_id())->get_pickup_point())->toBeNull()
        ->and(\Smart_Send\Delivery\Order_Meta::consume_staged_pickup_point($order->get_id(), null))->toBeFalse();
    cleanup_created_objects();
})->with([true, false]);

it('rejects omitting an explicit pickup choice even after an empty lookup for the current address', function () {
    mock_smart_send_api(fn ($url) => ss_api_response(200, [
        'data' => str_contains($url, 'agents/closest') ? [] : sample_agent(),
    ]));
    $lookup = new \Smart_Send\Delivery_Options\Pickup_Point_Lookup();
    $lookup->select('postnord', 'DK', '1234', true);
    $lookup->find_closest_by_address('postnord', 'DK', '2300', 'Copenhagen', 'Islands Brygge 39');
    expect(frontend_checkout_validation()->get_error_message())->toBe('A pickup point must be selected.');
});

it('clears a resumed orders earlier pickup point when the current checkout uses the no-point fallback', function (bool $hpos) {
    with_option('woocommerce_custom_orders_table_enabled', $hpos ? 'yes' : 'no');
    $order = create_order(['status' => 'pending', 'shipping_method' => 'postnord_agent']);
    save_order_pickup_point($order->get_id(), sample_agent());
    $order = new WC_Order($order->get_id());
    $order->read_meta_data(true);
    $capture = mock_smart_send_api(fn () => ss_api_response(200, ['data' => []]));
    (new \Smart_Send\Delivery_Options\Pickup_Point_Lookup())
        ->find_closest_by_address('postnord', 'DK', '2300', 'Copenhagen', 'Islands Brygge 39');

    expect(frontend_checkout_validation()->has_errors())->toBeFalse();
    frontend()->process_ss_pickup_points($order, []);
    expect($order->get_meta('ss_shipping_order_agent_no', true))->toBe('')
        ->and($order->get_meta('_ss_shipping_order_agent', true))->toBe('')
        ->and(wc_get_order($order->get_id())->get_meta('ss_shipping_order_agent_no', true))->toBe('1234');

    $order->save();
    $fresh = new WC_Order($order->get_id());
    $fresh->read_meta_data(true);
    expect($fresh->get_meta('ss_shipping_order_agent_no', true))->toBe('')
        ->and($fresh->get_meta('_ss_shipping_order_agent', true))->toBe('')
        ->and(\Smart_Send\Delivery\Order_Meta::consume_staged_pickup_point($order->get_id(), null))->toBeFalse()
        ->and($capture->requests)->toHaveCount(1);
    cleanup_created_objects();
})->with([true, false]);

it('allows no selection only after an empty lookup for the exact shipping address', function () {
    mock_smart_send_api(fn () => ss_api_response(200, ['data' => []]));
    (new \Smart_Send\Delivery_Options\Pickup_Point_Lookup())
        ->find_closest_by_address('postnord', 'DK', '2300', 'Copenhagen', 'Islands Brygge 39');
    expect(frontend_checkout_validation()->has_errors())->toBeFalse()
        ->and(frontend_checkout_validation('postnord_agent', ['shipping_postcode' => '8000'])->has_errors())->toBeTrue();
    $order = create_order(['shipping_method' => 'postnord_agent']);
    frontend()->process_ss_pickup_points($order, []);
    expect($order->get_meta('ss_shipping_order_agent_no', true))->toBe('');
});

it('does not accept an unscoped empty session cache as proof that no selection was possible', function () {
    WC()->session->set('ss_shipping_agents', []);
    expect(frontend_checkout_validation()->has_errors())->toBeTrue();
});

/**
 * Render the pickup point section for a chosen rate the way checkout does
 * (is_checkout() forced, method chosen in the session), with control over
 * the posted address fields and the customer's session-stored shipping
 * address - covering the first, non-AJAX page load, which posts nothing.
 */
function frontend_render_pickup_section(string $method_code = 'postnord_agent', array $post = [], array $customer_address = []): string
{
    if (is_null(WC()->cart)) {
        wc_load_cart();
    }

    add_filter('woocommerce_is_checkout', '__return_true');
    unset($_POST['s_country'], $_POST['s_postcode'], $_POST['s_city'], $_POST['s_address']);
    $_POST = array_merge($_POST, $post);
    WC()->session->set('chosen_shipping_methods', ['smart_send_shipping:1']);

    $customer = WC()->customer;
    $customer->set_shipping_country($customer_address['country'] ?? '');
    $customer->set_shipping_postcode($customer_address['postcode'] ?? '');
    $customer->set_shipping_city($customer_address['city'] ?? '');
    $customer->set_shipping_address_1($customer_address['address'] ?? '');

    remember_cleanup_callback(function (): void {
        remove_filter('woocommerce_is_checkout', '__return_true');
        unset($_POST['s_country'], $_POST['s_postcode'], $_POST['s_city'], $_POST['s_address']);
        WC()->session->set('chosen_shipping_methods', null);
        WC()->session->set('ss_shipping_agents', null);

        $customer = WC()->customer;
        $customer->set_shipping_country('');
        $customer->set_shipping_postcode('');
        $customer->set_shipping_city('');
        $customer->set_shipping_address_1('');
    });

    $rate = new WC_Shipping_Rate('smart_send_shipping:1', 'Smart Send', 49.0, [], 'smart_send_shipping', 1);
    $rate->add_meta_data('smart_send_shipping_method', $method_code);

    ob_start();
    frontend()->display_ss_pickup_points($rate, 0);

    return ob_get_clean();
}

it('renders the enter-your-address hint on the first page load, before any address is posted', function () {
    $capture = mock_smart_send_api();

    $output = frontend_render_pickup_section('postnord_agent');

    expect($output)->toContain('<div class="woocommerce-info ss-agent-info ss-agent-info--address_incomplete">Enter your shipping address to see available pickup points.</div>')
        ->and($capture->requests)->toBe([]);
});

it('renders nothing on first page load when the chosen method is not a pickup point method', function () {
    mock_smart_send_api();

    expect(frontend_render_pickup_section('postnord_homedelivery'))->toBe('');
});

it('falls back to the customer\'s session-stored shipping address when nothing is posted', function () {
    $capture = mock_smart_send_api(function () {
        return ss_api_response(200, ['data' => [sample_agent()]]);
    });

    $output = frontend_render_pickup_section('postnord_agent', [], [
        'country'  => 'DK',
        'postcode' => '2300',
        'city'     => 'Copenhagen',
        'address'  => 'Islands Brygge 39',
    ]);

    expect($output)->toContain('ss_shipping_store_pickup')
        ->and(end($capture->requests)['url'])->toContain('/agents/closest/carrier/postnord/country/DK/postalcode/2300/city/Copenhagen/street/Islands');
});

it('prefers the posted address fields over the customer\'s stored address', function () {
    $capture = mock_smart_send_api(function () {
        return ss_api_response(200, ['data' => [sample_agent()]]);
    });

    frontend_render_pickup_section(
        'postnord_agent',
        ['s_country' => 'DK', 's_postcode' => '8000', 's_city' => 'Aarhus', 's_address' => 'Posted Street 1'],
        ['country' => 'DK', 'postcode' => '2300', 'city' => 'Copenhagen', 'address' => 'Islands Brygge 39']
    );

    expect(end($capture->requests)['url'])->toContain('/postalcode/8000/city/Aarhus/street/Posted%20Street%201');
});

it('keeps an explicit compatible workplace pickup point outside refreshed nearest results', function () {
    $capture = mock_smart_send_api(function ($url) {
        return ss_api_response(200, ['data' => str_contains($url, '/closest/')
            ? [sample_agent(['agent_no' => '1111', 'company' => 'Nearby Shop'])]
            : sample_agent(['agent_no' => '9999', 'company' => 'Workplace Shop'])]);
    });
    $lookup = new \Smart_Send\Delivery_Options\Pickup_Point_Lookup();
    $lookup->select('postnord', 'DK', '9999', true);
    $output = frontend_render_pickup_section('postnord_agent', [
        's_country' => 'DK', 's_postcode' => '8000', 's_city' => 'Aarhus', 's_address' => 'Other Street 2',
    ]);
    expect($output)->toMatch('/value="9999"[^>]*selected/')
        ->and($output)->toContain('Workplace Shop')
        ->and($output)->toContain('Nearby Shop')
        ->and($lookup->is_selection_explicit())->toBeTrue()
        ->and($capture->requests)->toHaveCount(2);
});

it('follows the new nearest result only for an automatic selection', function () {
    with_ss_settings(['default_select_agent' => 'yes']);
    mock_smart_send_api(function ($url) {
        $agent_no = str_contains($url, '/postalcode/8000') ? '2222' : '1111';
        return ss_api_response(200, ['data' => [sample_agent(['agent_no' => $agent_no])]]);
    });
    $first = frontend_render_pickup_section('postnord_agent', [
        's_country' => 'DK', 's_postcode' => '2300', 's_city' => 'Copenhagen', 's_address' => 'Islands Brygge 39',
    ]);
    expect($first)->toMatch('/value="1111"[^>]*selected/');
    preg_match('/name="ss_shipping_pickup_address" value="([^"]*)"/', $first, $matches);
    $old_context = $matches[1];
    $posted = [
        'ss_shipping_store_pickup' => '1111',
        'ss_shipping_pickup_origin' => 'automatic',
        'ss_shipping_pickup_carrier' => 'postnord',
        'ss_shipping_pickup_country' => 'DK',
        'ss_shipping_pickup_address' => $old_context,
    ];
    $second = frontend_render_pickup_section('postnord_agent', [
        'post_data' => http_build_query($posted),
        's_country' => 'DK', 's_postcode' => '8000', 's_city' => 'Copenhagen', 's_address' => 'Islands Brygge 39',
    ]);
    expect($second)->toMatch('/value="2222"[^>]*selected/')
        ->and((new \Smart_Send\Delivery_Options\Pickup_Point_Lookup())->is_selection_explicit())->toBeFalse();
    $_POST = $posted;
    expect(frontend_checkout_validation('postnord_agent', ['shipping_postcode' => '8000'])->has_errors())->toBeTrue();
});

it('retains an explicit failed selection visibly instead of falling back to a different point', function () {
    with_ss_settings(['default_select_agent' => 'yes']);
    mock_smart_send_api(function ($url) {
        return str_contains($url, '/closest/')
            ? ss_api_response(200, ['data' => [sample_agent(['agent_no' => '1111'])]])
            : ss_api_response(404, ['message' => 'Point not found.']);
    });
    $output = frontend_render_pickup_section('postnord_agent', [
        'post_data' => http_build_query([
            'ss_shipping_store_pickup' => '9999',
            'ss_shipping_pickup_origin' => 'explicit',
            'ss_shipping_pickup_carrier' => 'postnord',
            'ss_shipping_pickup_country' => 'DK',
        ]),
        's_country' => 'DK', 's_postcode' => '2300', 's_city' => 'Copenhagen', 's_address' => 'Islands Brygge 39',
    ]);
    expect($output)->toContain('Please select a pickup point again.')
        ->and($output)->toMatch('/value="9999"[^>]*selected/')
        ->and($output)->not->toMatch('/value="1111"[^>]*selected/')
        ->and(frontend_checkout_validation()->has_errors())->toBeTrue();
});

it('invalidates a visible explicit choice after changing country without selecting a replacement', function () {
    mock_smart_send_api(fn () => ss_api_response(200, ['data' => [sample_agent(['agent_no' => '2222', 'country' => 'SE'])]]));
    $output = frontend_render_pickup_section('postnord_agent', [
        'post_data' => http_build_query([
            'ss_shipping_store_pickup' => '1234',
            'ss_shipping_pickup_origin' => 'explicit',
            'ss_shipping_pickup_carrier' => 'postnord',
            'ss_shipping_pickup_country' => 'DK',
        ]),
        's_country' => 'SE', 's_postcode' => '11122', 's_city' => 'Stockholm', 's_address' => 'Other Street 2',
    ]);
    expect($output)->toContain('Please select a pickup point again.')
        ->and($output)->toMatch('/value="1234"[^>]*selected/')
        ->and(frontend_checkout_validation('postnord_agent', ['shipping_country' => 'SE'])->has_errors())->toBeTrue();
});

it('normalizes slashed checkout addresses before validating an automatic choice', function () {
    with_ss_settings(['default_select_agent' => 'yes']);
    mock_smart_send_api(fn () => ss_api_response(200, ['data' => [sample_agent()]]));
    $output = frontend_render_pickup_section('postnord_agent', [
        's_country' => 'DK', 's_postcode' => '2300', 's_city' => 'Copenhagen',
        's_address' => wp_slash("King's Road 1"),
    ]);
    preg_match('/name="ss_shipping_pickup_address" value="([^"]+)"/', $output, $context);
    $_POST = [
        'ss_shipping_store_pickup' => '1234',
        'ss_shipping_pickup_origin' => 'automatic',
        'ss_shipping_pickup_carrier' => 'postnord',
        'ss_shipping_pickup_country' => 'DK',
        'ss_shipping_pickup_address' => $context[1],
    ];
    expect(frontend_checkout_validation('postnord_agent', ["shipping_address_1" => "King's Road 1"])->has_errors())->toBeFalse();
});

it('stages a changed pickup point on a resumed checkout order on either storage backend', function (bool $hpos) {
    with_option('woocommerce_custom_orders_table_enabled', $hpos ? 'yes' : 'no');
    $order = create_order(['shipping_method' => 'postnord_agent']);
    save_order_pickup_point($order->get_id(), sample_agent());
    $order = new WC_Order($order->get_id());
    $order->read_meta_data(true);
    $capture = mock_smart_send_api(fn () => ss_api_response(200, ['data' => sample_agent(['agent_no' => '5678', 'company' => 'Other Shop'])]));
    $_POST['ss_shipping_store_pickup'] = '5678';
    frontend()->process_ss_pickup_points($order, []);
    expect(wc_get_order($order->get_id())->get_meta('ss_shipping_order_agent_no', true))->toBe('1234');
    $order->save();
    $fresh = new WC_Order($order->get_id());
    $fresh->read_meta_data(true);
    expect($fresh->get_meta('ss_shipping_order_agent_no', true))->toBe('5678')
        ->and($fresh->get_meta('_ss_shipping_order_agent', true)->company)->toBe('Other Shop')
        ->and($capture->requests)->toHaveCount(1)
        ->and(\Smart_Send\Delivery\Order_Meta::consume_staged_pickup_point($order->get_id(), '5678'))->toBeFalse();
    if (! $hpos) {
        foreach ($fresh->get_meta_data() as $meta) {
            if ($meta->key === 'ss_shipping_order_agent_no') {
                // A later Custom Fields edit must still resolve its new number.
                expect(update_metadata_by_mid('post', $meta->id, '9999', 'ss_shipping_order_agent_no'))->toBeFalse();
            }
        }
        $fresh->read_meta_data(true);
        expect($fresh->get_meta('ss_shipping_order_agent_no', true))->toBe('5678')
            ->and($capture->requests)->toHaveCount(2);
    }
    cleanup_created_objects();
})->with([true, false]);
