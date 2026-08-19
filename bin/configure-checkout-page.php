<?php
/**
 * Point the store's checkout page at the requested checkout surface. Run
 * inside the store via WP-CLI from bin/setup-local-dev.sh:
 *
 *   wp eval-file configure-checkout-page.php <classic|block>
 *
 * "classic" writes the [woocommerce_checkout] shortcode; "block" writes the
 * stock minimal Checkout block markup (the same WC_Install writes for a
 * fresh store - the block force-renders every registered inner block,
 * including the Smart Send pickup point block, when it is missing from the
 * saved content). The page WooCommerce registered on install
 * (woocommerce_checkout_page_id) is rewritten in place; when it is missing
 * (e.g. --skip-seed edge cases) a fresh page is created and registered.
 *
 * Echoes {"page_id": ..., "type": ...} on the last line.
 */

$type = isset($args[0]) ? $args[0] : 'classic';

$content = 'block' === $type
    ? '<!-- wp:woocommerce/checkout --><div class="wp-block-woocommerce-checkout"></div><!-- /wp:woocommerce/checkout -->'
    : '[woocommerce_checkout]';

$page_id = (int) get_option('woocommerce_checkout_page_id');

if (!$page_id || !get_post($page_id)) {
    $page_id = wp_insert_post(array(
        'post_title'   => 'Checkout',
        'post_name'    => 'checkout',
        'post_content' => $content,
        'post_status'  => 'publish',
        'post_type'    => 'page',
    ));
    update_option('woocommerce_checkout_page_id', $page_id);
} else {
    wp_update_post(array(
        'ID'           => $page_id,
        'post_content' => $content,
    ));
}

echo json_encode(array('page_id' => $page_id, 'type' => $type));
