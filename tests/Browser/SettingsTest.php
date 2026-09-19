<?php

/*
 * The plugin's general settings surface, end-to-end: the settings page
 * itself, the validate-on-save connection flow (success and
 * failure, against the mocked API), the debug log reaching the WooCommerce
 * log viewer, and the order-status-after-label setting taking effect.
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

    // One order for the order-status-after-label test (which books a
    // label on it).
    ss_browser_seed_store([
        'settings' => ['api_token' => 'ss-browser-settings-token'],
        'orders'   => [[]],
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
        ->assertMissing('#woocommerce_smart_send_shipping_api_token_validate');
});

it('validates the API token with a valid token', function () {
    login_as_admin()
        ->navigate(ss_settings_page_url())
        ->fill('#woocommerce_smart_send_shipping_api_token', 'new-browser-token')
        ->click('button[name="save"]')
        ->waitForEvent('load')
        ->assertSeeIn('.ss-connection', 'Connected to Smart Send')
        ->click('button[name="save"]')
        ->waitForEvent('load')
        ->assertSeeIn('.ss-connection', 'Connected to Smart Send')
        ->fill('#woocommerce_smart_send_shipping_api_token', 'edited-unsaved-token')
        ->assertMissing('.ss-connection');
});

it('shows actionable feedback for connection failures after saving', function ($status, $message) {
    ss_browser_set_api_scenarios(['authenticate' => $status]);

    try {
        login_as_admin()
            ->navigate(ss_settings_page_url())
            ->click('button[name="save"]')
            ->assertSeeIn('.ss-connection.error', $message)
            ->assertMissing('.ss-connection.updated');
    } finally {
        ss_browser_set_api_scenarios(null);
    }
})->with([
    ['401', 'Invalid API token provided'],
    ['403', 'Mocked HTTP 403'],
    ['403-subscription', 'The team does not have a subscription.'],
    ['404', 'Mocked HTTP 404'],
    ['500', 'Mocked HTTP 500'],
]);

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
            ->click('button[name="save"]')
            ->assertSeeIn('.ss-connection', 'Connected to Smart Send')
            ->navigate(base_url('/wp-admin/admin.php?page=wc-status&tab=logs'))
            ->assertSee('smart-send-logistics');
    } finally {
        ss_browser_update_plugin_setting('ss_debug', 'no');
    }
});

it('order-status-after-label setting changes the order status', function () {
    $state = ss_browser_state();
    $order_id = $state['orders'][0];

    ss_browser_update_plugin_setting('order_status', 'wc-completed');

    try {
        login_as_admin()
            ->navigate(base_url(ss_browser_order_edit_path($order_id)))
            ->assertSeeIn('#woocommerce-ss-shipping-label .hndle', 'Smart Send')
            ->click('[data-ss-action="create-label"]')
            ->assertPresent('[data-ss-result="outbound"]');

        $result = ss_browser_wp_eval(<<<PHP
\$order = wc_get_order({$order_id});
echo json_encode(array('status' => \$order ? \$order->get_status() : null));
PHP);

        expect($result['status'])->toBe('completed');
    } finally {
        ss_browser_update_plugin_setting('order_status', '0');
    }
});
