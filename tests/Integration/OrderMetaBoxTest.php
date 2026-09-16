<?php

/*
 * Tests for the order screen "Smart Send" meta box (#182,
 * SS_Shipping_Order_Meta_Box + SS_Shipping_Order_Fulfillment_Presenter::render_form()):
 * registration on the legacy and the HPOS order screen, the server-rendered
 * first paint per state of section 1.2 of the issue - not connected (the
 * callout over the read-only sections, no Edit links), no Smart Send
 * method ("None" + Edit), not yet booked (the sectioned Option A layout:
 * read values with Edit links, the parcels collapsed to their summary,
 * the stacked actions, the grey settings section) and booked (#182
 * review, 2026-09-16: the form NEVER closes - the same sections plus the
 * "Booked shipments" timeline built from the order's append-only labels
 * list, with the legacy fallback for orders carrying only the frozen
 * shipment ids, and never a green result box, which is the client's
 * memory of the run it just made) - with the state inlined as JSON and every value escaped, and the built React app (build/order-fulfillment/) enqueued
 * from the render callback only, with every script dependency registered
 * on the running WordPress (the WP 6.5 floor has no react-jsx-runtime
 * handle, #183 - the app is built with the classic JSX runtime).
 */

/**
 * Render the meta box for an order and return its HTML.
 */
function render_meta_box(WC_Order $order): string
{
    ob_start();
    SS_SHIPPING_WC()->meta_box()->render_smart_send_order_meta_box(wc_get_order($order->get_id()));

    return (string) ob_get_clean();
}

/**
 * The state the meta box inlined for the client on its last render.
 */
function inlined_meta_box_state(): array
{
    $inline = inlined_meta_box_script();

    expect($inline)->toStartWith('window.smartSendOrderFulfillment = ');

    return json_decode(rtrim(substr($inline, strlen('window.smartSendOrderFulfillment = ')), ';'), true);
}

/**
 * The raw inline script the meta box added on its last render (exactly one
 * per render; the beforeEach below clears earlier ones).
 */
function inlined_meta_box_script(): string
{
    // WordPress seeds a handle's first inline entry with a `false` slot
    // ((array) of the missing data) - only the strings are scripts.
    $scripts = array_values(array_filter((array) wp_scripts()->get_data(SS_Shipping_Order_Meta_Box::STATE_SCRIPT_HANDLE, 'before'), 'is_string'));

    expect($scripts)->toHaveCount(1);

    return $scripts[0];
}

/**
 * An order that can have a label generated for it.
 */
function create_meta_box_order(array $args = []): WC_Order
{
    $product = create_simple_product(['price' => 100, 'weight' => 1]);

    return create_order(array_merge([
        'products'        => [$product],
        'shipping_method' => 'postnord_agent',
        'shipping_total'  => '39',
    ], $args));
}

beforeEach(function (): void {
    with_ss_settings();

    // Every render re-adds the inline state; start each test without one,
    // and without the app enqueued (wp_scripts() persists across tests).
    wp_scripts()->add_data(SS_Shipping_Order_Meta_Box::STATE_SCRIPT_HANDLE, 'before', []);
    wp_dequeue_script(SS_Shipping_Order_Meta_Box::STATE_SCRIPT_HANDLE);
    wp_dequeue_style(SS_Shipping_Order_Meta_Box::STYLE_HANDLE);
});

it('registers the meta box on the HPOS order screen when HPOS is enabled', function () {
    with_option('woocommerce_custom_orders_table_enabled', 'yes');
    // wc_get_page_screen_id() is an admin-only WooCommerce function.
    require_once WC()->plugin_path() . '/includes/admin/wc-admin-functions.php';
    $screen = wc_get_page_screen_id('shop-order');

    global $wp_meta_boxes;
    unset($wp_meta_boxes[$screen]);

    SS_SHIPPING_WC()->meta_box()->add_smart_send_order_meta_box();

    expect($wp_meta_boxes[$screen]['side']['default'])->toHaveKey('woocommerce-ss-shipping-label')
        ->and($wp_meta_boxes[$screen]['side']['default']['woocommerce-ss-shipping-label']['title'])->toBe('Smart Send');
});

it('registers the meta box on the legacy shop_order screen when HPOS is disabled', function () {
    with_option('woocommerce_custom_orders_table_enabled', 'no');

    global $wp_meta_boxes;
    unset($wp_meta_boxes['shop_order']);

    SS_SHIPPING_WC()->meta_box()->add_smart_send_order_meta_box();

    expect($wp_meta_boxes['shop_order']['side']['default'])->toHaveKey('woocommerce-ss-shipping-label');
});

