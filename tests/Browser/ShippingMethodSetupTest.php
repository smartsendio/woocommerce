<?php

/*
 * Configuring a Smart Send shipping method through the admin UI - the same
 * walk the Docs suite screenshots (zone -> add method -> title, carrier
 * method, weight row -> save), but with real assertions: the saved
 * configuration is verified in the database, the configured method shows
 * up at checkout with its title and weight-table price, and it disappears
 * again when the cart weight exceeds the configured table (the #16
 * weight-precedence rule) - proving the UI wiring reaches the same rate
 * calculation the seeded Integration tests exercise.
 *
 * Runs against the "Denmark" shipping zone (zone 1) that
 * bin/setup-local-dev.sh seeds, same as the Docs suite. The zone's real
 * methods are snapshotted and removed in beforeAll (the shared 'Edit' step
 * needs the Smart Send method to be the only one on the zone) and restored
 * in afterAll.
 *
 * The UI steps themselves live in tests/Support/ShippingMethodSteps.php,
 * shared with the Docs suite so the two cannot drift apart.
 */

function ss_method_setup_state(): array
{
    return $GLOBALS['ss_method_setup_state'];
}

beforeAll(function (): void {
    if (!ss_browser_store_manageable()) {
        return;
    }

    $GLOBALS['ss_method_setup_state'] = ss_browser_wp_eval(<<<'PHP'
// Snapshot and clear zone 1's methods: the method list must contain only
// the method this suite configures, both for the unambiguous 'Edit' click
// and so the checkout tests see exactly the UI-configured rate.
$zone = new WC_Shipping_Zone(1);
$snapshot = array();
foreach ($zone->get_shipping_methods() as $method) {
    $snapshot[] = array(
        'method_id' => $method->id,
        'settings'  => get_option('woocommerce_' . $method->id . '_' . $method->instance_id . '_settings'),
        'enabled'   => $method->enabled,
    );
    $zone->delete_shipping_method($method->instance_id);
}
update_option('ss_method_setup_zone_snapshot', $snapshot);

// Products for the checkout tests: one inside the weight table (1 kg) and
// one that pushes the cart outside it (15 kg).
$make_product = function ($sku, $name, $weight) {
    $id = wc_get_product_id_by_sku($sku);
    if ($id) {
        return $id;
    }
    $product = new WC_Product_Simple();
    $product->set_name($name);
    $product->set_sku($sku);
    $product->set_regular_price('100');
    $product->set_weight((string) $weight);
    $product->save();
    return $product->get_id();
};

echo json_encode(array(
    'zone_id'          => 1,
    'zone_name'        => $zone->get_zone_name(),
    'light_product_id' => $make_product('SS-UI-LIGHT', 'SS UI Light Product', 1),
    'heavy_product_id' => $make_product('SS-UI-HEAVY', 'SS UI Heavy Product', 15),
));
PHP);
});

afterAll(function (): void {
    if (!ss_browser_store_manageable() || empty($GLOBALS['ss_method_setup_state'])) {
        return;
    }

    ss_browser_wp_eval(<<<'PHP'
// Remove whatever this suite configured on zone 1 and restore the
// snapshotted methods (fresh instance ids, same configuration).
$zone = new WC_Shipping_Zone(1);
foreach ($zone->get_shipping_methods() as $method) {
    $zone->delete_shipping_method($method->instance_id);
}
foreach (get_option('ss_method_setup_zone_snapshot', array()) as $entry) {
    $instance_id = $zone->add_shipping_method($entry['method_id']);
    if (is_array($entry['settings'])) {
        update_option('woocommerce_' . $entry['method_id'] . '_' . $instance_id . '_settings', $entry['settings']);
    }
    if (isset($entry['enabled']) && 'yes' !== $entry['enabled']) {
        // Newly added methods are enabled by default; disable when the
        // snapshot says so.
        global $wpdb;
        $wpdb->update("{$wpdb->prefix}woocommerce_shipping_zone_methods", array('is_enabled' => 0), array('instance_id' => $instance_id));
    }
}
delete_option('ss_method_setup_zone_snapshot');

foreach (array('SS-UI-LIGHT', 'SS-UI-HEAVY') as $sku) {
    $product_id = wc_get_product_id_by_sku($sku);
    if ($product_id) {
        wc_get_product($product_id)->delete(true);
    }
}
echo json_encode(array('cleaned' => true));
PHP);

    unset($GLOBALS['ss_method_setup_state']);
});

beforeEach(function (): void {
    ss_browser_skip_unless_store_manageable($this);
});

it('configures a Smart Send method on a zone through the admin UI', function () {
    $state = ss_method_setup_state();

    $page = login_as_admin()->navigate(ss_zone_page_url($state['zone_id']));

    $page->assertSee($state['zone_name']);

    ss_step_add_smart_send_method($page);
    ss_step_open_method_settings($page);
    ss_step_fill_method_settings($page, 'SS UI Method', 'postnord_collect');
    // One weight row: 0-10 kg costs 250 (a price the order total cannot
    // accidentally contain, so the checkout assertion below is unambiguous).
    ss_step_fill_weight_row($page, 0, '0', '10', '250');
    ss_step_save_method_settings($page, $state['zone_name']);

    $page->assertSee('SS UI Method');

    // The UI-configured settings actually persisted.
    $saved = ss_browser_wp_eval(<<<'PHP'
$zone = new WC_Shipping_Zone(1);
$settings = null;
foreach ($zone->get_shipping_methods() as $method) {
    if ($method->id === 'smart_send_shipping') {
        $settings = get_option('woocommerce_smart_send_shipping_' . $method->instance_id . '_settings');
        break;
    }
}
echo json_encode(array('settings' => $settings));
PHP);

    expect($saved['settings'])->not->toBeNull()
        ->and($saved['settings']['title'])->toBe('SS UI Method')
        ->and($saved['settings']['method'])->toBe('postnord_collect')
        ->and($saved['settings']['cost_weight'][0]['ss_min_weight'])->toBe('0')
        ->and($saved['settings']['cost_weight'][0]['ss_max_weight'])->toBe('10')
        ->and($saved['settings']['cost_weight'][0]['ss_cost_weight'])->toBe('250');
});

it('shows the UI-configured method at checkout with its title and price', function () {
    $state = ss_method_setup_state();

    // A 1 kg cart falls inside the configured 0-10 kg row.
    visit(base_url('/?add-to-cart=' . $state['light_product_id']))
        ->navigate(base_url('/cart/'))
        ->assertSee('SS UI Light Product')
        ->assertSee('SS UI Method')
        ->assertSee('250');
});

it('hides the method when the cart weight exceeds the configured table', function () {
    $state = ss_method_setup_state();

    // 1 + 15 = 16 kg: no weight row matches, so the method must not be
    // offered (the #16 weight-precedence rule, through a UI-configured
    // method).
    visit(base_url('/?add-to-cart=' . $state['light_product_id']))
        ->navigate(base_url('/?add-to-cart=' . $state['heavy_product_id']))
        ->navigate(base_url('/cart/'))
        ->assertSee('SS UI Heavy Product')
        ->assertDontSee('SS UI Method');
});
