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
 * and ss_policy_notices()/ss_policy_method()/ss_policy_package() from
 * LoggingPolicyTest.php, both of which load earlier alphabetically.
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

/**
 * Render the pickup point section for a chosen Smart Send agent rate the
 * way checkout does, so the frontend emits its delivery-options debug bar
 * line. (Local twin of PickupLookupDebugTest's helper, which loads later
 * alphabetically.)
 */
function notice_translation_render_pickup_section(): void
{
    add_filter('woocommerce_is_checkout', '__return_true');
    $_POST = array_merge($_POST, [
        's_country'  => 'DK',
        's_postcode' => '2300',
        's_city'     => 'Copenhagen',
        's_address'  => 'Main Street 1',
    ]);
    WC()->session->set('chosen_shipping_methods', ['smart_send_shipping:1']);

    remember_cleanup_callback(function (): void {
        remove_filter('woocommerce_is_checkout', '__return_true');
        unset($_POST['s_country'], $_POST['s_postcode'], $_POST['s_city'], $_POST['s_address']);
        WC()->session->set('chosen_shipping_methods', null);
        WC()->session->set('ss_shipping_agents', null);
    });

    $rate = new WC_Shipping_Rate('smart_send_shipping:1', 'Smart Send', 49.0, [], 'smart_send_shipping', 1);
    $rate->add_meta_data('smart_send_shipping_method', 'postnord_agent');

    ob_start();
    (new SS_Shipping_Frontend())->display_ss_pickup_points($rate, 0);
    ob_end_clean();
}

it('renders the checkout debug notice through the translation functions while the log entry stays English', function () {
    with_option('woocommerce_shipping_debug_mode', 'yes');
    with_ss_settings(['ss_debug' => 'yes']); // Debug-level log entries require the plugin debug setting.
    $spy = spy_on_logger();

    $english_template = 'Smart Send: Evaluated method "%1$s" (%2$s) as available (total=%3$s, weight=%4$s %5$s). Weight table row %6$s applied, cost %7$s.';
    $translated_template = 'OVERSAT: metode "%1$s" (%2$s) tilgaengelig (total=%3$s, vaegt=%4$s %5$s). Vaegtraekke %6$s, pris %7$s.';

    $filter = function (string $translation, string $text, string $domain) use ($english_template, $translated_template): string {
        if ('smart-send-logistics' === $domain && $english_template === $text) {
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
        'cost_weight' => [['ss_min_weight' => '1', 'ss_max_weight' => '5', 'ss_cost_weight' => '49']],
    ], 99962);
    $method->calculate_shipping($package);

    $unit = get_option('woocommerce_weight_unit');

    // The merchant-facing debug bar notice is translated ...
    expect(ss_policy_notices())->toContain(
        'OVERSAT: metode "SS Policy Method" (smart_send_shipping:99962) tilgaengelig (total=100, vaegt=2 ' . $unit . '). Vaegtraekke 1-5 ' . $unit . ', pris 49.'
    );

    // ... while the log keeps the greppable English step trace, untouched
    // by the translation filter.
    $debug_log = implode("\n", array_map(
        fn (array $entry): string => (string) $entry['message'],
        array_filter($spy->entries, fn (array $entry): bool => 'debug' === $entry['level'])
    ));
    expect($debug_log)->toContain('Smart Send "SS Policy Method": cart weight 2 ' . $unit . ' matched weight table row 1-5 ' . $unit . ' - rate added with cost 49.')
        ->and($debug_log)->not->toContain('OVERSAT');
});

it('substitutes values into a translated sprintf template on the debug bar', function () {
    with_option('woocommerce_shipping_debug_mode', 'yes');
    spy_on_logger();

    $translated_template = 'OVERSAT: "%1$s" (%2$s) ikke tilgaengelig (total=%3$s, vaegt=%4$s %5$s): ingen vaegtraekke.';

    $filter = function (string $translation, string $text, string $domain) use ($translated_template): string {
        if ('smart-send-logistics' === $domain && 'Smart Send: Evaluated method "%1$s" (%2$s) as not available (total=%3$s, weight=%4$s %5$s): the cart weight matched no weight table row.' === $text) {
            return $translated_template;
        }

        return $translation;
    };
    add_filter('gettext', $filter, 10, 3);
    remember_cleanup_callback(function () use ($filter): void {
        remove_filter('gettext', $filter, 10);
    });

    $product = create_simple_product(['price' => 100, 'weight' => 10]);
    $package = ss_policy_package([$product]);

    $method = ss_policy_method([
        'title'       => 'Oversat Metode',
        'cost_weight' => [['ss_min_weight' => '1', 'ss_max_weight' => '5', 'ss_cost_weight' => '49']],
    ], 99961);
    $method->calculate_shipping($package);

    $unit = get_option('woocommerce_weight_unit');

    expect(ss_policy_notices())->toContain(
        'OVERSAT: "Oversat Metode" (smart_send_shipping:99961) ikke tilgaengelig (total=100, vaegt=10 ' . $unit . '): ingen vaegtraekke.'
    );
});

it('uses singular and plural pickup point outcomes via _n()', function () {
    with_option('woocommerce_shipping_debug_mode', 'yes');
    spy_on_logger();
    mock_smart_send_api(function () {
        return ss_api_response(200, ['data' => [sample_agent()]]);
    });

    notice_translation_render_pickup_section();

    expect(ss_policy_notices())->toContain('Smart Send: Showing pickup point for "Smart Send" (smart_send_shipping:1). Found 1 pickup point near the entered address.');

    wc_clear_notices();
    mock_smart_send_api(function () {
        return ss_api_response(200, ['data' => [sample_agent(), sample_agent(['agent_no' => '5678'])]]);
    });

    notice_translation_render_pickup_section();

    expect(ss_policy_notices())->toContain('Smart Send: Showing pickup point for "Smart Send" (smart_send_shipping:1). Found 2 pickup points near the entered address.');
});