it('renders the not-yet-booked form with the state inlined as JSON', function () {
    $order = create_meta_box_order(['auto_return' => 'yes']);
    save_order_pickup_point($order->get_id(), sample_agent());

    $html = render_meta_box($order);

    expect($html)->toContain('<fieldset id="smart-send-fulfillment"')
        ->toContain('data-ss-state="ready"')
        ->toContain('data-ss-order-id="' . $order->get_id() . '"')
        // The sectioned layout: the shipping section (read values, an Edit
        // link per row), the parcels section, the actions, the grey settings.
        ->toContain('<div class="smart-send-fulfillment__section" data-ss-section="details">')
        ->toContain('<div class="smart-send-fulfillment__section smart-send-fulfillment__row" data-ss-section="parcel_plan">')
        ->toContain('<div class="smart-send-fulfillment__section smart-send-fulfillment__section--actions" data-ss-section="actions">')
        ->toContain('<div class="smart-send-fulfillment__section smart-send-fulfillment__section--settings" data-ss-section="settings">')
        // The shipping method as a read value with WooCommerce's help tip
        // (its markup; bound to tipTip by the app) and the Edit link - no
        // select until Edit (the app's).
        ->toContain('<span data-ss-value="shipping_method">PostNord: Select pickup point (MyPack Collect)</span>')
        ->toContain('<span class="woocommerce-help-tip" tabindex="0" aria-label="Shipping method used for booking of outgoing shipment" data-tip="Shipping method used for booking of outgoing shipment" data-ss-help=""></span>')
        ->not->toContain('smart-send-fulfillment__help')
        ->toContain('data-ss-action="edit-method"')
        ->not->toContain('data-ss-field="shipping_method"')
        // The pickup point row for an agent method: the pin, "#agent no
        // company" over the address, an Edit link.
        ->toContain('data-ss-section="pickup_point"')
        ->toContain('<svg class="smart-send-fulfillment__pin"')
        ->toContain('<span data-ss-value="pickup_point.agent_no">#1234</span> Corner Shop')
        ->toContain('<div class="smart-send-fulfillment__address"><span>Main Street 1</span><span>2300 Copenhagen</span></div>')
        ->toContain('data-ss-action="edit-pickup-point"')
        ->not->toContain('data-ss-field="pickup_point.agent_no"')
        // The return method row: the configured method, its help tip, Edit,
        // no select.
        ->toContain('<span data-ss-value="return_method">PostNord: Return from pickup point (Return Drop Off)</span>')
        ->toContain('<span class="woocommerce-help-tip" tabindex="0" aria-label="Shipping method used for booking of return shipments" data-tip="Shipping method used for booking of return shipments" data-ss-help=""></span>')
        ->toContain('data-ss-action="edit-return-method"')
        ->not->toContain('data-ss-field="return_method"')
        ->not->toContain('No return method configured')
        // The parcels section collapsed to its summary line with Edit.
        ->toContain('<span class="smart-send-fulfillment__summary" data-ss-value="parcel_plan.summary">1 parcel · 1.00 kg</span>')
        ->toContain('data-ss-action="edit-parcels"')
        ->not->toContain('data-ss-section="parcel_editor"')
        ->not->toContain('Weight: 1.00 kg')
        // The return checkbox defaults from the auto-return setting, with
        // its help tip next to the label (outside it).
        ->toContain('name="smart_send[with_return]" value="1" data-ss-field="with_return" autocomplete="off" checked=\'checked\'>')
        ->toContain('Also create return label</span></label>' . '<span class="woocommerce-help-tip" tabindex="0" aria-label="When booking an outgoing label, then we will automatically also book a return label" data-tip="When booking an outgoing label, then we will automatically also book a return label" data-ss-help=""></span>')
        ->not->toContain('Default from the shipping method settings')
        // Both actions, always: the primary "Create shipping label" over the
        // secondary "Create return label" (a return leg on its own).
        ->toContain('class="button button-primary smart-send-fulfillment__action" name="smart_send[flow]" value="outbound" data-ss-action="create-label">Create shipping label</button>')
        ->toContain('class="button smart-send-fulfillment__action" name="smart_send[flow]" value="return" data-ss-action="create-return-label">Create return label</button>')
        // Rendered disabled: the app enables the fieldset once mounted
        // (submission is JS-only, #182) - nothing of the AJAX bridge is left.
        ->toContain('data-ss-order-id="' . $order->get_id() . '" disabled>')
        ->not->toContain('ss-shipping-label-button')
        ->not->toContain('ss_shipping_label_nonce')
        ->not->toContain('ss_shipping_box_no')
        // No state callout.
        ->not->toContain('data-ss-notice="no_method"')
        ->not->toContain('data-ss-notice="not_connected"');

    $state = inlined_meta_box_state();
    expect($state['order_id'])->toBe($order->get_id())
        ->and($state['delivery_details']['shipping_method'])->toBe('postnord_agent')
        ->and($state['delivery_details']['pickup_point']['agent_no'])->toBe('1234')
        ->and($state['return']['auto_default'])->toBeTrue()
        // toEqual: JSON decoding turns a whole-number float (1.0) into an int.
        ->and($state)->toEqual(SS_SHIPPING_WC()->fulfillment_presenter()->state(wc_get_order($order->get_id())));

    expect(wp_script_is('ss-shipping-label-js', 'enqueued'))->toBeFalse();
});

