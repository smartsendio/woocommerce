<?php

/*
 * Baseline health checks for the local development store itself, as produced
 * by bin/setup-local-dev.sh - deliberately no Smart Send seeding and no API
 * mock. When the store's own wiring is broken (wrong theme, a store page
 * option pointing at a deleted page, JavaScript errors on the storefront),
 * every other suite fails in confusing ways; this one fails first and names
 * the actual problem. When the dev store misbehaves, run this file before
 * debugging the plugin. (Grown from the original StorefrontTest.php after a
 * dangling woocommerce_checkout_page_id left the cart's Proceed to Checkout
 * button spinning forever - a hang no console-error assertion would catch,
 * only actually clicking through.)
 */

it('loads the store home page without javascript errors', function () {
    visit(base_url('/'))
        ->assertSee('Smart Send')
        ->assertNoJavaScriptErrors();
});

it('runs the Storefront theme', function () {
    // The theme the Browser and Docs suites (and documentation screenshots)
    // assume; installed and activated by bin/setup-local-dev.sh.
    visit(base_url('/'))
        ->assertSourceHas('wp-content/themes/storefront');
});

it('lists the sample products in the shop', function () {
    // Two products from the seeded sample catalog (sample-data/products.csv,
    // imported by bin/setup-local-dev.sh); both sort onto the first shop page.
    visit(base_url('/shop/'))
        ->assertSee('Beanie')
        ->assertSee('Belt')
        ->assertNoJavaScriptErrors();
});

/**
 * Put the Beanie in the cart from its product page and land on the cart
 * page.
 *
 * The click targets the product form's own submit button by selector. A
 * text click ('Add to cart') resolves to the first of several matches on
 * a Storefront product page - the related products' AJAX "Add to cart"
 * links and the sticky add-to-cart bar carry the same text - so on the
 * WooCommerce 8.2 floor leg it added a related product instead and the
 * cart page had no Beanie. The form POST reloads the product page with
 * WooCommerce's "added to your cart" notice; wait for it before opening
 * the cart so the navigation does not cut the POST short.
 */
function ss_dev_store_add_beanie_and_open_cart()
{
    return visit(base_url('/product/beanie/'))
        ->assertSee('Add to cart')
        ->click('form.cart button.single_add_to_cart_button')
        ->assertSee('has been added to your cart')
        ->navigate(base_url('/cart/'));
}

it('calculates flat rate shipping for the Danish store address in the cart', function () {
    ss_dev_store_add_beanie_and_open_cart()
        ->assertSee('Beanie')
        ->assertSee('Flat rate')
        ->assertNoJavaScriptErrors();
});

it('proceeds from the cart to the checkout', function () {
    // Guards the store-page wiring: a woocommerce_checkout_page_id pointing
    // at a missing page makes the cart block render this button with an
    // empty href, spinning forever without an error anywhere.
    ss_dev_store_add_beanie_and_open_cart()
        ->assertSee('Proceed to Checkout')
        ->click('Proceed to Checkout')
        ->assertPathContains('checkout')
        ->assertNoJavaScriptErrors();
});
