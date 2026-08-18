<?php
/**
 * Seed the store fixtures the Browser suite journeys (and the manual-testing
 * demo mode) build on. Run inside the store via WP-CLI:
 *
 *   wp eval-file seed-store.php '<config json>' [<state option name>]
 *
 * Shared between tests/Browser/Support/SmartSendStore.php
 * (ss_browser_seed_store()) and bin/demo-store.sh (demo:on) - the single
 * source of truth for what "a store exercising the Smart Send plugin against
 * the mocked API" looks like. Echoes the resulting state as JSON on the last
 * line and stores it in the given wp option (default ss_browser_test_state)
 * so cleanup-store.php can undo everything.
 *
 * Always: snapshots + replaces the plugin's global settings, activates the
 * API mock (the mu-plugin file itself is copied in by the caller), enables
 * Cash on Delivery, ensures a Denmark zone with a Smart Send agent method
 * instance ordered after a Flat rate, creates the test product and a classic
 * (shortcode) checkout page.
 *
 * Config keys:
 *  - 'settings' (array)  merged over the default plugin settings
 *  - 'orders'   (array)  a list of order specs; each spec may set
 *                        'auto_return' => true to enable the method's
 *                        auto-generate-return-label flag on the order.
 *                        Created order ids come back in state 'orders'.
 */

$config = json_decode(isset($args[0]) ? $args[0] : '{}', true);
$config = is_array($config) ? $config : array();
$config += array('settings' => array(), 'orders' => array());
$state_option = isset($args[1]) ? $args[1] : 'ss_browser_test_state';

$original_settings = get_option('woocommerce_smart_send_shipping_settings');
update_option('woocommerce_smart_send_shipping_settings', array_merge(array(
    'demo' => 'yes', 'ss_debug' => 'no', 'include_order_comment' => 'no',
    'save_shipping_labels_in_uploads' => 'no', 'dropdown_display_format' => '4',
    'default_select_agent' => 'no', 'order_status' => '0',
), $config['settings']));
update_option('ss_test_api_mock', 'yes');
delete_option('ss_test_api_scenario');

// Cash on delivery so the checkout can be completed.
$cod = get_option('woocommerce_cod_settings', array());
$cod_was_enabled = isset($cod['enabled']) ? $cod['enabled'] : 'no';
$cod['enabled'] = 'yes';
update_option('woocommerce_cod_settings', $cod);

// Denmark zone with a Smart Send agent method instance.
$zone_id = 0;
foreach (WC_Shipping_Zones::get_zones() as $zone_data) {
    foreach ($zone_data['zone_locations'] as $location) {
        if ($location->code === 'DK') {
            $zone_id = $zone_data['id'];
            break 2;
        }
    }
}
if (!$zone_id) {
    $zone = new WC_Shipping_Zone();
    $zone->set_zone_name('Denmark');
    $zone->set_zone_locations(array((object) array('code' => 'DK', 'type' => 'country')));
    $zone->save();
    $zone_id = $zone->get_id();
}
$zone = new WC_Shipping_Zone($zone_id);
$instance_id = 0;
foreach ($zone->get_shipping_methods() as $iid => $method) {
    if ($method->id === 'smart_send_shipping') {
        $instance_id = $iid;
        break;
    }
}
$created_instance = 0;
if (!$instance_id) {
    $instance_id = $zone->add_shipping_method('smart_send_shipping');
    $created_instance = 1;
}
update_option('woocommerce_smart_send_shipping_' . $instance_id . '_settings', array(
    'title' => 'Smart Send Pickup Point',
    'method' => 'postnord_agent',
    'return_method' => 'postnord_returndropoff',
    'auto_generate_return_label' => 'no',
    'tax_status' => 'none',
    'requires' => '',
    'flatfee_cost' => '0',
    'min_amount' => '0',
    'advanced_settings_enable' => 'no',
    'cost_weight' => array(array('ss_min_weight' => '', 'ss_max_weight' => '', 'ss_cost_weight' => '29')),
));

