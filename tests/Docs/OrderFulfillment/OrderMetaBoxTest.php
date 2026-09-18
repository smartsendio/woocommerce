<?php

/*
 * Documentation captures for outbound labels, returns, history and advanced
 * Colli shipments. Every scenario owns a fresh store fixture: a filtered test
 * can generate its image without another test having booked the order first.
 *
 * Keep the literal visit() in each test. Pest's browser discovery needs it
 * for tests outside tests/Browser, even though the login steps are identical.
 * UI labels come from WordPress/WooCommerce/plugin translations; selectors
 * target stable attributes rather than translating the interface in tests.
 */

beforeEach(function (): void {
    ss_browser_skip_unless_store_manageable($this);

    docs_seed_store(['orders' => [
        [],                       // 0: one-parcel outbound or return booking.
        ['quantity' => 3],         // 1: advanced Colli shipments.
        ['auto_return' => true],   // 2: combined outbound and return booking.
        ['flat_rate' => true],     // 3: choose a Smart Send service on the order.
    ]]);
});

afterEach(function (): void {
    if (ss_browser_store_manageable()) {
        docs_cleanup_store();
    }
});

/** The HPOS-aware edit URL of an independently seeded order. */
function docs_order_url(int $index = 0): string
{
    return base_url(ss_browser_order_edit_path(ss_browser_state()['orders'][$index]));
}

it('documents an order ready for an outbound label', function () {
    $page = visit(base_url('/wp-login.php'))
        ->fill('#user_login', admin_username())
        ->fill('#user_pass', admin_password())
        ->click('#wp-submit')
        ->assertPathContains('wp-admin')
        ->navigate(docs_order_url());

    $page->assertSeeIn('#woocommerce-ss-shipping-label .hndle', 'Smart Send')
        ->assertSeeIn('[data-ss-value="pickup_point.agent_no"]', '#1234')
        ->assertEnabled('[data-ss-action="create-label"]')
        ->assertEnabled('[data-ss-action="create-return-label"]')
        ->assertNotChecked('[data-ss-field="with_return"]')
        ->assertNotPresent('[data-ss-section="parcel_editor"]');

    highlight_element($page, '#smart-send-fulfillment', outlineOffset: -2, context: '#woocommerce-ss-shipping-label');
    capture_doc_screenshot($page, 'outbound', 'ready');
});

it('documents changing the outbound service', function () {
    $page = visit(base_url('/wp-login.php'))
        ->fill('#user_login', admin_username())
        ->fill('#user_pass', admin_password())
        ->click('#wp-submit')
        ->assertPathContains('wp-admin')
        ->navigate(docs_order_url());

    $page->click('[data-ss-action="edit-method"]')
        ->assertPresent('[data-ss-field="shipping_method"]')
        ->assertPresent('[data-ss-field="shipping_method"] option[value="postnord_agent"]');

    highlight_element($page, '[data-ss-section="shipping_method"]', context: '#woocommerce-ss-shipping-label');
    capture_doc_screenshot($page, 'outbound', 'method-change');
});

it('documents editing the pickup point number', function () {
    $page = visit(base_url('/wp-login.php'))
        ->fill('#user_login', admin_username())
        ->fill('#user_pass', admin_password())
        ->click('#wp-submit')
        ->assertPathContains('wp-admin')
        ->navigate(docs_order_url());

    $page->click('[data-ss-action="edit-pickup-point"]')
        ->assertPresent('[data-ss-field="pickup_point.agent_no"]')
        ->assertEnabled('[data-ss-action="lookup-pickup-point"]');

    highlight_element($page, '[data-ss-section="pickup_point"]', context: '#woocommerce-ss-shipping-label');
    capture_doc_screenshot($page, 'outbound', 'pickup-edit');
});

