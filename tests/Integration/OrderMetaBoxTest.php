<?php

/*
 * Tests for the order screen "Smart Send" meta box (#182,
 * SS_Shipping_Order_Meta_Box + SS_Shipping_Order_Fulfillment_Presenter::render_form()):
 * registration on the legacy and the HPOS order screen, the server-rendered
 * first paint per state of section 1.2 of the issue - not connected, no
 * Smart Send method (method select offered), not yet booked, booked from a
 * stored shipment id - with the state inlined as JSON and every value
 * escaped, and the built React app (build/order-fulfillment/) enqueued
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
        // The shipping method select, pre-selected with the order's method.
        ->toContain('name="smart_send[delivery_details][shipping_method]"')
        ->toContain('<option value="postnord_agent" selected=\'selected\'>')
        ->toContain('<optgroup label="GLS">')
        ->toContain('Weight: 1.00 kg')
        // The pickup point row for an agent method: stored point + override input.
        ->toContain('data-ss-section="pickup_point"')
        ->toContain('Agent No.: 1234')
        ->toContain('Corner Shop')
        ->toContain('name="smart_send[delivery_details][pickup_point][agent_no]" value="1234"')
        // The parcels row: one box select per unit.
        ->toContain('data-ss-section="parcel_plan"')
        ->toContain('name="smart_send[delivery_details][parcel_plan][split]"')
        ->toContain('data-ss-field="parcel_plan.units[0].box"')
        // The return checkbox defaults from the auto-return setting and names the return method.
        ->toContain('name="smart_send[with_return]" value="1" data-ss-field="with_return" autocomplete="off" checked=\'checked\'>')
        ->toContain('PostNord: Return from pickup point (Return Drop Off)')
        ->not->toContain('data-ss-hint="no_return_method"')
        // Both actions, always: the primary "Create shipping label" and the
        // secondary "Create return label" (a return leg on its own).
        ->toContain('class="button button-primary" name="smart_send[flow]" value="outbound" data-ss-action="create-label"')
        ->toContain('DEMO MODE: Create shipping label')
        ->toContain('class="button" name="smart_send[flow]" value="return" data-ss-action="create-return-label"')
        ->toContain('DEMO MODE: Create return label')
        // Rendered disabled: the app enables the fieldset once mounted
        // (submission is JS-only, #182) - nothing of the AJAX bridge is left.
        ->toContain('data-ss-order-id="' . $order->get_id() . '" disabled>')
        ->not->toContain('ss-shipping-label-button')
        ->not->toContain('ss_shipping_label_nonce')
        ->not->toContain('ss_shipping_box_no')
        ->not->toContain('data-ss-notice=');

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

it('keeps the return checkbox usable and offers a return method select when no return method is configured', function () {
    $order = create_meta_box_order(['return_method' => '']);

    $html = render_meta_box($order);

    // The select serves both the combined run and the return-only action,
    // so it is rendered right away (not behind the checkbox).
    expect($html)->toContain('data-ss-field="with_return" autocomplete="off">')
        ->not->toContain('disabled=\'disabled\'')
        ->toContain('data-ss-hint="no_return_method"')
        ->toContain('No return method configured on the shipping method - choose one here')
        ->toContain('name="smart_send[return_method]" data-ss-field="return_method"')
        ->toContain('<option value="postnord_returndropoff">')
        ->toContain('data-ss-action="create-label"')
        ->toContain('data-ss-action="create-return-label"');
});

it('renders the not-connected notice with the form disabled when no API token is configured and demo mode is off', function () {
    with_ss_settings(['api_token' => '', 'demo' => 'no']);
    $order = create_meta_box_order();

    $html = render_meta_box($order);

    expect($html)->toContain('data-ss-state="not_connected"')
        ->toContain('<fieldset id="smart-send-fulfillment"')
        ->toContain(' disabled>')
        ->toContain('data-ss-notice="not_connected"')
        ->toContain('Smart Send is not connected.')
        ->toContain('data-ss-action="open-settings"')
        ->toContain('section=smart_send_shipping')
        ->not->toContain('data-ss-action="create-label"');

    expect(inlined_meta_box_state()['connected'])->toBeFalse();
});

it('offers the method select for an order without a Smart Send shipping method', function () {
    $product = create_simple_product(['price' => 100, 'weight' => 1]);
    $order   = create_order(['products' => [$product]]);

    $html = render_meta_box($order);

    expect($html)->toContain('data-ss-state="no_method"')
        ->toContain('data-ss-notice="no_method"')
        ->toContain('This order has no Smart Send shipping method. Choose the method to ship it with.')
        ->toContain('name="smart_send[delivery_details][shipping_method]"')
        ->toContain('<option value="" selected=\'selected\'>Select a method…</option>')
        // No method, so no pickup point row; no return method either, so the
        // return method select (for both actions) is rendered.
        ->not->toContain('data-ss-section="pickup_point"')
        ->toContain('data-ss-hint="no_return_method"')
        ->toContain('name="smart_send[return_method]" data-ss-field="return_method"')
        // Both actions: the primary outbound one and the secondary return one.
        ->toContain('class="button button-primary" name="smart_send[flow]" value="outbound" data-ss-action="create-label"')
        ->toContain('class="button" name="smart_send[flow]" value="return" data-ss-action="create-return-label"');

    expect(inlined_meta_box_state()['delivery_details']['shipping_method'])->toBeNull();
});

it('renders the booked block from a stored shipment id with the book-again disclosure and the return action', function () {
    $order = create_meta_box_order();
    SS_SHIPPING_WC()->shipment_ids()->save($order, 'shipment-old', false);

    $html = render_meta_box($order);

    expect($html)->toContain('data-ss-state="booked"')
        ->toContain('data-ss-section="outbound_shipment"')
        ->toContain('<span data-ss-value="outbound_shipment.shipment_id">shipment-old</span>')
        ->toContain('Documents and tracking are in the order notes.')
        ->toContain('data-ss-section="rebook"')
        ->toContain('A shipping label already exists for this order.')
        ->toContain('name="smart_send[confirm_rebook]" value="1"')
        ->toContain('data-ss-action="create-label"')
        // No return yet: the separate action.
        ->toContain('data-ss-section="return_shipment"')
        ->toContain('not created')
        ->toContain('data-ss-action="create-return-label"')
        ->not->toContain('data-ss-section="rebook_return"');

    expect(inlined_meta_box_state()['outbound_shipment'])->toBe(['shipment_id' => 'shipment-old', 'legacy' => true]);

    // Both booked: both blocks, each with its own disclosure.
    SS_SHIPPING_WC()->shipment_ids()->save($order, 'return-old', true);
    $html = render_meta_box($order);

    expect($html)->toContain('<span data-ss-value="return_shipment.shipment_id">return-old</span>')
        ->toContain('data-ss-section="rebook_return"')
        ->toContain('A return label already exists for this order.')
        ->not->toContain('not created');
});

it('renders the stored parcel split into the per-unit box selects', function () {
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

    expect($html)->toContain('data-ss-field="parcel_plan.split" autocomplete="off" checked=\'checked\'>')
        ->toContain('<div class="smart-send-fulfillment__units"><table');

    preg_match_all('/data-ss-field="parcel_plan\.units\[\d\]\.box" autocomplete="off">(.*?)<\/select>/s', $html, $selects);
    expect($selects[1])->toHaveCount(3)
        ->and($selects[1][0])->toContain('<option value="1" selected=\'selected\'>')
        ->and($selects[1][1])->toContain('<option value="2" selected=\'selected\'>')
        ->and($selects[1][2])->toContain('<option value="2" selected=\'selected\'>');

    expect(inlined_meta_box_state()['delivery_details']['parcel_plan']['specs'])->toHaveCount(2);
});

it('escapes every value it renders, including product names and the pickup point', function () {
    $product = create_simple_product(['name' => 'Evil <script>alert(1)</script> "Hoodie"', 'price' => 100, 'weight' => 1]);
    $order   = create_order(['products' => [$product], 'shipping_method' => 'postnord_agent']);
    save_order_pickup_point($order->get_id(), sample_agent(['company' => 'Shop <img src=x onerror=alert(1)>']));

    $html = render_meta_box($order);

    expect($html)->not->toContain('<script>alert(1)</script>')
        ->toContain('Evil &lt;script&gt;alert(1)&lt;/script&gt; &quot;Hoodie&quot;')
        ->not->toContain('onerror=')
        ->toContain('Shop <img src="x">');

    // The inline JSON cannot close the script element either.
    $inline = inlined_meta_box_script();
    expect($inline)->not->toContain('</script>')
        ->toContain('\\u003C/script\\u003E');
});
