<?php

/*
 * End-to-end characterization of the three big Smart Send flows against a
 * running store:
 *
 *  - classic checkout with an agent method: pickup point selector shown,
 *    a point chosen, order placed, agent shown on the thank-you page;
 *  - generating a label from the admin order screen;
 *  - the bulk "Generate Labels" action on two orders.
 *
 * The Smart Send API is mocked with a temporary mu-plugin (installed into
 * the development store for the duration of this file and removed again),
 * gated on the ss_test_api_mock option, so no real API calls are made. The
 * store fixtures (shipping method instance, classic checkout page, orders)
 * are created through WP-CLI and torn down in afterAll.
 *
 * These tests need filesystem + WP-CLI access to the store installation
 * (WP_DEV_PATH, default ./local-dev/wordpress) in addition to WP_BASE_URL;
 * they are skipped when the installation is not reachable, e.g. when the
 * suite targets a remote store.
 */

function ss_browser_wp_path(): string
{
    return rtrim(getenv('WP_DEV_PATH') ?: __DIR__ . '/../../local-dev/wordpress', '/');
}

function ss_browser_store_manageable(): bool
{
    return file_exists(ss_browser_wp_path() . '/wp-load.php')
        && file_exists(ss_browser_wp_path() . '/.wp-cli/wp-cli.phar');
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
 * tests/Browser/SmartSendFlowsTest.php and removed again when the file's
 * tests finish. Only active while the ss_test_api_mock option is 'yes'.
 */
add_filter('pre_http_request', function ($pre, $args, $url) {
    if (get_option('ss_test_api_mock') !== 'yes' || strpos($url, 'smartsend.io') === false) {
        return $pre;
    }

    $respond = function ($body) {
        return array(
            'response' => array('code' => 200, 'message' => 'OK'),
            'headers'  => array('content-type' => 'application/json'),
            'body'     => json_encode($body),
            'cookies'  => array(),
            'filename' => null,
        );
    };

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

    return $respond(array('data' => array('id' => 1, 'email' => 'mock@smartsend.test', 'website' => 'localhost')));
}, 5, 3);
PHP);
}

/**
 * The fixture state created by beforeAll (ids of the zone method, checkout
 * page, product and admin-test orders).
 */
function ss_browser_state(): array
{
    return $GLOBALS['ss_browser_state'];
}

