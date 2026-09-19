<?php

require_once dirname(__DIR__) . '/ShippingMethod/ShippingFixtures.php';

beforeEach(function (): void {
    ss_browser_skip_unless_store_manageable($this);
    docs_shipping_cleanup();
    docs_seed_store();
});

afterEach(function (): void {
    docs_shipping_cleanup();
    docs_cleanup_store();
});

function docs_overlapping_zones(): array
{
    $denmark = json_encode(docs_text('Denmark', 'Danmark'));
    $nordics = json_encode(docs_text('Nordic countries', 'Nordiske lande'));
    return ss_browser_wp_eval(<<<PHP
\$state = get_option('ss_browser_test_state');
\$snapshot = array('original' => array(), 'created' => array());
foreach (WC_Shipping_Zones::get_zones() as \$data) {
    \$zone = new WC_Shipping_Zone(\$data['id']);
    \$snapshot['original'][] = array('id' => \$zone->get_id(), 'name' => \$zone->get_zone_name(), 'order' => \$zone->get_zone_order());
}
update_option('ss_docs_shipping_zone_snapshot', \$snapshot);
foreach (\$snapshot['original'] as \$index => \$original) {
    \$zone = new WC_Shipping_Zone(\$original['id']);
    \$zone->set_zone_order(\$index + 2);
    \$zone->save();
}
\$denmark = new WC_Shipping_Zone(\$state['zone_id']);
\$denmark->set_zone_name($denmark);
\$denmark->set_zone_order(0);
\$denmark->save();
\$nordics = new WC_Shipping_Zone();
\$nordics->set_zone_name($nordics);
\$nordics->set_zone_order(1);
foreach (array('DK', 'SE', 'NO', 'FI') as \$country) {
    \$nordics->add_location(\$country, 'country');
}
\$nordics->save();
\$snapshot['created'][] = \$nordics->get_id();
update_option('ss_docs_shipping_zone_snapshot', \$snapshot);
\$instance = \$nordics->add_shipping_method('smart_send_shipping');
update_option('woocommerce_smart_send_shipping_' . \$instance . '_settings', array('title' => $nordics, 'method' => 'postnord_homedelivery'));
WC_Cache_Helper::get_transient_version('shipping', true);
echo json_encode(array('denmark' => \$denmark->get_id(), 'nordics' => \$nordics->get_id()));
PHP);
}

it('documents creating a WooCommerce shipping zone', function () {
    $page = visit(base_url('/wp-login.php'))
        ->fill('#user_login', admin_username())->fill('#user_pass', admin_password())->click('#wp-submit')
        ->navigate(base_url('/wp-admin/admin.php?page=wc-settings&tab=shipping&zone_id=new'))
        ->assertPresent('#zone_name')
        ->fill('#zone_name', docs_text('Denmark', 'Danmark'));
    highlight_element($page, '.wc-shipping-zone-settings');
    capture_doc_screenshot($page, 'zones', 'create-zone');
});

it('documents the Nordic shipping zone country selection', function () {
    $zones = docs_overlapping_zones();
    $page = visit(base_url('/wp-login.php'))
        ->fill('#user_login', admin_username())->fill('#user_pass', admin_password())->click('#wp-submit')
        ->navigate(ss_zone_page_url($zones['nordics']))
        ->assertValue('#zone_name', docs_text('Nordic countries', 'Nordiske lande'))
        ->assertPresent('#wc-shipping-zone-region-picker-root')
        ->assertSee(docs_text('Denmark', 'Danmark'))
        ->assertSee(docs_text('Sweden', 'Sverige'))
        ->assertSee(docs_text('Norway', 'Norge'))
        ->assertSee('Finland');
    highlight_element($page, '#wc-shipping-zone-region-picker-root');
    capture_doc_screenshot($page, 'zones', 'country-selection');
});

it('documents Denmark before overlapping Nordic countries', function () {
    $zones = docs_overlapping_zones();
    $page = visit(base_url('/wp-login.php'))
        ->fill('#user_login', admin_username())->fill('#user_pass', admin_password())->click('#wp-submit')
        ->navigate(base_url('/wp-admin/admin.php?page=wc-settings&tab=shipping'))
        ->assertSee(docs_text('Denmark', 'Danmark'))
        ->assertSee(docs_text('Nordic countries', 'Nordiske lande'));
    // This verifies the displayed order, not merely the presence of both labels.
    $denmark = json_encode(docs_text('Denmark', 'Danmark'));
    $nordics = json_encode(docs_text('Nordic countries', 'Nordiske lande'));
    ss_wait_for_script($page, "(function () { const rows = Array.from(document.querySelectorAll('.wc-shipping-zones tbody tr')).map(row => row.textContent); const dk = rows.findIndex(text => text.includes($denmark)); const nordic = rows.findIndex(text => text.includes($nordics)); return dk >= 0 && nordic > dk; })()");
    highlight_element($page, '.wc-shipping-zones');
    capture_doc_screenshot($page, 'zones', 'denmark-before-nordics');
});
