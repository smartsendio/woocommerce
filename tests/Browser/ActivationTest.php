<?php

/*
 * Plugin lifecycle: activation and the settings-persistence contract.
 *
 * The setup script installs the plugin by symlinking it from the repository,
 * so the wp-admin activation path is never exercised elsewhere. Cycling the
 * plugin through deactivate -> activate on the plugins screen catches
 * fatals in the (de)activation hooks and on plugin load. The tests are
 * self-contained: they always leave the plugin active for the other tests,
 * regardless of execution order.
 *
 * Decided behavior contract (#146): neither deactivation nor uninstall
 * deletes the plugin's settings - there is intentionally no uninstall.php,
 * and readme.txt documents that settings survive by design. The second
 * test pins the deactivate/re-activate half of that contract end-to-end.
 */

it('activates from a deactivated state without errors', function () {
    $page = login_as_admin()
        ->navigate(base_url('/wp-admin/plugins.php'));

    $page->assertPresent('#deactivate-smart-send-logistics')
        ->click('#deactivate-smart-send-logistics')
        ->assertSee('Plugin deactivated.')
        ->assertPresent('#activate-smart-send-logistics')
        ->click('#activate-smart-send-logistics')
        ->assertSee('Plugin activated.')
        ->assertPresent('#deactivate-smart-send-logistics');
});

it('deactivating and re-activating preserves the saved settings', function () {
    // Managing the settings option requires WP-CLI access to the store.
    ss_browser_skip_unless_store_manageable($this);

    // Plant a distinctive settings value, stashing the real settings in a
    // temporary option so they can be restored verbatim afterwards.
    ss_browser_wp_eval(<<<'PHP'
$original = get_option('woocommerce_smart_send_shipping_settings');
update_option('ss_browser_activation_original', array('settings' => $original));
$settings = is_array($original) ? $original : array();
$settings['api_token'] = 'ss-browser-persistence-token-146';
update_option('woocommerce_smart_send_shipping_settings', $settings);
echo json_encode(array('planted' => true));
PHP);

    $page = login_as_admin()
        ->navigate(base_url('/wp-admin/plugins.php'));

    $page->click('#deactivate-smart-send-logistics')
        ->assertSee('Plugin deactivated.')
        ->click('#activate-smart-send-logistics')
        ->assertSee('Plugin activated.');

    // Read the surviving value, then restore the pre-test settings before
    // asserting so a failure cannot leave the marker token behind.
    $after = ss_browser_wp_eval(<<<'PHP'
$settings = get_option('woocommerce_smart_send_shipping_settings');
$token = is_array($settings) && isset($settings['api_token']) ? $settings['api_token'] : null;
$stash = get_option('ss_browser_activation_original', array());
if (array_key_exists('settings', $stash) && is_array($stash['settings'])) {
    update_option('woocommerce_smart_send_shipping_settings', $stash['settings']);
} else {
    delete_option('woocommerce_smart_send_shipping_settings');
}
delete_option('ss_browser_activation_original');
echo json_encode(array('token' => $token));
PHP);

    expect($after['token'])->toBe('ss-browser-persistence-token-146');
});