it('documents the pickup point returned by a lookup', function () {
    $page = visit(base_url('/wp-login.php'))
        ->fill('#user_login', admin_username())
        ->fill('#user_pass', admin_password())
        ->click('#wp-submit')
        ->assertPathContains('wp-admin')
        ->navigate(docs_order_url());

    $page->click('[data-ss-action="edit-pickup-point"]')
        ->fill('[data-ss-field="pickup_point.agent_no"]', '5678')
        ->click('[data-ss-action="lookup-pickup-point"]')
        ->assertSeeIn('[data-ss-value="pickup_point.agent_no"]', '#5678')
        ->assertNotPresent('[data-ss-field="pickup_point.agent_no"]');

    highlight_element($page, '[data-ss-section="pickup_point"]', context: '#woocommerce-ss-shipping-label');
    capture_doc_screenshot($page, 'outbound', 'pickup-changed');
});

it('documents a booked outbound shipment and its PDF link', function () {
    $page = visit(base_url('/wp-login.php'))
        ->fill('#user_login', admin_username())
        ->fill('#user_pass', admin_password())
        ->click('#wp-submit')
        ->assertPathContains('wp-admin')
        ->navigate(docs_order_url());

    $page->click('[data-ss-action="create-label"]')
        ->assertPresent('[data-ss-result="outbound"]')
        ->assertSeeIn('[data-ss-result="outbound"]', 'BROWSERTRACK1')
        ->assertPresent('[data-ss-result="outbound"] [data-ss-document="label"]')
        ->assertSeeIn('[data-ss-result="outbound"] [data-ss-document="label"]', 'PDF')
        ->assertNotPresent('[data-ss-result="return"]');

    highlight_element($page, '[data-ss-result="outbound"]', outlineOffset: -2, context: '#woocommerce-ss-shipping-label');
    capture_doc_screenshot($page, 'outbound', 'booked-documents');
});

it('documents the configured return service and automatic return default', function () {
    $instance_id = (int) ss_browser_state()['instance_id'];
    ss_browser_wp_eval(<<<PHP
\$key = 'woocommerce_smart_send_shipping_{$instance_id}_settings';
\$settings = get_option(\$key, array());
\$settings['return_method'] = 'postnord_returndropoff';
\$settings['auto_generate_return_label'] = 'yes';
update_option(\$key, \$settings);
echo json_encode(array('configured' => true));
PHP);

    $page = visit(base_url('/wp-login.php'))
        ->fill('#user_login', admin_username())
        ->fill('#user_pass', admin_password())
        ->click('#wp-submit')
        ->assertPathContains('wp-admin')
        ->navigate(base_url('/wp-admin/admin.php?page=wc-settings&tab=shipping&instance_id=' . $instance_id));

    $page->assertSelected('#woocommerce_smart_send_shipping_return_method', 'postnord_returndropoff')
        ->assertChecked('#woocommerce_smart_send_shipping_auto_generate_return_label');

    highlight_element($page, 'table:has(#woocommerce_smart_send_shipping_return_method)');
    capture_doc_screenshot($page, 'returns', 'method-settings');
});

it('documents the separate return-only booking action', function () {
    $page = visit(base_url('/wp-login.php'))
        ->fill('#user_login', admin_username())
        ->fill('#user_pass', admin_password())
        ->click('#wp-submit')
        ->assertPathContains('wp-admin')
        ->navigate(docs_order_url());

    $page->assertPresent('[data-ss-value="return_method"]')
        ->assertNotChecked('[data-ss-field="with_return"]')
        ->assertEnabled('[data-ss-action="create-return-label"]')
        ->assertNotPresent('[data-ss-section="timeline"]');

    // Keep the configured return service visible; a border identifies the
    // separate return action without obscuring the rest of the order panel.
    highlight_element($page, '[data-ss-action="create-return-label"]', overlay: false, outlineOffset: -2, context: '#woocommerce-ss-shipping-label');
    capture_doc_screenshot($page, 'returns', 'return-only-ready');
});

