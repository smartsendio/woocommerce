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
 * SS_Shipping_Order_Meta (booking_outcome_meta_keys() vs
 * delivery_configuration_meta_keys()); these tests pin the exclusion to
 * that vocabulary via reflection, so a new META_* constant fails here
 * until it is classified into exactly one of the two lists.
 */

/**
 * Every META_* constant on SS_Shipping_Order_Meta, discovered via reflection.
 *
 * @return array<string, string> constant name => meta key
 */
function ss_order_meta_key_constants(): array
{
    $constants = (new ReflectionClass(SS_Shipping_Order_Meta::class))->getConstants();

    return array_filter(
        $constants,
        fn (string $name): bool => str_starts_with($name, 'META_'),
        ARRAY_FILTER_USE_KEY
    );
}

test('the renewal exclusion contains exactly the booking-outcome keys', function () {
    $booking_outcome = SS_Shipping_Order_Meta::booking_outcome_meta_keys();
    expect($booking_outcome)->not->toBeEmpty();

    $compat = new SS_Shipping_Subscriptions_Compat();
    $fragment = $compat->woocommerce_subscriptions_renewal_order_meta_query('SELECT `meta_key` FROM wp_postmeta');

    // The original query survives, with an exclusion appended.
    expect($fragment)->toStartWith('SELECT `meta_key` FROM wp_postmeta');

    // The NOT IN list is exactly the booking-outcome keys, in order.
    $expected = "AND `meta_key` NOT IN ( '" . implode("', '", $booking_outcome) . "' )";
    expect($fragment)->toContain($expected);
});

test('delivery-configuration keys are NOT excluded and copy to renewals', function () {
    $compat = new SS_Shipping_Subscriptions_Compat();
    $fragment = $compat->woocommerce_subscriptions_renewal_order_meta_query('');

    foreach (SS_Shipping_Order_Meta::delivery_configuration_meta_keys() as $meta_key) {
        expect($fragment)->not->toContain("'{$meta_key}'");
    }
});

test('every META_* constant is classified into exactly one of the two lists', function () {
    $constants = ss_order_meta_key_constants();
    expect($constants)->not->toBeEmpty();

    $booking_outcome = SS_Shipping_Order_Meta::booking_outcome_meta_keys();
    $delivery_config = SS_Shipping_Order_Meta::delivery_configuration_meta_keys();

    // The two lists are disjoint...
    expect(array_intersect($booking_outcome, $delivery_config))->toBe([]);

    // ...their union is exactly the reflected constant vocabulary...
    expect(array_values($constants))
        ->toEqualCanonicalizing(array_merge($booking_outcome, $delivery_config));

    // ...and all_meta_keys() is that union.
    expect(SS_Shipping_Order_Meta::all_meta_keys())
        ->toEqualCanonicalizing(array_values($constants));
});

test('the phantom _ss_shipping_label key is gone from the exclusion list', function () {
    // '_ss_shipping_label' was never written anywhere; the real keys are
    // suffixed (_ss_shipping_label_id). Quoted-and-delimited match so the
    // real key does not mask a lingering phantom entry.
    $compat = new SS_Shipping_Subscriptions_Compat();
    $fragment = $compat->woocommerce_subscriptions_renewal_order_meta_query('');

    expect($fragment)->not->toContain("'_ss_shipping_label'");
});
