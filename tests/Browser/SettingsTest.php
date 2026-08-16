<?php

/*
 * The plugin's general settings surface, end-to-end: the settings page
 * itself, the "Validate API Token" test-connection flow (success and
 * failure, against the mocked API), the debug log reaching the WooCommerce
 * log viewer, demo mode relabelling the label buttons, and the
 * order-status-after-label setting taking effect.
 *
 * Settings-permutation depth deliberately lives in the Integration suite
 * (tests/Integration/RateCalculationTest.php and friends); this file
 * proves each settings surface works through the real admin UI once.
 *
 * Store fixtures and the API mock are managed through the shared helpers
 * in tests/Browser/Support/SmartSendStore.php. Every test restores the
 * settings toggles it changes, so the file is re-runnable.
 */

function ss_settings_page_url(): string
{
    return base_url('/wp-admin/admin.php?page=wc-settings&tab=shipping&section=smart_send_shipping');
}

beforeAll(function (): void {
    if (!ss_browser_store_manageable()) {
        return;
    }

    // Two orders: one for the demo-mode meta box test, one for the
    // order-status-after-label test (which books a label on it).
    ss_browser_seed_store([
        'settings' => ['api_token' => 'ss-browser-settings-token'],
        'orders'   => [[], []],
    ]);
});

afterAll(function (): void {
    if (!ss_browser_store_manageable()) {
        return;
    }

    ss_browser_cleanup_store();
});

beforeEach(function (): void {
    ss_browser_skip_unless_store_manageable($this);
});

it('renders the Smart Send settings page', function () {
    login_as_admin()
        ->navigate(ss_settings_page_url())
        ->assertSee('API Token')
        ->assertSee('Validate API Token')
        ->assertSee('Demo mode');
});

it('validates the API token with a valid token', function () {
    login_as_admin()
        ->navigate(ss_settings_page_url())
        ->click('#woocommerce_smart_send_shipping_api_token_validate')
        // The AJAX handler validates the saved token against the (mocked)
        // account endpoint and reports the connected account.
        ->assertSeeIn('.ss-connection', 'API Token verified')
        ->assertSeeIn('.ss-connection', 'mock@smartsend.test');
});

it('shows a clear error for an invalid API token', function () {
    ss_browser_set_api_scenario('invalid-token');

    try {
        login_as_admin()
            ->navigate(ss_settings_page_url())
            ->click('#woocommerce_smart_send_shipping_api_token_validate')
            ->assertSeeIn('.ss-connection', 'API Token validation failed')
            ->assertSeeIn('.ss-connection', 'Invalid API token provided');
    } finally {
        ss_browser_set_api_scenario(null);
    }
});

it('enabling debug logging produces entries in the WooCommerce log viewer', function () {
    // Start from a clean slate so the entries seen below are provably from
    // this test: remove existing smart-send-logistics log files and enable
    // the Debug Log setting (API request cycles are logged at debug level,
    // which is the only level gated on that setting).
    ss_browser_wp_eval(<<<'PHP'
foreach (glob(trailingslashit(WC_LOG_DIR) . 'smart-send-logistics-*.log') as $file) {
    unlink($file);
}
$settings = get_option('woocommerce_smart_send_shipping_settings', array());
$settings['ss_debug'] = 'yes';
update_option('woocommerce_smart_send_shipping_settings', $settings);
echo json_encode(array('ok' => true));
PHP);

    try {
        // Trigger an API request cycle (the test-connection call), then
        // check the log viewer lists the smart-send-logistics source.
        login_as_admin()
            ->navigate(ss_settings_page_url())
            ->click('#woocommerce_smart_send_shipping_api_token_validate')
            ->assertSeeIn('.ss-connection', 'API Token verified')
            ->navigate(base_url('/wp-admin/admin.php?page=wc-status&tab=logs'))
            ->assertSee('smart-send-logistics');
    } finally {
        ss_browser_update_plugin_setting('ss_debug', 'no');
    }
});

it('demo mode relabels the label buttons', function () {
    $state = ss_browser_state();

    // Demo mode on (the seeded default): the meta box button carries the
    // DEMO MODE prefix.
    login_as_admin()
        ->navigate(base_url(ss_browser_order_edit_path($state['orders'][0])))
        ->assertSee('DEMO MODE: Generate label')
        ->assertSee('DEMO MODE: Generate return label');

    // Demo mode off: the plain button text.
    ss_browser_update_plugin_setting('demo', 'no');

    try {
        login_as_admin()
            ->navigate(base_url(ss_browser_order_edit_path($state['orders'][0])))
            ->assertDontSee('DEMO MODE: Generate label')
            ->assertSee('Generate label');
    } finally {
        ss_browser_update_plugin_setting('demo', 'yes');
    }
});

it('order-status-after-label setting changes the order status', function () {
    $state = ss_browser_state();
    $order_id = $state['orders'][1];

    ss_browser_update_plugin_setting('order_status', 'wc-completed');

    try {
        login_as_admin()
            ->navigate(base_url(ss_browser_order_edit_path($order_id)))
            ->assertSee('Smart Send Shipping')
            ->click('#ss-shipping-label-button')
            ->assertSeeIn('#ss-label-created', 'Download shipping label');

        $result = ss_browser_wp_eval(<<<PHP
\$order = wc_get_order({$order_id});
echo json_encode(array('status' => \$order ? \$order->get_status() : null));
PHP);

        expect($result['status'])->toBe('completed');
    } finally {
        ss_browser_update_plugin_setting('order_status', '0');
    }
});
