<?php

require_once __DIR__ . '/ShippingFixtures.php';

beforeEach(function (): void {
    ss_browser_skip_unless_store_manageable($this);
    docs_shipping_cleanup();
    docs_seed_store();
});

afterEach(function (): void {
    docs_shipping_cleanup();
    docs_cleanup_store();
});

it('documents an empty shipping zone', function () {
    $state = docs_shipping_fixture('empty');
    $page = visit(base_url('/wp-login.php'))
        ->fill('#user_login', admin_username())->fill('#user_pass', admin_password())->click('#wp-submit')
        ->navigate(ss_zone_page_url($state['zone_id']))
        ->assertPresent('.wc-shipping-zone-add-method');
    highlight_element($page, '.wc-shipping-zone-add-method');
    capture_doc_screenshot($page, 'methods', 'zone-empty');
});

it('documents the Smart Send method picker', function () {
    $state = docs_shipping_fixture('empty');
    $page = visit(base_url('/wp-login.php'))
        ->fill('#user_login', admin_username())->fill('#user_pass', admin_password())->click('#wp-submit')
        ->navigate(ss_zone_page_url($state['zone_id']))
        ->click('.wc-shipping-zone-add-method')
        ->assertPresent('label[for="smart_send_shipping"]');
    highlight_element($page, 'label[for="smart_send_shipping"]');
    capture_doc_screenshot($page, 'methods', 'method-picker');
});

it('documents adding Smart Send to the shipping zone', function () {
    $state = docs_shipping_fixture('empty');
    $page = visit(base_url('/wp-login.php'))
        ->fill('#user_login', admin_username())->fill('#user_pass', admin_password())->click('#wp-submit')
        ->navigate(ss_zone_page_url($state['zone_id']))
        ->click('.wc-shipping-zone-add-method')
        ->click('label[for="smart_send_shipping"]')
        ->assertVisible('.wc-backbone-modal #btn-next')
        ->click('.wc-backbone-modal #btn-next')
        ->assertNotPresent('.wc-backbone-modal')
        ->assertSeeIn('.wc-shipping-zone-method-rows .wc-shipping-zone-method-title', 'Smart Send');
    highlight_element($page, '.wc-shipping-zone-methods');
    capture_doc_screenshot($page, 'methods', 'method-added');
});

it('documents the unconfigured method settings', function () {
    docs_shipping_fixture('unconfigured');
    $page = visit(base_url('/wp-login.php'))
        ->fill('#user_login', admin_username())->fill('#user_pass', admin_password())->click('#wp-submit')
        ->navigate(docs_method_settings_url())
        ->assertPresent('#woocommerce_smart_send_shipping_method');
    highlight_element($page, 'tr:has(#woocommerce_smart_send_shipping_method)');
    capture_doc_screenshot($page, 'methods', 'settings-empty');
});

it('documents a filled method settings form', function () {
    docs_shipping_fixture('unconfigured');
    $page = visit(base_url('/wp-login.php'))
        ->fill('#user_login', admin_username())->fill('#user_pass', admin_password())->click('#wp-submit')
        ->navigate(docs_method_settings_url());
    ss_step_fill_method_settings($page, docs_text('Pickup point', 'Afhentningssted'), 'postnord_agent');
    ss_step_fill_weight_row($page, 0, '', '', '31.20');
    $page->assertValue('#woocommerce_smart_send_shipping_method', 'postnord_agent');
    highlight_element($page, 'tr:has(#woocommerce_smart_send_shipping_method)');
    capture_doc_screenshot($page, 'methods', 'settings-filled');
});

it('documents saved method settings', function () {
    docs_shipping_fixture('unconfigured');
    $page = visit(base_url('/wp-login.php'))
        ->fill('#user_login', admin_username())->fill('#user_pass', admin_password())->click('#wp-submit')
        ->navigate(docs_method_settings_url());
    ss_step_fill_method_settings($page, docs_text('Pickup point', 'Afhentningssted'), 'postnord_agent');
    ss_step_fill_weight_row($page, 0, '', '', '31.20');
    $page->click('button[name="save"]')->assertPresent('#message.updated');
    highlight_element($page, '#message.updated');
    capture_doc_screenshot($page, 'methods', 'settings-saved');
});

it('documents the configured zone method', function () {
    $state = docs_shipping_fixture('single');
    $page = visit(base_url('/wp-login.php'))
        ->fill('#user_login', admin_username())->fill('#user_pass', admin_password())->click('#wp-submit')
        ->navigate(ss_zone_page_url($state['zone_id']))
        ->assertSee(docs_text('Pickup point', 'Afhentningssted'));
    highlight_element($page, '.wc-shipping-zone-methods');
    capture_doc_screenshot($page, 'methods', 'zone-configured');
});
