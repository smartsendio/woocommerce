<?php

/*
 * WooCommerce Subscriptions renewal-meta exclusion (issue #138).
 *
 * Subscription renewal orders must not inherit the parent order's Smart Send
 * meta (shipment ids, pickup point selection, parcel split). The exclusion
 * list in SS_Shipping_Subscriptions_Compat is built from the META_* constants
 * on SS_Shipping_Order_Meta; these tests pin the list to that vocabulary via
 * reflection, so adding a new META_* constant fails here until it is
 * classified (added to all_meta_keys() and thereby to the exclusion).
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

test('every order meta key constant is excluded from subscription renewal meta copying', function () {
    $constants = ss_order_meta_key_constants();
    expect($constants)->not->toBeEmpty();

    $compat = new SS_Shipping_Subscriptions_Compat();
    $fragment = $compat->woocommerce_subscriptions_renewal_order_meta_query('SELECT `meta_key` FROM wp_postmeta');

    // The original query survives, with an exclusion appended.
    expect($fragment)->toStartWith('SELECT `meta_key` FROM wp_postmeta')
        ->and($fragment)->toContain('NOT IN');

    // Every meta key Smart Send writes appears, quoted, in the exclusion.
    foreach ($constants as $name => $meta_key) {
        expect($fragment)->toContain("'{$meta_key}'");
    }
});

test('all_meta_keys() covers exactly the META_* constants', function () {
    expect(array_values(ss_order_meta_key_constants()))
        ->toEqualCanonicalizing(SS_Shipping_Order_Meta::all_meta_keys());
});

test('the phantom _ss_shipping_label key is gone from the exclusion list', function () {
    // '_ss_shipping_label' was never written anywhere; the real keys are
    // suffixed (_ss_shipping_label_id). Quoted-and-delimited match so the
    // real key does not mask a lingering phantom entry.
    $compat = new SS_Shipping_Subscriptions_Compat();
    $fragment = $compat->woocommerce_subscriptions_renewal_order_meta_query('');

    expect($fragment)->not->toContain("'_ss_shipping_label'");
});
