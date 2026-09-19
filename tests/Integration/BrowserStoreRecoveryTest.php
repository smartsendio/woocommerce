<?php

/* These exercise the actual WP-CLI boundary used after CI kills a browser
 * process. No browser is needed to verify the durable fixture ownership. */

function ss_recovery_zone_id(): int
{
    return $GLOBALS['ss_recovery_zone_id'];
}

function ss_recovery_retry(): void
{
    ss_browser_recover_store(ss_recovery_zone_id());
}

beforeAll(function (): void {
    if (!ss_browser_store_manageable()) {
        return;
    }

    // Integration CI deliberately omits the sample catalogue and zones.
    // Own a separate zone instead of depending on (or replacing) zone 1.
    $state = ss_browser_wp_eval(<<<'PHP'
$zone = new WC_Shipping_Zone();
$zone->set_zone_name('SS recovery regression');
$zone->set_zone_locations(array((object) array('code' => 'DK', 'type' => 'country')));
$zone->save();
$instance = $zone->add_shipping_method('flat_rate');
update_option('woocommerce_flat_rate_' . $instance . '_settings', array('title' => 'Flat rate', 'cost' => '39'));
echo json_encode(array('zone_id' => $zone->get_id()));
PHP);
    $GLOBALS['ss_recovery_zone_id'] = $state['zone_id'];
});

afterAll(function (): void {
    if (!isset($GLOBALS['ss_recovery_zone_id'])) {
        return;
    }

    ss_recovery_retry();
    $zone_id = ss_recovery_zone_id();
    ss_browser_wp_eval(<<<PHP
\$zone = new WC_Shipping_Zone({$zone_id});
foreach (\$zone->get_shipping_methods() as \$method) {
    \$zone->delete_shipping_method(\$method->instance_id);
}
\$zone->delete();
echo json_encode(array('cleaned' => true));
PHP);
    unset($GLOBALS['ss_recovery_zone_id']);
});

function ss_recovery_baseline(bool $with_ids = false): array
{
    $zone_helpers = var_export(dirname(__DIR__) . '/Browser/Support/Snippets/zone-methods.php', true);
    $keep_ids = var_export($with_ids, true);
    $zone_id = ss_recovery_zone_id();

    return ss_browser_wp_eval(<<<PHP
require_once {$zone_helpers};
\$zones = array();
foreach (WC_Shipping_Zones::get_zones() as \$data) {
    \$zone = new WC_Shipping_Zone(\$data['id']);
    \$methods = ss_browser_capture_zone(\$zone);
    if (!{$keep_ids}) {
        foreach (\$methods as &\$method) {
            unset(\$method['instance_id']);
        }
        unset(\$method);
    }
    \$zones[\$zone->get_id()] = array('name' => \$zone->get_zone_name(), 'methods' => \$methods);
}
echo json_encode(array(
    'settings' => get_option('woocommerce_smart_send_shipping_settings'),
    'cod' => get_option('woocommerce_cod_settings'),
    'checkout' => get_option('woocommerce_checkout_page_id'),
    'zone' => \$zones[{$zone_id}]['methods'],
    'all_zones' => \$zones,
));
PHP);
}

beforeEach(function (): void {
    ss_browser_skip_unless_store_manageable($this);
    ss_recovery_retry();
});

it('uses the same explicit and relative store path for browser fixtures and integration bootstrap', function () {
    $wp_path = getenv('WP_PATH');
    $legacy_path = getenv('WP_DEV_PATH');
    $wp_url = getenv('WP_URL');
    $legacy_url = getenv('WP_BASE_URL');
    $env_file = tempnam(sys_get_temp_dir(), 'ss-store-env-');
    file_put_contents($env_file, "WP_PATH=local-dev/from-file\nWP_URL=http://127.0.0.1:9191\n");
    try {
        putenv('WP_PATH=/private/tmp/ss-explicit-store/');
        putenv('WP_DEV_PATH=/private/tmp/ss-legacy-store');
        expect(ss_browser_wp_path())->toBe('/private/tmp/ss-explicit-store')
            ->and(ss_browser_wp_path())->toBe(ss_test_wp_path());

        putenv('WP_PATH=local-dev/isolated-store');
        expect(ss_browser_wp_path())->toBe(dirname(__DIR__, 2) . '/local-dev/isolated-store');

        putenv('WP_PATH');
        putenv('WP_URL');
        putenv('WP_BASE_URL=http://127.0.0.1:9292');
        ss_test_load_store_environment($env_file);
        expect(ss_browser_wp_path())->toBe('/private/tmp/ss-legacy-store');
        expect(getenv('WP_PATH'))->toBeFalse()
            ->and(getenv('WP_URL'))->toBeFalse()
            ->and(base_url())->toBe('http://127.0.0.1:9292/');

        putenv('WP_DEV_PATH');
        expect(ss_browser_wp_path())->toBe(dirname(__DIR__, 2) . '/./local-dev/wordpress');
        putenv('WP_BASE_URL');
        ss_test_load_store_environment($env_file);
        expect(ss_browser_wp_path())->toBe(dirname(__DIR__, 2) . '/local-dev/from-file')
            ->and(base_url())->toBe('http://127.0.0.1:9191/');
    } finally {
        putenv($wp_path === false ? 'WP_PATH' : 'WP_PATH=' . $wp_path);
        putenv($legacy_path === false ? 'WP_DEV_PATH' : 'WP_DEV_PATH=' . $legacy_path);
        putenv($wp_url === false ? 'WP_URL' : 'WP_URL=' . $wp_url);
        putenv($legacy_url === false ? 'WP_BASE_URL' : 'WP_BASE_URL=' . $legacy_url);
        unlink($env_file);
    }
});

