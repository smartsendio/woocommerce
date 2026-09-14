<?php

/*
 * Characterization tests for SS_Shipping_Order_Bulk_Actions::register_hooks().
 *
 * The bulk "Generate Labels" / "Generate Return Labels" actions are wired
 * onto one of two screens depending on whether HPOS (the custom orders
 * table) is enabled, toggled through the woocommerce_custom_orders_table_enabled
 * option (see #106): the hook names are literal per branch rather than built
 * by string interpolation, so this asserts each branch registers the
 * expected literal hook name.
 */

/**
 * Re-register the bulk actions against the live SS_Shipping_Order_Bulk_Actions
 * component while a given HPOS option value is in effect, mirroring what
 * happens once at plugin bootstrap.
 */
function register_bulk_actions_with_hpos(bool $hpos): void
{
    with_option('woocommerce_custom_orders_table_enabled', $hpos ? 'yes' : 'no');

    SS_SHIPPING_WC()->bulk_actions()->register_hooks();
}

it('registers the HPOS orders-screen bulk action hooks when HPOS is enabled', function () {
    register_bulk_actions_with_hpos(true);

    expect(has_filter('bulk_actions-woocommerce_page_wc-orders', [SS_SHIPPING_WC()->bulk_actions(), 'add_bulk_order_actions']))->not->toBeFalse()
        ->and(has_filter('handle_bulk_actions-woocommerce_page_wc-orders', [SS_SHIPPING_WC()->bulk_actions(), 'handle_bulk_order_actions']))->not->toBeFalse();
});

it('registers the legacy orders-screen bulk action hooks when HPOS is disabled', function () {
    register_bulk_actions_with_hpos(false);

    expect(has_filter('bulk_actions-edit-shop_order', [SS_SHIPPING_WC()->bulk_actions(), 'add_bulk_order_actions']))->not->toBeFalse()
        ->and(has_filter('handle_bulk_actions-edit-shop_order', [SS_SHIPPING_WC()->bulk_actions(), 'handle_bulk_order_actions']))->not->toBeFalse();
});