it('documents a booked return without an outbound booking', function () {
    $page = visit(base_url('/wp-login.php'))
        ->fill('#user_login', admin_username())
        ->fill('#user_pass', admin_password())
        ->click('#wp-submit')
        ->assertPathContains('wp-admin')
        ->navigate(docs_order_url());

    $page->click('[data-ss-action="create-return-label"]')
        ->assertPresent('[data-ss-result="return"]')
        ->assertPresent('[data-ss-result="return"] [data-ss-document="label"]')
        ->assertSeeIn('[data-ss-result="return"] [data-ss-document="label"]', 'PDF')
        ->assertPresent('[data-ss-timeline="return"]')
        ->assertNotPresent('[data-ss-result="outbound"]')
        ->assertNotPresent('[data-ss-timeline="outbound"]');

    highlight_element($page, '[data-ss-result="return"]', outlineOffset: -2, context: '#woocommerce-ss-shipping-label');
    capture_doc_screenshot($page, 'returns', 'return-only-booked');
});

it('documents outbound and return booked together', function () {
    $page = visit(base_url('/wp-login.php'))
        ->fill('#user_login', admin_username())
        ->fill('#user_pass', admin_password())
        ->click('#wp-submit')
        ->assertPathContains('wp-admin')
        ->navigate(docs_order_url(2));

    $page->assertChecked('[data-ss-field="with_return"]')
        ->click('[data-ss-action="create-label"]')
        ->assertPresent('[data-ss-result="outbound"] [data-ss-document="label"]')
        ->assertPresent('[data-ss-result="return"] [data-ss-document="label"]')
        ->assertPresent('[data-ss-timeline="outbound"]')
        ->assertPresent('[data-ss-timeline="return"]');

    highlight_element($page, '[data-ss-section="actions"]', outlineOffset: -2, context: '#woocommerce-ss-shipping-label');
    capture_doc_screenshot($page, 'returns', 'outbound-and-return');
});

it('documents outbound success when the return booking fails', function () {
    ss_browser_set_api_scenarios(['booking' => '500-return']);

    $page = visit(base_url('/wp-login.php'))
        ->fill('#user_login', admin_username())
        ->fill('#user_pass', admin_password())
        ->click('#wp-submit')
        ->assertPathContains('wp-admin')
        ->navigate(docs_order_url(2));

    $page->click('[data-ss-action="create-label"]')
        ->assertPresent('[data-ss-notice="return_failed"]')
        ->assertPresent('[data-ss-result="outbound"] [data-ss-document="label"]')
        ->assertPresent('[data-ss-timeline="outbound"]')
        ->assertNotPresent('[data-ss-result="return"]')
        ->assertNotPresent('[data-ss-timeline="return"]');

    highlight_element($page, '#smart-send-fulfillment', outlineOffset: -2, context: '#woocommerce-ss-shipping-label');
    capture_doc_screenshot($page, 'returns', 'partial-success');
});

it('documents the persistent shipment timeline after reloading', function () {
    $page = visit(base_url('/wp-login.php'))
        ->fill('#user_login', admin_username())
        ->fill('#user_pass', admin_password())
        ->click('#wp-submit')
        ->assertPathContains('wp-admin')
        ->navigate(docs_order_url());

    // This scenario creates its own history, including when run in isolation.
    $page->click('[data-ss-action="create-label"]')
        ->assertPresent('[data-ss-result="outbound"]')
        ->refresh()
        ->assertNotPresent('[data-ss-result="outbound"]')
        ->assertPresent('[data-ss-timeline="outbound"]');

    highlight_element($page, '[data-ss-section="timeline"]', outlineOffset: -2, context: '#woocommerce-ss-shipping-label');
    capture_doc_screenshot($page, 'history', 'booked-timeline');
});

