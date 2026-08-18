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
        ->assertSee('Smart Send Dev Store')
        ->assertNoJavaScriptErrors();
});

it('runs the Storefront theme', function () {
    // The theme the Browser and Docs suites (and documentation screenshots)
    // assume; installed and activated by bin/setup-local-dev.sh.
    visit(base_url('/'))
        ->assertSourceHas('wp-content/themes/storefront');
});

it('lists the sample products in the shop', function () {
    visit(base_url('/shop/'))
        ->assertSee('Sample Parcel Product')
        ->assertSee('Sample Letter Product')
        ->assertNoJavaScriptErrors();
});

it('calculates flat rate shipping for the Danish store address in the cart', function () {
    visit(base_url('/product/sample-parcel-product/'))
        ->assertSee('Add to cart')
        ->click('Add to cart')
        ->navigate(base_url('/cart/'))
        ->assertSee('Sample Parcel Product')
        ->assertSee('Flat rate')
        ->assertNoJavaScriptErrors();
});

it('proceeds from the cart to the checkout', function () {
    // Guards the store-page wiring: a woocommerce_checkout_page_id pointing
    // at a missing page makes the cart block render this button with an
    // empty href, spinning forever without an error anywhere.
    visit(base_url('/product/sample-parcel-product/'))
        ->assertSee('Add to cart')
        ->click('Add to cart')
        ->navigate(base_url('/cart/'))
        ->assertSee('Proceed to Checkout')
        ->click('Proceed to Checkout')
        ->assertPathContains('checkout')
        ->assertNoJavaScriptErrors();
});
