<?php

/*
 * Coverage for \Smart_Send\Delivery_Options\Pickup_Point_Validator (#139): the agent-number
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
        if ($meta->key === \Smart_Send\Delivery\Order_Meta::META_AGENT_NO) {
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

    $previous_user = get_current_user_id();
    $user_id = wp_insert_user([
        'user_login' => 'pickup-form-' . wp_generate_uuid4(),
        'user_pass' => wp_generate_password(),
        'role' => 'shop_manager',
    ]);
    wp_set_current_user($user_id);
    remember_cleanup_callback(function () use ($previous_user, $user_id): void {
        wp_set_current_user($previous_user);
        require_once ABSPATH . 'wp-admin/includes/user.php';
        wp_delete_user($user_id);
    });

    $product = create_simple_product(['price' => 100, 'weight' => 1]);
    $order   = create_order(['products' => [$product], 'shipping_method' => 'postnord_agent']);

    save_order_pickup_point($order->get_id(), sample_agent());

    $order   = wc_get_order($order->get_id());
    $meta_id = agent_no_meta_id($order);

    $_POST['meta'] = [
        $meta_id => ['key' => \Smart_Send\Delivery\Order_Meta::META_AGENT_NO, 'value' => $new_agent_no],
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
    expect($fresh->get_meta(\Smart_Send\Delivery\Order_Meta::META_AGENT_NO, true))->toBe('1234')
        ->and($fresh->get_meta(\Smart_Send\Delivery\Order_Meta::META_AGENT, true)->agent_no)->toBe('1234');

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
    expect($fresh->get_meta(\Smart_Send\Delivery\Order_Meta::META_AGENT_NO, true))->toBe('5678')
        ->and($fresh->get_meta(\Smart_Send\Delivery\Order_Meta::META_AGENT, true)->agent_no)->toBe('5678')
        ->and($fresh->get_meta(\Smart_Send\Delivery\Order_Meta::META_AGENT, true)->company)->toBe('Other Shop');

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

/*
 * The shared "find a pickup point by agent number" lookup (#182):
 * \Smart_Send\Delivery_Options\Pickup_Point_Lookup::find_by_agent_no() is the one API call
 * behind the Custom Fields validator and the fulfillment service's
 * pickup point override; a miss is \Smart_Send\Delivery_Options\Exceptions\Pickup_Point_Not_Found_Exception.
 */

it('resolves an agent number into a pickup point value object through the shared lookup', function () {
    $capture = mock_smart_send_api(function () {
        return ss_api_response(200, ['data' => sample_agent(['agent_no' => '5678', 'company' => 'Other Shop'])]);
    });

    $pickup_point = SS_SHIPPING_WC()->pickup_point_lookup()->find_by_agent_no('postnord', 'DK', '5678');

    expect($pickup_point)->toBeInstanceOf(\Smart_Send\Delivery\Pickup_Point::class)
        ->and($pickup_point->get_agent_no())->toBe('5678')
        ->and($pickup_point->get_company())->toBe('Other Shop')
        ->and($pickup_point->is_agent_no_only())->toBeFalse()
        // The API object round-trips losslessly into the stored form.
        ->and($pickup_point->to_array())->toEqual((array) sample_agent(['agent_no' => '5678', 'company' => 'Other Shop']))
        ->and($capture->requests)->toHaveCount(1)
        ->and($capture->requests[0]['url'])->toContain('agents/carrier/postnord/country/DK/agentno/5678');
});

it('throws \Smart_Send\Delivery_Options\Exceptions\Pickup_Point_Not_Found_Exception when the API knows no such agent number', function () {
    mock_smart_send_api(function () {
        return ss_api_response(404, ['code' => 'NoResults', 'message' => 'The agent was not found.']);
    });

    try {
        SS_SHIPPING_WC()->pickup_point_lookup()->find_by_agent_no('postnord', 'DK', '9999');
        $this->fail('Expected \Smart_Send\Delivery_Options\Exceptions\Pickup_Point_Not_Found_Exception.');
    } catch (\Smart_Send\Delivery_Options\Exceptions\Pickup_Point_Not_Found_Exception $e) {
        expect($e->getMessage())->toBe('The agent number entered, 9999, was not found.')
            ->and($e->carrier())->toBe('postnord')
            ->and($e->agent_no())->toBe('9999')
            ->and($e->getPrevious())->toBeInstanceOf(\Smart_Send\API\Exceptions\HTTP_Client_Exception::class);
    }

    // A 200 without a pickup point object is a miss too.
    mock_smart_send_api(function () {
        return ss_api_response(200, ['data' => null]);
    });
    expect(fn () => SS_SHIPPING_WC()->pickup_point_lookup()->find_by_agent_no('postnord', 'DK', '9999'))
        ->toThrow(\Smart_Send\Delivery_Options\Exceptions\Pickup_Point_Not_Found_Exception::class);
});

