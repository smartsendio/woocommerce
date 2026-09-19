<?php

use Smart_Send\Booking\Order_Reader;
use Smart_Send\Booking\Shipment_Builder;
use Smart_Send\Delivery\Delivery_Details;
use Smart_Send\Delivery\Order_Meta;
use Smart_Send\Delivery\Parcel_Plan;

/*
 * Hook-contract tests for the documented Subscriptions renewal lifecycle.
 *
 * The commercial Subscriptions package is NOT installed here. A deliberately
 * small fixture copies real WooCommerce orders/items and dispatches the public
 * filters in the documented order; this does not execute the renewal engine,
 * scheduler, payment processing or WC_Subscription objects.
 *
 * Contract sources:
 * https://developer.woocommerce.com/2023/03/07/woocommerce-subscriptions-hpos-understanding-next-steps/
 * https://github.com/Automattic/woocommerce-subscriptions-core/blob/trunk/includes/class-wc-subscriptions-data-copier.php
 * https://github.com/Automattic/woocommerce-subscriptions-core/blob/trunk/includes/wcs-order-functions.php
 */

function ss_order_meta_key_constants(): array
{
    $constants = (new ReflectionClass(Order_Meta::class))->getConstants();

    return array_filter(
        $constants,
        fn (string $name): bool => str_starts_with($name, 'META_'),
        ARRAY_FILTER_USE_KEY
    );
}

/** The documented copier exposes raw posts values but native HPOS values. */
function ss_subscription_contract_metadata(WC_Order $source): array
{
    $metadata = [];
    $hpos = Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
    foreach ($source->get_meta_data() as $entry) {
        $metadata[$entry->key] = $hpos ? $entry->value : maybe_serialize($entry->value);
    }

    return $metadata;
}

/**
 * Emulate only the copy/filter boundary using WooCommerce CRUD. Callers can
 * reorder/filter the marked items or edit the persisted target before the
 * final filter, as another extension could. Never call this a real renewal.
 *
 * @return array{0: WC_Order, 1: array<int, int>} Target and fixture-only ID map.
 */
function ss_subscription_contract_copy(WC_Order $source, ?callable $filter_items = null, ?callable $before_created = null): array
{
    $target = create_order();
    $metadata = ss_subscription_contract_metadata($source);
    $metadata = apply_filters('wc_subscriptions_renewal_order_data', $metadata, $target, $source);
    foreach ($metadata as $key => $value) {
        $target->update_meta_data($key, maybe_unserialize($value));
    }
    $target->save();

    $items = apply_filters('wcs_renewal_order_items', $source->get_items(['line_item', 'shipping']), $target, $source);
    if ($filter_items) {
        $items = $filter_items($items, $source);
    }
    $id_map = [];
    foreach ($items as $item) {
        $copy = $item instanceof WC_Order_Item_Product ? new WC_Order_Item_Product() : new WC_Order_Item_Shipping();
        $copy->set_props(array_diff_key($item->get_data(), array_flip(['id', 'order_id', 'meta_data'])));
        foreach ($item->get_meta_data() as $entry) {
            $copy->update_meta_data($entry->key, $entry->value);
        }
        $target->add_item($copy);
        $copy->save();
        $id_map[$item->get_id()] = $copy->get_id();
    }
    $target->calculate_totals(false);
    $target->save();
    $target = new WC_Order($target->get_id());
    if ($before_created) {
        $before_created($target, $id_map);
        $target->save();
        $target = new WC_Order($target->get_id());
    }

    $filtered = apply_filters('wcs_renewal_order_created', $target, $source);
    expect($filtered)->toBe($target);

    return [new WC_Order($target->get_id()), $id_map];
}

/** Fixture with repeated catalogue identity but two different purchased lines. */
function ss_subscription_contract_source(): WC_Order
{
    $product = create_simple_product(['price' => 100, 'weight' => 1]);
    $source = create_order([
        'products' => [[$product, 2], [$product, 1]],
        'shipping_method' => 'postnord_homedelivery',
        'auto_return' => 'yes',
    ]);
    $lines = array_values($source->get_items());
    foreach ([['First purchased option', '150'], ['Second purchased option', '70']] as $index => [$name, $total]) {
        $lines[$index]->set_name($name);
        $lines[$index]->set_subtotal($total);
        $lines[$index]->set_total($total);
        $lines[$index]->update_meta_data('merchant_line_option', 'option-' . $index);
        $lines[$index]->save();
    }
    $source->calculate_totals(false);
    save_order_pickup_point($source->get_id(), sample_agent());
    save_order_parcels($source->get_id(), ['specs' => [
        ['reference' => 'Parcel A', 'items' => [['order_item_id' => $lines[0]->get_id(), 'quantity' => 1, 'name' => 'First unit']]],
        ['reference' => 'Parcel B', 'items' => [
            ['order_item_id' => $lines[0]->get_id(), 'quantity' => 1, 'name' => 'Second unit'],
            ['order_item_id' => $lines[1]->get_id(), 'quantity' => 1, 'name' => 'Separate purchased option'],
        ]],
    ]]);
    $source = new WC_Order($source->get_id());
    $source->update_meta_data(Order_Meta::META_LABEL_ID, 'parent-outbound-shipment');
    $source->update_meta_data(Order_Meta::META_RETURN_LABEL_ID, 'parent-return-shipment');
    $source->update_meta_data(Order_Meta::META_LABELS, [['direction' => 'outbound', 'shipment_id' => 'parent-outbound-shipment', 'booked_at' => '2026-09-17T12:00:00+00:00']]);
    $source->update_meta_data('_wc_shipment_tracking_items', [['tracking_number' => 'PARENT-TRACKING']]);
    $source->update_meta_data('merchant_custom_setting', ['keep' => true]);
    $source->save();

    return $source;
}