it('enqueues the built app from the render callback only, with every dependency registered on this WordPress', function () {
    $order = create_meta_box_order();

    expect(wp_script_is(SS_Shipping_Order_Meta_Box::STATE_SCRIPT_HANDLE, 'enqueued'))->toBeFalse()
        ->and(wp_style_is(SS_Shipping_Order_Meta_Box::STYLE_HANDLE, 'enqueued'))->toBeFalse();

    render_meta_box($order);

    $asset = require SS_SHIPPING_PLUGIN_DIR_PATH . '/build/order-fulfillment/index.asset.php';
    $script = wp_scripts()->query(SS_Shipping_Order_Meta_Box::STATE_SCRIPT_HANDLE);

    expect(wp_script_is(SS_Shipping_Order_Meta_Box::STATE_SCRIPT_HANDLE, 'enqueued'))->toBeTrue()
        ->and($script->src)->toEndWith('/build/order-fulfillment/index.js')
        ->and($script->ver)->toBe($asset['version'])
        ->and($script->deps)->toBe($asset['dependencies'])
        ->and($asset['dependencies'])->toContain('wp-api-fetch', 'wp-components', 'wp-element', 'wp-i18n', 'wp-url')
        // The classic JSX runtime: no dependency on the react-jsx-runtime
        // handle WordPress registers only from 6.6 (#183).
        ->and($asset['dependencies'])->not->toContain('react-jsx-runtime')
        ->and($script->textdomain)->toBe('smart-send-logistics');

    foreach ($asset['dependencies'] as $dependency) {
        expect(wp_script_is($dependency, 'registered'))->toBeTrue("Script dependency {$dependency} is not registered on this WordPress");
    }

    expect(wp_style_is(SS_Shipping_Order_Meta_Box::STYLE_HANDLE, 'enqueued'))->toBeTrue()
        ->and(wp_styles()->query(SS_Shipping_Order_Meta_Box::STYLE_HANDLE)->src)->toEndWith('/build/order-fulfillment/style-index.css')
        ->and(wp_style_is('wp-components', 'enqueued'))->toBeTrue();

    // The inline state rides before the bundle.
    expect(inlined_meta_box_state()['order_id'])->toBe($order->get_id());
});

it('reads "None" with an Edit link for the return method, and keeps the return checkbox usable, when no return method is configured', function () {
    $order = create_meta_box_order(['return_method' => '']);

    $html = render_meta_box($order);

    // Collapsed like every other row: "None" + Edit, the select (serving
    // both the combined run and the return-only action) is the app's - no
    // select, no hint on the first paint.
    expect($html)->toContain('data-ss-field="with_return" autocomplete="off">')
        ->not->toContain('disabled=\'disabled\'')
        ->toContain('data-ss-section="return_method"')
        ->toContain('<span class="smart-send-fulfillment__none" data-ss-value="return_method">None</span>')
        ->toContain('data-ss-action="edit-return-method"')
        ->not->toContain('data-ss-field="return_method"')
        ->not->toContain('No return method configured')
        ->toContain('data-ss-action="create-label"')
        ->toContain('data-ss-action="create-return-label"');
});

