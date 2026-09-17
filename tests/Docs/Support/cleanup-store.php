<?php

// Browser fixtures identify their product by their own SKU. Docs assigns a
// readable SKU, so retain and clean up the exact product created by this run.
$product_id = get_option('ss_docs_product_id');
if ($product_id && get_option('ss_docs_store')) {
    $product = wc_get_product($product_id);
    if ($product) {
        $product->delete(true);
    }
}
delete_option('ss_docs_product_id');

$snapshot = get_option('ss_docs_snapshot');
if (is_array($snapshot)) {
    foreach ($snapshot['options'] as $key => $value) {
        if ($value === false) {
            delete_option($key);
        } else {
            update_option($key, $value);
        }
    }
    $admin = get_user_by('login', 'admin');
    update_user_meta($admin->ID, 'locale', $snapshot['admin_locale']);
}
delete_option('ss_docs_snapshot');
delete_option('ss_docs_active');
delete_option('ss_docs_booking_count');
echo json_encode(array('cleaned' => true));
