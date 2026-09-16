<?php

/*
 * Tests for the public smart_send_fulfillment_shipping_methods filter
 * (#182): the shipping methods the order screen's "Smart Send" box offers
 * in its method drop-downs, as carriers => services => addons, filtered
 * once per list (outbound and return) with the WC_Order and the direction.
 *
 * The filter is a UI narrowing only - the REST controller does not
 * validate a submitted method against it (pinned below).
 */

/**
 * Replace the logger's WC_Logger with a spy that records every entry.
 */
function spy_on_logger_for_methods_filter(): object
{
    $spy = new class {
        public array $entries = [];

        public function log($level, $message, $context = []): void
        {
            $this->entries[] = ['level' => $level, 'message' => $message, 'context' => $context];
        }

        public function __call(string $level, array $args): void
        {
            $this->log($level, $args[0], $args[1] ?? []);
        }
    };

    SS_Shipping_Logger::$logger = $spy;

    remember_cleanup_callback(function (): void {
        SS_Shipping_Logger::$logger = null;
    });

    return $spy;
}

/**
 * Add a smart_send_fulfillment_shipping_methods callback, removed after
 * the test. Every call it receives is recorded in the returned array.
 */
function with_methods_filter(callable $callback): array
{
    $calls = new ArrayObject();

    $wrapped = function ($carriers, $order, $is_return) use ($callback, $calls) {
        $calls[] = ['carriers' => $carriers, 'order' => $order, 'is_return' => $is_return];

        return $callback($carriers, $order, $is_return);
    };

    add_filter('smart_send_fulfillment_shipping_methods', $wrapped, 10, 3);

    remember_cleanup_callback(function () use ($wrapped): void {
        remove_filter('smart_send_fulfillment_shipping_methods', $wrapped, 10);
    });

    return [$calls];
}

/**
 * An order with a Smart Send shipping method, like the meta box sees it.
 */
function create_methods_filter_order(array $args = []): WC_Order
{
    $product = create_simple_product(['price' => 100, 'weight' => 1]);

    return create_order(array_merge([
        'products'        => [$product],
        'shipping_method' => 'postnord_agent',
        'shipping_total'  => '39',
    ], $args));
}

/**
 * The meta box state of an order.
 */
function methods_filter_state(WC_Order $order): array
{
    return SS_SHIPPING_WC()->fulfillment_presenter()->state(wc_get_order($order->get_id()));
}

/**
 * The carrier row of a carriers list, or null.
 */
function methods_filter_carrier(array $carriers, string $code): ?array
{
    foreach ($carriers as $carrier) {
        if ($carrier['code'] === $code) {
            return $carrier;
        }
    }

    return null;
}

/**
 * The service codes a carriers list offers for a carrier.
 */
function methods_filter_services(array $carriers, string $carrier_code): array
{
    $carrier = methods_filter_carrier($carriers, $carrier_code);

    return $carrier === null ? [] : array_column($carrier['services'], 'code');
}

beforeEach(function (): void {
    with_ss_settings();
});

it('offers the catalogue as carriers with services and reserved addons', function () {
    $state = methods_filter_state(create_methods_filter_order());

    $outbound = $state['methods']['outbound'];
    $postnord = methods_filter_carrier($outbound, 'postnord');

    expect($postnord['code'])->toBe('postnord')
        ->and($postnord['name'])->toBe('PostNord')
        ->and($postnord['services'][0])->toBe([
            'code'   => 'agent',
            'name'   => 'PostNord: Select pickup point (MyPack Collect)',
            'addons' => [],
        ])
        // Every service of every carrier, on both lists, carries the
        // reserved (always empty) addons key.
        ->and(methods_filter_carrier($outbound, 'gls'))->not->toBeNull()
        ->and(methods_filter_carrier($state['methods']['return'], 'postnord')['services'])
        ->toContain(['code' => 'returndropoff', 'name' => 'PostNord: Return from pickup point (Return Drop Off)', 'addons' => []]);

    foreach (['outbound', 'return'] as $direction) {
        foreach ($state['methods'][$direction] as $carrier) {
            expect($carrier['services'])->not->toBeEmpty();

            foreach ($carrier['services'] as $service) {
                expect($service)->toHaveKeys(['code', 'name', 'addons'])
                    ->and($service['addons'])->toBe([])
                    // '<carrier code>_<service code>' is the code booked with.
                    ->and(SS_SHIPPING_WC()->fulfillment_presenter()->method_name($carrier['code'] . '_' . $service['code']))
                    ->toBe($service['name']);
            }
        }
    }
});

