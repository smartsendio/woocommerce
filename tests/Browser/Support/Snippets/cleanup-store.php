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
 * zone method instances only when the seeding created them, and deactivates
 * the mock options. It never deletes zones, products, pages or orders it did
 * not create itself. The mu-plugin mock file is removed by the caller.
 */

$state_option = isset($args[0]) ? $args[0] : 'ss_browser_test_state';
$state = get_option($state_option, array());

// Orders created by the admin fixtures and by the checkout test run.
$fixture_orders = wc_get_orders(array('created_via' => 'ss-browser-test', 'limit' => -1));
$checkout_orders = wc_get_orders(array('billing_email' => 'ss-browser-test@smartsend.io', 'limit' => -1));
foreach (array_merge($fixture_orders, $checkout_orders) as $order) {
    $order->delete(true);
}

if (!empty($state['created_instance']) && !empty($state['zone_id'])) {
    $zone = new WC_Shipping_Zone($state['zone_id']);
    $zone->delete_shipping_method($state['instance_id']);
}
if (!empty($state['created_flat']) && !empty($state['zone_id'])) {
    $zone = new WC_Shipping_Zone($state['zone_id']);
    $zone->delete_shipping_method($state['flat_instance']);
}

if (!empty($state['checkout_page_id'])) {
    wp_delete_post($state['checkout_page_id'], true);
}
if (!empty($state['block_checkout_page_id'])) {
    wp_delete_post($state['block_checkout_page_id'], true);
}
if (isset($state['original_checkout_page'])) {
    update_option('woocommerce_checkout_page_id', $state['original_checkout_page']);
}

if (($state['cod_was_enabled'] ?? 'no') !== 'yes') {
    $cod = get_option('woocommerce_cod_settings', array());
    $cod['enabled'] = $state['cod_was_enabled'] ?? 'no';
    update_option('woocommerce_cod_settings', $cod);
}

$product_id = wc_get_product_id_by_sku('SS-BROWSER-TEST');
if ($product_id) {
    $product = wc_get_product($product_id);
    $product->delete(true);
}

if (empty($state['original_settings'])) {
    delete_option('woocommerce_smart_send_shipping_settings');
} else {
    update_option('woocommerce_smart_send_shipping_settings', $state['original_settings']);
}

delete_option('ss_test_api_mock');
delete_option('ss_test_api_scenario');
delete_option($state_option);
echo json_encode(array('cleaned' => true));
