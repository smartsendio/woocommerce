<?php
/**
 * Undo seed-store.php. Run inside the store via WP-CLI:
 *
 *   wp eval-file cleanup-store.php [<state option name>]
 *
 * Shared between tests/Browser/Support/SmartSendStore.php
 * (ss_browser_cleanup_store()) and bin/demo-store.sh (demo:off). Reads the
 * state seed-store.php stored in the given wp option (default
 * ss_browser_test_state), then: deletes the fixture orders (both the seeded
 * ones and any placed through the seeded checkout - matched on the fixture
 * billing email), the seeded checkout page(s) and the seeded product,
 * restores the plugin settings / COD / checkout-page option, removes the
 * fixture zone methods while restoring the original methods/configuration,
 * and deactivates the mock options. It never deletes zones, products, pages or orders it did
 * not create itself. The mu-plugin mock file is removed by the caller.
 */

$state_option = isset($args[0]) ? $args[0] : 'ss_browser_test_state';
$state = get_option($state_option);
if (!is_array($state) || !array_key_exists('original_settings', $state)) {
    echo json_encode(array('cleaned' => false));
    return;
}

require_once __DIR__ . '/zone-methods.php';

// Orders created by the admin fixtures and by the checkout test run.
$fixture_orders = wc_get_orders(array('created_via' => 'ss-browser-test', 'limit' => -1));
$checkout_orders = wc_get_orders(array('billing_email' => 'ss-browser-test@smartsend.io', 'limit' => -1));
$recorded_orders = array_filter(array_map('wc_get_order', isset($state['orders']) ? $state['orders'] : array()));
foreach (array_merge($recorded_orders, $fixture_orders, $checkout_orders) as $order) {
    $order->delete(true);
}

if (isset($state['original_zone_methods'], $state['zone_id'])) {
    ss_browser_restore_zone(new WC_Shipping_Zone($state['zone_id']), $state['original_zone_methods']);
} else {
    // State left by the earlier seeder records only newly-created instances.
    if (!empty($state['created_instance']) && !empty($state['zone_id'])) {
        (new WC_Shipping_Zone($state['zone_id']))->delete_shipping_method($state['instance_id']);
    }
    if (!empty($state['created_flat']) && !empty($state['zone_id'])) {
        (new WC_Shipping_Zone($state['zone_id']))->delete_shipping_method($state['flat_instance']);
    }
}

if (!empty($state['checkout_page_id'])) {
    wp_delete_post($state['checkout_page_id'], true);
}
if (!empty($state['block_checkout_page_id'])) {
    wp_delete_post($state['block_checkout_page_id'], true);
}
if (isset($state['original_checkout_page'])) {
    $original = (int) $state['original_checkout_page'];
    // Never restore a dangling page id: the cart block would render its
    // "Proceed to Checkout" button without a URL and spin forever. Fall
    // back to the checkout page WooCommerce created at install.
    if (!$original || !get_post($original)) {
        $fallback = get_page_by_path('checkout');
        if ($fallback) {
            $original = $fallback->ID;
        }
    }
    update_option('woocommerce_checkout_page_id', $original);
}

if (array_key_exists('original_cod_settings', $state)) {
    if ($state['original_cod_settings'] === false) {
        delete_option('woocommerce_cod_settings');
    } else {
        update_option('woocommerce_cod_settings', $state['original_cod_settings']);
    }
} elseif (isset($state['cod_was_enabled'])) {
    $cod = get_option('woocommerce_cod_settings', array());
    $cod['enabled'] = $state['cod_was_enabled'];
    update_option('woocommerce_cod_settings', $cod);
}

$product_id = wc_get_product_id_by_sku('SS-BROWSER-TEST');
if ($product_id) {
    $product = wc_get_product($product_id);
    $product->delete(true);
}

if ($state['original_settings'] === false || $state['original_settings'] === null) {
    delete_option('woocommerce_smart_send_shipping_settings');
} else {
    update_option('woocommerce_smart_send_shipping_settings', $state['original_settings']);
}

delete_option('ss_test_api');
delete_option('ss_test_api_requests');
// Legacy single-scenario mock options (pre per-endpoint rework) - remove if left behind.
delete_option('ss_test_api_mock');
delete_option('ss_test_api_scenario');
delete_option($state_option);
echo json_encode(array('cleaned' => true));