function ss_subscription_expect_no_item_markers(WC_Order $order): void
{
    foreach ($order->get_items(['line_item', 'shipping']) as $item) {
        expect($item->meta_exists('_smart_send_renewal_source_item'))->toBeFalse();
    }
}

beforeEach(function (): void {
    with_ss_settings();
    with_option('woocommerce_weight_unit', 'kg');
});

test('every Smart Send meta key is classified exactly once', function () {
    $constants = ss_order_meta_key_constants();
    $booking_outcome = Order_Meta::booking_outcome_meta_keys();
    $delivery_config = Order_Meta::delivery_configuration_meta_keys();

    expect($constants)->not->toBeEmpty()
        ->and($booking_outcome)->toContain(Order_Meta::META_LABELS)
        ->and(array_intersect($booking_outcome, $delivery_config))->toBe([])
        ->and(array_values($constants))->toEqualCanonicalizing(array_merge($booking_outcome, $delivery_config))
        ->and(Order_Meta::all_meta_keys())->toEqualCanonicalizing(array_values($constants));
});

it('filters booking outcomes through the modern data hook while retaining configuration and unrelated metadata', function (bool $hpos) {
    with_option('woocommerce_custom_orders_table_enabled', $hpos ? 'yes' : 'no');
    $source = ss_subscription_contract_source();
    $metadata = ss_subscription_contract_metadata($source);
    $filtered = apply_filters('wc_subscriptions_renewal_order_data', $metadata, create_order(), $source);
    $expected_metadata = array_diff_key($metadata, array_flip(array_merge(Order_Meta::booking_outcome_meta_keys(), ['_wc_shipment_tracking_items'])));
    expect($filtered)->toBe($expected_metadata);
    if ($hpos) {
        expect($filtered[Order_Meta::META_PARCELS])->toBeArray();
    } else {
        expect($filtered[Order_Meta::META_PARCELS])->toBeString();
    }
    [$target] = ss_subscription_contract_copy($source);

    foreach (array_merge(Order_Meta::booking_outcome_meta_keys(), ['_wc_shipment_tracking_items']) as $key) {
        expect($target->meta_exists($key))->toBeFalse()
            ->and((new WC_Order($source->get_id()))->get_meta($key))->toEqual($source->get_meta($key));
    }
    expect($target->get_meta(Order_Meta::META_AGENT))->toEqual($source->get_meta(Order_Meta::META_AGENT))
        ->and($target->get_meta(Order_Meta::META_AGENT_NO))->toBe('1234')
        ->and($target->get_meta('merchant_custom_setting'))->toBe(['keep' => true]);
    $shipping = array_values($target->get_items('shipping'))[0];
    expect($shipping->get_method_id())->toBe('smart_send_shipping')
        ->and($shipping->get_meta('smart_send_shipping_method'))->toBe('postnord_homedelivery')
        ->and($shipping->get_meta('smart_send_return_method'))->toBe('postnord_returndropoff')
        ->and($shipping->get_meta('smart_send_auto_generate_return_label'))->toBe('yes');
    ss_subscription_expect_no_item_markers($target);
    ss_subscription_expect_no_item_markers(new WC_Order($source->get_id()));
    cleanup_created_objects();
})->with(['HPOS' => true, 'posts' => false]);

