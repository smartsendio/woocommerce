<?php

use Smart_Send\Booking\Exceptions\Booking_Exception;
use Smart_Send\Booking\Order_Reader;
use Smart_Send\Booking\Shipment;
use Smart_Send\Booking\Shipment_Builder;
use Smart_Send\Delivery\Delivery_Details;
use Smart_Send\Delivery\Parcel_Plan;
use Smart_Send\Delivery\Parcel_Spec;

function allocation_shipment(WC_Order $order, ?Parcel_Plan $plan = null): Shipment
{
    $details = (new Delivery_Details())->set_shipping_method('postnord_homedelivery');
    if ($plan !== null) {
        $details->set_parcel_plan($plan);
    }
    return (new Shipment_Builder($order, new Order_Reader($order)))->build($details);
}

function allocation_wire(Shipment $shipment): array
{
    $wire = SS_SHIPPING_WC()->get_api_handle()->bookings()->from_shipment($shipment);
    return json_decode(wp_json_encode($wire), true);
}

beforeEach(function (): void {
    with_ss_settings();
    with_option('woocommerce_weight_unit', 'kg');
    with_option('woocommerce_price_num_decimals', 2);
});

it('keeps repeated catalog products on separate order lines with their own amounts', function () {
    $product = create_simple_product(['price' => 100, 'weight' => 2]);
    $order = create_order(['products' => [$product, $product]]);
    $lines = array_values($order->get_items());
    $lines[0]->set_name('First purchased option');
    $lines[0]->set_total('50');
    $lines[0]->save();
    $lines[1]->set_name('Second purchased option');
    $lines[1]->set_total('80');
    $lines[1]->save();
    $order->calculate_totals(false);

    $plan = (new Parcel_Plan())
        ->add_spec((new Parcel_Spec())->add_item($lines[0]->get_id()))
        ->add_spec((new Parcel_Spec())->add_item($lines[1]->get_id()));
    $parcels = allocation_shipment($order, $plan)->get_parcels();

    expect($parcels[0]->get_items()[0])->toMatchArray([
        'order_item_id' => $lines[0]->get_id(), 'product_id' => $product->get_id(),
        'name' => 'First purchased option', 'quantity' => 1, 'total_net_amount' => 50.0,
    ])->and($parcels[1]->get_items()[0])->toMatchArray([
        'order_item_id' => $lines[1]->get_id(), 'product_id' => $product->get_id(),
        'name' => 'Second purchased option', 'quantity' => 1, 'total_net_amount' => 80.0,
    ])->and($parcels[0]->get_total_net_amount())->toBe(50.0)
        ->and($parcels[1]->get_total_net_amount())->toBe(80.0);
});

it('sends only the allocated units and amounts of a quantity-two line in each parcel', function () {
    $product = create_simple_product(['price' => 100, 'weight' => 2]);
    $order = create_order(['products' => [[$product, 2]]]);
    $id = array_key_first($order->get_items());
    $plan = (new Parcel_Plan())
        ->add_spec((new Parcel_Spec())->add_item($id))
        ->add_spec((new Parcel_Spec())->add_item($id));
    $shipment = allocation_shipment($order, $plan);
    $wire = allocation_wire($shipment);

    foreach ($wire['parcels'] as $parcel) {
        expect($parcel['weight'])->toEqual(2.0)
            ->and($parcel['items'])->toHaveCount(1)
            ->and($parcel['items'][0])->toMatchArray([
                'internal_id' => (string) $id,
                'quantity' => 1, 'unit_price_excluding_tax' => 100,
                'total_price_excluding_tax' => 100, 'total_tax_amount' => 0,
            ])->and($parcel['total_price_excluding_tax'])->toEqual(100);
    }
    expect(array_sum(array_column(array_merge(...array_column($wire['parcels'], 'items')), 'quantity')))->toEqual(2);
});

it('distributes rounding residuals deterministically without losing net amounts or tax', function () {
    $product = create_simple_product(['price' => 10]);
    $order = create_order(['products' => [[$product, 3]]]);
    $line = array_values($order->get_items())[0];
    $line->set_total('10');
    $line->set_taxes(['total' => [1 => '1.00'], 'subtotal' => [1 => '1.00']]);
    $line->save();
    $plan = new Parcel_Plan();
    for ($i = 0; $i < 3; $i++) {
        $plan->add_spec((new Parcel_Spec())->add_item($line->get_id()));
    }
    $parcels = allocation_shipment($order, $plan)->get_parcels();
    $items = array_map(fn ($parcel) => $parcel->get_items()[0], $parcels);

    expect(array_column($items, 'total_net_amount'))->toBe([3.33, 3.34, 3.33])
        ->and(array_column($items, 'total_tax_amount'))->toBe([0.33, 0.34, 0.33])
        ->and(array_sum(array_column($items, 'total_net_amount')))->toEqual(10.0)
        ->and(array_sum(array_column($items, 'total_tax_amount')))->toEqual(1.0)
        ->and(array_map(fn ($parcel) => $parcel->get_total_net_amount(), $parcels))->toBe([3.33, 3.34, 3.33]);
});

