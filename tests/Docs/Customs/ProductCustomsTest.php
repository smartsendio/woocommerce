<?php

/*
 * International shipping -> Product and customs information.
 * These are Smart Send's real fields in WooCommerce's Shipping product tab,
 * not product attributes. Seed saved values so a filtered capture is independent.
 */

beforeEach(function (): void {
    ss_browser_skip_unless_store_manageable($this);
    $store = docs_seed_store();
    $product_id = (int) $store['product_id'];
    $name = var_export(docs_text('Cotton T-shirt', 'T-shirt i bomuld'), true);

    ss_browser_wp_eval(<<<PHP
\$product = wc_get_product({$product_id});
\$product->set_name({$name});
\$product->set_weight('0.2');
\$product->set_length('25');
\$product->set_width('20');
\$product->set_height('2');
\$product->update_meta_data('_ss_country_of_origin', 'DK');
\$product->update_meta_data('_ss_customs_desc', 'Cotton T-shirt');
\$product->update_meta_data('_ss_hs_code', '61091000');
\$product->save();
echo json_encode(array('product_id' => \$product->get_id()));
PHP);
});

afterEach(function (): void {
    docs_cleanup_store();
});

it('documents saved product customs fields on the Shipping tab', function () {
    $product_id = (int) ss_browser_state()['product_id'];

    $page = visit(base_url('/wp-login.php'))
        ->fill('#user_login', admin_username())
        ->fill('#user_pass', admin_password())
        ->click('#wp-submit')
        ->assertPathContains('wp-admin')
        ->navigate(base_url('/wp-admin/post.php?post=' . $product_id . '&action=edit'))
        ->click('a[href="#shipping_product_data"]')
        ->assertVisible('#shipping_product_data')
        ->assertValue('#_ss_country_of_origin', 'DK')
        ->assertValue('#_ss_customs_desc', 'Cotton T-shirt')
        ->assertValue('#_ss_hs_code', '61091000');

    // Include the product-data tab list, so the field location remains clear.
    highlight_element($page, '#woocommerce-product-data');
    capture_doc_screenshot($page, 'customs', 'product-shipping-fields');
});