it('renders the not-connected callout over the read-only sections, without Edit links, when no API token is configured', function () {
    with_ss_settings(['api_token' => '']);
    $order = create_meta_box_order();

    $html = render_meta_box($order);

    expect($html)->toContain('data-ss-state="not_connected"')
        ->toContain('<fieldset id="smart-send-fulfillment"')
        ->toContain(' disabled>')
        ->toContain('data-ss-notice="not_connected"')
        ->toContain('Smart Send is not connected.')
        ->toContain('data-ss-action="open-settings"')
        ->toContain('section=smart_send_shipping')
        // The callout sits in the first section's padding, over the rows.
        ->toContain('data-ss-notice="not_connected"><p>Smart Send is not connected.')
        ->toContain('<span data-ss-value="shipping_method">PostNord: Select pickup point (MyPack Collect)</span>')
        ->toContain('data-ss-value="parcel_plan.summary">1 parcel · 1.00 kg</span>')
        ->toContain('data-ss-action="create-label"')
        // Read-only: nothing to edit while not connected.
        ->not->toContain('data-ss-action="edit-');

    expect(inlined_meta_box_state()['connected'])->toBeFalse();
});

it('reads "None" with an Edit link for an order without a Smart Send shipping method', function () {
    $product = create_simple_product(['price' => 100, 'weight' => 1]);
    $order   = create_order(['products' => [$product]]);

    $html = render_meta_box($order);

    expect($html)->toContain('data-ss-state="no_method"')
        ->toContain('data-ss-notice="no_method"')
        ->toContain('Shipping method is not from the Smart Send plugin.')
        // The callout is inside the first section, over the method row,
        // which reads "None" with its Edit link (the select is the app's).
        ->toContain('data-ss-section="details"><div class="notice notice-info inline smart-send-fulfillment__notice" data-ss-notice="no_method">')
        ->toContain('<span class="smart-send-fulfillment__none" data-ss-value="shipping_method">None</span>')
        ->toContain('data-ss-action="edit-method"')
        ->not->toContain('data-ss-field="shipping_method"')
        // No method, so no pickup point row; no return method either, so
        // that row reads "None" with its Edit link too (the select is the app's).
        ->not->toContain('data-ss-section="pickup_point"')
        ->toContain('<span class="smart-send-fulfillment__none" data-ss-value="return_method">None</span>')
        ->toContain('data-ss-action="edit-return-method"')
        ->not->toContain('data-ss-field="return_method"')
        ->not->toContain('No return method configured')
        // Both actions, enabled, each explaining its missing method in its
        // title (the app shows the same sentence on click, no request sent).
        ->toContain('class="button button-primary smart-send-fulfillment__action" name="smart_send[flow]" value="outbound" data-ss-action="create-label" title="Select a shipping method first">')
        ->toContain('class="button smart-send-fulfillment__action" name="smart_send[flow]" value="return" data-ss-action="create-return-label" title="Select a return shipping method first">')
        ->not->toContain('disabled=\'disabled\'');

    expect(inlined_meta_box_state()['delivery_details']['shipping_method'])->toBeNull();
});