it('fires once per list with the order and the direction', function () {
    $order = create_methods_filter_order();

    [$calls] = with_methods_filter(fn ($carriers) => $carriers);

    $state = methods_filter_state($order);

    expect($calls)->toHaveCount(2)
        ->and($calls[0]['is_return'])->toBeFalse()
        ->and($calls[1]['is_return'])->toBeTrue();

    foreach ((array) $calls as $call) {
        expect($call['carriers'])->toBeArray()
            ->and($call['order'])->toBeInstanceOf(WC_Order::class)
            ->and($call['order']->get_id())->toBe($order->get_id())
            ->and($call['is_return'])->toBeBool()
            ->and($call['carriers'][0])->toHaveKeys(['code', 'name', 'services']);
    }

    // The outbound list is filtered with the outbound catalogue, the return
    // list with the return catalogue.
    expect(array_column($calls[0]['carriers'], 'code'))->toContain('budbee')
        ->and(array_column($calls[1]['carriers'], 'code'))->not->toContain('budbee')
        ->and($state['methods']['outbound'])->toBe($calls[0]['carriers']);
});

it('restricts the outbound and the return drop-down differently', function () {
    $order = create_methods_filter_order(['return_method' => 'postnord_returndropoff']);

    with_methods_filter(function (array $carriers, WC_Order $order, bool $is_return): array {
        $keep = $is_return ? 'gls' : 'postnord';

        return array_values(array_filter($carriers, fn ($carrier) => $carrier['code'] === $keep));
    });

    $state = methods_filter_state($order);

    expect(array_column($state['methods']['outbound'], 'code'))->toBe(['postnord'])
        // The order's own return method survives (see below); GLS is what
        // the filter kept.
        ->and(array_column($state['methods']['return'], 'code'))->toBe(['gls', 'postnord']);
});

it('removes a single service from a carrier', function () {
    $order = create_methods_filter_order(['shipping_method' => 'postnord_homedelivery']);

    with_methods_filter(function (array $carriers, WC_Order $order, bool $is_return): array {
        foreach ($carriers as $index => $carrier) {
            if ($carrier['code'] !== 'postnord') {
                continue;
            }

            $carriers[$index]['services'] = array_values(array_filter(
                $carrier['services'],
                fn ($service) => $service['code'] !== 'collect'
            ));
        }

        return $carriers;
    });

    $services = methods_filter_services(methods_filter_state($order)['methods']['outbound'], 'postnord');

    expect($services)->not->toContain('collect')
        ->and($services)->toContain('agent')
        ->and($services)->toContain('homedelivery');
});