it('documents the native WooCommerce order note after reloading', function () {
    $page = visit(base_url('/wp-login.php'))
        ->fill('#user_login', admin_username())
        ->fill('#user_pass', admin_password())
        ->click('#wp-submit')
        ->assertPathContains('wp-admin')
        ->navigate(docs_order_url());

    $page->click('[data-ss-action="create-label"]')
        ->assertPresent('[data-ss-result="outbound"]')
        ->refresh()
        ->assertSeeIn('ul.order_notes li:first-child', 'BROWSERTRACK1')
        ->assertPresent('ul.order_notes a[href*="labels/label.pdf"]');

    highlight_element($page, '#woocommerce-order-notes ul.order_notes li:first-child', context: '#woocommerce-order-notes');
    capture_doc_screenshot($page, 'history', 'order-note-after-reload');
});

it('documents the collapsed Colli shipment summary', function () {
    $page = visit(base_url('/wp-login.php'))
        ->fill('#user_login', admin_username())
        ->fill('#user_pass', admin_password())
        ->click('#wp-submit')
        ->assertPathContains('wp-admin')
        ->navigate(docs_order_url(1));

    $page->assertSeeIn('[data-ss-value="parcel_plan.summary"]', '3.00 kg')
        ->assertPresent('[data-ss-action="edit-parcels"]')
        ->assertNotPresent('[data-ss-section="parcel_editor"]');

    highlight_element($page, '[data-ss-section="parcel_plan"]', outlineOffset: -2, context: '#woocommerce-ss-shipping-label');
    capture_doc_screenshot($page, 'colli', 'summary');
});

it('documents three units allocated across two colli', function () {
    $page = visit(base_url('/wp-login.php'))
        ->fill('#user_login', admin_username())
        ->fill('#user_pass', admin_password())
        ->click('#wp-submit')
        ->assertPathContains('wp-admin')
        ->navigate(docs_order_url(1));

    $page->click('[data-ss-action="edit-parcels"]')
        ->click('[data-ss-box="1"] [data-ss-action="move-down"]')
        ->assertCount('[data-ss-box]', 2)
        ->assertSeeIn('[data-ss-box="1"] [data-ss-value="line.count"]', '× 2')
        ->assertSeeIn('[data-ss-box="2"] [data-ss-value="line.count"]', '× 1');

    highlight_element($page, '[data-ss-section="parcel_editor"]', context: '#woocommerce-ss-shipping-label');
    capture_doc_screenshot($page, 'colli', 'item-allocation');
});

it('documents explicit Colli weights and dimensions including packaging', function () {
    $page = visit(base_url('/wp-login.php'))
        ->fill('#user_login', admin_username())
        ->fill('#user_pass', admin_password())
        ->click('#wp-submit')
        ->assertPathContains('wp-admin')
        ->navigate(docs_order_url(1));

    $page->click('[data-ss-action="edit-parcels"]')
        ->click('[data-ss-box="1"] [data-ss-action="move-down"]')
        ->fill('[data-ss-field="parcel_plan.specs[0].weight"]', '2.3')
        ->fill('[data-ss-field="parcel_plan.specs[0].length"]', '40')
        ->fill('[data-ss-field="parcel_plan.specs[0].width"]', '30')
        ->fill('[data-ss-field="parcel_plan.specs[0].height"]', '20')
        ->fill('[data-ss-field="parcel_plan.specs[1].weight"]', '1.2')
        ->fill('[data-ss-field="parcel_plan.specs[1].length"]', '30')
        ->fill('[data-ss-field="parcel_plan.specs[1].width"]', '20')
        ->fill('[data-ss-field="parcel_plan.specs[1].height"]', '10')
        ->assertCount('[data-ss-box]', 2)
        ->assertSeeIn('[data-ss-value="parcel_plan.summary"]', '3.50 kg');

    highlight_element($page, '[data-ss-section="parcel_editor"]', context: '#woocommerce-ss-shipping-label');
    capture_doc_screenshot($page, 'colli', 'weights-dimensions');
});

