<?php

/** Docs-only shipping fixtures. Every capture owns a fresh, restorable store. */
function docs_shipping_fixture(string $scenario = 'pickup-home'): array
{
    $config = json_encode(array(
        'scenario' => $scenario,
        'pickup_title' => docs_text('Pickup point', 'Afhentningssted'),
        'home_title' => docs_text('Home delivery', 'Hjemmelevering'),
        'class_title' => docs_text('Standard parcels', 'Standardpakker'),
    ));

    $GLOBALS['ss_browser_state'] = ss_browser_wp_eval(<<<PHP
\$config = json_decode('$config', true);
\$state = get_option('ss_browser_test_state');
\$zone = new WC_Shipping_Zone(\$state['zone_id']);
foreach (\$zone->get_shipping_methods() as \$method) {
    \$zone->delete_shipping_method(\$method->instance_id);
}
\$settings = array(
    'title' => \$config['pickup_title'], 'method' => 'postnord_agent',
    'return_method' => 'postnord_returndropoff', 'auto_generate_return_label' => 'no',
    'tax_status' => 'taxable', 'requires' => 'disabled', 'flatfee_cost' => '0',
    'min_amount' => '0', 'advanced_settings_enable' => 'no',
    'cost_weight' => array(array('ss_min_weight' => '', 'ss_max_weight' => '', 'ss_cost_weight' => '31.20')),
);
if (\$config['scenario'] === 'free-threshold') {
    \$settings['requires'] = 'min_amount';
    \$settings['min_amount'] = '500';
}
if (\$config['scenario'] === 'weight-bands') {
    \$settings['cost_weight'] = array(
        array('ss_min_weight' => '0', 'ss_max_weight' => '5', 'ss_cost_weight' => '31.20'),
        array('ss_min_weight' => '5', 'ss_max_weight' => '20', 'ss_cost_weight' => '47.20'),
    );
}
if (\$config['scenario'] === 'advanced') {
    \$term = wp_insert_term(\$config['class_title'], 'product_shipping_class', array('slug' => 'ss-docs-standard-parcels'));
    if (is_wp_error(\$term)) { throw new RuntimeException(\$term->get_error_message()); }
    update_option('ss_docs_shipping_class_id', \$term['term_id']);
    \$product = wc_get_product(\$state['product_id']);
    \$product->set_shipping_class_id(\$term['term_id']);
    \$product->save();
    \$settings['advanced_settings_enable'] = 'yes';
    \$settings['display_shipping_class_opt'] = 'all_shipping_class';
    \$settings['display_shipping_class'] = array('ss-docs-standard-parcels');
    \$settings['user_roles'] = array('administrator');
}
if (\$config['scenario'] !== 'empty') {
    \$state['instance_id'] = \$zone->add_shipping_method('smart_send_shipping');
    update_option('woocommerce_smart_send_shipping_' . \$state['instance_id'] . '_settings', \$config['scenario'] === 'unconfigured' ? array() : \$settings);
}
if (in_array(\$config['scenario'], array('pickup-home', 'free-threshold', 'weight-bands', 'advanced'), true)) {
    // Home delivery starts selected, so the pickup selector shots show a real customer choice.
    \$home_id = \$zone->add_shipping_method('smart_send_shipping');
    \$home_settings = array_merge(\$settings, array('title' => \$config['home_title'], 'method' => 'postnord_homedelivery', 'requires' => 'disabled', 'advanced_settings_enable' => 'no', 'cost_weight' => array(array('ss_min_weight' => '', 'ss_max_weight' => '', 'ss_cost_weight' => '47.20'))));
    update_option('woocommerce_smart_send_shipping_' . \$home_id . '_settings', \$home_settings);
    global \$wpdb;
    \$wpdb->update(\$wpdb->prefix . 'woocommerce_shipping_zone_methods', array('method_order' => 0), array('instance_id' => \$home_id));
    \$wpdb->update(\$wpdb->prefix . 'woocommerce_shipping_zone_methods', array('method_order' => 1), array('instance_id' => \$state['instance_id']));
    \$state['home_instance_id'] = \$home_id;
}
update_option('ss_browser_test_state', \$state);
WC_Cache_Helper::get_transient_version('shipping', true);
echo json_encode(\$state);
PHP);

    return $GLOBALS['ss_browser_state'];
}