// The zone must offer a second rate, ordered before the Smart Send one:
// with a single available rate WooCommerce's classic checkout renders the
// method as a hidden input instead of a radio (so the checkout test's
// click can never succeed), and with Smart Send preselected the radio
// click fires no change event (so the pickup dropdown never renders).
// CI's store always has the setup script's Flat rate first; enforce the
// same shape here for stores that have drifted.
$flat_instance = 0;
$created_flat = 0;
foreach ($zone->get_shipping_methods() as $iid => $method) {
    if ($method->id === 'flat_rate') {
        $flat_instance = $iid;
        break;
    }
}
if (!$flat_instance) {
    $flat_instance = $zone->add_shipping_method('flat_rate');
    $created_flat = 1;
    update_option('woocommerce_flat_rate_' . $flat_instance . '_settings', array('title' => 'Flat rate', 'cost' => '39'));
}
global $wpdb;
$wpdb->update("{$wpdb->prefix}woocommerce_shipping_zone_methods", array('method_order' => 1), array('instance_id' => $flat_instance));
$wpdb->update("{$wpdb->prefix}woocommerce_shipping_zone_methods", array('method_order' => 2), array('instance_id' => $instance_id));

// A classic (shortcode) checkout page; the pickup point selector only
// renders in the classic checkout.
$page_id = wp_insert_post(array(
    'post_title'   => 'SS Classic Checkout',
    'post_name'    => 'ss-classic-checkout',
    'post_content' => '[woocommerce_checkout]',
    'post_status'  => 'publish',
    'post_type'    => 'page',
));
$original_checkout_page = get_option('woocommerce_checkout_page_id');
update_option('woocommerce_checkout_page_id', $page_id);

// A product to buy.
$product_id = wc_get_product_id_by_sku('SS-BROWSER-TEST');
if (!$product_id) {
    $product = new WC_Product_Simple();
    $product->set_name('SS Browser Test Product');
    $product->set_sku('SS-BROWSER-TEST');
    $product->set_regular_price('100');
    $product->set_weight('1');
    $product->save();
    $product_id = $product->get_id();
}

// Orders for the admin label tests, built like checkout would build them.
$make_order = function ($spec) use ($product_id) {
    $auto_return = !empty($spec['auto_return']);
    $order = wc_create_order(array('status' => 'processing', 'created_via' => 'ss-browser-test'));
    $order->add_product(wc_get_product($product_id), 1);
    $address = array(
        'first_name' => 'Browser', 'last_name' => 'Test', 'address_1' => 'Islands Brygge 39',
        'city' => 'Copenhagen', 'postcode' => '2300', 'country' => 'DK',
    );
    $order->set_address(array_merge($address, array('email' => 'ss-browser-test@smartsend.io', 'phone' => '+4512345678')), 'billing');
    $order->set_address($address, 'shipping');
    $item = new WC_Order_Item_Shipping();
    $item->set_method_title('Smart Send Pickup Point');
    $item->set_method_id('smart_send_shipping');
    $item->set_instance_id(1);
    $item->set_total('29');
    $item->add_meta_data('smart_send_shipping_method', 'postnord_agent', true);
    $item->add_meta_data('smart_send_return_method', 'postnord_returndropoff', true);
    $item->add_meta_data('smart_send_auto_generate_return_label', $auto_return ? 'yes' : 'no', true);
    $order->add_item($item);
    $order->calculate_totals();
    $order->update_meta_data('ss_shipping_order_agent_no', '1234');
    $order->update_meta_data('_ss_shipping_order_agent', (object) array(
        'id' => 1, 'agent_no' => '1234', 'company' => 'Browser Test Shop', 'address_line1' => 'Main Street 1',
        'address_line2' => null, 'postal_code' => '2300', 'city' => 'Copenhagen', 'country' => 'DK',
    ));
    $order->save();
    return $order->get_id();
};
$order_ids = array();
foreach ($config['orders'] as $spec) {
    $order_ids[] = $make_order(is_array($spec) ? $spec : array());
}

$hpos = Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

$state = array(
    'original_settings'      => $original_settings === false ? null : $original_settings,
    'zone_id'                => $zone_id,
    'instance_id'            => $instance_id,
    'created_instance'       => $created_instance,
    'flat_instance'          => $flat_instance,
    'created_flat'           => $created_flat,
    'checkout_page_id'       => $page_id,
    'original_checkout_page' => $original_checkout_page,
    'cod_was_enabled'        => $cod_was_enabled,
    'product_id'             => $product_id,
    'orders'                 => $order_ids,
    'orders_list_path'       => $hpos ? '/wp-admin/admin.php?page=wc-orders' : '/wp-admin/edit.php?post_type=shop_order',
    'hpos'                   => $hpos ? 1 : 0,
);
update_option($state_option, $state);
echo json_encode($state);
