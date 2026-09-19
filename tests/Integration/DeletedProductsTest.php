<?php

use Smart_Send\Booking\Order_Reader;

it('retains a deleted catalog item as an order line without inventing product data', function (string $product_type) {
    with_ss_settings();

    if ($product_type === 'variation') {
        [$parent, $product] = create_variable_product(['sku' => 'DELETED-VARIATION-' . uniqid(), 'weight' => 3]);
        $parent->update_meta_data('_ss_hs_code', '61091000');
        $parent->save();
        $product_id = $parent->get_id();
        $variation_id = $product->get_id();
    } else {
        $product = create_simple_product([
            'name' => 'Former catalog name',
            'sku' => 'DELETED-SIMPLE-' . uniqid(),
            'weight' => 3,
            'hs_code' => '61091000',
            'customs_desc' => 'Old customs description',
            'country_of_origin' => 'DK',
        ]);
        $product_id = $product->get_id();
        $variation_id = 0;
    }

    $order = create_order(['products' => [[$product, 2]]]);
    $item = array_values($order->get_items())[0];
    $item->set_name('Historical item label');
    $item->set_total('160');
    $item->set_taxes(['total' => [1 => '40'], 'subtotal' => [1 => '50']]);
    $item->save();
    $item_id = $item->get_id();
    $deleted_id = $product->get_id();
    $product->delete(true);

    // Use a fresh order, as an order screen or return booking would.
    $rows = (new Order_Reader(wc_get_order($order->get_id())))->get_items_data();

    expect(get_post($deleted_id))->toBeNull()
        ->and($rows)->toBe([[
            'order_item_id' => $item_id,
            'product_id' => $product_id,
            'variation_id' => $variation_id,
            'sku' => '',
            'name' => __('Deleted', 'smart-send-logistics'),
            'description' => null,
            'hs_code' => null,
            'country_of_origin' => null,
            'quantity' => 2,
            'unit_weight' => null,
            'total_net_amount' => 160.0,
            'total_tax_amount' => 40.0,
            'product_missing' => true,
        ]]);
})->with(['simple', 'variation']);

it('keeps duplicate catalog products distinct by order item identity and saved line label', function () {
    $product = create_simple_product([
        'name' => 'Catalog shirt',
        'sku' => 'LIVE-' . uniqid(),
        'weight' => 1.25,
        'hs_code' => '61091000',
        'customs_desc' => 'Cotton shirt',
        'country_of_origin' => 'DK',
    ]);
    $order = create_order(['products' => [[$product, 1], [$product, 2]]]);
    [$first, $second] = array_values($order->get_items());
    $first->set_name('Shirt with Alice embroidery');
    $first->set_total('80');
    $first->save();
    $second->set_name('Shirt with Bob embroidery');
    $second->set_total('150');
    $second->save();

    $rows = (new Order_Reader(wc_get_order($order->get_id())))->get_items_data();

    expect(array_column($rows, 'order_item_id'))->toBe([$first->get_id(), $second->get_id()])
        ->and(array_column($rows, 'product_id'))->toBe([$product->get_id(), $product->get_id()])
        ->and(array_column($rows, 'variation_id'))->toBe([0, 0])
        ->and(array_column($rows, 'name'))->toBe(['Shirt with Alice embroidery', 'Shirt with Bob embroidery'])
        ->and(array_column($rows, 'quantity'))->toBe([1, 2])
        ->and(array_column($rows, 'total_net_amount'))->toBe([80.0, 150.0])
        ->and(array_column($rows, 'unit_weight'))->toBe([1.25, 1.25])
        ->and(array_column($rows, 'product_missing'))->toBe([false, false])
        ->and($rows[0]['sku'])->toBe($product->get_sku())
        ->and($rows[0]['hs_code'])->toBe('61091000')
        ->and($rows[0]['description'])->toBe('Cotton shirt')
        ->and($rows[0]['country_of_origin'])->toBe('DK')
        ->and($rows[0])->not->toHaveKey('id');
});

it('reads surviving variation data when its former parent no longer exists', function () {
    [$parent, $variation] = create_variable_product(['sku' => 'ORPHAN-VARIATION-' . uniqid(), 'weight' => 2.5]);
    $parent->update_meta_data('_ss_customs_desc', 'Deleted parent description');
    $parent->save();
    $variation->update_meta_data('_ss_hs_code', '62052000');
    $variation->save();
    $order = create_order(['products' => [$variation]]);
    $item = array_values($order->get_items())[0];
    $product_id = $parent->get_id();
    $variation_id = $variation->get_id();

    // Detach before deleting to model an orphaned variation without the
    // WooCommerce parent's normal cascade also deleting the variation.
    $variation->set_parent_id(0);
    $variation->save();
    $parent->delete(true);

    $row = (new Order_Reader(wc_get_order($order->get_id())))->get_items_data()[0];

    expect(wc_get_product($product_id))->toBeFalse()
        ->and($row['order_item_id'])->toBe($item->get_id())
        ->and($row['product_id'])->toBe($product_id)
        ->and($row['variation_id'])->toBe($variation_id)
        ->and($row['product_missing'])->toBeFalse()
        ->and($row['name'])->toBe($item->get_name())
        ->and($row['sku'])->toBe($variation->get_sku())
        ->and($row['unit_weight'])->toBe(2.5)
        ->and($row['hs_code'])->toBe('62052000')
        ->and($row['description'])->toBeNull()
        ->and($row['country_of_origin'])->toBeNull();
});
