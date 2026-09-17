<?php

$state = get_option('ss_browser_test_state');
$profile = json_decode(file_get_contents(dirname(__DIR__) . '/profile.json'), true);
$danish = $args[0] === 'da';
$name = $danish ? 'Bomulds-T-shirt' : 'Cotton T-shirt';
$product = wc_get_product($state['product_id']);
update_option('ss_docs_product_id', $state['product_id']);
$product->set_name($name);
$product->set_sku('TSHIRT-COTTON');
$product->set_tax_status('taxable');
$product->set_catalog_visibility('visible');
$product->save();
wp_update_post(array('ID' => $state['checkout_page_id'], 'post_title' => $danish ? 'Kasse' : 'Checkout'));
update_post_meta($state['checkout_page_id'], '_wp_page_template', 'template-fullwidth.php');
foreach ($state['orders'] as $order_id) {
    $order = wc_get_order($order_id);
    $order->set_billing_first_name('Alex');
    $order->set_billing_last_name('Example');
    $order->set_shipping_first_name('Alex');
    $order->set_shipping_last_name('Example');
    $order->set_billing_email('alex@example.test');
    $order->set_date_created($profile['order_created_at']);
    foreach ($order->get_items() as $item) {
        $item->set_name($name);
        $item->save();
    }
    $point = $order->get_meta('_ss_shipping_order_agent');
    if (is_object($point)) {
        $point = clone $point;
        $point->company = 'Harbour Parcel Shop';
        $point->address_line1 = 'Harbour Street 1';
        $order->update_meta_data('_ss_shipping_order_agent', $point);
    }
    $order->calculate_totals();
    $order->save();
}
echo json_encode(array('decorated' => true));
