<?php

/*
 * The merchant fulfillment journey on the order screen: the "Smart Send
 * Shipping" meta box rewritten on the fulfillment contract (#182) - a React
 * app talking to the smart-send/v1 REST routes, no page reload. One test
 * per UX state of the issue's section 1.2: create the outbound label, the
 * return checkbox defaulting from the method setting (books both), a return
 * label on its own, a validation failure shown on the field it belongs to
 * (and the general notice for one outside the box), the parcel editor
 * (units moved between boxes, an empty box with an explicit weight - the
 * booking request carries three parcels), a pickup point override through
 * the lookup route, a method override to a non-agent method (no pickup
 * point), booking again behind a confirm, and an order placed without a
 * Smart Send method booked with a chosen method (+ a chosen return method).
 *
 * The bulk-action journey stays in LabelGenerationTest.php. Runs against
 * whichever order storage the store uses: ss_browser_state()['hpos'] drives
 * the order screen path (bin/setup-local-dev.sh --order-storage hpos|posts).
 *
 * The meta box is server-rendered disabled and enabled by the app once it
 * mounted, so every click/fill below waits for hydration through
 * Playwright's actionability checks - no explicit waits needed.
 */

beforeAll(function (): void {
    if (!ss_browser_store_manageable()) {
        return;
    }

    ss_browser_seed_store(['orders' => [
        [],                       // 0: outbound label
        [],                       // 1: return label only
        ['auto_return' => true],  // 2: return checkbox pre-ticked -> both labels
        [],                       // 3: validation failures
        ['quantity' => 3],        // 4: parcel editor
        [],                       // 5: pickup point override
        [],                       // 6: method override to a non-agent method
        [],                       // 7: book again requires a confirm
        ['flat_rate' => true],    // 8: state B - no Smart Send method
        ['return_method' => ''],  // 9: no return method configured
    ]]);
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

/**
 * The outbound and return shipment ids stored on an order.
 */
function ss_browser_shipment_ids(int $order_id): array
{
    return ss_browser_wp_eval(<<<PHP
\$order = wc_get_order({$order_id});
echo json_encode(array(
    'label_id'        => \$order ? \$order->get_meta('_ss_shipping_label_id', true) : null,
    'return_label_id' => \$order ? \$order->get_meta('_ss_shipping_return_label_id', true) : null,
    'agent_no'        => \$order ? \$order->get_meta('ss_shipping_order_agent_no', true) : null,
    'parcels'         => \$order ? \$order->get_meta('ss_shipping_order_parcels', true) : null,
));
PHP);
}

/**
 * Open an order's edit screen as the admin.
 */
function ss_browser_open_order(int $order_id)
{
    return login_as_admin()
        ->navigate(base_url(ss_browser_order_edit_path($order_id)))
        ->assertSee('Smart Send Shipping');
}

it('creates a shipping label from the meta box without a page reload and prepends the order note', function () {
    $order_id = ss_browser_state()['orders'][0];
    ss_browser_reset_api_requests();

    $page = ss_browser_open_order($order_id)
        ->assertSeeIn('[data-ss-section="pickup_point"]', 'Browser Test Shop')
        ->assertSeeIn('[data-ss-section="parcel_plan"]', '1 parcel · 1.00 kg')
        ->assertNotChecked('[data-ss-field="with_return"]')
        ->click('[data-ss-action="create-label"]')
        // State E, rendered from the POST response: documents and tracking.
        ->assertSeeIn('[data-ss-section="outbound_shipment"]', 'Booked')
        ->assertSeeIn('[data-ss-section="documents"]', 'Download shipping label (PDF)')
        ->assertSeeIn('[data-ss-section="tracking"]', 'BROWSERTRACK1')
        ->assertSeeIn('[data-ss-section="outbound_shipment"]', 'Shipped as 1 parcel')
        // No return was requested: the separate action stays.
        ->assertSeeIn('[data-ss-section="return_shipment"]', 'not created')
        ->assertPresent('[data-ss-action="create-return-label"]')
        // The order note the server added was prepended to WooCommerce's list.
        ->assertSeeIn('ul.order_notes', 'Tracking number: BROWSERTRACK1')
        // Same URL: nothing reloaded.
        ->assertQueryStringHas('action', 'edit');

    $meta = ss_browser_shipment_ids($order_id);
    expect($meta['label_id'])->toStartWith('browser-shipment-')
        ->and($meta['return_label_id'])->toBe('');

    $requests = ss_browser_api_requests('booking');
    expect($requests)->toHaveCount(1)
        ->and($requests[0]['body']['shipping_method'])->toBe('agent')
        ->and($requests[0]['body']['agent']['agent_no'])->toBe('1234');
});

it('pre-ticks the return checkbox from the method setting and books both labels in one run', function () {
    $order_id = ss_browser_state()['orders'][2];

    ss_browser_open_order($order_id)
        ->assertChecked('[data-ss-field="with_return"]')
        ->assertSeeIn('[data-ss-section="return"]', 'Return Drop Off')
        ->click('[data-ss-action="create-label"]')
        ->assertSeeIn('[data-ss-section="outbound_shipment"]', 'Booked')
        ->assertSeeIn('[data-ss-section="return_shipment"]', 'Booked')
        ->assertSeeIn('[data-ss-section="return_shipment"]', 'Download return label (PDF)')
        ->assertSeeIn('ul.order_notes', 'Download return shipping label');

    $meta = ss_browser_shipment_ids($order_id);
    expect($meta['label_id'])->toStartWith('browser-shipment-')
        ->and($meta['return_label_id'])->toStartWith('browser-shipment-');
});

it('creates only a return label from the separate action', function () {
    $order_id = ss_browser_state()['orders'][1];

    ss_browser_open_order($order_id)
        ->click('[data-ss-action="create-return-label"]')
        ->assertSeeIn('[data-ss-section="return_shipment"]', 'Booked')
        ->assertSeeIn('[data-ss-section="return_shipment"]', 'Download return label (PDF)')
        // The outbound side is still the form.
        ->assertPresent('[data-ss-action="create-label"]');

    $meta = ss_browser_shipment_ids($order_id);
    expect($meta['return_label_id'])->toStartWith('browser-shipment-')
        ->and($meta['label_id'])->toBe('');
});

it('shows a validation failure on the field it belongs to, and the rest as a general notice with the response id', function () {
    $order_id = ss_browser_state()['orders'][3];

    // A field the box owns (agent_no -> pickup_point.agent_no).
    ss_browser_set_api_scenarios(['booking' => '422-agent-no']);

    try {
        $page = ss_browser_open_order($order_id)
            ->click('[data-ss-action="create-label"]')
            ->assertSeeIn('[data-ss-error="pickup_point.agent_no"]', 'The selected pickup point is not available for this carrier')
            // Still bookable: the form stays, no booked block.
            ->assertPresent('[data-ss-action="create-label"]')
            ->assertNotPresent('[data-ss-section="outbound_shipment"]');

        // A field outside the box (the receiver address): the general notice.
        ss_browser_set_api_scenarios(['booking' => '422-wrong-zip']);

        $page->click('[data-ss-action="create-label"]')
            ->assertSeeIn('[data-ss-notice="outbound_failed"]', 'The given data was invalid.')
            ->assertSeeIn('[data-ss-notice="outbound_failed"]', 'receiver.zip_code: The receiver zip code does not match the receiver country')
            ->assertNotPresent('[data-ss-error="pickup_point.agent_no"]');
    } finally {
        ss_browser_set_api_scenarios(null);
    }

    // Nothing was written on the failed bookings.
    expect(ss_browser_shipment_ids($order_id)['label_id'])->toBe('');
});

it('books three parcels from the parcel editor: a unit moved to box 2 and an empty box with an explicit weight', function () {
    $order_id = ss_browser_state()['orders'][4];
    ss_browser_reset_api_requests();

    ss_browser_open_order($order_id)
        ->assertSeeIn('[data-ss-section="parcel_plan"]', '1 parcel · 3.00 kg')
        ->click('[data-ss-action="edit-parcels"]')
        // Box 2 for the third unit (select values are box indexes).
        ->click('[data-ss-action="add-box"]')
        ->select('[data-ss-field="parcel_plan.units[2].box"]', '1')
        // Box 3 stays empty and carries an explicit weight; the placeholder of
        // a box shows its computed weight.
        ->click('[data-ss-action="add-box"]')
        ->assertAttribute('[data-ss-field="parcel_plan.specs[0].weight"]', 'placeholder', '2.00')
        ->assertAttribute('[data-ss-field="parcel_plan.specs[2].weight"]', 'placeholder', '0.00')
        ->fill('[data-ss-field="parcel_plan.specs[2].weight"]', '2.5')
        ->assertSeeIn('[data-ss-section="parcel_plan"]', '3 parcels · 5.50 kg')
        ->click('[data-ss-action="parcels-done"]')
        ->click('[data-ss-action="create-label"]')
        ->assertSeeIn('[data-ss-section="outbound_shipment"]', 'Booked');

    // The booking request carried the three parcels as edited...
    $requests = ss_browser_api_requests('booking');
    expect($requests)->toHaveCount(1);

    // (The wire carries one item row per unit - a v8 oddity keeps the order
    // line's quantity on every row, so count rows, not quantities.)
    $parcels = $requests[0]['body']['parcels'];
    expect($parcels)->toHaveCount(3)
        ->and($parcels[0]['items'])->toHaveCount(2)
        ->and($parcels[1]['items'])->toHaveCount(1)
        ->and($parcels[2]['items'])->toBeEmpty()
        ->and($parcels[2]['weight'])->toEqual(2.5);

    // ...and the split was persisted after the successful booking (item rows
    // only: the empty box has none to store).
    $meta = ss_browser_shipment_ids($order_id);
    expect($meta['label_id'])->toStartWith('browser-shipment-')
        ->and(array_column($meta['parcels'], 'value'))->toBe(['1', '1', '2']);
});

it('overrides the pickup point through the lookup and reports an unknown agent number on the field', function () {
    $order_id = ss_browser_state()['orders'][5];
    ss_browser_reset_api_requests();

    $page = ss_browser_open_order($order_id)
        ->assertSeeIn('[data-ss-section="pickup_point"]', 'Browser Test Shop')
        ->click('[data-ss-action="change-pickup-point"]');

    // An unknown number: the lookup route's 404 lands on the field.
    ss_browser_set_api_scenarios(['agent-lookup' => 'not-found']);

    try {
        $page->fill('[data-ss-field="pickup_point.agent_no"]', '9999')
            ->click('[data-ss-action="lookup-pickup-point"]')
            ->assertPresent('[data-ss-error="pickup_point.agent_no"]')
            ->assertSeeIn('[data-ss-section="pickup_point"]', 'Browser Test Shop');
    } finally {
        ss_browser_set_api_scenarios(null);
    }

    // A known one resolves and is shown before booking.
    $page->fill('[data-ss-field="pickup_point.agent_no"]', '5678')
        ->click('[data-ss-action="lookup-pickup-point"]')
        ->assertSeeIn('[data-ss-section="pickup_point"]', 'Second Test Shop')
        ->assertSeeIn('[data-ss-section="pickup_point"]', 'Agent No.: 5678')
        ->click('[data-ss-action="create-label"]')
        ->assertSeeIn('[data-ss-section="outbound_shipment"]', 'Booked');

    // Booked with, and stored after success, the overriding point.
    expect(ss_browser_api_requests('booking')[0]['body']['agent']['agent_no'])->toBe('5678')
        ->and(ss_browser_shipment_ids($order_id)['agent_no'])->toBe('5678');
});

it('hides the pickup point when the method is changed to a non-agent method and books without one', function () {
    $order_id = ss_browser_state()['orders'][6];
    ss_browser_reset_api_requests();

    ss_browser_open_order($order_id)
        ->assertSeeIn('[data-ss-section="shipping_method"]', 'MyPack Collect')
        ->assertPresent('[data-ss-section="pickup_point"]')
        ->click('[data-ss-action="change-method"]')
        ->select('[data-ss-field="shipping_method"]', 'postnord_homedelivery')
        ->assertNotPresent('[data-ss-section="pickup_point"]')
        ->click('[data-ss-action="create-label"]')
        ->assertSeeIn('[data-ss-section="outbound_shipment"]', 'Booked');

    $body = ss_browser_api_requests('booking')[0]['body'];
    expect($body['shipping_carrier'])->toBe('postnord')
        ->and($body['shipping_method'])->toBe('homedelivery')
        ->and($body['agent'])->toBeNull();

    // The method override is per booking: the stored pickup point survives.
    expect(ss_browser_shipment_ids($order_id)['agent_no'])->toBe('1234');
});

it('shows the id-only variant after a reload and requires a confirm to book again', function () {
    $order_id = ss_browser_state()['orders'][7];

    ss_browser_open_order($order_id)
        ->click('[data-ss-action="create-label"]')
        ->assertSeeIn('[data-ss-section="outbound_shipment"]', 'Booked');

    $first = ss_browser_shipment_ids($order_id)['label_id'];
    expect($first)->toStartWith('browser-shipment-');

    // After a reload only the id is known (nothing but the id is persisted).
    $page = ss_browser_open_order($order_id)
        ->assertSeeIn('[data-ss-section="outbound_shipment"]', $first)
        ->assertSeeIn('[data-ss-section="outbound_shipment"]', 'Documents and tracking are in the order notes.')
        ->assertNotPresent('[data-ss-action="create-label"]')
        // The disclosure opens the form behind a warning and a confirm; the
        // action stays disabled until confirmed.
        ->click('[data-ss-action="rebook"]')
        ->assertSeeIn('[data-ss-notice="rebook"]', 'A shipping label already exists for this order.')
        ->assertDisabled('[data-ss-action="create-label"]')
        ->check('[data-ss-field="confirm_rebook"]')
        ->click('[data-ss-action="create-label"]')
        ->assertSeeIn('[data-ss-section="documents"]', 'Download shipping label (PDF)');

    $second = ss_browser_shipment_ids($order_id)['label_id'];
    expect($second)->toStartWith('browser-shipment-')
        ->and($second)->not->toBe($first);

    $page->assertSeeIn('[data-ss-section="outbound_shipment"]', $second);
});

it('books an order placed without a Smart Send method once a method and a return method are chosen', function () {
    $order_id = ss_browser_state()['orders'][8];
    ss_browser_reset_api_requests();

    ss_browser_open_order($order_id)
        ->assertSeeIn('[data-ss-notice="no_method"]', 'This order was not placed with a Smart Send shipping method. Choose one to book anyway.')
        ->assertSeeIn('[data-ss-hint="no_return_method"]', 'No return method configured')
        ->select('[data-ss-field="shipping_method"]', 'postnord_homedelivery')
        ->check('[data-ss-field="with_return"]')
        ->select('[data-ss-field="return_method"]', 'postnord_returndropoff')
        ->click('[data-ss-action="create-label"]')
        ->assertSeeIn('[data-ss-section="outbound_shipment"]', 'Booked')
        ->assertSeeIn('[data-ss-section="return_shipment"]', 'Booked');

    $requests = ss_browser_api_requests('booking');
    expect($requests)->toHaveCount(2)
        ->and($requests[0]['body']['shipping_method'])->toBe('homedelivery')
        ->and($requests[1]['body']['shipping_method'])->toBe('returndropoff');

    $meta = ss_browser_shipment_ids($order_id);
    expect($meta['label_id'])->toStartWith('browser-shipment-')
        ->and($meta['return_label_id'])->toStartWith('browser-shipment-');
});

it('explains a missing return method and lets the merchant choose one for the run', function () {
    $order_id = ss_browser_state()['orders'][9];

    ss_browser_open_order($order_id)
        ->assertSeeIn('[data-ss-hint="no_return_method"]', 'No return method configured on the shipping method')
        ->assertNotChecked('[data-ss-field="with_return"]')
        ->check('[data-ss-field="with_return"]')
        ->assertPresent('[data-ss-field="return_method"]');
});