it('stores the agent object byte-identically through the validator, which now delegates to the shared lookup', function () {
    // Message parity with the previous inline API call is pinned by the
    // other tests in this file; this pins that the stored object is the
    // API's agent object exactly as received (to_object() of the DTO).
    $product = create_simple_product(['price' => 100, 'weight' => 1]);
    $order   = create_order(['products' => [$product], 'shipping_method' => 'postnord_agent']);

    $api_agent = sample_agent(['agent_no' => '5678', 'company' => 'Other Shop', 'custom_extra' => 'kept']);
    $capture   = mock_smart_send_api(function () use ($api_agent) {
        return ss_api_response(200, ['data' => $api_agent]);
    });

    $result = SS_SHIPPING_WC()->pickup_point_validator()->validate_and_store($order->get_id(), true, '5678');

    expect($result)->toBeTrue()
        ->and($capture->requests)->toHaveCount(1)
        ->and($capture->requests[0]['url'])->toContain('agents/carrier/postnord/country/DK/agentno/5678');

    // Loose equality on purpose: the fixture's int id becomes the DTO's
    // string internal id (the real API id is a UUID string, so nothing
    // changes for real data - PickupPointDtoTest pins that byte for byte).
    $stored = wc_get_order($order->get_id())->get_meta(\Smart_Send\Delivery\Order_Meta::META_AGENT, true);
    expect($stored)->toBeObject()
        ->and(array_keys((array) $stored))->toBe(array_keys((array) $api_agent))
        ->and((array) $stored)->toEqual((array) $api_agent)
        ->and($stored->custom_extra)->toBe('kept');
});

it('does not re-validate a pickup point the repository writes programmatically (legacy storage)', function () {
    // On legacy post storage WooCommerce updates an existing meta row via
    // update_metadata_by_mid, which is the validator's hook. A repository
    // write() carries a complete, typed pickup point (e.g. the one a
    // successful booking was submitted with, #182), so the validator must
    // not fire an API lookup for it - and must not be able to refuse it.
    with_option('woocommerce_custom_orders_table_enabled', 'no');

    $product = create_simple_product(['price' => 100, 'weight' => 1]);
    $order   = create_order(['products' => [$product], 'shipping_method' => 'postnord_agent']);
    save_order_pickup_point($order->get_id(), sample_agent());

    $capture = mock_smart_send_api(function () {
        throw new RuntimeException('No API call expected for a repository write.');
    });

    // Changing the stored pickup point through the repository...
    save_order_pickup_point($order->get_id(), sample_agent(['agent_no' => '5678', 'company' => 'Other Shop']));

    $fresh = wc_get_order($order->get_id());
    expect($capture->requests)->toBe([])
        ->and($fresh->get_meta(\Smart_Send\Delivery\Order_Meta::META_AGENT_NO, true))->toBe('5678')
        ->and($fresh->get_meta(\Smart_Send\Delivery\Order_Meta::META_AGENT, true)->company)->toBe('Other Shop');

    // ...and clearing it, likewise without the deleted_post_meta cascade
    // needing to do anything (both keys are gone).
    SS_SHIPPING_WC()->order_meta()->write($order->get_id(), (new \Smart_Send\Delivery\Delivery_Details())->clear_pickup_point());

    $fresh = wc_get_order($order->get_id());
    expect($capture->requests)->toBe([])
        ->and($fresh->get_meta(\Smart_Send\Delivery\Order_Meta::META_AGENT_NO, true))->toBe('')
        ->and($fresh->get_meta(\Smart_Send\Delivery\Order_Meta::META_AGENT, true))->toBe('')
        ->and(\Smart_Send\Delivery\Order_Meta::is_writing())->toBeFalse();

    cleanup_created_objects();
});
