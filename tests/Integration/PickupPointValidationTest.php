<?php

/*
 * Coverage for SS_Shipping_Pickup_Point_Validator (#139): the agent-number
 * validation extracted from the meta layer, including the deliberate v9
 * fix for the HPOS validation gap - validation used to hang exclusively
 * off update_post_metadata_by_mid/deleted_post_meta, which never fire on
 * HPOS stores, so hand-editing ss_shipping_order_agent_no on an HPOS
 * order skipped validation entirely. The HPOS order edit form seam
 * (woocommerce_process_shop_order_meta, running before WooCommerce's
 * CustomMetaBox applies $_POST['meta']) is exercised here the way the
 * edit screen drives it.
 */

/**
 * The order's meta id for the stored agent-number row.
 */
function agent_no_meta_id(WC_Order $order): int
{
    foreach ($order->get_meta_data() as $meta) {
        if ($meta->key === SS_Shipping_Order_Meta::META_AGENT_NO) {
            return (int) $meta->id;
        }
    }

    throw new RuntimeException('No agent-number meta row on the order.');
}

/**
 * An HPOS order with a stored (valid) pickup point selection, plus the
 * $_POST payload the HPOS Custom Fields box would send when hand-editing
 * the agent number to $new_agent_no.
 */
function prepare_hpos_agent_edit(string $new_agent_no): WC_Order
{
    with_option('woocommerce_custom_orders_table_enabled', 'yes');

    $product = create_simple_product(['price' => 100, 'weight' => 1]);
    $order   = create_order(['products' => [$product], 'shipping_method' => 'postnord_agent']);

    $handler = SS_SHIPPING_WC()->order_meta();
    $handler->save_ss_shipping_order_agent_no($order->get_id(), '1234');
    $handler->save_ss_shipping_order_agent($order->get_id(), sample_agent());

    $order   = wc_get_order($order->get_id());
    $meta_id = agent_no_meta_id($order);

    $_POST['meta'] = [
        $meta_id => ['key' => SS_Shipping_Order_Meta::META_AGENT_NO, 'value' => $new_agent_no],
    ];
    remember_cleanup_callback(function (): void {
        unset($_POST['meta'], $_POST['metakeyinput'], $_POST['metavalue']);
    });

    return $order;
}

/**
 * Apply $_POST['meta'] to the order the way WooCommerce's HPOS
 * CustomMetaBox::handle_metadata_changes() does after the validator ran.
 */
function apply_posted_meta(WC_Order $order): void
{
    foreach ($_POST['meta'] as $meta_id => $posted) {
        $order->update_meta_data($posted['key'], $posted['value'], $meta_id);
    }
    $order->save();
}

beforeEach(function (): void {
    with_ss_settings();
});

it('rejects an invalid agent number edited on an HPOS order (v9 fix: the HPOS validation gap)', function () {
    $order = prepare_hpos_agent_edit('9999');

    mock_smart_send_api(function () {
        return ss_api_response(404, ['code' => 'NoResults', 'message' => 'The agent was not found.']);
    });

    SS_SHIPPING_WC()->pickup_point_validator()
        ->validate_hpos_form_meta_changes($order->get_id(), $order);

    // The posted change was reverted, so applying the form payload (as
    // WooCommerce's CustomMetaBox does next) leaves the stored number and
    // the stored agent object untouched.
    apply_posted_meta($order);

    $fresh = wc_get_order($order->get_id());
    expect($fresh->get_meta(SS_Shipping_Order_Meta::META_AGENT_NO, true))->toBe('1234')
        ->and($fresh->get_meta(SS_Shipping_Order_Meta::META_AGENT, true)->agent_no)->toBe('1234');

    cleanup_created_objects();
});

it('accepts a valid agent number edited on an HPOS order and stores the agent object', function () {
    $order = prepare_hpos_agent_edit('5678');

    mock_smart_send_api(function () {
        return ss_api_response(200, ['data' => sample_agent(['agent_no' => '5678', 'company' => 'Other Shop'])]);
    });

    SS_SHIPPING_WC()->pickup_point_validator()
        ->validate_hpos_form_meta_changes($order->get_id(), $order);

    apply_posted_meta($order);

    $fresh = wc_get_order($order->get_id());
    expect($fresh->get_meta(SS_Shipping_Order_Meta::META_AGENT_NO, true))->toBe('5678')
        ->and($fresh->get_meta(SS_Shipping_Order_Meta::META_AGENT, true)->agent_no)->toBe('5678')
        ->and($fresh->get_meta(SS_Shipping_Order_Meta::META_AGENT, true)->company)->toBe('Other Shop');

    cleanup_created_objects();
});

it('registers both the legacy meta hooks and the HPOS edit seams', function () {
    expect(has_filter('update_post_metadata_by_mid'))->not->toBeFalse()
        ->and(has_action('deleted_post_meta'))->not->toBeFalse()
        ->and(has_action('woocommerce_process_shop_order_meta'))->not->toBeFalse()
        ->and(has_action('wp_ajax_woocommerce_order_add_meta'))->not->toBeFalse()
        ->and(has_action('wp_ajax_woocommerce_order_delete_meta'))->not->toBeFalse();
});

it('rejects an invalid agent number through the legacy validation entry point', function () {
    $product = create_simple_product(['price' => 100, 'weight' => 1]);
    $order   = create_order(['products' => [$product], 'shipping_method' => 'postnord_agent']);

    mock_smart_send_api(function () {
        return ss_api_response(404, ['code' => 'NoResults', 'message' => 'The agent was not found.']);
    });

    $result = SS_SHIPPING_WC()->pickup_point_validator()
        ->validate_and_store($order->get_id(), true, '9999');

    expect($result)->toBeString()
        ->and($result)->toContain('9999');
});