function docs_shipping_cleanup(): void
{
    if (!ss_browser_store_manageable()) {
        return;
    }
    ss_browser_wp_eval(<<<'PHP'
$term_id = get_option('ss_docs_shipping_class_id');
if ($term_id) { wp_delete_term((int) $term_id, 'product_shipping_class'); }
delete_option('ss_docs_shipping_class_id');
$snapshot = get_option('ss_docs_shipping_zone_snapshot');
if (is_array($snapshot)) {
    foreach ($snapshot['original'] as $original) {
        $zone = new WC_Shipping_Zone($original['id']);
        $zone->set_zone_name($original['name']);
        $zone->set_zone_order($original['order']);
        $zone->save();
    }
    foreach ($snapshot['created'] as $id) {
        (new WC_Shipping_Zone($id))->delete();
    }
    delete_option('ss_docs_shipping_zone_snapshot');
}
WC_Cache_Helper::get_transient_version('shipping', true);
echo json_encode(array('cleaned' => true));
PHP);
}

function docs_method_settings_url(?int $instance_id = null): string
{
    $instance_id = $instance_id ?? (int) ss_browser_state()['instance_id'];
    return base_url('/wp-admin/admin.php?page=wc-settings&tab=shipping&instance_id=' . $instance_id);
}

/** Fill actual checkout controls and wait for WooCommerce's recalculation. */
function docs_classic_checkout($page): void
{
    $state = ss_browser_state();
    $page->navigate(base_url('/?page_id=' . $state['checkout_page_id']))
        ->assertPresent('#billing_first_name')
        ->fill('#billing_first_name', 'Alex')
        ->fill('#billing_last_name', 'Example')
        ->fill('#billing_address_1', 'Eksempelvej 12')
        ->fill('#billing_city', docs_text('Copenhagen', 'København'))
        ->fill('#billing_postcode', '2300')
        ->fill('#billing_phone', '+4512345678')
        ->fill('#billing_email', 'alex@example.com');
    $page->script("void jQuery(document.body).one('updated_checkout', () => document.body.setAttribute('data-docs-checkout-ready', 'yes')); void jQuery(document.body).trigger('update_checkout');");
    $page->assertAttribute('html > body', 'data-docs-checkout-ready', 'yes');
    docs_classic_wait_for_idle($page);
}

/** Assert the amount in this method's label, never an unrelated cart total. */
function docs_assert_shipping_amount($page, int $instance_id, string $amount): void
{
    $selector = 'label[for="shipping_method_0_smart_send_shipping' . $instance_id . '"]';
    $encoded = json_encode($selector);
    $expected = json_encode($amount);
    ss_wait_for_script($page, "(function () { const label = document.querySelector($encoded); if (!label) return false; const amount = label.querySelector('.woocommerce-Price-amount'); if (!amount) return false; const value = amount.cloneNode(true); value.querySelectorAll('.woocommerce-Price-currencySymbol').forEach(symbol => symbol.remove()); return value.textContent.trim().replace(/\\s/g, '').replace(',', '.') === $expected; })()");
}

function docs_classic_select_pickup($page): void
{
    $state = ss_browser_state();
    $page->click('#shipping_method_0_smart_send_shipping' . $state['instance_id'])
        ->assertPresent('select[name="ss_shipping_store_pickup"] option[value="1234"]');
    docs_classic_wait_for_idle($page);
}

/** Selection triggers a second checkout refresh; capture its persisted result. */
function docs_classic_confirm_pickup($page): void
{
    $page->script("void jQuery(document.body).one('updated_checkout', () => document.body.setAttribute('data-docs-pickup-ready', 'yes'));");
    $page->select('ss_shipping_store_pickup', '1234')
        ->assertAttribute('html > body', 'data-docs-pickup-ready', 'yes')
        ->assertValue('select[name="ss_shipping_store_pickup"]', '1234');
    docs_classic_wait_for_idle($page);
}

/** WooCommerce may still fade its loading overlay after updated_checkout fires. */
function docs_classic_wait_for_idle($page): void
{
    ss_wait_for_script($page, "!document.querySelector('.woocommerce-checkout-review-order .blockUI') && !document.querySelector('form.checkout.processing') && jQuery.active === 0", 15);
}
