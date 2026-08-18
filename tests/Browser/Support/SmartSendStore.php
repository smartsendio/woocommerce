<?php

/*
|--------------------------------------------------------------------------
| Browser suite store helpers
|--------------------------------------------------------------------------
|
| Shared store-management helpers for the Browser suite: WP-CLI access to
| the development store, the Smart Send API mock (a temporary mu-plugin
| installed for the duration of a test file and removed again), and the
| store fixture seeding/cleanup the journey files build on.
|
| Extracted from the historic tests/Browser/SmartSendFlowsTest.php when it
| was split into one file per journey stage (#146). Loaded from
| tests/Pest.php so every Browser (and Docs) file shares one definition -
| function declarations in two test files would collide, since Pest loads
| all test files into one process.
|
| These helpers need filesystem + WP-CLI access to the store installation
| (WP_DEV_PATH, default ./local-dev/wordpress) in addition to WP_BASE_URL;
| tests using them are skipped when the installation is not reachable,
| e.g. when the suite targets a remote store.
|
*/

function ss_browser_wp_path(): string
{
    return rtrim(getenv('WP_DEV_PATH') ?: dirname(__DIR__, 3) . '/local-dev/wordpress', '/');
}

function ss_browser_store_manageable(): bool
{
    return file_exists(ss_browser_wp_path() . '/wp-load.php')
        && file_exists(ss_browser_wp_path() . '/.wp-cli/wp-cli.phar');
}

/**
 * Skip the current test when the store installation is not reachable from
 * the test process - the shared beforeEach guard of every Browser file
 * whose fixtures are managed through WP-CLI.
 */
function ss_browser_skip_unless_store_manageable($test): void
{
    if (!ss_browser_store_manageable()) {
        $test->markTestSkipped('The WordPress installation is not reachable from the test process (WP_DEV_PATH).');
    }
}

/**
 * Run a PHP snippet inside the store via WP-CLI and return the decoded JSON
 * the snippet echoed on its last line.
 */
function ss_browser_wp_eval(string $code): array
{
    $snippet = tempnam(sys_get_temp_dir(), 'ss-browser-eval-') . '.php';
    file_put_contents($snippet, "<?php\n" . $code);

    try {
        return ss_browser_wp_eval_file($snippet);
    } finally {
        unlink($snippet);
    }
}

/**
 * Run a PHP snippet file inside the store via WP-CLI eval-file (positional
 * arguments land in the snippet's $args) and return the decoded JSON the
 * snippet echoed on its last line. The larger seeding/cleanup snippets live
 * as files in tests/Browser/Support/Snippets/, shared with bin/demo-store.sh.
 */
function ss_browser_wp_eval_file(string $snippet, array $args = []): array
{
    $command = escapeshellarg(PHP_BINARY)
        . ' -d memory_limit=512M -d error_reporting=0 -d display_errors=0 '
        . escapeshellarg(ss_browser_wp_path() . '/.wp-cli/wp-cli.phar')
        . ' --path=' . escapeshellarg(ss_browser_wp_path())
        . ' eval-file ' . escapeshellarg($snippet)
        . implode('', array_map(fn (string $arg): string => ' ' . escapeshellarg($arg), $args))
        . ' 2>/dev/null';

    $output = shell_exec($command);

    // WP-CLI may print deprecation noise before the snippet's JSON line.
    $json = null;
    foreach (array_reverse(explode("\n", trim((string) $output))) as $line) {
        if (str_starts_with(trim($line), '{')) {
            $json = json_decode(trim($line), true);
            break;
        }
    }

    if (!is_array($json)) {
        throw new RuntimeException("WP-CLI eval did not return JSON. Output was:\n" . $output);
    }

    return $json;
}

/**
 * Snapshot every shipping method configured on the given zone into a wp
 * option and remove them from the zone, so the zone starts empty for the
 * calling suite and ss_browser_restore_zone_methods() can put the store's
 * real methods (e.g. the Flat rate seeded by bin/setup-local-dev.sh) back
 * afterwards. Clearing goes through WooCommerce's own API rather than the
 * admin UI: the zone screen is client-side rendered, so page-content checks
 * for "is there something to delete" are unreliable - see the
 * ShippingMethod test files' history for the two ways that bit the suites.
 */