it('marks cloned product lines without mutating the source objects or shipping items', function (bool $hpos) {
    with_option('woocommerce_custom_orders_table_enabled', $hpos ? 'yes' : 'no');
    $source = ss_subscription_contract_source();
    $target = create_order();
    $originals = $source->get_items(['line_item', 'shipping']);
    $filtered = apply_filters('wcs_renewal_order_items', $originals, $target, $source);

    foreach ($originals as $id => $item) {
        expect($item->meta_exists('_smart_send_renewal_source_item'))->toBeFalse();
        if ($item instanceof WC_Order_Item_Product) {
            expect($filtered[$id])->not->toBe($item)
                ->and($filtered[$id]->get_meta('_smart_send_renewal_source_item'))->toBe(['order_id' => $source->get_id(), 'item_id' => $id]);
        } else {
            expect($filtered[$id])->toBe($item)
                ->and($filtered[$id]->meta_exists('_smart_send_renewal_source_item'))->toBeFalse();
        }
    }
    ss_subscription_expect_no_item_markers(new WC_Order($source->get_id()));
    cleanup_created_objects();
})->with(['HPOS' => true, 'posts' => false]);

it('remaps exact purchased-line identities when duplicate products are copied in reverse order', function (bool $hpos) {
    with_option('woocommerce_custom_orders_table_enabled', $hpos ? 'yes' : 'no');
    $source = ss_subscription_contract_source();
    $source_plan = $source->get_meta(Order_Meta::META_PARCELS);
    [$target, $id_map] = ss_subscription_contract_copy($source, fn ($items) => array_reverse($items, true));

    $expected = $source_plan;
    foreach ($expected['specs'] as &$spec) {
        foreach ($spec['items'] as &$allocation) {
            $allocation['order_item_id'] = $id_map[$allocation['order_item_id']];
        }
        unset($allocation);
    }
    unset($spec);
    expect($target->get_meta(Order_Meta::META_PARCELS))->toBe($expected)
        ->and((new WC_Order($source->get_id()))->get_meta(Order_Meta::META_PARCELS))->toBe($source_plan)
        ->and(SS_SHIPPING_WC()->order_meta()->parcel_plan_error($target))->toBeNull();

    $reader = new Order_Reader($target);
    $plan = Parcel_Plan::from_array($expected);
    expect($plan->validation_errors($reader->get_items_data()))->toBe([]);
    $details = SS_SHIPPING_WC()->order_meta()->read($target)->set_shipping_method('postnord_homedelivery');
    $parcels = (new Shipment_Builder($target, $reader))->build($details)->get_parcels();
    expect($parcels)->toHaveCount(2)
        ->and($parcels[0]->get_items()[0]['name'])->toBe('First purchased option')
        ->and($parcels[0]->get_items()[0]['total_net_amount'])->toBe(75.0)
        ->and(array_column($parcels[1]->get_items(), 'name'))->toBe(['First purchased option', 'Second purchased option'])
        ->and(array_column($parcels[1]->get_items(), 'total_net_amount'))->toBe([75.0, 70.0]);
    ss_subscription_expect_no_item_markers($target);
    ss_subscription_expect_no_item_markers(new WC_Order($source->get_id()));
    $requests = mock_smart_send_api(fn () => ss_api_response(200, ['data' => ss_api_shipment_data(['shipment_id' => 'renewal-own-shipment'])]));
    $result = SS_SHIPPING_WC()->fulfillment()->fulfill_outbound($target, false, null, false);
    expect($result->is_successful())->toBeTrue()
        ->and($requests->requests)->toHaveCount(1)
        ->and((new WC_Order($target->get_id()))->get_meta(Order_Meta::META_LABEL_ID))->toBe('renewal-own-shipment')
        ->and((new WC_Order($target->get_id()))->meta_exists(Order_Meta::META_RETURN_LABEL_ID))->toBeFalse()
        ->and((new WC_Order($source->get_id()))->get_meta(Order_Meta::META_LABEL_ID))->toBe('parent-outbound-shipment');
    cleanup_created_objects();
})->with(['HPOS' => true, 'posts' => false]);

it('retains absent empty and itemless plans without introducing source-line references', function (bool $hpos, string $kind) {
    with_option('woocommerce_custom_orders_table_enabled', $hpos ? 'yes' : 'no');
    $source = ss_subscription_contract_source();
    if ($kind === 'absent') {
        $source->delete_meta_data(Order_Meta::META_PARCELS);
    } else {
        $raw = $kind === 'empty' ? ['specs' => []] : ['specs' => [['reference' => 'Everything together', 'items' => []]]];
        $source->update_meta_data(Order_Meta::META_PARCELS, Parcel_Plan::from_array($raw)->to_array());
    }
    $source->save();
    [$target] = ss_subscription_contract_copy($source, null, function (WC_Order $target) use ($kind, $source): void {
        if ($kind === 'absent') {
            // A removed plan must not leave transient linkage on the order.
            $line = array_values($target->get_items())[0];
            $line->update_meta_data('_smart_send_renewal_source_item', ['order_id' => $source->get_id(), 'item_id' => array_key_first($source->get_items())]);
            $line->save();
        }
    });

    expect($target->meta_exists(Order_Meta::META_PARCELS))->toBe($kind !== 'absent')
        ->and($target->get_meta(Order_Meta::META_PARCELS))->toBe($source->get_meta(Order_Meta::META_PARCELS))
        ->and(SS_SHIPPING_WC()->order_meta()->parcel_plan_error($target))->toBeNull();
    ss_subscription_expect_no_item_markers($target);
    ss_subscription_expect_no_item_markers($source);
    cleanup_created_objects();
})->with(['HPOS' => true, 'posts' => false])->with(['absent', 'empty', 'itemless']);

