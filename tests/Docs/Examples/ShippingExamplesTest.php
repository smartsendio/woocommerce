<?php

require_once dirname(__DIR__) . '/ShippingMethod/ShippingFixtures.php';

beforeEach(function (): void {
    ss_browser_skip_unless_store_manageable($this);
    docs_shipping_cleanup();
    docs_seed_store();
});

afterEach(function (): void {
    docs_shipping_cleanup();
    docs_cleanup_store();
});

it('documents pickup pricing before VAT', function () {
    docs_shipping_fixture();
    $page = visit(base_url('/wp-login.php'))
        ->fill('#user_login', admin_username())->fill('#user_pass', admin_password())->click('#wp-submit')
        ->navigate(docs_method_settings_url())
        ->assertValue('#woocommerce_smart_send_shipping_title', docs_text('Pickup point', 'Afhentningssted'))
        ->assertValue('input[name="ss_cost_weight[0]"]', '31.20');
    highlight_element($page, '#ss_cost_weight');
    capture_doc_screenshot($page, 'examples', 'pickup-home-settings');
});

it('documents home delivery pricing before VAT', function () {
    $state = docs_shipping_fixture();
    $page = visit(base_url('/wp-login.php'))
        ->fill('#user_login', admin_username())->fill('#user_pass', admin_password())->click('#wp-submit')
        ->navigate(docs_method_settings_url($state['home_instance_id']))
        ->assertValue('#woocommerce_smart_send_shipping_title', docs_text('Home delivery', 'Hjemmelevering'))
        ->assertValue('input[name="ss_cost_weight[0]"]', '47.20');
    highlight_element($page, '#ss_cost_weight');
    capture_doc_screenshot($page, 'examples', 'pickup-home-home-settings');
});

it('documents pickup and home delivery prices including VAT', function () {
    $state = docs_shipping_fixture();
    $page = visit(base_url('/?add-to-cart=' . $state['product_id']))->assertPresent('html > body');
    docs_classic_checkout($page);
    docs_assert_shipping_amount($page, $state['instance_id'], '39.00');
    docs_assert_shipping_amount($page, $state['home_instance_id'], '59.00');
    highlight_element($page, 'ul#shipping_method');
    capture_doc_screenshot($page, 'examples', 'pickup-home-checkout');
});

it('documents the free shipping threshold settings', function () {
    docs_shipping_fixture('free-threshold');
    $page = visit(base_url('/wp-login.php'))
        ->fill('#user_login', admin_username())->fill('#user_pass', admin_password())->click('#wp-submit')
        ->navigate(docs_method_settings_url())
        ->assertValue('#woocommerce_smart_send_shipping_requires', 'min_amount');
    ss_wait_for_script($page, "Number(document.querySelector('#woocommerce_smart_send_shipping_min_amount').value.replace(',', '.')) === 500");
    highlight_element($page, 'table.form-table:has(#woocommerce_smart_send_shipping_requires)');
    capture_doc_screenshot($page, 'examples', 'free-threshold-settings');
});

it('documents free pickup at a 500 DKK cart subtotal including VAT', function () {
    $state = docs_shipping_fixture('free-threshold');
    // 4 x 100 DKK entered excluding VAT = 500 DKK displayed including VAT.
    $page = visit(base_url('/?add-to-cart=' . $state['product_id'] . '&quantity=4'))->assertPresent('html > body');
    docs_classic_checkout($page);
    docs_classic_select_pickup($page);
    docs_classic_confirm_pickup($page);
    $id = (int) $state['instance_id'];
    ss_wait_for_script($page, "(function () { const label = document.querySelector('label[for=\"shipping_method_0_smart_send_shipping$id\"]'); const subtotal = document.querySelector('.cart-subtotal .amount'); const total = document.querySelector('.order-total .amount'); const numeric = el => { if (!el) return null; const value = el.cloneNode(true); value.querySelectorAll('.woocommerce-Price-currencySymbol').forEach(symbol => symbol.remove()); return value.textContent.trim().replace(/\\s/g, '').replace(',', '.'); }; return label && !label.querySelector('.amount') && numeric(subtotal) === '500.00' && numeric(total) === '500.00'; })()");
    highlight_element($page, '.woocommerce-checkout-review-order-table');
    capture_doc_screenshot($page, 'examples', 'free-threshold-checkout');
});

it('documents the configured weight bands', function () {
    docs_shipping_fixture('weight-bands');
    $page = visit(base_url('/wp-login.php'))
        ->fill('#user_login', admin_username())->fill('#user_pass', admin_password())->click('#wp-submit')
        ->navigate(docs_method_settings_url())
        ->assertValue('input[name="ss_min_weight[1]"]', '5')
        ->assertValue('input[name="ss_max_weight[1]"]', '20')
        ->assertValue('input[name="ss_cost_weight[1]"]', '47.20');
    highlight_element($page, '#ss_cost_weight');
    capture_doc_screenshot($page, 'examples', 'weight-bands');
});

it('documents the five kilogram weight band at checkout', function () {
    $state = docs_shipping_fixture('weight-bands');
    // Five 1 kg products fall in [5,20), so the second row applies.
    $page = visit(base_url('/?add-to-cart=' . $state['product_id'] . '&quantity=5'))->assertPresent('html > body');
    docs_classic_checkout($page);
    docs_assert_shipping_amount($page, $state['instance_id'], '59.00');
    highlight_element($page, '.woocommerce-checkout-review-order-table');
    capture_doc_screenshot($page, 'examples', 'weight-band-checkout');
});

it('documents advanced shipping class and role conditions', function () {
    docs_shipping_fixture('advanced');
    $page = visit(base_url('/wp-login.php'))
        ->fill('#user_login', admin_username())->fill('#user_pass', admin_password())->click('#wp-submit')
        ->navigate(docs_method_settings_url())
        ->assertChecked('#woocommerce_smart_send_shipping_advanced_settings_enable')
        ->assertValue('#woocommerce_smart_send_shipping_display_shipping_class_opt', 'all_shipping_class')
        ->assertSee(docs_text('Standard parcels', 'Standardpakker'));
    highlight_element($page, 'table.form-table:has(#woocommerce_smart_send_shipping_advanced_settings_enable)');
    capture_doc_screenshot($page, 'examples', 'advanced-conditions');
});

it('documents a guest cart matching the advanced shipping conditions', function () {
    $state = docs_shipping_fixture('advanced');
    $page = visit(base_url('/?add-to-cart=' . $state['product_id']))->assertPresent('html > body');
    docs_classic_checkout($page);
    // Every product belongs to Standard parcels; the guest is not an excluded administrator.
    docs_assert_shipping_amount($page, $state['instance_id'], '39.00');
    highlight_element($page, '.woocommerce-checkout-review-order-table');
    capture_doc_screenshot($page, 'examples', 'advanced-conditions-checkout');
});

it('documents mapping native WooCommerce free shipping to a carrier service', function () {
    ss_browser_update_plugin_setting('shipping_method_for_free_shipping', 'postnord_homedelivery');
    $page = visit(base_url('/wp-login.php'))
        ->fill('#user_login', admin_username())->fill('#user_pass', admin_password())->click('#wp-submit')
        ->navigate(base_url('/wp-admin/admin.php?page=wc-settings&tab=shipping&section=smart_send_shipping'))
        ->assertValue('#woocommerce_smart_send_shipping_shipping_method_for_free_shipping', 'postnord_homedelivery');
    highlight_element($page, 'tr:has(#woocommerce_smart_send_shipping_shipping_method_for_free_shipping)');
    capture_doc_screenshot($page, 'examples', 'free-shipping-mapping');
});
