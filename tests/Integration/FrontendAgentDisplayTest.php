<?php

/*
 * Characterization tests for SS_Shipping_Frontend: the pick-up point block
 * on the thank-you page and in order emails, the checkout validation and
 * agent persistence, and the invalid-order guard from #60.
 */

/**
 * Fresh frontend instance to call the hook callbacks directly. (The plugin
 * singleton keeps its own instance private; constructing another one only
 * re-adds idempotent hooks.)
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

it('renders the pick-up point block for an order with a selected agent', function () {
    $product = create_simple_product(['price' => 100, 'weight' => 1]);
    $order   = create_order(['products' => [$product], 'shipping_method' => 'postnord_agent']);

    $handler = SS_SHIPPING_WC()->get_ss_shipping_wc_order();
    $handler->save_ss_shipping_order_agent_no($order->get_id(), '1234');
    $handler->save_ss_shipping_order_agent($order->get_id(), sample_agent());

    $output = capture_agent_display($order);

    expect($output)->toContain('Pick-up Point')
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

it('survives deleting the agent meta of an order that no longer exists', function () {
    // Invalid-order guard (#60): the deleted_post_meta hook can fire for
    // orders that were already removed; the handler must not fatal.
    $handler = SS_SHIPPING_WC()->get_ss_shipping_wc_order();

    $handler->delete_ss_shipping_order_agent(999999999);
    $handler->action_deleted_agent_meta([1], 999999999, 'ss_shipping_order_agent_no', '1234');

    expect(true)->toBeTrue();
});

it('rejects checkout when the pick-up point dropdown is present but empty', function () {
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
    expect($notices[0]['notice'])->toBe('A pick-up point must be selected.');
});

it('accepts checkout when a pick-up point is selected', function () {
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

    $handler = SS_SHIPPING_WC()->get_ss_shipping_wc_order();
    expect($handler->get_ss_shipping_order_agent_no($order->get_id()))->toBe('1234')
        ->and($handler->get_ss_shipping_order_agent($order->get_id())->company)->toBe('Corner Shop');
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

    expect(SS_SHIPPING_WC()->get_ss_shipping_wc_order()->get_ss_shipping_order_agent_no($order->get_id()))
        ->toBeNull();
});