function ss_browser_snapshot_and_clear_zone_methods(int $zoneId, string $optionName): void
{
    $encodedOption = var_export($optionName, true);

    ss_browser_wp_eval(<<<PHP
        \$zone = new WC_Shipping_Zone({$zoneId});
        \$snapshot = array();
        foreach (\$zone->get_shipping_methods() as \$method) {
            \$snapshot[] = array(
                'method_id' => \$method->id,
                'settings'  => get_option('woocommerce_' . \$method->id . '_' . \$method->instance_id . '_settings'),
                'enabled'   => \$method->enabled,
                'order'     => isset(\$method->method_order) ? (int) \$method->method_order : 0,
            );
            \$zone->delete_shipping_method(\$method->instance_id);
        }
        update_option({$encodedOption}, \$snapshot);
        echo json_encode(array('snapshotted' => count(\$snapshot)));
        PHP);
}

/**
 * Remove whatever the calling suite configured on the given zone and restore
 * the methods snapshotted by ss_browser_snapshot_and_clear_zone_methods()
 * (fresh instance ids, same configuration).
 */
function ss_browser_restore_zone_methods(int $zoneId, string $optionName): void
{
    $encodedOption = var_export($optionName, true);

    ss_browser_wp_eval(<<<PHP
        \$zone = new WC_Shipping_Zone({$zoneId});
        foreach (\$zone->get_shipping_methods() as \$method) {
            \$zone->delete_shipping_method(\$method->instance_id);
        }
        global \$wpdb;
        foreach (get_option({$encodedOption}, array()) as \$entry) {
            \$instance_id = \$zone->add_shipping_method(\$entry['method_id']);
            if (is_array(\$entry['settings'])) {
                update_option('woocommerce_' . \$entry['method_id'] . '_' . \$instance_id . '_settings', \$entry['settings']);
            }
            // add_shipping_method() appends (enabled, next order); restore the
            // snapshotted order and enabled flag so the store's rate ordering -
            // which determines the preselected method at checkout - survives.
            \$wpdb->update(
                "{\$wpdb->prefix}woocommerce_shipping_zone_methods",
                array(
                    'method_order' => isset(\$entry['order']) ? (int) \$entry['order'] : 0,
                    'is_enabled'   => (isset(\$entry['enabled']) && 'yes' !== \$entry['enabled']) ? 0 : 1,
                ),
                array('instance_id' => \$instance_id)
            );
        }
        delete_option({$encodedOption});
        echo json_encode(array('restored' => true));
        PHP);
}

function ss_browser_mu_plugin_path(): string
{
    return ss_browser_wp_path() . '/wp-content/mu-plugins/ss-browser-test-api-mock.php';
}

/**
 * Install the Smart Send API mock as a temporary mu-plugin. The mock source
 * is the shared tests/Browser/Support/ApiMockMuPlugin.php (also installed by
 * bin/demo-store.sh for manual testing). Only active while the
 * ss_test_api_mock option is 'yes' (set by ss_browser_seed_store() and
 * removed by ss_browser_cleanup_store()).
 *
 * Failure scenarios are switched on per test via the ss_test_api_scenario
 * option - see ss_browser_set_api_scenario() and the scenario list in the
 * mock source's header.
 */
function ss_browser_install_api_mock(): void
{
    $mu_dir = dirname(ss_browser_mu_plugin_path());
    if (!is_dir($mu_dir)) {
        mkdir($mu_dir, 0755, true);
    }

    copy(__DIR__ . '/ApiMockMuPlugin.php', ss_browser_mu_plugin_path());
}

function ss_browser_remove_api_mock(): void
{
    if (file_exists(ss_browser_mu_plugin_path())) {
        unlink(ss_browser_mu_plugin_path());
    }
}