it('keeps a multi-unit allocation as one row with scaled amounts', function () {
    $product = create_simple_product(['price' => 20, 'weight' => 0.5]);
    $order = create_order(['products' => [[$product, 3]]]);
    $id = array_key_first($order->get_items());
    $plan = (new Parcel_Plan())
        ->add_spec((new Parcel_Spec())->add_item($id, 2))
        ->add_spec((new Parcel_Spec())->add_item($id, 1));
    $parcels = allocation_shipment($order, $plan)->get_parcels();

    expect($parcels[0]->get_items())->toHaveCount(1)
        ->and($parcels[0]->get_items()[0])->toMatchArray(['quantity' => 2, 'total_net_amount' => 40.0])
        ->and($parcels[0]->get_weight())->toBe(1.0)
        ->and($parcels[1]->get_items()[0])->toMatchArray(['quantity' => 1, 'total_net_amount' => 20.0]);
});

it('uses the order-item identity when allocating a variation', function () {
    [$parent, $variation] = create_variable_product(['price' => 30, 'weight' => 2]);
    $order = create_order(['products' => [$variation]]);
    $id = array_key_first($order->get_items());
    $plan = (new Parcel_Plan())->add_spec((new Parcel_Spec())->add_item($id));
    $item = allocation_shipment($order, $plan)->get_parcels()[0]->get_items()[0];

    expect($item)->toMatchArray([
        'order_item_id' => $id, 'product_id' => $parent->get_id(),
        'variation_id' => $variation->get_id(), 'quantity' => 1,
    ]);
});

it('includes all items in a single specification containing only measurements', function () {
    $product = create_simple_product(['price' => 5, 'weight' => 2]);
    $order = create_order(['products' => [[$product, 2]]]);
    $spec = (new Parcel_Spec())->set_length(30)->set_weight(9.5);
    $plan = (new Parcel_Plan())->add_spec($spec);
    $parcel = allocation_shipment($order, $plan)->get_parcels()[0];

    expect($parcel->get_items())->toHaveCount(1)
        ->and($parcel->get_items()[0]['quantity'])->toBe(2)
        ->and($parcel->get_total_net_amount())->toBe(10.0)
        ->and($parcel->get_weight())->toBe(9.5)
        ->and($parcel->get_length())->toBe(30.0)
        ->and($spec->has_items())->toBeFalse();
});

it('rejects plans which underallocate, overallocate or reference a removed order item', function (string $case) {
    $product = create_simple_product();
    $order = create_order(['products' => [[$product, 2]]]);
    $id = array_key_first($order->get_items());
    $plan = (new Parcel_Plan())->add_spec((new Parcel_Spec())->add_item($case === 'removed' ? $id + 9999 : $id, $case === 'over' ? 3 : 1));

    expect($plan->validation_errors((new Order_Reader($order))->get_items_data()))->not->toBeEmpty()
        ->and(fn () => allocation_shipment($order, $plan))->toThrow(Booking_Exception::class, 'Review the parcel allocations');
})->with(['under', 'over', 'removed']);

it('does not guess a distribution between multiple itemless parcels', function () {
    $product = create_simple_product();
    $order = create_order(['products' => [$product]]);
    $plan = (new Parcel_Plan())
        ->add_spec((new Parcel_Spec())->set_weight(3))
        ->add_spec((new Parcel_Spec())->set_weight(4));
    expect(fn () => allocation_shipment($order, $plan))->toThrow(Booking_Exception::class);
});

it('refuses legacy or malformed allocation data instead of dropping the offending rows', function (array $data) {
    expect(fn () => Parcel_Plan::from_array($data))->toThrow(InvalidArgumentException::class);
})->with([
    'legacy box rows' => [[[ 'id' => 12, 'name' => 'Old item', 'value' => '1' ]]],
    'legacy item id' => [['specs' => [['items' => [['id' => 12, 'quantity' => 1]]]]]],
    'missing quantity' => [['specs' => [['items' => [['order_item_id' => 12]]]]]],
    'fractional quantity' => [['specs' => [['items' => [['order_item_id' => 12, 'quantity' => 1.5]]]]]],
    'zero quantity' => [['specs' => [['items' => [['order_item_id' => 12, 'quantity' => 0]]]]]],
    'negative identity' => [['specs' => [['items' => [['order_item_id' => -1, 'quantity' => 1]]]]]],
    'mixed malformed rows' => [['specs' => [['items' => [['order_item_id' => 12, 'quantity' => 1], 'not an allocation']]]]],
    'non-array items' => [['specs' => [['items' => 'not allocations']]]],
    'non-array spec' => [['specs' => ['not a spec']]],
    'missing specs' => [['unexpected' => []]],
]);