it('keeps the whole form open once a label is booked and renders the timeline, never a green result box', function () {
    $order = create_meta_box_order();
    SS_SHIPPING_WC()->shipment_ids()->save($order, 'shipment-new', false, '2026-09-16T11:25:01+00:00');

    $when = SS_SHIPPING_WC()->fulfillment_presenter()->format_booked_at('2026-09-16T11:25:01+00:00');
    $html = render_meta_box($order);

    expect($html)->toContain('data-ss-state="booked"')
        // The form NEVER closes (#182 review, 2026-09-16): the rows, the
        // parcels section, both actions and the return checkbox are exactly
        // what they are before booking.
        ->toContain('data-ss-section="shipping_method"')
        ->toContain('data-ss-section="return_method"')
        ->toContain('data-ss-section="parcel_plan"')
        ->toContain('data-ss-action="edit-method"')
        ->toContain('data-ss-action="edit-return-method"')
        ->toContain('data-ss-action="edit-parcels"')
        ->toContain('data-ss-field="with_return"')
        ->toContain('data-ss-action="create-label"')
        ->toContain('data-ss-action="create-return-label"')
        // The green result box is the client's memory of the run it just
        // made - the server never renders one - and the closed booked box
        // of the previous round is gone with it.
        ->not->toContain('smart-send-fulfillment__result')
        ->not->toContain('Documents and tracking are in the order notes.')
        ->not->toContain('data-ss-action="reset"')
        ->not->toContain('data-ss-notice="booked"')
        ->not->toContain('Book again')
        // The "Booked shipments" timeline, from the order's labels list:
        // the entry links into the Smart Send app and carries its time.
        ->toContain('data-ss-section="timeline"')
        ->toContain('Booked shipments')
        ->toContain('<a class="smart-send-fulfillment__timeline-row" href="https://app.smartsend.io/shipments/shipment-new" target="_blank" rel="noopener noreferrer" data-ss-timeline="outbound" data-ss-shipment-id="shipment-new">')
        ->toContain('<span class="smart-send-fulfillment__timeline-when">' . $when . '</span>');

    // A second, return label: both entries, newest first.
    SS_SHIPPING_WC()->shipment_ids()->save($order, 'return-new', true, '2026-09-16T11:25:04+00:00');
    $html = render_meta_box($order);

    expect(strpos($html, 'data-ss-shipment-id="return-new"'))
        ->toBeLessThan(strpos($html, 'data-ss-shipment-id="shipment-new"'));

    // (The box was rendered twice above, so read the state from the
    // presenter rather than the inlined script.)
    $state = SS_SHIPPING_WC()->fulfillment_presenter()->state(wc_get_order($order->get_id()));
    expect($state['outbound_shipment'])->toBe([
        'shipment_id' => 'shipment-new',
        'app_url'     => 'https://app.smartsend.io/shipments/shipment-new',
    ])
        ->and($state['timeline'])->toBe([
            [
                'direction'         => 'return',
                'shipment_id'       => 'return-new',
                'app_url'           => 'https://app.smartsend.io/shipments/return-new',
                'booked_at'         => '2026-09-16T11:25:04+00:00',
                'booked_at_display' => SS_SHIPPING_WC()->fulfillment_presenter()->format_booked_at('2026-09-16T11:25:04+00:00'),
            ],
            [
                'direction'         => 'outbound',
                'shipment_id'       => 'shipment-new',
                'app_url'           => 'https://app.smartsend.io/shipments/shipment-new',
                'booked_at'         => '2026-09-16T11:25:01+00:00',
                'booked_at_display' => $when,
            ],
        ]);
});

it('falls back to the frozen shipment ids, without a time, for an order booked before the labels list existed', function () {
    $order = create_meta_box_order();

    // An order from before this round: the two frozen id keys only, no
    // labels list and no timestamps anywhere.
    $order->update_meta_data('_ss_shipping_label_id', 'legacy-shipment');
    $order->update_meta_data('_ss_shipping_return_label_id', 'legacy-return');
    $order->save();

    $timeline = SS_SHIPPING_WC()->fulfillment_presenter()->timeline(wc_get_order($order->get_id()));

    expect($timeline)->toBe([
        [
            'direction'         => 'outbound',
            'shipment_id'       => 'legacy-shipment',
            'app_url'           => 'https://app.smartsend.io/shipments/legacy-shipment',
            'booked_at'         => null,
            'booked_at_display' => null,
        ],
        [
            'direction'         => 'return',
            'shipment_id'       => 'legacy-return',
            'app_url'           => 'https://app.smartsend.io/shipments/legacy-return',
            'booked_at'         => null,
            'booked_at_display' => null,
        ],
    ]);

    // Rendered as links with no time line under them.
    expect(render_meta_box($order))
        ->toContain('data-ss-shipment-id="legacy-shipment"')
        ->toContain('data-ss-shipment-id="legacy-return"')
        ->not->toContain('smart-send-fulfillment__timeline-when');

    // Booking again adds the new label to the list; the legacy ids the list
    // does not carry keep their place at the end.
    SS_SHIPPING_WC()->shipment_ids()->save($order, 'shipment-after', false, '2026-09-16T12:00:00+00:00');

    expect(array_column(SS_SHIPPING_WC()->fulfillment_presenter()->timeline(wc_get_order($order->get_id())), 'shipment_id'))
        ->toBe(['shipment-after', 'legacy-return']);
});

it('renders no timeline section for an order that has never been booked', function () {
    expect(render_meta_box(create_meta_box_order()))
        ->not->toContain('data-ss-section="timeline"')
        ->not->toContain('Booked shipments');
});