it('leaves a clean store untouched when no fixture snapshots exist', function () {
    $before = ss_recovery_baseline();
    expect(array_column($before['zone'], 'method_id'))->toContain('flat_rate');

    ss_browser_cleanup_store();
    ss_browser_restore_zone_methods(ss_recovery_zone_id(), 'ss_method_setup_zone_snapshot');
    ss_recovery_retry();

    expect(ss_recovery_baseline())->toBe($before);
});

it('recovers a partially seeded store before a retry without relying on process memory', function () {
    $before = ss_recovery_baseline(true);
    $seeder = var_export(dirname(__DIR__) . '/Browser/Support/Snippets/seed-store.php', true);

    try {
        // Stop immediately after the settings and mock have changed, before
        // products, pages or final state can be written. This models a kill
        // during beforeAll, rather than only after seeding has succeeded.
        $interrupted = ss_browser_wp_eval(<<<PHP
add_action('updated_option', function (\$option) {
    if (\$option === 'ss_test_api') {
        throw new RuntimeException('simulated interrupted fixture setup');
    }
}, 10, 1);
add_action('added_option', function (\$option) {
    if (\$option === 'ss_test_api') {
        throw new RuntimeException('simulated interrupted fixture setup');
    }
}, 10, 1);
\$args = array('{}');
try {
    require {$seeder};
} catch (RuntimeException \$error) {
    echo json_encode(array('interrupted' => \$error->getMessage(), 'saved' => is_array(get_option('ss_browser_test_state'))));
}
PHP);
        expect($interrupted['saved'])->toBeTrue()
            ->and($interrupted['interrupted'])->toBe('simulated interrupted fixture setup');

        unset($GLOBALS['ss_browser_state']);
        ss_recovery_retry();
        expect(ss_recovery_baseline(true))->toBe($before);

        // The next fixture can start and clean up normally after recovery.
        $state = ss_browser_seed_store(['orders' => [[]]]);
        ss_browser_create_block_checkout_page();
        ss_browser_install_methods_filter(['outbound' => ['postnord_agent']]);
        ss_browser_set_api_scenarios(['booking' => '500']);
        unset($GLOBALS['ss_browser_state']);
        ss_recovery_retry();

        $remaining = ss_browser_wp_eval(<<<PHP
echo json_encode(array(
    'state' => get_option('ss_browser_test_state'),
    'mock' => get_option('ss_test_api'),
    'filter' => get_option('ss_test_methods_filter'),
    'order' => (bool) wc_get_order({$state['orders'][0]}),
    'checkout' => (bool) get_post({$state['checkout_page_id']}),
    'product' => (bool) wc_get_product({$state['product_id']}),
));
PHP);
        expect(array_unique(array_values($remaining)))->toBe([false])
            ->and(file_exists(ss_browser_mu_plugin_path()))->toBeFalse()
            ->and(file_exists(ss_browser_methods_filter_path()))->toBeFalse()
            ->and(ss_recovery_baseline(true))->toBe($before);
    } finally {
        ss_recovery_retry();
    }
});

it('recovers the original Flat rate after an interrupted zone setup and repeated retry', function () {
    $before = ss_recovery_baseline();
    $zone_helpers = var_export(dirname(__DIR__) . '/Browser/Support/Snippets/zone-methods.php', true);
    $zone_id = ss_recovery_zone_id();
    expect(array_column($before['zone'], 'method_id'))->toContain('flat_rate');

    try {
        $interrupted = ss_browser_wp_eval(<<<PHP
require_once {$zone_helpers};
add_action('woocommerce_shipping_zone_method_deleted', function () {
    throw new RuntimeException('simulated interrupted zone clear');
});
try {
    ss_browser_snapshot_and_clear_saved_zone({$zone_id}, 'ss_method_setup_zone_snapshot');
} catch (RuntimeException \$error) {
    echo json_encode(array('interrupted' => \$error->getMessage(), 'saved' => get_option('ss_method_setup_zone_snapshot')));
}
PHP);
        expect($interrupted['interrupted'])->toBe('simulated interrupted zone clear')
            ->and(array_column($interrupted['saved'], 'method_id'))->toContain('flat_rate');
        expect(ss_recovery_baseline()['zone'])->toBe([]);

        ss_browser_wp_eval(<<<PHP
\$zone = new WC_Shipping_Zone({$zone_id});
\$zone->add_shipping_method('smart_send_shipping');
echo json_encode(array('saved_snapshot' => get_option('ss_method_setup_zone_snapshot')));
PHP);

        // A second beforeAll must not replace the durable original snapshot
        // with the first attempt's half-configured Smart Send method.
        ss_browser_snapshot_and_clear_zone_methods($zone_id, 'ss_method_setup_zone_snapshot');
        ss_recovery_retry();
        expect(ss_recovery_baseline())->toBe($before);
        ss_recovery_retry();
        expect(ss_recovery_baseline())->toBe($before);
    } finally {
        ss_recovery_retry();
    }
});