it('requires an explicit parcel reset when a copied allocation cannot be trusted', function (bool $hpos, string $fault) {
    with_option('woocommerce_custom_orders_table_enabled', $hpos ? 'yes' : 'no');
    $source = ss_subscription_contract_source();
    $raw = $source->get_meta(Order_Meta::META_PARCELS);
    if ($fault === 'legacy') {
        $raw = [['id' => array_values($source->get_items())[0]->get_product_id(), 'value' => '2']];
    } elseif ($fault === 'stale') {
        $raw['specs'][0]['items'][0]['order_item_id'] = 2147483647;
    } elseif ($fault === 'source quantity') {
        $line = array_values($source->get_items())[0];
        $line->set_quantity(3);
        $line->save();
    }
    $source->update_meta_data(Order_Meta::META_PARCELS, $raw);
    $source->save();

    [$target] = ss_subscription_contract_copy($source, null, function (WC_Order $target) use ($fault, $source): void {
        $lines = array_values($target->get_items());
        if ($fault === 'missing marker') {
            $lines[0]->delete_meta_data('_smart_send_renewal_source_item');
        } elseif ($fault === 'duplicate marker') {
            $lines[1]->update_meta_data('_smart_send_renewal_source_item', $lines[0]->get_meta('_smart_send_renewal_source_item'));
            $lines[1]->save();
        } elseif ($fault === 'foreign marker') {
            $marker = $lines[0]->get_meta('_smart_send_renewal_source_item');
            $marker['order_id'] = $source->get_id() + 1000000;
            $lines[0]->update_meta_data('_smart_send_renewal_source_item', $marker);
        } elseif ($fault === 'malformed marker') {
            $marker = $lines[0]->get_meta('_smart_send_renewal_source_item');
            $marker['item_id'] = ['unexpected' => 'array'];
            $lines[0]->update_meta_data('_smart_send_renewal_source_item', $marker);
        } elseif ($fault === 'target quantity') {
            $lines[0]->set_quantity(3);
        }
        $lines[0]->save();
    });

    expect($target->get_meta(Order_Meta::META_PARCELS))->toBe(['unmapped_subscription_plan' => $raw])
        ->and(SS_SHIPPING_WC()->order_meta()->parcel_plan_error($target))->toContain('Reset')
        ->and((new WC_Order($source->get_id()))->get_meta(Order_Meta::META_PARCELS))->toBe($raw)
        ->and($target->get_meta(Order_Meta::META_AGENT_NO))->toBe('1234');
    $requests = mock_smart_send_api(fn () => ss_api_response(200, ['data' => ss_api_shipment_data(['shipment_id' => 'renewal-shipment-after-reset'])]));
    $result = SS_SHIPPING_WC()->fulfillment()->fulfill_outbound($target, false);
    expect($result->is_successful())->toBeFalse()
        ->and($result->get_first_error_message())->toContain('Reset')
        ->and($requests->requests)->toBe([]);
    ss_subscription_expect_no_item_markers($target);
    if (in_array($fault, ['legacy', 'missing marker'], true)) {
        $reset = (new Delivery_Details())->set_parcel_plan(new Parcel_Plan());
        $reset_result = SS_SHIPPING_WC()->fulfillment()->fulfill_outbound($target, false, $reset, false);
        $fresh = new WC_Order($target->get_id());
        expect($reset_result->is_successful())->toBeTrue()
            ->and($requests->requests)->toHaveCount(1)
            ->and($fresh->get_meta(Order_Meta::META_PARCELS))->toBe(['specs' => []])
            ->and(SS_SHIPPING_WC()->order_meta()->parcel_plan_error($fresh))->toBeNull()
            ->and($fresh->get_meta(Order_Meta::META_LABEL_ID))->toBe('renewal-shipment-after-reset')
            ->and((new WC_Order($source->get_id()))->get_meta(Order_Meta::META_LABEL_ID))->toBe('parent-outbound-shipment')
            ->and((new WC_Order($source->get_id()))->get_meta(Order_Meta::META_PARCELS))->toBe($raw);
    }
    cleanup_created_objects();
})->with(['HPOS' => true, 'posts' => false])->with(['legacy', 'stale', 'source quantity', 'missing marker', 'duplicate marker', 'foreign marker', 'malformed marker', 'target quantity']);
