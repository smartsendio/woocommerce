<?php

/** Shared by the fixture seeder and the UI journey's separate zone snapshot. */
function ss_browser_capture_zone($zone)
{
    $snapshot = array();
    foreach ($zone->get_shipping_methods() as $method) {
        $snapshot[] = array(
            'instance_id' => (int) $method->instance_id,
            'method_id' => $method->id,
            'settings'  => get_option('woocommerce_' . $method->id . '_' . $method->instance_id . '_settings'),
            'enabled'   => $method->enabled,
            'order'     => isset($method->method_order) ? (int) $method->method_order : 0,
        );
    }

    return $snapshot;
}

function ss_browser_clear_zone($zone)
{
    foreach ($zone->get_shipping_methods() as $method) {
        $zone->delete_shipping_method($method->instance_id);
    }
}

function ss_browser_snapshot_and_clear_saved_zone($zone_id, $option_name)
{
    // Preserve the original baseline if the previous attempt was interrupted.
    ss_browser_restore_saved_zone($zone_id, $option_name);
    $zone = new WC_Shipping_Zone($zone_id);
    $snapshot = ss_browser_capture_zone($zone);
    update_option($option_name, $snapshot);
    ss_browser_clear_zone($zone);

    return count($snapshot);
}

function ss_browser_restore_zone($zone, $snapshot)
{
    // The seeder leaves existing methods in place. Keep their IDs so demo
    // mode never changes references from existing orders/settings. The UI
    // setup journey intentionally clears a zone, so those deleted methods
    // are recreated through WooCommerce with fresh IDs.
    $existing = $zone->get_shipping_methods();
    $original_ids = array_column($snapshot, 'instance_id');
    foreach ($existing as $method) {
        if (!in_array((int) $method->instance_id, $original_ids, true)) {
            $zone->delete_shipping_method($method->instance_id);
        }
    }
    global $wpdb;
    foreach ($snapshot as $entry) {
        $original_id = isset($entry['instance_id']) ? $entry['instance_id'] : 0;
        $instance_id = isset($existing[$original_id]) && $existing[$original_id]->id === $entry['method_id']
            ? $original_id
            : $zone->add_shipping_method($entry['method_id']);
        if (is_array($entry['settings'])) {
            update_option('woocommerce_' . $entry['method_id'] . '_' . $instance_id . '_settings', $entry['settings']);
        } else {
            delete_option('woocommerce_' . $entry['method_id'] . '_' . $instance_id . '_settings');
        }
        $wpdb->update(
            "{$wpdb->prefix}woocommerce_shipping_zone_methods",
            array(
                'method_order' => isset($entry['order']) ? (int) $entry['order'] : 0,
                'is_enabled' => isset($entry['enabled']) && 'yes' !== $entry['enabled'] ? 0 : 1,
            ),
            array('instance_id' => $instance_id)
        );
    }
    WC_Cache_Helper::get_transient_version('shipping', true);
}

function ss_browser_restore_saved_zone($zone_id, $option_name)
{
    $snapshot = get_option($option_name);
    if (!is_array($snapshot)) {
        return false;
    }

    ss_browser_restore_zone(new WC_Shipping_Zone($zone_id), $snapshot);
    delete_option($option_name);

    return true;
}
