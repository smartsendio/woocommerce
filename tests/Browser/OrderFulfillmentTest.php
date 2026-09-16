<?php

/*
 * The merchant fulfillment journey on the order screen: the "Smart Send" meta
 * box rewritten on the fulfillment contract (#182) - a React
 * app talking to the smart-send/v1 REST routes, no page reload. One test
 * per UX state of the issue's section 1.2: create the outbound label, the
 * return checkbox defaulting from the method setting (books both), a return
 * label on its own from the secondary action (with a configured return
 * method, and from state B with a chosen one), a validation failure shown
 * on the field it belongs to
 * (and the general notice for one outside the box), the parcel editor
 * (opened with Edit, collapsed with Done keeping the plan: a line split
 * across boxes one unit at a time with the ▲▼ arrows, a box emptied by a
 * move removed and the rest renumbered - the booking request carries the
 * parcels as edited), a pickup point override through the lookup route,
 * a method override to a non-agent method (no pickup point), a return
 * method override, a return booked from the same page, booking again
 * behind one confirming click, and an order placed without a Smart Send
 * method booked with a chosen method (+ a chosen return method).
 *
 * Booked state (#182 review, 2026-09-16): the form NEVER closes. A booking
 * adds the green result box of the run (title + "Open" into the Smart Send
 * app, the time and the id, one row per parcel with tracking/weight/
 * dimensions/reference, the documents) above the two actions, and an entry
 * to the grey "Booked shipments" timeline. The result box is component
 * memory only - a reload loses it and keeps the timeline.
 *
 * Layout (Option A): the shipping method, pickup point and return method
 * are read-only values - "None" when missing - each with an "Edit" link
 * that swaps the value for its control; the journeys click Edit before
 * changing anything.
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
        ['flat_rate' => true],    // 10: state B - return label only
        [],                       // 11: return method override
        [],                       // 12: a return booked from the booked state
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
    'labels'          => \$order ? \$order->get_meta('_ss_shipping_labels', true) : null,
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
        ->assertSeeIn('#woocommerce-ss-shipping-label .hndle', 'Smart Send');
}

it('creates a shipping label from the meta box without a page reload and prepends the order note', function () {
    $order_id = ss_browser_state()['orders'][0];
    ss_browser_reset_api_requests();

    $page = ss_browser_open_order($order_id)
        // The read state: the method, pickup point and return method as
        // values with Edit links, the parcels collapsed to their summary.
        ->assertSeeIn('[data-ss-value="shipping_method"]', 'MyPack Collect')
        ->assertSeeIn('[data-ss-value="pickup_point.agent_no"]', '#1234')
        ->assertSeeIn('[data-ss-section="pickup_point"]', 'Browser Test Shop')
        ->assertSeeIn('[data-ss-section="pickup_point"]', 'Main Street 1')
        ->assertSeeIn('[data-ss-value="return_method"]', 'Return Drop Off')
        ->assertPresent('[data-ss-action="edit-method"]')
        ->assertPresent('[data-ss-action="edit-pickup-point"]')
        ->assertPresent('[data-ss-action="edit-return-method"]')
        ->assertPresent('[data-ss-action="edit-parcels"]')
        ->assertNotPresent('[data-ss-field="shipping_method"]')
        ->assertNotPresent('[data-ss-section="parcel_editor"]')
        ->assertSeeIn('[data-ss-section="parcel_plan"]', '1 parcel · 1.00 kg')
        ->assertNotChecked('[data-ss-field="with_return"]')
        ->assertDontSee('Default from the shipping method settings')
        // Demo mode (the seeded default) is a warning callout, not a button prefix.
        ->assertSeeIn('[data-ss-notice="demo_mode"]', 'Demo mode active')
        ->assertSeeIn('[data-ss-section="actions"]', 'Create shipping label')
        ->assertDontSee('DEMO MODE: Create')
        // WooCommerce's help tips (aria-label = the tip text) on the three
        // rows and on the settings checkbox.
        ->assertAttribute('[data-ss-section="shipping_method"] .woocommerce-help-tip', 'aria-label', 'Shipping method used for booking of outgoing shipment')
        ->assertAttribute('[data-ss-section="return_method"] .woocommerce-help-tip', 'aria-label', 'Shipping method used for booking of return shipments')
        ->assertAttribute('[data-ss-section="parcel_plan"] .woocommerce-help-tip', 'aria-label', 'Move items between boxes with the arrows. Weight is calculated from the items unless you enter one.')
        ->assertAttribute('[data-ss-section="settings"] .woocommerce-help-tip', 'aria-label', 'When booking an outgoing label, then we will automatically also book a return label')
        // The tips the app rendered are bound to WooCommerce's tipTip
        // tooltip (WooCommerce binds on DOM ready only; the app binds after
        // every render): hovering one shows WooCommerce's tooltip holder.
        ->hover('[data-ss-section="shipping_method"] .woocommerce-help-tip')
        ->assertSeeIn('#tiptip_holder', 'Shipping method used for booking of outgoing shipment')
        ->click('[data-ss-action="create-label"]')
        // The green result box of the run, rendered from the POST response
        // above the actions: the header with "Open" into the Smart Send
        // app, the booking time + id, one row per parcel (tracking +
        // measures) and the documents.
        ->assertSeeIn('[data-ss-result="outbound"] .smart-send-fulfillment__result-title', 'Shipment')
        ->assertSeeIn('[data-ss-action="view-shipment"]', 'Open')
        ->assertAttribute('[data-ss-action="view-shipment"]', 'target', '_blank')
        ->assertAttribute('[data-ss-action="view-shipment"]', 'rel', 'noopener noreferrer')
        ->assertSeeIn('[data-ss-section="parcels"]', 'BROWSERTRACK1')
        ->assertSeeIn('[data-ss-section="parcels"]', '1 kg')
        ->assertSeeIn('[data-ss-section="documents"]', 'Download shipping label (PDF)')
        // The form is still fully there: the method rows, the parcel
        // editor, both actions and the return checkbox.
        ->assertSeeIn('[data-ss-value="shipping_method"]', 'MyPack Collect')
        ->assertPresent('[data-ss-section="pickup_point"]')
        ->assertPresent('[data-ss-action="edit-return-method"]')
        ->assertPresent('[data-ss-section="parcel_plan"]')
        ->assertPresent('[data-ss-action="edit-parcels"]')
        ->assertPresent('[data-ss-field="with_return"]')
        ->assertPresent('[data-ss-action="create-label"]')
        ->assertPresent('[data-ss-action="create-return-label"]')
        // ...and nothing of the closed booked box of the previous round.
        ->assertNotPresent('[data-ss-action="reset"]')
        ->assertDontSee('Documents and tracking are in the order notes.')
        // A new entry appeared on the "Booked shipments" timeline.
        ->assertPresent('[data-ss-section="timeline"]')
        ->assertSeeIn('[data-ss-section="timeline"]', 'Booked shipments')
        ->assertSeeIn('[data-ss-timeline="outbound"]', 'Shipment')
        ->assertCount('[data-ss-timeline="outbound"]', 1)
        // The order note the server added was prepended to WooCommerce's list.
        ->assertSeeIn('ul.order_notes', 'Tracking number: BROWSERTRACK1')
        // Same URL: nothing reloaded.
        ->assertQueryStringHas('action', 'edit');

    $meta = ss_browser_shipment_ids($order_id);
    expect($meta['label_id'])->toStartWith('browser-shipment-')
        ->and($meta['return_label_id'])->toBe('');

    // The app link is built from the resolved API host (the demo store talks
    // to the production host), never hardcoded in the client.
    $page->assertAttribute('[data-ss-action="view-shipment"]', 'href', 'https://app.smartsend.io/shipments/' . $meta['label_id'])
        ->assertAttribute('[data-ss-timeline="outbound"]', 'href', 'https://app.smartsend.io/shipments/' . $meta['label_id']);

    // One row on the order's append-only labels list - what the timeline
    // renders after a reload.
    expect($meta['labels'])->toHaveCount(1)
        ->and($meta['labels'][0]['direction'])->toBe('outbound')
        ->and($meta['labels'][0]['shipment_id'])->toBe($meta['label_id'])
        ->and($meta['labels'][0]['booked_at'])->not->toBeEmpty();

    $requests = ss_browser_api_requests('booking');
    expect($requests)->toHaveCount(1)
        ->and($requests[0]['body']['shipping_method'])->toBe('agent')
        ->and($requests[0]['body']['agent']['agent_no'])->toBe('1234');
});

it('pre-ticks the return checkbox from the method setting and books both labels in one run', function () {
    $order_id = ss_browser_state()['orders'][2];

    ss_browser_open_order($order_id)
        ->assertChecked('[data-ss-field="with_return"]')
        ->assertSeeIn('[data-ss-value="return_method"]', 'Return Drop Off')
        ->click('[data-ss-action="create-label"]')
        ->assertPresent('[data-ss-result="outbound"]')
        ->assertPresent('[data-ss-result="return"]')
        ->assertSeeIn('[data-ss-result="return"]', 'Download return label (PDF)')
        ->assertSeeIn('ul.order_notes', 'Download return shipping label')
        // One timeline entry per leg, the return (newest) first.
        ->assertPresent('[data-ss-timeline="outbound"]')
        ->assertPresent('[data-ss-timeline="return"]');

    $meta = ss_browser_shipment_ids($order_id);
    expect($meta['label_id'])->toStartWith('browser-shipment-')
        ->and($meta['return_label_id'])->toStartWith('browser-shipment-')
        ->and(array_column($meta['labels'], 'direction'))->toBe(['outbound', 'return']);
});

it('creates only a return label from the secondary action before any outbound label exists', function () {
    $order_id = ss_browser_state()['orders'][1];
    ss_browser_reset_api_requests();

    ss_browser_open_order($order_id)
        // Both actions are offered in the not-yet-booked state: the primary
        // outbound one and the secondary return-only one.
        ->assertAttributeContains('[data-ss-action="create-label"]', 'class', 'is-primary')
        ->assertAttributeContains('[data-ss-action="create-return-label"]', 'class', 'is-secondary')
        ->click('[data-ss-action="create-return-label"]')
        ->assertSeeIn('[data-ss-result="return"] .smart-send-fulfillment__result-title', 'Return shipment')
        ->assertSeeIn('[data-ss-result="return"]', 'Download return label (PDF)')
        // Nothing of the form moved, and only the return leg is on the
        // timeline.
        ->assertPresent('[data-ss-action="create-label"]')
        ->assertPresent('[data-ss-section="parcel_plan"]')
        ->assertNotPresent('[data-ss-result="outbound"]')
        ->assertPresent('[data-ss-timeline="return"]')
        ->assertNotPresent('[data-ss-timeline="outbound"]');

    $meta = ss_browser_shipment_ids($order_id);
    expect($meta['return_label_id'])->toStartWith('browser-shipment-')
        ->and($meta['label_id'])->toBe('');

    // One booking only: the configured return method, no outbound leg.
    $requests = ss_browser_api_requests('booking');
    expect($requests)->toHaveCount(1)
        ->and($requests[0]['body']['shipping_method'])->toBe('returndropoff');
});

it('creates only a return label for an order placed without a Smart Send method once a return method is chosen', function () {
    $order_id = ss_browser_state()['orders'][10];
    ss_browser_reset_api_requests();

    ss_browser_open_order($order_id)
        ->assertSeeIn('[data-ss-notice="no_method"]', 'Shipping method is not from the Smart Send plugin.')
        // No return method to fall back on: the row reads "None" and the
        // secondary action waits for one chosen behind Edit (no checkbox
        // needed for a return-only booking).
        ->assertNotChecked('[data-ss-field="with_return"]')
        ->assertSeeIn('[data-ss-value="return_method"]', 'None')
        ->assertEnabled('[data-ss-action="create-return-label"]')
        ->assertAttribute('[data-ss-action="create-return-label"]', 'title', 'Select a return shipping method first')
        // Pressed without a return method: the error instead of a request,
        // the return method row switched into Edit with its select focused.
        ->click('[data-ss-action="create-return-label"]')
        ->assertSeeIn('[data-ss-notice="missing_return_method"]', 'Select a return shipping method first')
        ->assertVisible('[data-ss-field="return_method"]')
        ->assertNotPresent('[data-ss-action="edit-return-method"]')
        ->assertNotPresent('[data-ss-section="return_shipment"]')
        ->select('[data-ss-field="return_method"]', 'postnord_returndropoff')
        // Choosing one clears the error.
        ->assertNotPresent('[data-ss-notice="missing_return_method"]')
        ->click('[data-ss-action="create-return-label"]')
        ->assertPresent('[data-ss-result="return"]')
        // The form is untouched by the booking: the method row still reads
        // "None" with its Edit link and the outbound action is there.
        ->assertSeeIn('[data-ss-value="shipping_method"]', 'None')
        ->assertPresent('[data-ss-action="edit-method"]')
        ->assertPresent('[data-ss-action="create-label"]')
        ->assertNotPresent('[data-ss-action="reset"]');

    $requests = ss_browser_api_requests('booking');
    expect($requests)->toHaveCount(1)
        ->and($requests[0]['body']['shipping_method'])->toBe('returndropoff');

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
            ->assertNotPresent('[data-ss-result="outbound"]')
            ->assertNotPresent('[data-ss-section="timeline"]');

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

it('splits a line across boxes with the arrows, removes a box emptied by a move, and books the parcels as edited', function () {
    $order_id = ss_browser_state()['orders'][4];
    ss_browser_reset_api_requests();

    $line = '[data-ss-line]';
    $count = fn (int $box) => "[data-ss-box=\"{$box}\"] {$line} [data-ss-value=\"line.count\"]";

    ss_browser_open_order($order_id)
        ->assertSeeIn('[data-ss-section="parcel_plan"]', '1 parcel · 3.00 kg')
        ->assertNotPresent('[data-ss-section="parcel_editor"]')
        ->click('[data-ss-action="edit-parcels"]')
        // Expanded: Done in place of Edit.
        ->assertNotPresent('[data-ss-action="edit-parcels"]')
        ->assertPresent('[data-ss-action="done-parcels"]')
        // One row per product line: name + SKU (full text in title attributes),
        // the count in this box over the line's total, ▲ disabled in box 1.
        ->assertSeeIn($count(1), '× 3')
        ->assertSeeIn('[data-ss-box="1"] [data-ss-value="line.total"]', 'of 3')
        ->assertSeeIn('[data-ss-box="1"] [data-ss-value="line.sku"]', 'SKU: SS-BROWSER-TEST')
        ->assertAttribute('[data-ss-box="1"] [data-ss-value="line.name"]', 'title', 'SS Browser Test Product')
        ->assertAttribute('[data-ss-box="1"] [data-ss-value="line.sku"]', 'title', 'SS-BROWSER-TEST')
        ->assertAttribute('[data-ss-box="1"] [data-ss-action="move-down"]', 'aria-label', 'Move one unit to the box below')
        ->assertDisabled('[data-ss-box="1"] [data-ss-action="move-up"]')
        ->assertNotPresent('[data-ss-box="2"]')
        ->assertNotPresent('[data-ss-action="add-box"]')
        // ▼ below the last box creates box 2 with the moved unit: 2 / 1.
        ->click('[data-ss-box="1"] [data-ss-action="move-down"]')
        ->assertSeeIn($count(1), '× 2')
        ->assertSeeIn($count(2), '× 1')
        ->assertSeeIn('[data-ss-section="parcel_plan"]', '2 parcels · 3.00 kg')
        // A lone unit in the last box cannot move down (it would only spawn
        // a box while emptying this one).
        ->assertDisabled('[data-ss-box="2"] [data-ss-action="move-down"]')
        ->assertAttributeContains('[data-ss-box="2"] [data-ss-action="move-down"]', 'aria-label', 'a box must not be empty')
        // ▼ again on box 1's row: 1 / 2.
        ->click('[data-ss-box="1"] [data-ss-action="move-down"]')
        ->assertSeeIn($count(1), '× 1')
        ->assertSeeIn($count(2), '× 2')
        // ▲ on box 2's row: back to 2 / 1.
        ->click('[data-ss-box="2"] [data-ss-action="move-up"]')
        ->assertSeeIn($count(1), '× 2')
        ->assertSeeIn($count(2), '× 1')
        // Two more ▼: 1 / 1 / 1 - box 3 created from the last box's row.
        ->click('[data-ss-box="1"] [data-ss-action="move-down"]')
        ->click('[data-ss-box="2"] [data-ss-action="move-down"]')
        ->assertSeeIn($count(1), '× 1')
        ->assertSeeIn($count(2), '× 1')
        ->assertSeeIn($count(3), '× 1')
        // Explicit weights travel with their box: box 2 gets one, box 3 too.
        ->assertAttribute('[data-ss-field="parcel_plan.specs[0].weight"]', 'placeholder', '1.00')
        ->fill('[data-ss-field="parcel_plan.specs[1].weight"]', '9')
        ->fill('[data-ss-field="parcel_plan.specs[2].weight"]', '2.5')
        // ▲ on box 2's last unit empties box 2: it is removed (its weight 9
        // dropped) and box 3 renumbers to box 2, keeping its 2.5.
        ->click('[data-ss-box="2"] [data-ss-action="move-up"]')
        ->assertNotPresent('[data-ss-box="3"]')
        ->assertSeeIn($count(1), '× 2')
        ->assertSeeIn($count(2), '× 1')
        ->assertValue('[data-ss-field="parcel_plan.specs[1].weight"]', '2.5')
        ->assertSeeIn('[data-ss-section="parcel_plan"]', '2 parcels · 4.50 kg')
        // Done collapses the editor and keeps the edited plan.
        ->click('[data-ss-action="done-parcels"]')
        ->assertNotPresent('[data-ss-section="parcel_editor"]')
        ->assertPresent('[data-ss-action="edit-parcels"]')
        ->assertSeeIn('[data-ss-section="parcel_plan"]', '2 parcels · 4.50 kg')
        ->click('[data-ss-action="create-label"]')
        ->assertPresent('[data-ss-result="outbound"]')
        // One booked row per parcel: tracking, and the weight each was
        // booked with (box 1 the sum of its units, box 2 its explicit 2.5) -
        // no parcel reference (it is the order number on every row).
        ->assertSeeIn('[data-ss-parcel="1"]', 'BROWSERTRACK1')
        ->assertSeeIn('[data-ss-parcel="1"]', '2 kg')
        ->assertSeeIn('[data-ss-parcel="2"]', 'BROWSERTRACK2')
        ->assertSeeIn('[data-ss-parcel="2"]', '2.5 kg')
        ->assertDontSee('Ref.');

    // The booking request carried the two parcels as edited...
    $requests = ss_browser_api_requests('booking');
    expect($requests)->toHaveCount(1);

    // (The wire carries one item row per unit - a v8 oddity keeps the order
    // line's quantity on every row, so count rows, not quantities.)
    $parcels = $requests[0]['body']['parcels'];
    expect($parcels)->toHaveCount(2)
        ->and($parcels[0]['items'])->toHaveCount(2)
        ->and($parcels[1]['items'])->toHaveCount(1)
        ->and($parcels[1]['weight'])->toEqual(2.5);

    // ...and the split was persisted after the successful booking.
    $meta = ss_browser_shipment_ids($order_id);
    expect($meta['label_id'])->toStartWith('browser-shipment-')
        ->and(array_column($meta['parcels'], 'value'))->toBe(['1', '1', '2']);
});

it('overrides the pickup point through the lookup and reports an unknown agent number on the field', function () {
    $order_id = ss_browser_state()['orders'][5];
    ss_browser_reset_api_requests();

    $page = ss_browser_open_order($order_id)
        ->assertSeeIn('[data-ss-section="pickup_point"]', 'Browser Test Shop')
        ->assertNotPresent('[data-ss-field="pickup_point.agent_no"]')
        ->click('[data-ss-action="edit-pickup-point"]')
        // Editing: the input + "Look up" over the current point, no Edit link.
        ->assertNotPresent('[data-ss-action="edit-pickup-point"]')
        ->assertValue('[data-ss-field="pickup_point.agent_no"]', '1234');

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

    // A known one resolves and is shown before booking; the row is back
    // to its read state with the new point.
    $page->fill('[data-ss-field="pickup_point.agent_no"]', '5678')
        ->click('[data-ss-action="lookup-pickup-point"]')
        ->assertSeeIn('[data-ss-section="pickup_point"]', 'Second Test Shop')
        ->assertSeeIn('[data-ss-value="pickup_point.agent_no"]', '#5678')
        ->assertSeeIn('[data-ss-section="pickup_point"]', 'Other Street 9')
        ->assertNotPresent('[data-ss-field="pickup_point.agent_no"]')
        ->assertPresent('[data-ss-action="edit-pickup-point"]')
        ->click('[data-ss-action="create-label"]')
        ->assertPresent('[data-ss-result="outbound"]');

    // Booked with, and stored after success, the overriding point.
    expect(ss_browser_api_requests('booking')[0]['body']['agent']['agent_no'])->toBe('5678')
        ->and(ss_browser_shipment_ids($order_id)['agent_no'])->toBe('5678');
});

it('hides the pickup point when the method is changed to a non-agent method and books without one', function () {
    $order_id = ss_browser_state()['orders'][6];
    ss_browser_reset_api_requests();

    ss_browser_open_order($order_id)
        ->assertSeeIn('[data-ss-value="shipping_method"]', 'MyPack Collect')
        ->assertPresent('[data-ss-section="pickup_point"]')
        ->assertNotPresent('[data-ss-field="shipping_method"]')
        ->click('[data-ss-action="edit-method"]')
        // Editing: the select in place of the value, no Edit link.
        ->assertNotPresent('[data-ss-action="edit-method"]')
        ->assertNotPresent('[data-ss-value="shipping_method"]')
        ->select('[data-ss-field="shipping_method"]', 'postnord_homedelivery')
        ->assertNotPresent('[data-ss-section="pickup_point"]')
        ->click('[data-ss-action="create-label"]')
        ->assertPresent('[data-ss-result="outbound"]');

    $body = ss_browser_api_requests('booking')[0]['body'];
    expect($body['shipping_carrier'])->toBe('postnord')
        ->and($body['shipping_method'])->toBe('homedelivery')
        ->and($body['agent'])->toBeNull();

    // The method override is per booking: the stored pickup point survives.
    expect(ss_browser_shipment_ids($order_id)['agent_no'])->toBe('1234');
});

it('keeps the timeline but not the green box after a reload, and books again behind one confirming click', function () {
    $order_id = ss_browser_state()['orders'][7];

    ss_browser_open_order($order_id)
        ->assertNotPresent('[data-ss-section="timeline"]')
        ->click('[data-ss-action="create-label"]')
        ->assertPresent('[data-ss-result="outbound"]');

    $first = ss_browser_shipment_ids($order_id)['label_id'];
    expect($first)->toStartWith('browser-shipment-');

    // After a reload the green box is gone (it is the memory of the run
    // just made, never persisted) - the timeline entry is what stays, with
    // its link into the Smart Send app and the time it was booked.
    $page = ss_browser_open_order($order_id)
        ->assertNotPresent('[data-ss-result="outbound"]')
        ->assertNotPresent('[data-ss-section="parcels"]')
        ->assertNotPresent('[data-ss-section="documents"]')
        ->assertSeeIn('[data-ss-section="timeline"]', 'Booked shipments')
        ->assertAttribute('[data-ss-timeline="outbound"]', 'href', 'https://app.smartsend.io/shipments/' . $first)
        ->assertAttribute('[data-ss-timeline="outbound"]', 'target', '_blank')
        ->assertSeeIn('[data-ss-timeline="outbound"] .smart-send-fulfillment__timeline-when', ':')
        // The form is all there, both actions included.
        ->assertSeeIn('[data-ss-value="shipping_method"]', 'MyPack Collect')
        ->assertPresent('[data-ss-section="parcel_plan"]')
        ->assertPresent('[data-ss-field="with_return"]')
        ->assertPresent('[data-ss-action="create-label"]')
        // The first click on a direction that already has a label warns and
        // relabels the button instead of booking...
        ->click('[data-ss-action="create-label"]')
        ->assertSeeIn('[data-ss-notice="rebook"]', 'This order already has a shipping label.')
        ->assertSeeIn('[data-ss-action="create-label"]', 'Yes, create another')
        ->assertNotPresent('[data-ss-result="outbound"]')
        // ...and clicking anything else cancels it.
        ->check('[data-ss-field="with_return"]')
        ->assertSeeIn('[data-ss-action="create-label"]', 'Create shipping label')
        ->uncheck('[data-ss-field="with_return"]')
        // The second click on the relabelled button books.
        ->click('[data-ss-action="create-label"]')
        ->assertSeeIn('[data-ss-action="create-label"]', 'Yes, create another')
        ->click('[data-ss-action="create-label"]')
        ->assertSeeIn('[data-ss-section="documents"]', 'Download shipping label (PDF)')
        ->assertSeeIn('[data-ss-action="create-label"]', 'Create shipping label');

    $meta = ss_browser_shipment_ids($order_id);
    expect($meta['label_id'])->toStartWith('browser-shipment-')
        ->and($meta['label_id'])->not->toBe($first);

    // A second timeline entry, newest first, and both on the labels list.
    $page->assertCount('[data-ss-timeline="outbound"]', 2)
        // Newest first: the re-booking heads the timeline.
        ->assertScript(
            'document.querySelector(\'[data-ss-section="timeline"] a\').getAttribute("data-ss-shipment-id")',
            $meta['label_id']
        );

    expect(array_column($meta['labels'], 'shipment_id'))->toBe([$first, $meta['label_id']]);
});

it('books a return from the same page, adding its own timeline entry', function () {
    $order_id = ss_browser_state()['orders'][12];
    ss_browser_reset_api_requests();

    $page = ss_browser_open_order($order_id)
        ->click('[data-ss-action="create-label"]')
        ->assertPresent('[data-ss-result="outbound"]')
        // The form did not move: the return method row is still editable.
        ->assertSeeIn('[data-ss-value="return_method"]', 'Return Drop Off')
        ->assertPresent('[data-ss-action="edit-return-method"]')
        ->click('[data-ss-action="create-return-label"]')
        ->assertSeeIn('[data-ss-result="return"] .smart-send-fulfillment__result-title', 'Return shipment')
        ->assertSeeIn('[data-ss-result="return"]', 'Download return label (PDF)')
        // Only the run just made is shown as a green box; the outbound one
        // of the previous run is gone with its response.
        ->assertNotPresent('[data-ss-result="outbound"]')
        // Both legs are on the timeline, the return (newest) first.
        ->assertPresent('[data-ss-timeline="outbound"]')
        ->assertPresent('[data-ss-timeline="return"]');

    $page->assertScript(
        'document.querySelector(\'[data-ss-section="timeline"] a\').getAttribute("data-ss-timeline")',
        'return'
    );

    $meta = ss_browser_shipment_ids($order_id);
    expect($meta['label_id'])->toStartWith('browser-shipment-')
        ->and($meta['return_label_id'])->toStartWith('browser-shipment-')
        ->and(array_column($meta['labels'], 'direction'))->toBe(['outbound', 'return']);

    $requests = ss_browser_api_requests('booking');
    expect($requests)->toHaveCount(2)
        ->and($requests[1]['body']['shipping_method'])->toBe('returndropoff');
});

it('books an order placed without a Smart Send method once a method and a return method are chosen', function () {
    $order_id = ss_browser_state()['orders'][8];
    ss_browser_reset_api_requests();

    ss_browser_open_order($order_id)
        ->assertSeeIn('[data-ss-notice="no_method"]', 'Shipping method is not from the Smart Send plugin.')
        ->assertDontSee('No return method configured')
        // Both method rows read "None" until Edit opens their select; the
        // primary action waits for a method.
        ->assertSeeIn('[data-ss-value="shipping_method"]', 'None')
        ->assertSeeIn('[data-ss-value="return_method"]', 'None')
        ->assertNotPresent('[data-ss-field="return_method"]')
        // The primary action stays enabled and explains itself: its title,
        // and on a click the error notice + the method row in Edit (select
        // focused) instead of a request.
        ->assertEnabled('[data-ss-action="create-label"]')
        ->assertAttribute('[data-ss-action="create-label"]', 'title', 'Select a shipping method first')
        ->click('[data-ss-action="create-label"]')
        ->assertSeeIn('[data-ss-notice="missing_method"]', 'Select a shipping method first')
        ->assertVisible('[data-ss-field="shipping_method"]')
        ->assertNotPresent('[data-ss-action="edit-method"]')
        ->assertScript('document.activeElement === document.querySelector(\'[data-ss-field="shipping_method"]\')', true)
        ->select('[data-ss-field="shipping_method"]', 'postnord_homedelivery')
        ->assertNotPresent('[data-ss-notice="missing_method"]')
        // A combined run without a return method: the return sentence, the
        // return method row in Edit.
        ->check('[data-ss-field="with_return"]')
        ->assertAttribute('[data-ss-action="create-label"]', 'title', 'Select a return shipping method first')
        ->click('[data-ss-action="create-label"]')
        ->assertSeeIn('[data-ss-notice="missing_return_method"]', 'Select a return shipping method first')
        ->assertVisible('[data-ss-field="return_method"]')
        ->select('[data-ss-field="return_method"]', 'postnord_returndropoff')
        ->click('[data-ss-action="create-label"]')
        ->assertPresent('[data-ss-result="outbound"]')
        ->assertPresent('[data-ss-result="return"]');

    $requests = ss_browser_api_requests('booking');
    expect($requests)->toHaveCount(2)
        ->and($requests[0]['body']['shipping_method'])->toBe('homedelivery')
        ->and($requests[1]['body']['shipping_method'])->toBe('returndropoff');

    $meta = ss_browser_shipment_ids($order_id);
    expect($meta['label_id'])->toStartWith('browser-shipment-')
        ->and($meta['return_label_id'])->toStartWith('browser-shipment-');
});

it('books a return with another return method chosen behind the return method row\'s Edit link', function () {
    $order_id = ss_browser_state()['orders'][11];
    ss_browser_reset_api_requests();

    ss_browser_open_order($order_id)
        ->assertSeeIn('[data-ss-value="return_method"]', 'Return Drop Off')
        ->assertNotPresent('[data-ss-field="return_method"]')
        ->click('[data-ss-action="edit-return-method"]')
        ->assertNotPresent('[data-ss-action="edit-return-method"]')
        ->select('[data-ss-field="return_method"]', 'gls_returndropoff')
        ->check('[data-ss-field="with_return"]')
        ->click('[data-ss-action="create-label"]')
        ->assertPresent('[data-ss-result="outbound"]')
        ->assertPresent('[data-ss-result="return"]');

    // The override is per booking: the return leg went out with the chosen
    // method, the order's configured one untouched.
    $requests = ss_browser_api_requests('booking');
    expect($requests)->toHaveCount(2)
        ->and($requests[0]['body']['shipping_method'])->toBe('agent')
        ->and($requests[1]['body']['shipping_carrier'])->toBe('gls')
        ->and($requests[1]['body']['shipping_method'])->toBe('returndropoff');
});

it('reads "None" for a missing return method and offers the select behind Edit for both actions', function () {
    $order_id = ss_browser_state()['orders'][9];

    ss_browser_open_order($order_id)
        ->assertSeeIn('[data-ss-value="return_method"]', 'None')
        ->assertDontSee('No return method configured')
        ->assertAttribute('[data-ss-section="return_method"] .woocommerce-help-tip', 'aria-label', 'Shipping method used for booking of return shipments')
        ->assertNotChecked('[data-ss-field="with_return"]')
        // Collapsed like every row: no select until Edit; the return action
        // waits for a choice (the return-only action needs it without the
        // checkbox).
        ->assertNotPresent('[data-ss-field="return_method"]')
        ->assertPresent('[data-ss-action="edit-return-method"]')
        ->assertEnabled('[data-ss-action="create-return-label"]')
        ->assertAttribute('[data-ss-action="create-return-label"]', 'title', 'Select a return shipping method first')
        ->click('[data-ss-action="edit-return-method"]')
        ->assertNotPresent('[data-ss-action="edit-return-method"]')
        ->select('[data-ss-field="return_method"]', 'postnord_returndropoff')
        ->assertAttributeMissing('[data-ss-action="create-return-label"]', 'title')
        ->check('[data-ss-field="with_return"]')
        ->assertAttributeMissing('[data-ss-action="create-label"]', 'title');
});
