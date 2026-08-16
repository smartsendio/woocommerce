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

    $command = escapeshellarg(PHP_BINARY)
        . ' -d memory_limit=512M -d error_reporting=0 -d display_errors=0 '
        . escapeshellarg(ss_browser_wp_path() . '/.wp-cli/wp-cli.phar')
        . ' --path=' . escapeshellarg(ss_browser_wp_path())
        . ' eval-file ' . escapeshellarg($snippet) . ' 2>/dev/null';

    $output = shell_exec($command);
    unlink($snippet);

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

function ss_browser_mu_plugin_path(): string
{
    return ss_browser_wp_path() . '/wp-content/mu-plugins/ss-browser-test-api-mock.php';
}

/**
 * Install the Smart Send API mock as a temporary mu-plugin. Only active
 * while the ss_test_api_mock option is 'yes' (set by ss_browser_seed_store()
 * and removed by ss_browser_cleanup_store()).
 *
 * Failure scenarios are switched on per test via the ss_test_api_scenario
 * option - see ss_browser_set_api_scenario():
 *
 *  - 'invalid-token'   -> the account/authenticate call returns 401
 *  - 'booking-failure' -> the shipments/labels booking call returns 422
 *                         with a validation message
 */
function ss_browser_install_api_mock(): void
{
    $mu_dir = dirname(ss_browser_mu_plugin_path());
    if (!is_dir($mu_dir)) {
        mkdir($mu_dir, 0755, true);
    }

    file_put_contents(ss_browser_mu_plugin_path(), <<<'PHP'
<?php
/**
 * Smart Send API mock for the Pest browser tests. Installed temporarily by
 * tests/Browser/Support/SmartSendStore.php and removed again when a test
 * file's afterAll runs. Only active while the ss_test_api_mock option is
 * 'yes'; failure scenarios are switched on via ss_test_api_scenario.
 */
add_filter('pre_http_request', function ($pre, $args, $url) {
    if (get_option('ss_test_api_mock') !== 'yes' || strpos($url, 'smartsend.io') === false) {
        return $pre;
    }

    $respond = function ($body, $code = 200) {
        return array(
            'response' => array('code' => $code, 'message' => $code === 200 ? 'OK' : 'Error'),
            'headers'  => array('content-type' => 'application/json'),
            'body'     => json_encode($body),
            'cookies'  => array(),
            'filename' => null,
        );
    };

    $scenario = get_option('ss_test_api_scenario');

    if (strpos($url, 'agents/closest') !== false) {
        return $respond(array('data' => array(
            array('id' => 1, 'agent_no' => '1234', 'company' => 'Browser Test Shop', 'address_line1' => 'Main Street 1', 'address_line2' => null, 'postal_code' => '2300', 'city' => 'Copenhagen', 'country' => 'DK', 'distance' => 0.42),
            array('id' => 2, 'agent_no' => '5678', 'company' => 'Second Test Shop', 'address_line1' => 'Other Street 9', 'address_line2' => null, 'postal_code' => '2300', 'city' => 'Copenhagen', 'country' => 'DK', 'distance' => 1.2),
        )));
    }

    if (strpos($url, 'shipments/labels/combine') !== false) {
        return $respond(array('data' => array(
            'pdf' => array('link' => 'https://mock.smartsend.test/labels/combined.pdf', 'base_64_encoded' => base64_encode('%PDF-combo')),
        )));
    }

    if (strpos($url, 'shipments/labels') !== false) {
        if ($scenario === 'booking-failure') {
            // A validation failure in the shape the real API produces
            // (message + field errors); the plugin renders it through
            // Response::errorString() into the meta box error div.
            return $respond(array(
                'message' => 'The given data was invalid.',
                'errors'  => array(
                    'receiver.zip_code' => array('The receiver zip code does not match the receiver country'),
                ),
            ), 422);
        }

        return $respond(array('data' => array(
            'shipment_id'  => 'browser-shipment-' . uniqid(),
            'carrier_name' => 'PostNord',
            'carrier_code' => 'postnord',
            'pdf'          => array('link' => 'https://mock.smartsend.test/labels/label.pdf', 'base_64_encoded' => base64_encode('%PDF-label')),
            'parcels'      => array(
                array('parcel_internal_id' => 1, 'tracking_code' => 'BROWSERTRACK1', 'tracking_link' => 'https://mock.smartsend.test/track/1'),
            ),
        )));
    }

    if (strpos($url, 'agents/carrier') !== false) {
        return $respond(array('data' => array(
            'id' => 1, 'agent_no' => '1234', 'company' => 'Browser Test Shop', 'address_line1' => 'Main Street 1', 'address_line2' => null, 'postal_code' => '2300', 'city' => 'Copenhagen', 'country' => 'DK',
        )));
    }

    // Anything else is the account/authenticate call (the API base URL with
    // no resource path - Smartsend\Resources\AccountResource::getAuthenticatedUser()).
    if ($scenario === 'invalid-token') {
        return $respond(array('message' => 'Invalid API token provided'), 401);
    }

    return $respond(array('data' => array('id' => 1, 'email' => 'mock@smartsend.test', 'website' => 'localhost')));
}, 5, 3);
PHP);
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
 * Always: snapshots + replaces the plugin's global settings, activates the
 * API mock, enables Cash on Delivery, ensures a Denmark zone with a Smart
 * Send agent method instance, creates the test product and a classic
 * (shortcode) checkout page.
 *
 * $config:
 *  - 'settings' (array)  merged over the default plugin settings
 *  - 'orders'   (array)  a list of order specs; each spec may set
 *                        'auto_return' => true to enable the method's
 *                        auto-generate-return-label flag on the order.
 *                        Created order ids come back in state 'orders'.
 *
 * The counterpart ss_browser_cleanup_store() restores everything.
 */
function ss_browser_seed_store(array $config = []): array
{
    ss_browser_install_api_mock();

    $config += ['settings' => [], 'orders' => []];
    $config_json = var_export(json_encode($config), true);

    $GLOBALS['ss_browser_state'] = ss_browser_wp_eval(<<<PHP
\$config = json_decode({$config_json}, true);

\$original_settings = get_option('woocommerce_smart_send_shipping_settings');
update_option('woocommerce_smart_send_shipping_settings', array_merge(array(
    'demo' => 'yes', 'ss_debug' => 'no', 'include_order_comment' => 'no',
    'save_shipping_labels_in_uploads' => 'no', 'dropdown_display_format' => '4',
    'default_select_agent' => 'no', 'order_status' => '0',
), \$config['settings']));
update_option('ss_test_api_mock', 'yes');
delete_option('ss_test_api_scenario');

// Cash on delivery so the checkout can be completed.
\$cod = get_option('woocommerce_cod_settings', array());
\$cod_was_enabled = isset(\$cod['enabled']) ? \$cod['enabled'] : 'no';
\$cod['enabled'] = 'yes';
update_option('woocommerce_cod_settings', \$cod);

// Denmark zone with a Smart Send agent method instance.
\$zone_id = 0;
foreach (WC_Shipping_Zones::get_zones() as \$zone_data) {
    foreach (\$zone_data['zone_locations'] as \$location) {
        if (\$location->code === 'DK') {
            \$zone_id = \$zone_data['id'];
            break 2;
        }
    }
}
if (!\$zone_id) {
    \$zone = new WC_Shipping_Zone();
    \$zone->set_zone_name('Denmark');
    \$zone->set_zone_locations(array((object) array('code' => 'DK', 'type' => 'country')));
    \$zone->save();
    \$zone_id = \$zone->get_id();
}
\$zone = new WC_Shipping_Zone(\$zone_id);
\$instance_id = 0;
foreach (\$zone->get_shipping_methods() as \$iid => \$method) {
    if (\$method->id === 'smart_send_shipping') {
        \$instance_id = \$iid;
        break;
    }
}
\$created_instance = 0;
if (!\$instance_id) {
    \$instance_id = \$zone->add_shipping_method('smart_send_shipping');
    \$created_instance = 1;
}
update_option('woocommerce_smart_send_shipping_' . \$instance_id . '_settings', array(
    'title' => 'Smart Send Pickup Point',
    'method' => 'postnord_agent',
    'return_method' => 'postnord_returndropoff',
    'auto_generate_return_label' => 'no',
    'tax_status' => 'none',
    'requires' => '',
    'flatfee_cost' => '0',
    'min_amount' => '0',
    'advanced_settings_enable' => 'no',
    'cost_weight' => array(array('ss_min_weight' => '', 'ss_max_weight' => '', 'ss_cost_weight' => '29')),
));

// A classic (shortcode) checkout page; the pickup point selector only
// renders in the classic checkout.
\$page_id = wp_insert_post(array(
    'post_title'   => 'SS Classic Checkout',
    'post_name'    => 'ss-classic-checkout',
    'post_content' => '[woocommerce_checkout]',
    'post_status'  => 'publish',
    'post_type'    => 'page',
));
\$original_checkout_page = get_option('woocommerce_checkout_page_id');
update_option('woocommerce_checkout_page_id', \$page_id);

// A product to buy.
\$product_id = wc_get_product_id_by_sku('SS-BROWSER-TEST');
if (!\$product_id) {
    \$product = new WC_Product_Simple();
    \$product->set_name('SS Browser Test Product');
    \$product->set_sku('SS-BROWSER-TEST');
    \$product->set_regular_price('100');
    \$product->set_weight('1');
    \$product->save();
    \$product_id = \$product->get_id();
}

// Orders for the admin label tests, built like checkout would build them.
\$make_order = function (\$spec) use (\$product_id) {
    \$auto_return = !empty(\$spec['auto_return']);
    \$order = wc_create_order(array('status' => 'processing', 'created_via' => 'ss-browser-test'));
    \$order->add_product(wc_get_product(\$product_id), 1);
    \$address = array(
        'first_name' => 'Browser', 'last_name' => 'Test', 'address_1' => 'Islands Brygge 39',
        'city' => 'Copenhagen', 'postcode' => '2300', 'country' => 'DK',
    );
    \$order->set_address(array_merge(\$address, array('email' => 'ss-browser-test@smartsend.io', 'phone' => '+4512345678')), 'billing');
    \$order->set_address(\$address, 'shipping');
    \$item = new WC_Order_Item_Shipping();
    \$item->set_method_title('Smart Send Pickup Point');
    \$item->set_method_id('smart_send_shipping');
    \$item->set_instance_id(1);
    \$item->set_total('29');
    \$item->add_meta_data('smart_send_shipping_method', 'postnord_agent', true);
    \$item->add_meta_data('smart_send_return_method', 'postnord_returndropoff', true);
    \$item->add_meta_data('smart_send_auto_generate_return_label', \$auto_return ? 'yes' : 'no', true);
    \$order->add_item(\$item);
    \$order->calculate_totals();
    \$order->update_meta_data('ss_shipping_order_agent_no', '1234');
    \$order->update_meta_data('_ss_shipping_order_agent', (object) array(
        'id' => 1, 'agent_no' => '1234', 'company' => 'Browser Test Shop', 'address_line1' => 'Main Street 1',
        'address_line2' => null, 'postal_code' => '2300', 'city' => 'Copenhagen', 'country' => 'DK',
    ));
    \$order->save();
    return \$order->get_id();
};
\$order_ids = array();
foreach (\$config['orders'] as \$spec) {
    \$order_ids[] = \$make_order(is_array(\$spec) ? \$spec : array());
}

\$hpos = Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

\$state = array(
    'original_settings'      => \$original_settings === false ? null : \$original_settings,
    'zone_id'                => \$zone_id,
    'instance_id'            => \$instance_id,
    'created_instance'       => \$created_instance,
    'checkout_page_id'       => \$page_id,
    'original_checkout_page' => \$original_checkout_page,
    'cod_was_enabled'        => \$cod_was_enabled,
    'product_id'             => \$product_id,
    'orders'                 => \$order_ids,
    'orders_list_path'       => \$hpos ? '/wp-admin/admin.php?page=wc-orders' : '/wp-admin/edit.php?post_type=shop_order',
    'hpos'                   => \$hpos ? 1 : 0,
);
update_option('ss_browser_test_state', \$state);
echo json_encode(\$state);
PHP);

    return $GLOBALS['ss_browser_state'];
}

/**
 * Undo ss_browser_seed_store(): delete the fixture orders (both the seeded
 * ones and any placed by a checkout test run), the checkout page and the
 * product, restore the settings/COD/zone state and deactivate the mock.
 */
function ss_browser_cleanup_store(): void
{
    if (empty($GLOBALS['ss_browser_state'])) {
        return;
    }

    ss_browser_wp_eval(<<<'PHP'
$state = get_option('ss_browser_test_state', array());

// Orders created by the admin fixtures and by the checkout test run.
$fixture_orders = wc_get_orders(array('created_via' => 'ss-browser-test', 'limit' => -1));
$checkout_orders = wc_get_orders(array('billing_email' => 'ss-browser-test@smartsend.io', 'limit' => -1));
foreach (array_merge($fixture_orders, $checkout_orders) as $order) {
    $order->delete(true);
}

if (!empty($state['created_instance']) && !empty($state['zone_id'])) {
    $zone = new WC_Shipping_Zone($state['zone_id']);
    $zone->delete_shipping_method($state['instance_id']);
}

if (!empty($state['checkout_page_id'])) {
    wp_delete_post($state['checkout_page_id'], true);
}
if (isset($state['original_checkout_page'])) {
    update_option('woocommerce_checkout_page_id', $state['original_checkout_page']);
}

if (($state['cod_was_enabled'] ?? 'no') !== 'yes') {
    $cod = get_option('woocommerce_cod_settings', array());
    $cod['enabled'] = $state['cod_was_enabled'] ?? 'no';
    update_option('woocommerce_cod_settings', $cod);
}

$product_id = wc_get_product_id_by_sku('SS-BROWSER-TEST');
if ($product_id) {
    $product = wc_get_product($product_id);
    $product->delete(true);
}

if (empty($state['original_settings'])) {
    delete_option('woocommerce_smart_send_shipping_settings');
} else {
    update_option('woocommerce_smart_send_shipping_settings', $state['original_settings']);
}

delete_option('ss_test_api_mock');
delete_option('ss_test_api_scenario');
delete_option('ss_browser_test_state');
echo json_encode(array('cleaned' => true));
PHP);

    ss_browser_remove_api_mock();

    unset($GLOBALS['ss_browser_state']);
}