it('builds the link to the shipment in the Smart Send app from the filtered API host', function () {
    $presenter = SS_SHIPPING_WC()->fulfillment_presenter();

    expect($presenter->app_url('shipment-old'))->toBe('https://app.smartsend.io/shipments/shipment-old')
        ->and($presenter->app_url(''))->toBe('');

    $sandbox = fn () => 'https://app.smartsend.dev';
    add_filter('smart_send_api_endpoint', $sandbox);

    try {
        expect($presenter->app_url('shipment-old'))->toBe('https://app.smartsend.dev/shipments/shipment-old');
    } finally {
        remove_filter('smart_send_api_endpoint', $sandbox);
    }
});

it('enriches a booked shipment for the response with the app link and the parcel display strings', function () {
    $shipment = new SS_Shipping_Booked_Shipment('shipment-resp');
    $shipment->add_parcel(new SS_Shipping_Booked_Parcel('1', 'TRACK-1', 'https://tracking.example.test/1', 2.0, null, null, null, '4711'));
    $shipment->add_parcel(new SS_Shipping_Booked_Parcel('2', 'TRACK-2', null, 2.5, 40.0, 30.0, 20.0, '4711'));

    $enriched = SS_SHIPPING_WC()->fulfillment_presenter()->shipment_response($shipment->to_array());

    expect($enriched['app_url'])->toBe('https://app.smartsend.io/shipments/shipment-resp')
        // The store's units (kg / cm by default), dimensions only when all
        // three are there.
        ->and($enriched['parcels'][0]['weight_display'])->toBe('2 kg')
        ->and($enriched['parcels'][0]['dimensions_display'])->toBeNull()
        ->and($enriched['parcels'][0]['reference'])->toBe('4711')
        ->and($enriched['parcels'][1]['weight_display'])->toBe('2.5 kg')
        ->and($enriched['parcels'][1]['dimensions_display'])->toBe('40 × 30 × 20 cm');
});

it('renders the stored parcel split as the collapsed parcels summary, an explicit box weight winning over the computed one', function () {
    $product_a = create_simple_product(['name' => 'Split A', 'price' => 100, 'weight' => 1]);
    $product_b = create_simple_product(['name' => 'Split B', 'price' => 50, 'weight' => 2]);
    $order     = create_order([
        'products'        => [[$product_a, 2], $product_b],
        'shipping_method' => 'postnord_agent',
    ]);
    save_order_parcels($order->get_id(), [
        ['id' => $product_a->get_id(), 'name' => 'Split A', 'value' => '1'],
        ['id' => $product_a->get_id(), 'name' => 'Split A', 'value' => '2'],
        ['id' => $product_b->get_id(), 'name' => 'Split B', 'value' => '2'],
    ]);

    $html = render_meta_box($order);

    // Two boxes: 1.00 kg (one unit of A) and 3.00 kg (A + B) - the same
    // figure the app's totalWeight() computes, so nothing jumps on mount.
    expect($html)->toContain('data-ss-value="parcel_plan.summary">2 parcels · 4.00 kg</span>')
        ->not->toContain('data-ss-section="parcel_editor"')
        ->not->toContain('parcel_plan.units[');

    $state = inlined_meta_box_state();
    expect($state['delivery_details']['parcel_plan']['specs'])->toHaveCount(2);

    // An explicit weight on a box replaces its computed one (the stored
    // item rows carry none; the state shape allows one).
    $state['delivery_details']['parcel_plan']['specs'][1]['weight'] = 2.5;
    expect(SS_SHIPPING_WC()->fulfillment_presenter()->parcel_summary($state))->toBe('2 parcels · 3.50 kg');

    // No plan: everything in one parcel.
    $state['delivery_details']['parcel_plan'] = null;
    expect(SS_SHIPPING_WC()->fulfillment_presenter()->parcel_summary($state))->toBe('1 parcel · 4.00 kg');
});

it('escapes every value it renders, including the pickup point, and inlines a state that cannot close the script element', function () {
    $product = create_simple_product(['name' => 'Evil <script>alert(1)</script> "Hoodie"', 'price' => 100, 'weight' => 1]);
    $order   = create_order(['products' => [$product], 'shipping_method' => 'postnord_agent']);
    save_order_pickup_point($order->get_id(), sample_agent(['company' => 'Shop <img src=x onerror=alert(1)>']));

    $html = render_meta_box($order);

    expect($html)->not->toContain('<script>alert(1)</script>')
        ->not->toContain('<img src=x')
        ->toContain('Shop &lt;img src=x onerror=alert(1)&gt;');

    // The inline JSON cannot close the script element either.
    $inline = inlined_meta_box_script();
    expect($inline)->not->toContain('</script>')
        ->toContain('\\u003C/script\\u003E');
});