beforeAll(function (): void {
    if (!ss_browser_store_manageable()) {
        return;
    }

    ss_browser_install_api_mock();

    $GLOBALS['ss_browser_state'] = ss_browser_wp_eval(<<<'PHP'
$original_settings = get_option('woocommerce_smart_send_shipping_settings');
update_option('woocommerce_smart_send_shipping_settings', array(
    'demo' => 'yes', 'ss_debug' => 'no', 'include_order_comment' => 'no',
    'save_shipping_labels_in_uploads' => 'no', 'dropdown_display_format' => '4',
    'default_select_agent' => 'no', 'order_status' => '0',
));
update_option('ss_test_api_mock', 'yes');

// Cash on delivery so the checkout can be completed.
$cod = get_option('woocommerce_cod_settings', array());
$cod_was_enabled = isset($cod['enabled']) ? $cod['enabled'] : 'no';
$cod['enabled'] = 'yes';
update_option('woocommerce_cod_settings', $cod);

// Denmark zone with a Smart Send agent method instance.
$zone_id = 0;
foreach (WC_Shipping_Zones::get_zones() as $zone_data) {
    foreach ($zone_data['zone_locations'] as $location) {
        if ($location->code === 'DK') {
            $zone_id = $zone_data['id'];
            break 2;
        }
    }
}
if (!$zone_id) {
    $zone = new WC_Shipping_Zone();
    $zone->set_zone_name('Denmark');
    $zone->set_zone_locations(array((object) array('code' => 'DK', 'type' => 'country')));
    $zone->save();
    $zone_id = $zone->get_id();
}
$zone = new WC_Shipping_Zone($zone_id);
$instance_id = 0;
foreach ($zone->get_shipping_methods() as $iid => $method) {
    if ($method->id === 'smart_send_shipping') {
        $instance_id = $iid;
        break;
    }
}
$created_instance = 0;
if (!$instance_id) {
    $instance_id = $zone->add_shipping_method('smart_send_shipping');
    $created_instance = 1;
}
update_option('woocommerce_smart_send_shipping_' . $instance_id . '_settings', array(
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
$page_id = wp_insert_post(array(
    'post_title'   => 'SS Classic Checkout',
    'post_name'    => 'ss-classic-checkout',
    'post_content' => '[woocommerce_checkout]',
    'post_status'  => 'publish',
    'post_type'    => 'page',
));
$original_checkout_page = get_option('woocommerce_checkout_page_id');
update_option('woocommerce_checkout_page_id', $page_id);

// A product to buy.
$product_id = wc_get_product_id_by_sku('SS-BROWSER-TEST');
if (!$product_id) {
    $product = new WC_Product_Simple();
    $product->set_name('SS Browser Test Product');
    $product->set_sku('SS-BROWSER-TEST');
    $product->set_regular_price('100');
    $product->set_weight('1');
    $product->save();
    $product_id = $product->get_id();
}

// Orders for the admin label tests, built like checkout would build them.
$make_order = function () use ($product_id) {
    $order = wc_create_order(array('status' => 'processing', 'created_via' => 'ss-browser-test'));
    $order->add_product(wc_get_product($product_id), 1);
    $address = array(
        'first_name' => 'Browser', 'last_name' => 'Test', 'address_1' => 'Islands Brygge 39',
        'city' => 'Copenhagen', 'postcode' => '2300', 'country' => 'DK',
    );
    $order->set_address(array_merge($address, array('email' => 'ss-browser-test@smartsend.io', 'phone' => '+4512345678')), 'billing');
    $order->set_address($address, 'shipping');
    $item = new WC_Order_Item_Shipping();
    $item->set_method_title('Smart Send Pickup Point');
    $item->set_method_id('smart_send_shipping');
    $item->set_instance_id(1);
    $item->set_total('29');
    $item->add_meta_data('smart_send_shipping_method', 'postnord_agent', true);
    $item->add_meta_data('smart_send_return_method', 'postnord_returndropoff', true);
    $item->add_meta_data('smart_send_auto_generate_return_label', 'no', true);
    $order->add_item($item);
    $order->calculate_totals();
    $order->update_meta_data('ss_shipping_order_agent_no', '1234');
    $order->update_meta_data('_ss_shipping_order_agent', (object) array(
        'id' => 1, 'agent_no' => '1234', 'company' => 'Browser Test Shop', 'address_line1' => 'Main Street 1',
        'address_line2' => null, 'postal_code' => '2300', 'city' => 'Copenhagen', 'country' => 'DK',
    ));
    $order->save();
    return $order;
};
$order_a = $make_order();
$order_b = $make_order();

$hpos = Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

$state = array(
    'original_settings'      => $original_settings === false ? null : $original_settings,
    'zone_id'                => $zone_id,
    'instance_id'            => $instance_id,
    'created_instance'       => $created_instance,
    'checkout_page_id'       => $page_id,
    'original_checkout_page' => $original_checkout_page,
    'cod_was_enabled'        => $cod_was_enabled,
    'product_id'             => $product_id,
    'order_a'                => $order_a->get_id(),
    'order_b'                => $order_b->get_id(),
    'order_a_edit_path'      => $hpos
        ? '/wp-admin/admin.php?page=wc-orders&action=edit&id=' . $order_a->get_id()
        : '/wp-admin/post.php?post=' . $order_a->get_id() . '&action=edit',
    'orders_list_path'       => $hpos ? '/wp-admin/admin.php?page=wc-orders' : '/wp-admin/edit.php?post_type=shop_order',
    'hpos'                   => $hpos ? 1 : 0,
);
update_option('ss_browser_test_state', $state);
echo json_encode($state);
PHP);
});

afterAll(function (): void {
    if (!ss_browser_store_manageable() || empty($GLOBALS['ss_browser_state'])) {
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
delete_option('ss_browser_test_state');
echo json_encode(array('cleaned' => true));
PHP);

    if (file_exists(ss_browser_mu_plugin_path())) {
        unlink(ss_browser_mu_plugin_path());
    }
});

beforeEach(function (): void {
    if (!ss_browser_store_manageable()) {
        $this->markTestSkipped('The WordPress installation is not reachable from the test process (WP_DEV_PATH).');
    }
});

it('shows the pickup point selector on classic checkout and stores the chosen agent on the order', function () {
    $state = ss_browser_state();

    $page = visit(base_url('/?add-to-cart=' . $state['product_id']));

    $page->navigate(base_url('/?page_id=' . $state['checkout_page_id']))
        ->assertSee('Billing details')
        ->fill('#billing_first_name', 'Browser')
        ->fill('#billing_last_name', 'Test')
        ->fill('#billing_address_1', 'Islands Brygge 39')
        ->fill('#billing_city', 'Copenhagen')
        ->fill('#billing_postcode', '2300')
        ->fill('#billing_phone', '+4512345678')
        ->fill('#billing_email', 'ss-browser-test@smartsend.io');

    // Choose the Smart Send agent method; the checkout refreshes and the
    // plugin renders the pickup point dropdown (fed by the mocked API).
    // WooCommerce derives the radio id from the rate id
    // ('smart_send_shipping:N' -> 'smart_send_shippingN').
    $page->click('#shipping_method_0_smart_send_shipping' . $state['instance_id'])
        ->assertPresent('select[name=ss_shipping_store_pickup]')
        // The mocked agents are options of the (closed) dropdown, so assert
        // against the markup rather than visible text.
        ->assertSourceHas('Browser Test Shop');

    // Cash on delivery is the only enabled gateway, so it is preselected
    // (and its radio hidden). Pick the agent last so no further checkout
    // refresh re-renders the dropdown before submitting.
    $page->assertSee('Cash on delivery')
        ->select('ss_shipping_store_pickup', '1234')
        // Explicit selector: text-based lookups do not match submit buttons
        // by their value and hang instead of failing.
        ->click('#place_order');

    // Thank-you page: the frontend hook renders the stored pickup point,
    // which proves the agent meta ended up on the order.
    $page->assertSee('order has been received')
        ->assertSee('Pickup Point')
        ->assertSee('Browser Test Shop')
        ->assertSee('Main Street 1');
});

it('generates a shipping label from the admin order screen', function () {
    $state = ss_browser_state();

    login_as_admin()
        ->navigate(base_url($state['order_a_edit_path']))
        ->assertSee('Smart Send Shipping')
        ->assertSee('Pickup Point')
        ->assertSee('Browser Test Shop')
        ->click('#ss-shipping-label-button')
        ->assertSeeIn('#ss-label-created', 'Download shipping label');
});

it('rejects the bulk label action for more than one order', function () {
    // Multi-order bulk processing (and the combined PDF) is temporarily
    // removed pending the Phase 7 async bulk rebuild - selecting more
    // than one order must error without booking anything.
    $state = ss_browser_state();

    $page = login_as_admin()->navigate(base_url($state['orders_list_path']));

    $checkbox_name = $state['hpos'] ? 'id[]' : 'post[]';
    $page->check($checkbox_name, (string) $state['order_a'])
        ->check($checkbox_name, (string) $state['order_b'])
        ->select('action', 'ss_shipping_label_bulk')
        // Explicit selector: the Apply button is an input[type=submit], which
        // text-based lookups cannot find.
        ->click('#doaction');

    $page->assertSee('only a single order can be processed at a time');
});

it('generates a label for a single order through the bulk action', function () {
    $state = ss_browser_state();

    $page = login_as_admin()->navigate(base_url($state['orders_list_path']));

    $checkbox_name = $state['hpos'] ? 'id[]' : 'post[]';
    $page->check($checkbox_name, (string) $state['order_b'])
        ->select('action', 'ss_shipping_label_bulk')
        // Explicit selector: the Apply button is an input[type=submit], which
        // text-based lookups cannot find.
        ->click('#doaction');

    $page->assertSee('Shipping label created by Smart Send');
});
