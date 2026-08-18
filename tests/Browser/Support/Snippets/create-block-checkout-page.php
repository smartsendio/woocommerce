<?php
/**
 * Create a page carrying the Checkout block, alongside the classic
 * (shortcode) checkout page seed-store.php sets up - both checkouts coexist
 * against the same store. Run inside the store via WP-CLI:
 *
 *   wp eval-file create-block-checkout-page.php [<state option name>]
 *
 * The stock minimal markup (the same WC_Install writes for a fresh store's
 * checkout page) is enough: the Checkout block force-renders every
 * registered inner block - including the Smart Send pickup point block -
 * when it is missing from the saved content. WooCommerce's checkout page
 * setting stays on the classic page, so the post-purchase redirect exercises
 * the same thank-you rendering as the classic journey.
 *
 * Shared between tests/Browser/Support/SmartSendStore.php
 * (ss_browser_create_block_checkout_page()) and bin/demo-store.sh (demo:on).
 * When a state option name is given, the page id is recorded into that
 * option's state array as 'block_checkout_page_id' so cleanup-store.php
 * deletes the page (the tests instead track the id themselves and delete it
 * per test file). Echoes {"page_id": ...} on the last line.
 */

$page_id = wp_insert_post(array(
    'post_title'   => 'SS Block Checkout',
    'post_name'    => 'ss-block-checkout',
    'post_content' => '<!-- wp:woocommerce/checkout --><div class="wp-block-woocommerce-checkout"></div><!-- /wp:woocommerce/checkout -->',
    'post_status'  => 'publish',
    'post_type'    => 'page',
));

if (isset($args[0])) {
    $state = get_option($args[0], array());
    $state['block_checkout_page_id'] = $page_id;
    update_option($args[0], $state);
}

echo json_encode(array('page_id' => $page_id));