/**
 * Switch the API mock into a failure scenario ('invalid-token' or
 * 'booking-failure'), or back to normal (null). Tests that set a scenario
 * must reset it; ss_browser_cleanup_store() also clears it as a backstop.
 */
function ss_browser_set_api_scenario(?string $scenario): void
{
    if ($scenario === null) {
        ss_browser_wp_eval("delete_option('ss_test_api_scenario'); echo json_encode(array('ok' => true));");

        return;
    }

    $encoded = var_export($scenario, true);
    ss_browser_wp_eval("update_option('ss_test_api_scenario', {$encoded}); echo json_encode(array('ok' => true));");
}

/**
 * The fixture state created by ss_browser_seed_store() (ids of the zone
 * method, checkout page, product and admin-test orders).
 */
function ss_browser_state(): array
{
    return $GLOBALS['ss_browser_state'];
}

/**
 * The admin order-edit path for a fixture order, HPOS-aware.
 */
function ss_browser_order_edit_path(int $order_id): string
{
    return ss_browser_state()['hpos']
        ? '/wp-admin/admin.php?page=wc-orders&action=edit&id=' . $order_id
        : '/wp-admin/post.php?post=' . $order_id . '&action=edit';
}

/**
 * Update one key of the plugin's global settings option inside the store.
 * The seeding snapshot/restore in ss_browser_seed_store()/cleanup keeps the
 * merchant's real settings safe; per-test toggles still restore their own
 * change so tests stay order-independent.
 */
function ss_browser_update_plugin_setting(string $key, string $value): void
{
    $key_encoded   = var_export($key, true);
    $value_encoded = var_export($value, true);

    ss_browser_wp_eval(<<<PHP
\$settings = get_option('woocommerce_smart_send_shipping_settings', array());
\$settings[{$key_encoded}] = {$value_encoded};
update_option('woocommerce_smart_send_shipping_settings', \$settings);
echo json_encode(array('ok' => true));
PHP);
}

/**
 * Seed the store fixtures a journey file needs and remember how to undo it.
 *
 * The actual seeding lives in Snippets/seed-store.php (shared with
 * bin/demo-store.sh) - see its header for what is created and the supported
 * $config keys ('settings', 'orders'). The counterpart
 * ss_browser_cleanup_store() restores everything.
 */
function ss_browser_seed_store(array $config = []): array
{
    ss_browser_install_api_mock();

    $GLOBALS['ss_browser_state'] = ss_browser_wp_eval_file(
        __DIR__ . '/Snippets/seed-store.php',
        [json_encode($config)]
    );

    return $GLOBALS['ss_browser_state'];
}

/**
 * Create a page carrying the Checkout block, alongside the classic
 * (shortcode) checkout page ss_browser_seed_store() sets up - see
 * Snippets/create-block-checkout-page.php (shared with bin/demo-store.sh)
 * for the rationale. The caller tracks the returned page id and removes the
 * page again via ss_browser_delete_block_checkout_page().
 */
function ss_browser_create_block_checkout_page(): int
{
    $result = ss_browser_wp_eval_file(__DIR__ . '/Snippets/create-block-checkout-page.php');

    return (int) $result['page_id'];
}

function ss_browser_delete_block_checkout_page(int $page_id): void
{
    $encoded = var_export($page_id, true);
    ss_browser_wp_eval("wp_delete_post({$encoded}, true); echo json_encode(array('ok' => true));");
}

/**
 * Undo ss_browser_seed_store(): runs Snippets/cleanup-store.php (shared with
 * bin/demo-store.sh) - fixture orders, checkout page and product deleted,
 * settings/COD/zone state restored, mock deactivated - then removes the
 * mu-plugin mock file.
 */
function ss_browser_cleanup_store(): void
{
    if (empty($GLOBALS['ss_browser_state'])) {
        return;
    }

    ss_browser_wp_eval_file(__DIR__ . '/Snippets/cleanup-store.php');

    ss_browser_remove_api_mock();

    unset($GLOBALS['ss_browser_state']);
}
