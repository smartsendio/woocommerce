<?php

it('loads the storefront home page', function () {
    $page = visit(base_url('/'));

    $page->assertSee('Smart Send Dev Store');
});

it('lists the sample products in the shop', function () {
    $page = visit(base_url('/shop/'));

    $page->assertSee('Sample Parcel Product')
        ->assertSee('Sample Letter Product');
});

it('calculates flat rate shipping for the Danish store address in the cart', function () {
    $page = visit(base_url('/product/sample-parcel-product/'));

    $page->assertSee('Add to cart')
        ->click('Add to cart')
        ->navigate(base_url('/cart/'))
        ->assertSee('Sample Parcel Product')
        ->assertSee('Flat rate');
});
