<?php

/*
 * The merchant fulfillment journey through the Orders screen bulk action
 * (single order, and the 9.0 more-than-one-order limit, #173). The order
 * screen meta box half of this journey lives in OrderFulfillmentTest.php
 * (#182); the customer checkout side in CheckoutFlowTest.php. Store
 * fixtures and the API mock are managed through the shared helpers in
 * tests/Browser/Support/SmartSendStore.php.
 */

beforeAll(function (): void {
    if (!ss_browser_store_manageable()) {
        return;
    }

    // Three orders: two for the multi-order rejection (nothing booked) and
    // one for the single-order bulk action.
    ss_browser_seed_store(['orders' => [[], [], []]]);
});

afterAll(function (): void {
    if (!ss_browser_store_manageable()) {
        return;
    }

    ss_browser_cleanup_store();
});

beforeEach(function (): void {
    ss_browser_skip_unless_store_manageable($this);
});

it('rejects the bulk label action for more than one order', function () {
    // Bulk printing of multiple orders is not available in 9.0 (#173) -
    // selecting more than one order must explain that, link to the last
    // 8.x release and book nothing.
    $state = ss_browser_state();
    $before = ss_browser_label_ids([$state['orders'][0], $state['orders'][1]]);

    $page = login_as_admin()->navigate(base_url($state['orders_list_path']));

    $checkbox_name = $state['hpos'] ? 'id[]' : 'post[]';
    $page->check($checkbox_name, (string) $state['orders'][0])
        ->check($checkbox_name, (string) $state['orders'][1])
        ->select('action', 'ss_shipping_label_bulk')
        // Explicit selector: the Apply button is an input[type=submit], which
        // text-based lookups cannot find.
        ->click('#doaction');

    // assertSeeLink() guesses a locator from its argument and trips over
    // "8.x" (parsed as a CSS class selector), so locate the link by href.
    $page->assertSee('Bulk printing of multiple orders is not available in version 9.0.0')
        ->assertSeeIn('a[href="https://wordpress.org/plugins/smart-send-logistics/advanced/"]', 'downgrade to version 8.x');

    // Nothing was booked: the selected orders' shipment ids are unchanged.
    expect(ss_browser_label_ids([$state['orders'][0], $state['orders'][1]]))->toBe($before);
});

/**
 * Outbound and return shipment ids stored on the given orders.
 */
function ss_browser_label_ids(array $order_ids): array
{
    $ids = implode(',', array_map('intval', $order_ids));

    // ss_browser_wp_eval() only picks up a JSON object line.
    return ss_browser_wp_eval(<<<PHP
\$label_ids = array();
foreach (array({$ids}) as \$id) {
    \$order = wc_get_order(\$id);
    \$label_ids[] = array(
        'label'  => \$order ? \$order->get_meta('_ss_shipping_label_id', true) : null,
        'return' => \$order ? \$order->get_meta('_ss_shipping_return_label_id', true) : null,
    );
}
echo json_encode(array('orders' => \$label_ids));
PHP)['orders'];
}

it('generates a label for a single order through the bulk action', function () {
    $state = ss_browser_state();

    $page = login_as_admin()->navigate(base_url($state['orders_list_path']));

    $checkbox_name = $state['hpos'] ? 'id[]' : 'post[]';
    $page->check($checkbox_name, (string) $state['orders'][2])
        ->select('action', 'ss_shipping_label_bulk')
        // Explicit selector: the Apply button is an input[type=submit], which
        // text-based lookups cannot find.
        ->click('#doaction');

    $page->assertSee('Shipping label created by Smart Send');
});
