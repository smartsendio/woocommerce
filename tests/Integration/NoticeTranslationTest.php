<?php

/*
 * String building & i18n (#96): merchant-facing strings (including the
 * checkout shipping debug bar) are full-sentence sprintf templates run
 * through the translation functions, while log entries stay untranslated
 * English so support can grep them across locales.
 *
 * NOTE: tests here assert on checkout notices and must run before the
 * WC_DOING_AJAX-defining tests in ShippingDebugModeTest.php (alphabetical
 * file order guarantees this). Reuses spy_on_logger() from LoggerTest.php
 * and ss_policy_notices() from LoggingPolicyTest.php, both of which load
 * earlier alphabetically.
 */

beforeEach(function (): void {
    with_ss_settings();

    if (is_null(WC()->cart)) {
        wc_load_cart();
    }
    wc_clear_notices();
    remember_cleanup_callback(function (): void {
        wc_clear_notices();
    });
});

it('renders the checkout debug notice through the translation functions while the log entry stays English', function () {
    with_option('woocommerce_shipping_debug_mode', 'yes');
    with_ss_settings(['ss_debug' => 'yes']); // Debug-level log entries require the plugin debug setting.
    $spy = spy_on_logger();

    $english = 'Smart Send: no Smart Send rates offered for this package.';
    $translated = 'OVERSAT: ingen Smart Send-priser for denne pakke.';

    $filter = function (string $translation, string $text, string $domain) use ($english, $translated): string {
        if ('smart-send-logistics' === $domain && $english === $text) {
            return $translated;
        }

        return $translation;
    };
    add_filter('gettext', $filter, 10, 3);
    remember_cleanup_callback(function () use ($filter): void {
        remove_filter('gettext', $filter, 10);
    });

    $other = new WC_Shipping_Rate('flat_rate:3', 'Flat rate', '10', [], 'flat_rate', 3);
    SS_SHIPPING_WC()->ss_sort_shipping_methods(['flat_rate:3' => $other]);

    // The merchant-facing debug bar notice is translated ...
    expect(ss_policy_notices())->toContain($translated)
        ->and(implode("\n", ss_policy_notices()))->not->toContain($english)
        // ... while the log entry keeps the greppable English template.
        ->and(implode("\n", array_map(
            fn (array $entry): string => (string) $entry['message'],
            array_filter($spy->entries, fn (array $entry): bool => 'debug' === $entry['level'])
        )))->toContain($english)
        ->and(implode("\n", array_map(
            fn (array $entry): string => (string) $entry['message'],
            $spy->entries
        )))->not->toContain($translated);
});

it('substitutes values into a translated sprintf template on the debug bar', function () {
    with_option('woocommerce_shipping_debug_mode', 'yes');
    spy_on_logger();

    $translated_template = 'OVERSAT: metode "%1$s" evalueret (%2$s, pris-id %3$s).';

    $filter = function (string $translation, string $text, string $domain) use ($translated_template): string {
        if ('smart-send-logistics' === $domain && 'Smart Send: evaluated method "%1$s" (%2$s, rate id %3$s).' === $text) {
            return $translated_template;
        }

        return $translation;
    };
    add_filter('gettext', $filter, 10, 3);
    remember_cleanup_callback(function () use ($filter): void {
        remove_filter('gettext', $filter, 10);
    });

    $product = create_simple_product(['price' => 100, 'weight' => 2]);
    $package = ss_policy_package([$product]);

    $method = ss_policy_method([
        'title'       => 'Oversat Metode',
        'cost_weight' => [['ss_min_weight' => '1', 'ss_max_weight' => '5', 'ss_cost_weight' => '49']],
    ], 99961);
    $method->calculate_shipping($package);

    expect(ss_policy_notices())->toContain(
        'OVERSAT: metode "Oversat Metode" evalueret (postnord_agent, pris-id smart_send_shipping:99961).'
    );
});

it('uses singular and plural pickup point summaries via _n()', function () {
    with_option('woocommerce_shipping_debug_mode', 'yes');
    spy_on_logger();
    mock_smart_send_api(function () {
        return ss_api_response(200, ['data' => [sample_agent()]]);
    });

    SS_SHIPPING_WC()->ss_find_closest_agents_by_address('postnord', 'DK', '2300', 'Main Street 1', 'Copenhagen');

    expect(ss_policy_notices())->toContain('Smart Send: found 1 postnord pickup point near the entered address.');

    wc_clear_notices();
    mock_smart_send_api(function () {
        return ss_api_response(200, ['data' => [sample_agent(), sample_agent(['agent_no' => '5678'])]]);
    });

    SS_SHIPPING_WC()->ss_find_closest_agents_by_address('postnord', 'DK', '2300', 'Main Street 1', 'Copenhagen');

    expect(ss_policy_notices())->toContain('Smart Send: found 2 postnord pickup points near the entered address.');
});