it('documents the actionable error for an incomplete Colli allocation', function () {
    $order_id = (int) ss_browser_state()['orders'][1];
    // A valid v9 allocation structure with only two of the order's three
    // units assigned. This represents an order edited after its allocation
    // was saved; the merchant sees the real reset instruction, not a fake UI.
    ss_browser_wp_eval(<<<PHP
\$order = wc_get_order({$order_id});
\$line = array_values(\$order->get_items())[0];
\$order->update_meta_data('ss_shipping_order_parcels', array('specs' => array(array(
    'reference' => '1',
    'items' => array(array('order_item_id' => \$line->get_id(), 'quantity' => 2, 'name' => \$line->get_name())),
    'weight' => null, 'length' => null, 'width' => null, 'height' => null,
))));
\$order->save();
echo json_encode(array('configured' => true));
PHP);

    $page = visit(base_url('/wp-login.php'))
        ->fill('#user_login', admin_username())
        ->fill('#user_pass', admin_password())
        ->click('#wp-submit')
        ->assertPathContains('wp-admin')
        ->navigate(docs_order_url(1));

    $page->assertPresent('[data-ss-notice="parcel_plan_invalid"]')
        ->assertPresent('[data-ss-action="reset-parcels"]')
        ->assertNotPresent('[data-ss-action="edit-parcels"]');

    highlight_element($page, '[data-ss-section="parcel_plan"]', outlineOffset: -2, context: '#woocommerce-ss-shipping-label');
    capture_doc_screenshot($page, 'colli', 'allocation-error');
});

it('documents a booking validation error next to its pickup point field', function () {
    ss_browser_set_api_scenarios(['booking' => '422-agent-no']);

    $page = visit(base_url('/wp-login.php'))
        ->fill('#user_login', admin_username())
        ->fill('#user_pass', admin_password())
        ->click('#wp-submit')
        ->assertPathContains('wp-admin')
        ->navigate(docs_order_url());

    $page->click('[data-ss-action="create-label"]')
        ->assertPresent('[data-ss-error="pickup_point.agent_no"]')
        ->assertNotPresent('[data-ss-result="outbound"]');

    highlight_element($page, '[data-ss-section="pickup_point"]', context: '#woocommerce-ss-shipping-label');
    capture_doc_screenshot($page, 'help', 'booking-field-error');
});

it('documents selecting a Smart Send service for a standard WooCommerce order', function () {
    $page = visit(base_url('/wp-login.php'))
        ->fill('#user_login', admin_username())
        ->fill('#user_pass', admin_password())
        ->click('#wp-submit')
        ->assertPathContains('wp-admin')
        ->navigate(docs_order_url(3));

    $page->assertPresent('[data-ss-notice="no_method"]')
        ->click('[data-ss-action="edit-method"]')
        ->assertPresent('[data-ss-field="shipping_method"]');

    highlight_element($page, '[data-ss-section="details"]', outlineOffset: -2, context: '#woocommerce-ss-shipping-label');
    capture_doc_screenshot($page, 'help', 'no-smart-send-method');
});

it('documents the connection notice and its settings link', function () {
    ss_browser_update_plugin_setting('api_token', '');

    $page = visit(base_url('/wp-login.php'))
        ->fill('#user_login', admin_username())
        ->fill('#user_pass', admin_password())
        ->click('#wp-submit')
        ->assertPathContains('wp-admin')
        ->navigate(docs_order_url());

    $page->assertPresent('[data-ss-notice="not_connected"]')
        ->assertPresent('[data-ss-action="open-settings"]')
        ->assertDisabled('[data-ss-action="create-label"]');

    highlight_element($page, '[data-ss-notice="not_connected"]', outlineOffset: -2, context: '#woocommerce-ss-shipping-label');
    capture_doc_screenshot($page, 'help', 'not-connected');
});