it('preserves known zero item amounts while retaining null amounts for an itemless order', function () {
    $product = create_simple_product(['price' => 0]);
    $order = create_order(['products' => [$product]]);
    $wire = allocation_wire(allocation_shipment($order));
    expect($wire['parcels'][0]['items'][0])->toMatchArray([
        'unit_price_excluding_tax' => 0, 'total_price_excluding_tax' => 0,
        'total_price_including_tax' => 0, 'total_tax_amount' => 0,
    ])->and($wire['parcels'][0]['total_price_excluding_tax'])->toEqual(0);

    $empty = create_order();
    $plan = (new Parcel_Plan())->add_spec((new Parcel_Spec())->set_weight(1));
    $parcel = allocation_shipment($empty, $plan)->get_parcels()[0];
    expect($parcel->get_total_net_amount())->toBeNull()->and($parcel->get_total_tax_amount())->toBeNull();
});

it('requires explicit weight when any allocated product weight is unknown', function () {
    $missing = create_simple_product(['weight' => 2]);
    $known = create_simple_product(['weight' => 3]);
    $order = create_order(['products' => [$missing, $known]]);
    $missing->delete(true);
    $filter = fn (float $weight): float => $weight + 1;
    add_filter('smart_send_parcel_default_weight', $filter);
    remember_cleanup_callback(fn () => remove_filter('smart_send_parcel_default_weight', $filter));

    expect(fn () => allocation_shipment($order))->toThrow(Booking_Exception::class, 'Enter a parcel weight');
    $plan = (new Parcel_Plan())->add_spec((new Parcel_Spec())->set_weight(6));
    $parcel = allocation_shipment($order, $plan)->get_parcels()[0];
    expect($parcel->get_weight())->toBe(6.0)->and($parcel->get_items())->toHaveCount(2);
});

it('retains sub-cent line amounts and taxes without negative rounding residuals', function () {
    $product = create_simple_product();
    $order = create_order(['products' => [[$product, 3]]]);
    $line = array_values($order->get_items())[0];
    $line->set_total('10.005');
    $line->set_taxes(['total' => [1 => '0.009'], 'subtotal' => [1 => '0.009']]);
    $line->save();
    // A payload filter can preserve accounting precision beyond Woo's display rounding.
    $tax_filter = function (array $items): array {
        $items[0]['total_tax_amount'] = 0.009;
        return $items;
    };
    add_filter('smart_send_payload_items', $tax_filter);
    remember_cleanup_callback(fn () => remove_filter('smart_send_payload_items', $tax_filter));
    $plan = new Parcel_Plan();
    for ($i = 0; $i < 3; $i++) {
        $plan->add_spec((new Parcel_Spec())->add_item($line->get_id()));
    }
    $parcels = allocation_shipment($order, $plan)->get_parcels();
    $items = array_map(fn ($parcel) => $parcel->get_items()[0], $parcels);

    expect(array_column($items, 'total_net_amount'))->toBe([3.34, 3.33, 3.335])
        ->and(array_column($items, 'total_tax_amount'))->toBe([0.0, 0.009, 0.0])
        ->and(array_sum(array_column($items, 'total_net_amount')))->toEqualWithDelta(10.005, 0.0000001)
        ->and(array_sum(array_column($items, 'total_tax_amount')))->toEqual(0.009)
        ->and(array_map(fn ($parcel) => $parcel->get_total_tax_amount(), $parcels))->toBe([0.0, 0.009, 0.0]);
});

it('rejects duplicate order-item allocations within a parcel before calling the API', function (bool $canonical_input) {
    $product = create_simple_product(['price' => 20]);
    $order = create_order(['products' => [[$product, 2]], 'shipping_method' => 'postnord_homedelivery']);
    $id = array_key_first($order->get_items());
    $capture = mock_smart_send_api();
    $filter = function (Delivery_Details $details) use ($id, $canonical_input): Delivery_Details {
        // The quantities would cover the line, but identity must be unique per parcel.
        $plan = $canonical_input
            ? Parcel_Plan::from_array(['specs' => [['items' => [
                ['order_item_id' => $id, 'quantity' => 1],
                ['order_item_id' => (string) $id, 'quantity' => 1],
            ]]]])
            : (new Parcel_Plan())->add_spec((new Parcel_Spec())->add_item($id)->add_item((string) $id));
        return $details->set_parcel_plan($plan);
    };
    add_filter('smart_send_delivery_details', $filter);
    remember_cleanup_callback(fn () => remove_filter('smart_send_delivery_details', $filter));

    $result = SS_SHIPPING_WC()->fulfillment()->fulfill_outbound($order, false);
    expect($result->is_successful())->toBeFalse()
        ->and($result->get_first_error_message())->toContain('parcel plan is invalid')
        ->and($capture->requests)->toBe([]);
})->with(['programmatic allocations' => false, 'canonical allocations' => true]);
