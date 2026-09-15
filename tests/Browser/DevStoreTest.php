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
 * Put the Beanie in the cart and land on the cart page.
 *
 * The product page is still visited (it must render with its add-to-cart
 * form), but the add itself goes through WooCommerce's ?add-to-cart=<id>
 * URL - the same path every other Browser journey uses - rather than a
 * click on the product form's submit button. On the WooCommerce 8.2 floor
 * leg (classic shortcode cart rendered server-side) the session written by
 * that form POST was not seen by the next navigation of the Playwright
 * context and the cart page rendered empty, while the same POST replayed
 * with curl or in a real Chrome showed the item; the GET path is reliable
 * on every supported version, and the store wiring under test (cart page,
 * flat rate, Proceed to Checkout) is the same either way.
 */
function ss_dev_store_add_beanie_and_open_cart()
{
    $page = visit(base_url('/product/beanie/'))
        ->assertSee('Add to cart');

    $productId = (int) $page->script(
        "(function () { var input = document.querySelector('form.cart [name=\"add-to-cart\"]'); return input ? input.value : 0; })()"
    );
    expect($productId)->toBeGreaterThan(0);

    return $page->navigate(base_url('/?add-to-cart=' . $productId))
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
