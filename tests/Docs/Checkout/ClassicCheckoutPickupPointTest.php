<?php

require_once dirname(__DIR__) . '/ShippingMethod/ShippingFixtures.php';

beforeEach(function (): void {
    ss_browser_skip_unless_store_manageable($this);
    docs_shipping_cleanup();
    docs_seed_store();
    docs_shipping_fixture();
});

afterEach(function (): void {
    docs_shipping_cleanup();
    docs_cleanup_store();
});

it('documents the classic checkout shipping methods', function () {
    $state = ss_browser_state();
    $page = visit(base_url('/?add-to-cart=' . $state['product_id']))->assertPresent('html > body');
    docs_classic_checkout($page);
    $page->assertSee(docs_text('Pickup point', 'Afhentningssted'))
        ->assertSee(docs_text('Home delivery', 'Hjemmelevering'));
    docs_assert_shipping_amount($page, $state['instance_id'], '39.00');
    docs_assert_shipping_amount($page, $state['home_instance_id'], '59.00');
    highlight_element($page, 'ul#shipping_method');
    capture_doc_screenshot($page, 'checkout-classic', 'shipping-methods');
});

it('documents the classic checkout pickup selector', function () {
    $state = ss_browser_state();
    $page = visit(base_url('/?add-to-cart=' . $state['product_id']))->assertPresent('html > body');
    docs_classic_checkout($page);
    docs_classic_select_pickup($page);
    highlight_element($page, 'select[name="ss_shipping_store_pickup"]');
    capture_doc_screenshot($page, 'checkout-classic', 'pickup-selector');
});

it('documents a selected pickup point in classic checkout', function () {
    $state = ss_browser_state();
    $page = visit(base_url('/?add-to-cart=' . $state['product_id']))->assertPresent('html > body');
    docs_classic_checkout($page);
    docs_classic_select_pickup($page);
    docs_classic_confirm_pickup($page);
    highlight_element($page, 'select[name="ss_shipping_store_pickup"]');
    capture_doc_screenshot($page, 'checkout-classic', 'pickup-selected');
});
