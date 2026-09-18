<?php

/*
 * Installation, connection and support screenshots for the version 9 guides.
 * Every state seeds its own synthetic store and account response. The token
 * below is deliberately descriptive and cannot authenticate against a real API.
 * Keep visit() in each test: Pest uses it to identify Docs browser tests.
 */

beforeEach(function (): void {
    ss_browser_skip_unless_store_manageable($this);
    docs_seed_store([
        'settings' => ['api_token' => 'docs-example-token-not-valid-for-real-api'],
    ]);
});

afterEach(function (): void {
    docs_cleanup_store();
});

function docs_connection_settings_url(): string
{
    return base_url('/wp-admin/admin.php?page=wc-settings&tab=shipping&section=smart_send_shipping');
}

it('documents the installed and active plugin', function () {
    $page = visit(base_url('/wp-login.php'))
        ->fill('#user_login', admin_username())
        ->fill('#user_pass', admin_password())
        ->click('#wp-submit')
        ->assertPathContains('wp-admin')
        ->navigate(base_url('/wp-admin/plugins.php?s=Smart+Send&plugin_status=all'));

    $row = 'tr.active[data-plugin="smart-send-logistics/smart-send-logistics.php"]';
    $page->assertVisible($row)
        ->assertPresent($row . ' a[href*="action=deactivate"]')
        ->assertPresent($row . ' a[href*="section=smart_send_shipping"]');

    highlight_element($page, $row);
    capture_doc_screenshot($page, 'setup', 'plugin-active');
});

it('documents where to find the Smart Send settings', function () {
    $page = visit(base_url('/wp-login.php'))
        ->fill('#user_login', admin_username())
        ->fill('#user_pass', admin_password())
        ->click('#wp-submit')
        ->assertPathContains('wp-admin')
        ->navigate(docs_connection_settings_url());

    $page->assertVisible('.nav-tab-active[href*="tab=shipping"]')
        ->assertVisible('.subsubsub a.current[href*="section=smart_send_shipping"]')
        ->assertVisible('#woocommerce_smart_send_shipping_api_token');

    // Keep the native WooCommerce tabs visible as the navigation context.
    highlight_element($page, '.subsubsub');
    capture_doc_screenshot($page, 'setup', 'settings-location');
});

it('documents saving a synthetic API token', function () {
    ss_browser_update_plugin_setting('api_token', '');

    $page = visit(base_url('/wp-login.php'))
        ->fill('#user_login', admin_username())
        ->fill('#user_pass', admin_password())
        ->click('#wp-submit')
        ->assertPathContains('wp-admin')
        ->navigate(docs_connection_settings_url())
        ->fill('#woocommerce_smart_send_shipping_api_token', 'docs-example-token-not-valid-for-real-api')
        ->click('button[name="save"]')
        ->assertVisible('#message.updated')
        ->assertValue('#woocommerce_smart_send_shipping_api_token', 'docs-example-token-not-valid-for-real-api')
        ->assertVisible('.ss-connection.updated');

    highlight_element($page, '#mainform .form-table');
    capture_doc_screenshot($page, 'connection', 'token-settings');
});

it('documents successful API token validation', function () {
    $page = visit(base_url('/wp-login.php'))
        ->fill('#user_login', admin_username())
        ->fill('#user_pass', admin_password())
        ->click('#wp-submit')
        ->assertPathContains('wp-admin')
        ->navigate(docs_connection_settings_url())
        ->click('button[name="save"]')
        ->assertVisible('.ss-connection.updated')
        ->assertMissing('.ss-connection.error');

    highlight_element($page, '.ss-connection');
    capture_doc_screenshot($page, 'connection', 'validation-success');
});

it('documents an invalid API token response', function () {
    ss_browser_update_plugin_setting('api_token', 'docs-example-invalid-token');
    ss_browser_set_api_scenarios(['authenticate' => '401']);

    $page = visit(base_url('/wp-login.php'))
        ->fill('#user_login', admin_username())
        ->fill('#user_pass', admin_password())
        ->click('#wp-submit')
        ->assertPathContains('wp-admin')
        ->navigate(docs_connection_settings_url())
        ->click('button[name="save"]')
        ->assertVisible('.ss-connection.error')
        ->assertMissing('.ss-connection.updated');

    // Capture the real translated feedback from saving the settings.
    highlight_element($page, '.ss-connection');
    capture_doc_screenshot($page, 'connection', 'validation-error');
});

it('documents the WooCommerce log location and Smart Send source', function () {
    ss_browser_wp_eval(<<<'PHP'
\Smart_Send\Support\Logger::info('Documentation example: API token connection validated.');
echo json_encode(array('ok' => true));
PHP);

    $page = visit(base_url('/wp-login.php'))
        ->fill('#user_login', admin_username())
        ->fill('#user_pass', admin_password())
        ->click('#wp-submit')
        ->assertPathContains('wp-admin')
        ->navigate(base_url('/wp-admin/admin.php?page=wc-status&tab=logs&source=smart-send-logistics'))
        ->assertVisible('#filter-by-source')
        ->assertValue('#filter-by-source', 'smart-send-logistics')
        ->assertVisible('#logs-list-table-form tbody tr:first-child a.row-title');

    highlight_element($page, '#filter-by-source');
    capture_doc_screenshot($page, 'help', 'log-location');
});