it('adds the order own method back when the filter removed its service, and logs it', function () {
    with_ss_settings(['ss_debug' => 'yes']);
    $spy   = spy_on_logger_for_methods_filter();
    $order = create_methods_filter_order(['shipping_method' => 'postnord_agent']);

    with_methods_filter(function (array $carriers, WC_Order $order, bool $is_return): array {
        foreach ($carriers as $index => $carrier) {
            if ($carrier['code'] === 'postnord') {
                $carriers[$index]['services'] = array_values(array_filter(
                    $carrier['services'],
                    fn ($service) => $service['code'] !== 'agent'
                ));
            }
        }

        return $carriers;
    });

    $outbound = methods_filter_state($order)['methods']['outbound'];
    $postnord = methods_filter_carrier($outbound, 'postnord');

    expect(array_column($postnord['services'], 'code'))->toContain('agent')
        ->and(end($postnord['services']))->toBe([
            'code'   => 'agent',
            'name'   => 'PostNord: Select pickup point (MyPack Collect)',
            'addons' => [],
        ]);

    $debug = array_values(array_filter(
        $spy->entries,
        fn ($entry) => $entry['level'] === 'debug' && strpos($entry['message'], 'smart_send_fulfillment_shipping_methods') !== false
    ));

    expect($debug)->toHaveCount(1)
        ->and($debug[0]['message'])->toContain('added back')
        ->and($debug[0]['context']['method'])->toBe('postnord_agent')
        ->and($debug[0]['context']['is_return'])->toBeFalse();
});

it('adds the order own carrier back when the filter removed it entirely', function () {
    with_ss_settings(['ss_debug' => 'yes']);
    $spy   = spy_on_logger_for_methods_filter();
    $order = create_methods_filter_order([
        'shipping_method' => 'gls_agent',
        'return_method'   => 'gls_returndropoff',
    ]);

    with_methods_filter(fn (array $carriers) => array_values(array_filter(
        $carriers,
        fn ($carrier) => $carrier['code'] !== 'gls'
    )));

    $state = methods_filter_state($order);

    expect(methods_filter_services($state['methods']['outbound'], 'gls'))->toBe(['agent'])
        ->and(methods_filter_carrier($state['methods']['outbound'], 'gls')['name'])->toBe('GLS')
        ->and(methods_filter_services($state['methods']['return'], 'gls'))->toBe(['returndropoff']);

    $debug = array_values(array_filter(
        $spy->entries,
        fn ($entry) => $entry['level'] === 'debug' && strpos($entry['message'], 'smart_send_fulfillment_shipping_methods') !== false
    ));

    expect($debug)->toHaveCount(2)
        ->and($debug[0]['context'])->toMatchArray(['method' => 'gls_agent', 'is_return' => false])
        ->and($debug[1]['context'])->toMatchArray(['method' => 'gls_returndropoff', 'is_return' => true]);
});

it('leaves the drop-down empty when a snippet returns an empty array', function () {
    // An order without a Smart Send method has nothing to add back.
    $order = create_methods_filter_order(['shipping_method' => null]);

    with_methods_filter(fn () => []);

    $state = methods_filter_state($order);

    expect($state['methods']['outbound'])->toBe([])
        ->and($state['methods']['return'])->toBe([])
        ->and($state['delivery_details']['shipping_method'])->toBeNull();

    // The first paint renders without a fatal and still reads "None".
    $html = SS_SHIPPING_WC()->fulfillment_presenter()->render_form($state);

    expect($html)->toContain('smart-send-fulfillment__none" data-ss-value="shipping_method"');
});

it('ignores a malformed filter return value', function () {
    $order = create_methods_filter_order();

    with_methods_filter(fn () => [
        'not a carrier',
        ['name' => 'No code'],
        ['code' => 'postnord', 'services' => 'not a list'],
        ['code' => 'gls', 'name' => '', 'services' => [
            ['code' => 'agent'],
            ['name' => 'no code'],
        ]],
    ]);

    $outbound = methods_filter_state($order)['methods']['outbound'];

    expect(array_column($outbound, 'code'))->toBe(['gls', 'postnord'])
        ->and(methods_filter_carrier($outbound, 'gls'))->toBe([
            'code'     => 'gls',
            'name'     => 'GLS',
            'services' => [
                // The name is filled in from the catalogue, addons defaulted.
                ['code' => 'agent', 'name' => 'GLS: Select pickup point (ParcelShop)', 'addons' => []],
            ],
        ])
        // The order's own method, added back to a carrier the filter dropped.
        ->and(methods_filter_services($outbound, 'postnord'))->toBe(['agent']);
});
