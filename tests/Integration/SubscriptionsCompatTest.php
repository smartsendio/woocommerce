<?php

/*
 * WooCommerce Subscriptions renewal-meta exclusion (issue #138).
 *
 * Renewals keep the delivery configuration, drop the booking outcomes:
 * only the booked-label shipment ids (_ss_shipping_label_id,
 * _ss_shipping_return_label_id) are excluded from renewal-order meta
 * copying - a renewal must get its own label. The delivery-configuration
 * meta (pickup point agent object and number, parcel split) deliberately
 * copies through, because a renewal ships the same way as its parent.
 *
 * The exclusion list is built from the meta-key classification on
 * \Smart_Send\Delivery\Order_Meta (booking_outcome_meta_keys() vs
 * delivery_configuration_meta_keys()); these tests pin the exclusion to
 * that vocabulary via reflection, so a new META_* constant fails here
 * until it is classified into exactly one of the two lists.
 */

/**
 * Every META_* constant on \Smart_Send\Delivery\Order_Meta, discovered via reflection.
 *
 * @return array<string, string> constant name => meta key
 */
function ss_order_meta_key_constants(): array
{
    $constants = (new ReflectionClass(\Smart_Send\Delivery\Order_Meta::class))->getConstants();

    return array_filter(
        $constants,
        fn (string $name): bool => str_starts_with($name, 'META_'),
        ARRAY_FILTER_USE_KEY
    );
}

test('the renewal exclusion contains exactly the booking-outcome keys', function () {
    $booking_outcome = \Smart_Send\Delivery\Order_Meta::booking_outcome_meta_keys();
    expect($booking_outcome)->not->toBeEmpty();

    $compat = new \Smart_Send\Support\Subscriptions_Compat();
    $fragment = $compat->woocommerce_subscriptions_renewal_order_meta_query('SELECT `meta_key` FROM wp_postmeta');

    // The original query survives, with an exclusion appended.
    expect($fragment)->toStartWith('SELECT `meta_key` FROM wp_postmeta');

    // The NOT IN list is exactly the booking-outcome keys, in order.
    $expected = "AND `meta_key` NOT IN ( '" . implode("', '", $booking_outcome) . "' )";
    expect($fragment)->toContain($expected);

    // The append-only booked-labels list is a booking outcome too (#182
    // review): a renewal must not inherit the parent order's timeline.
    expect($booking_outcome)->toContain(\Smart_Send\Delivery\Order_Meta::META_LABELS)
        ->and(\Smart_Send\Delivery\Order_Meta::META_LABELS)->toBe('_ss_shipping_labels');
    expect($fragment)->toContain("'_ss_shipping_labels'");
});

test('delivery-configuration keys are NOT excluded and copy to renewals', function () {
    $compat = new \Smart_Send\Support\Subscriptions_Compat();
    $fragment = $compat->woocommerce_subscriptions_renewal_order_meta_query('');

    foreach (\Smart_Send\Delivery\Order_Meta::delivery_configuration_meta_keys() as $meta_key) {
        expect($fragment)->not->toContain("'{$meta_key}'");
    }
});

test('every META_* constant is classified into exactly one of the two lists', function () {
    $constants = ss_order_meta_key_constants();
    expect($constants)->not->toBeEmpty();

    $booking_outcome = \Smart_Send\Delivery\Order_Meta::booking_outcome_meta_keys();
    $delivery_config = \Smart_Send\Delivery\Order_Meta::delivery_configuration_meta_keys();

    // The two lists are disjoint...
    expect(array_intersect($booking_outcome, $delivery_config))->toBe([]);

    // ...their union is exactly the reflected constant vocabulary...
    expect(array_values($constants))
        ->toEqualCanonicalizing(array_merge($booking_outcome, $delivery_config));

    // ...and all_meta_keys() is that union.
    expect(\Smart_Send\Delivery\Order_Meta::all_meta_keys())
        ->toEqualCanonicalizing(array_values($constants));
});

test('the phantom _ss_shipping_label key is gone from the exclusion list', function () {
    // '_ss_shipping_label' was never written anywhere; the real keys are
    // suffixed (_ss_shipping_label_id). Quoted-and-delimited match so the
    // real key does not mask a lingering phantom entry.
    $compat = new \Smart_Send\Support\Subscriptions_Compat();
    $fragment = $compat->woocommerce_subscriptions_renewal_order_meta_query('');

    expect($fragment)->not->toContain("'_ss_shipping_label'");
});
