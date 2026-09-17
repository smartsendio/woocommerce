<?php

/*
 * Tests for the order screen "Smart Send" meta box (#182): registration on
 * the legacy and the HPOS order screen, the mount point the box renders,
 * the state it inlines for the app (the shape the app renders every state
 * of section 1.2 from - not connected, no Smart Send method, not yet
 * booked, booked with its timeline) and the built React app
 * (build/order-fulfillment/) enqueued from the render callback only, with
 * every script dependency registered on the running WordPress (the WP 6.5
 * floor has no react-jsx-runtime handle, #183 - the app is built with the
 * classic JSX runtime).
 *
 * The box has ONE renderer and it is the app, so PHP's output is asserted
 * for what it must NOT contain (any order value) and the states are
 * asserted on the state object instead of on markup. What the merchant
 * actually sees is covered end to end by tests/Browser/OrderFulfillmentTest.php.
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
    $scripts = array_values(array_filter((array) wp_scripts()->get_data(\Smart_Send\Admin\Order_Meta_Box::STATE_SCRIPT_HANDLE, 'before'), 'is_string'));

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
    wp_scripts()->add_data(\Smart_Send\Admin\Order_Meta_Box::STATE_SCRIPT_HANDLE, 'before', []);
    wp_dequeue_script(\Smart_Send\Admin\Order_Meta_Box::STATE_SCRIPT_HANDLE);
    wp_dequeue_style(\Smart_Send\Admin\Order_Meta_Box::STYLE_HANDLE);
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

it('renders a mount point with the state inlined as JSON, and no order data in the markup', function () {
    $order = create_meta_box_order(['auto_return' => 'yes']);
    save_order_pickup_point($order->get_id(), sample_agent());

    $html = render_meta_box($order);

    // A <fieldset>, not a <form>: the order screen already wraps every meta
    // box in one and the parser drops a nested start tag, which would leave
    // nothing to mount on. Rendered disabled, with the placeholder the
    // stylesheet keeps invisible for the first 200ms.
    expect($html)->toContain('<fieldset id="smart-send-fulfillment" class="smart-send-fulfillment__form" data-ss-state="loading" data-ss-app="loading" aria-busy="true" disabled>')
        ->toContain('<div class="smart-send-fulfillment__placeholder" aria-hidden="true">')
        ->toContain('<div class="smart-send-fulfillment__placeholder-row">')
        ->toContain('<div class="smart-send-fulfillment__placeholder-button">')
        // Not a second renderer: no order value, no section, no control and
        // nothing of the retired admin-ajax bridge is rendered in PHP.
        ->not->toContain('PostNord')
        ->not->toContain('Corner Shop')
        ->not->toContain('#1234')
        ->not->toContain('Create shipping label')
        ->not->toContain('data-ss-section=')
        ->not->toContain('data-ss-field=')
        ->not->toContain('data-ss-action=')
        ->not->toContain('data-ss-notice=')
        ->not->toContain('<select')
        ->not->toContain('<button')
        ->not->toContain('ss-shipping-label-button')
        ->not->toContain('ss_shipping_label_nonce')
        ->not->toContain('ss_shipping_box_no');

    // Everything the box shows comes from here.
    $state = inlined_meta_box_state();
    expect($state['order_id'])->toBe($order->get_id())
        ->and($state['connected'])->toBeTrue()
        ->and($state['delivery_details']['shipping_method'])->toBe('postnord_agent')
        ->and($state['delivery_details']['pickup_point']['agent_no'])->toBe('1234')
        ->and($state['delivery_details']['pickup_point']['display_html'])->toContain('Main Street 1')
        ->and($state['return']['method'])->toBe('postnord_returndropoff')
        ->and($state['return']['auto_default'])->toBeTrue()
        ->and($state['timeline'])->toBe([])
        // toEqual: JSON decoding turns a whole-number float (1.0) into an int.
        ->and($state)->toEqual(SS_SHIPPING_WC()->fulfillment_presenter()->state(wc_get_order($order->get_id())));

    expect(wp_script_is('ss-shipping-label-js', 'enqueued'))->toBeFalse();
});

it('enqueues the built app from the render callback only, with every dependency registered on this WordPress', function () {
    $order = create_meta_box_order();

    expect(wp_script_is(\Smart_Send\Admin\Order_Meta_Box::STATE_SCRIPT_HANDLE, 'enqueued'))->toBeFalse()
        ->and(wp_style_is(\Smart_Send\Admin\Order_Meta_Box::STYLE_HANDLE, 'enqueued'))->toBeFalse();

    render_meta_box($order);

    $asset = require SS_SHIPPING_PLUGIN_DIR_PATH . '/build/order-fulfillment/index.asset.php';
    $script = wp_scripts()->query(\Smart_Send\Admin\Order_Meta_Box::STATE_SCRIPT_HANDLE);

    expect(wp_script_is(\Smart_Send\Admin\Order_Meta_Box::STATE_SCRIPT_HANDLE, 'enqueued'))->toBeTrue()
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

    expect(wp_style_is(\Smart_Send\Admin\Order_Meta_Box::STYLE_HANDLE, 'enqueued'))->toBeTrue()
        ->and(wp_styles()->query(\Smart_Send\Admin\Order_Meta_Box::STYLE_HANDLE)->src)->toEndWith('/build/order-fulfillment/style-index.css')
        ->and(wp_style_is('wp-components', 'enqueued'))->toBeTrue();

    // The inline state rides before the bundle.
    expect(inlined_meta_box_state()['order_id'])->toBe($order->get_id());
});

it('reports no configured return method in the state, with the return list still offered', function () {
    $order = create_meta_box_order(['return_method' => '']);

    render_meta_box($order);

    $state = inlined_meta_box_state();

    // The app reads "None" from this and offers the select behind Edit -
    // a missing return method is not a dead end.
    expect($state['return']['method'])->toBeNull()
        ->and($state['return']['auto_default'])->toBeFalse()
        ->and($state['methods']['return'])->not->toBe([]);
});

it('reports the not-connected state when no API token is configured', function () {
    with_ss_settings(['api_token' => '']);
    $order = create_meta_box_order();

    $html = render_meta_box($order);

    // PHP does not decide what the box looks like in this state either: it
    // renders the same mount point and says "not connected" in the state.
    expect($html)->toContain('data-ss-state="loading"')
        ->not->toContain('not connected');

    $state = inlined_meta_box_state();

    expect($state['connected'])->toBeFalse()
        ->and($state['urls']['settings'])->toContain('section=smart_send_shipping');
});

it('reports an order without a Smart Send shipping method as bookable, with the methods to choose from', function () {
    $product = create_simple_product(['price' => 100, 'weight' => 1]);
    $order   = create_order(['products' => [$product]]);

    render_meta_box($order);

    $state = inlined_meta_box_state();

    // State B: no method on the order, but the drop-down has something to
    // offer, so the app can book it anyway.
    expect($state['delivery_details']['shipping_method'])->toBeNull()
        ->and($state['delivery_details']['pickup_point'])->toBeNull()
        ->and($state['methods']['outbound'])->not->toBe([])
        ->and($state['methods']['return'])->not->toBe([]);
});

it('carries the booked shipments in the state, newest first, once labels are booked', function () {
    $order = create_meta_box_order();
    SS_SHIPPING_WC()->shipment_ids()->save($order, 'shipment-new', false, '2026-09-16T11:25:01+00:00');

    $when = SS_SHIPPING_WC()->fulfillment_presenter()->format_booked_at('2026-09-16T11:25:01+00:00');

    // A second, return label: both entries, newest first.
    SS_SHIPPING_WC()->shipment_ids()->save($order, 'return-new', true, '2026-09-16T11:25:04+00:00');

    $html = render_meta_box($order);

    // A booked order renders the same mount point as any other: the app
    // decides that the form stays open and the timeline is shown.
    expect($html)->not->toContain('shipment-new')
        ->not->toContain('Booked shipments');

    $state = inlined_meta_box_state();

    expect($state['outbound_shipment'])->toBe([
        'shipment_id' => 'shipment-new',
        'app_url'     => 'https://app.smartsend.io/shipments/shipment-new',
    ])
        ->and($state['return_shipment']['shipment_id'])->toBe('return-new')
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

    // The app renders such an entry as a link with no time under it.
    expect(array_column($timeline, 'booked_at_display'))->toBe([null, null]);

    // Booking again adds the new label to the list; the legacy ids the list
    // does not carry keep their place at the end.
    SS_SHIPPING_WC()->shipment_ids()->save($order, 'shipment-after', false, '2026-09-16T12:00:00+00:00');

    expect(array_column(SS_SHIPPING_WC()->fulfillment_presenter()->timeline(wc_get_order($order->get_id())), 'shipment_id'))
        ->toBe(['shipment-after', 'legacy-return']);
});

it('carries an empty timeline for an order that has never been booked', function () {
    render_meta_box(create_meta_box_order());

    $state = inlined_meta_box_state();

    expect($state['timeline'])->toBe([])
        ->and($state['outbound_shipment'])->toBeNull()
        ->and($state['return_shipment'])->toBeNull();
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
    $shipment = new \Smart_Send\Booking\Booked_Shipment('shipment-resp');
    $shipment->add_parcel(new \Smart_Send\Booking\Booked_Parcel('1', 'TRACK-1', 'https://tracking.example.test/1', 2.0, null, null, null, '4711'));
    $shipment->add_parcel(new \Smart_Send\Booking\Booked_Parcel('2', 'TRACK-2', null, 2.5, 40.0, 30.0, 20.0, '4711'));

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

it('carries the stored parcel split in the state, one spec per box', function () {
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

    render_meta_box($order);

    $specs = inlined_meta_box_state()['delivery_details']['parcel_plan']['specs'];

    // Two boxes: one unit of A, then the second unit of A with B. The
    // summary the merchant reads ("2 parcels · 4.00 kg") is the app's
    // totalWeight() over exactly this - computed once, in one place.
    expect($specs)->toHaveCount(2)
        ->and($specs[0]['items'])->toHaveCount(1)
        ->and($specs[0]['items'][0]['id'])->toBe($product_a->get_id())
        ->and($specs[0]['weight'])->toBeNull()
        ->and(array_column($specs[1]['items'], 'id'))->toBe([$product_a->get_id(), $product_b->get_id()]);
});

it('renders no order value at all, and inlines a state that cannot close the script element', function () {
    $product = create_simple_product(['name' => 'Evil <script>alert(1)</script> "Hoodie"', 'price' => 100, 'weight' => 1]);
    $order   = create_order(['products' => [$product], 'shipping_method' => 'postnord_agent']);
    save_order_pickup_point($order->get_id(), sample_agent(['company' => 'Shop <img src=x onerror=alert(1)>']));

    $html = render_meta_box($order);

    // The strongest form of output escaping: the markup carries no order
    // value in the first place, escaped or otherwise.
    expect($html)->not->toContain('<script>alert(1)</script>')
        ->not->toContain('<img src=x')
        ->not->toContain('Hoodie')
        ->not->toContain('Shop');

    // The inline JSON cannot close the script element either.
    $inline = inlined_meta_box_script();
    expect($inline)->not->toContain('</script>')
        ->toContain('\\u003C/script\\u003E');
});
