<?php

/*
 * Characterization tests for SS_Shipping_Frontend: the pickup point block
 * on the thank-you page and in order emails, the checkout validation and
 * agent persistence, and the invalid-order guard from #60.
 */

/**
 * Fresh frontend instance to call the hook callbacks directly. Construction
 * has no side effects (hooks are wired separately via register_hooks()), so
 * this does not disturb the plugin singleton's own registered instance.
 */
function frontend(): SS_Shipping_Frontend
{
    return new SS_Shipping_Frontend();
}

function capture_agent_display(WC_Order $order): string
{
    ob_start();
    frontend()->display_ss_shipping_agent($order);

    return ob_get_clean();
}

beforeEach(function (): void {
    with_ss_settings();
});

it('hooks the agent display into the thank-you page and order emails', function () {
    expect(has_action('woocommerce_order_details_after_order_table'))->not->toBeFalse()
        ->and(has_action('woocommerce_email_after_order_table'))->not->toBeFalse()
        ->and(has_action('woocommerce_after_shipping_rate'))->not->toBeFalse()
        ->and(has_action('woocommerce_checkout_process'))->not->toBeFalse()
        ->and(has_action('woocommerce_checkout_order_processed'))->not->toBeFalse();
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

it('rejects checkout when the pickup point dropdown is present but empty', function () {
    if (is_null(WC()->cart)) {
        wc_load_cart();
    }
    wc_clear_notices();

    $_POST['ss_shipping_store_pickup'] = '';
    remember_cleanup_callback(function (): void {
        unset($_POST['ss_shipping_store_pickup']);
        wc_clear_notices();
    });

    frontend()->validate_agent_selected();

    expect(wc_notice_count('error'))->toBe(1);

    $notices = wc_get_notices('error');
    expect($notices[0]['notice'])->toBe('A pickup point must be selected.');
});

it('accepts checkout when a pickup point is selected', function () {
    if (is_null(WC()->cart)) {
        wc_load_cart();
    }
    wc_clear_notices();

    $_POST['ss_shipping_store_pickup'] = '1234';
    remember_cleanup_callback(function (): void {
        unset($_POST['ss_shipping_store_pickup']);
        wc_clear_notices();
    });

    frontend()->validate_agent_selected();

    expect(wc_notice_count('error'))->toBe(0);
});

it('accepts checkout when no pickup point dropdown was rendered because none were found', function () {
    // The none-found case on the classic checkout: the lookup returns an
    // empty result, display_ss_pickup_points() renders the "Shipping to
    // closest pickup point" fallback instead of the dropdown (covered by
    // PickupLookupDebugTest), so the submission carries NO
    // ss_shipping_store_pickup field at all - and the order must go through
    // without a pickup point selection.
    if (is_null(WC()->cart)) {
        wc_load_cart();
    }
    wc_clear_notices();

    mock_smart_send_api(function () {
        return ss_api_response(200, ['data' => []]);
    });

    remember_cleanup_callback(function (): void {
        WC()->session->set('ss_shipping_agents', null);
        wc_clear_notices();
    });

    // The render-time lookup finds nothing and caches the EMPTY result in
    // the session (replacing any stale points from a previous address).
    $points = (new SS_Shipping_Pickup_Point_Lookup())
        ->find_closest_by_address('postnord', 'DK', '2300', 'Copenhagen', 'Islands Brygge 39');
    expect($points)->toBe([])
        ->and(WC()->session->get('ss_shipping_agents'))->toBe([]);

    // No dropdown rendered -> the field is absent from the POST ->
    // validation passes.
    unset($_POST['ss_shipping_store_pickup']);
    frontend()->validate_agent_selected();
    expect(wc_notice_count('error'))->toBe(0);

    // And checkout persistence writes no pickup point meta.
    $order = create_order(['shipping_method' => 'postnord_agent']);
    frontend()->process_ss_pickup_points($order->get_id(), []);
    expect(SS_SHIPPING_WC()->order_meta()->read($order->get_id())->get_pickup_point())->toBeNull();
});

it('saves the selected agent from the session onto the order at checkout', function () {
    if (is_null(WC()->cart)) {
        wc_load_cart();
    }

    $product = create_simple_product(['price' => 100, 'weight' => 1]);
    $order   = create_order(['products' => [$product], 'shipping_method' => 'postnord_agent']);

    WC()->session->set('ss_shipping_agents', [
        sample_agent(['agent_no' => '1111', 'company' => 'Other Shop']),
        sample_agent(['agent_no' => '1234', 'company' => 'Corner Shop']),
    ]);
    $_POST['ss_shipping_store_pickup'] = '1234';
    remember_cleanup_callback(function (): void {
        unset($_POST['ss_shipping_store_pickup']);
        WC()->session->set('ss_shipping_agents', null);
    });

    frontend()->process_ss_pickup_points($order->get_id(), []);

    $pickup_point = SS_SHIPPING_WC()->order_meta()->read($order->get_id())->get_pickup_point();
    expect($pickup_point->get_agent_no())->toBe('1234')
        ->and($pickup_point->get_company())->toBe('Corner Shop');
    // The frozen meta keys carry the selection.
    $fresh = wc_get_order($order->get_id());
    expect($fresh->get_meta('ss_shipping_order_agent_no', true))->toBe('1234')
        ->and($fresh->get_meta('_ss_shipping_order_agent', true)->company)->toBe('Corner Shop');
});

it('saves nothing when the posted agent is not in the session list', function () {
    if (is_null(WC()->cart)) {
        wc_load_cart();
    }

    $product = create_simple_product(['price' => 100, 'weight' => 1]);
    $order   = create_order(['products' => [$product], 'shipping_method' => 'postnord_agent']);

    WC()->session->set('ss_shipping_agents', [sample_agent(['agent_no' => '1111'])]);
    $_POST['ss_shipping_store_pickup'] = '9999';
    remember_cleanup_callback(function (): void {
        unset($_POST['ss_shipping_store_pickup']);
        WC()->session->set('ss_shipping_agents', null);
    });

    frontend()->process_ss_pickup_points($order->get_id(), []);

    expect(SS_SHIPPING_WC()->order_meta()->read($order->get_id())->get_pickup_point())
        ->toBeNull();
});
