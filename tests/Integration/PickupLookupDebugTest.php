<?php

/*
 * Pick-up point lookup failure reasons (#47): when the agents request at
 * checkout fails, the shopper still sees the unchanged "Shipping to closest
 * pick-up point" fallback, while the classified reason (transport error,
 * API error response, empty result) is logged - error level for failures,
 * debug level for an empty result - and, with WooCommerce shipping debug
 * mode enabled, surfaced as a checkout debug notice via
 * SS_Shipping_Checkout_Debug::add_notice(). add_notice() is a no-op when
 * WC_DOING_AJAX is defined (matching core), so during checkout AJAX updates
 * the log entry is the only trace; these tests exercise the non-AJAX render
 * path.
 *
 * NOTE: like ShippingDebugModeTest, these tests assert on checkout notices
 * and therefore must run before the WC_DOING_AJAX-defining test in
 * ShippingDebugModeTest.php (alphabetical file order guarantees this).
 */

/**
 * Render the pick-up point block for a Smart Send agent rate the way
 * checkout does: is_checkout() forced true, a posted shipping address, the
 * rate chosen in the session.
 */
function pickup_debug_render(): string
{
    if (is_null(WC()->cart)) {
        wc_load_cart();
    }

    add_filter('woocommerce_is_checkout', '__return_true');
    $_POST = array_merge($_POST, [
        's_country'  => 'DK',
        's_postcode' => '2300',
        's_city'     => 'Copenhagen',
        's_address'  => 'Islands Brygge 39',
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

    return ob_get_clean();
}

/**
 * All queued notices of the default "success" type wc_add_notice() uses,
 * matching WooCommerce core's own shipping debug notices.
 */
function pickup_debug_notice_texts(): array
{
    return array_map(
        fn (array $notice): string => (string) $notice['notice'],
        wc_get_notices('success')
    );
}

/**
 * The logged messages of a given level recorded by the logger spy.
 */
function pickup_debug_logged(object $spy, string $level): array
{
    $messages = [];
    foreach ($spy->entries as $entry) {
        if ($entry['level'] === $level) {
            $messages[] = $entry['message'];
        }
    }

    return $messages;
}

const PICKUP_DEBUG_FALLBACK = '<div class="woocommerce-info ss-agent-info">Shipping to closest pick-up point</div>';

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

it('surfaces a transport failure as a debug notice and logs it at error level', function () {
    with_option('woocommerce_shipping_debug_mode', 'yes');
    $spy = spy_on_logger();
    mock_smart_send_api(function () {
        return new WP_Error('http_request_failed', 'cURL error 28: Operation timed out after 30001 milliseconds');
    });

    $output = pickup_debug_render();

    $expected = 'Smart Send: pick-up point lookup for postnord failed with a transport error (transport-timeout): '
        . 'The connection to the Smart Send API timed out. Please try again. If the problem persists, ask your host '
        . 'whether outgoing requests to app.smartsend.io are blocked or slow.'
        . ' Falling back to "Shipping to closest pick-up point".';

    // The customer-facing fallback is byte-for-byte unchanged.
    expect($output)->toContain(PICKUP_DEBUG_FALLBACK)
        ->and(pickup_debug_notice_texts())->toContain($expected)
        ->and(implode("\n", pickup_debug_logged($spy, 'error')))->toContain($expected);
});

it('surfaces an API error response as a debug notice and logs it at error level', function () {
    with_option('woocommerce_shipping_debug_mode', 'yes');
    $spy = spy_on_logger();
    mock_smart_send_api(function () {
        return ss_api_response(401, [
            'code'    => 'Unauthenticated',
            'message' => 'The API token is invalid.',
        ]);
    });

    $output = pickup_debug_render();

    $expected = 'Smart Send: pick-up point lookup for postnord failed with an API error (Unauthenticated): '
        . 'The API token is invalid. Falling back to "Shipping to closest pick-up point".';

    expect($output)->toContain(PICKUP_DEBUG_FALLBACK)
        ->and(pickup_debug_notice_texts())->toContain($expected)
        ->and(implode("\n", pickup_debug_logged($spy, 'error')))->toContain($expected);
});

it('falls back to the HTTP status when a validation error body has no code', function () {
    with_option('woocommerce_shipping_debug_mode', 'yes');
    spy_on_logger();
    mock_smart_send_api(function () {
        return ss_api_response(422, ['message' => 'The given data was invalid.']);
    });

    $output = pickup_debug_render();

    expect($output)->toContain(PICKUP_DEBUG_FALLBACK)
        ->and(pickup_debug_notice_texts())->toContain(
            'Smart Send: pick-up point lookup for postnord failed with an API error (422): '
            . 'The given data was invalid. Falling back to "Shipping to closest pick-up point".'
        );
});

it('reports an empty agent list as a debug notice and logs it at debug level only', function () {
    with_option('woocommerce_shipping_debug_mode', 'yes');
    with_ss_settings(['ss_debug' => 'yes']); // Debug-level log entries require the plugin debug setting.
    $spy = spy_on_logger();
    mock_smart_send_api(function () {
        return ss_api_response(200, ['data' => []]);
    });

    $output = pickup_debug_render();

    $expected = 'Smart Send: no postnord pick-up points found near the entered address'
        . ' - falling back to "Shipping to closest pick-up point".';

    expect($output)->toContain(PICKUP_DEBUG_FALLBACK)
        ->and(pickup_debug_notice_texts())->toContain($expected)
        ->and(implode("\n", pickup_debug_logged($spy, 'debug')))->toContain($expected)
        ->and(implode("\n", pickup_debug_logged($spy, 'error')))->not->toContain('no postnord pick-up points');
});

it('adds no notice when shipping debug mode is off but still logs the error', function () {
    with_option('woocommerce_shipping_debug_mode', 'no');
    $spy = spy_on_logger();
    mock_smart_send_api(function () {
        return new WP_Error('http_request_failed', 'Failed to connect to app.smartsend.io port 443: Connection refused');
    });

    $output = pickup_debug_render();

    // Same fallback text, no notices at all - checkout looks exactly as before.
    expect($output)->toContain(PICKUP_DEBUG_FALLBACK)
        ->and(wc_notice_count())->toBe(0)
        ->and(implode("\n", pickup_debug_logged($spy, 'error')))->toContain(
            'Smart Send: pick-up point lookup for postnord failed with a transport error (transport-connection):'
        );
});

it('adds no notice for an empty agent list when shipping debug mode is off', function () {
    with_option('woocommerce_shipping_debug_mode', 'no');
    spy_on_logger();
    mock_smart_send_api(function () {
        return ss_api_response(200, ['data' => []]);
    });

    $output = pickup_debug_render();

    expect($output)->toContain(PICKUP_DEBUG_FALLBACK)
        ->and(wc_notice_count())->toBe(0);
});

it('renders the agent dropdown and no failure notice when the lookup succeeds', function () {
    with_option('woocommerce_shipping_debug_mode', 'yes');
    spy_on_logger();
    mock_smart_send_api(function () {
        return ss_api_response(200, ['data' => [sample_agent()]]);
    });

    $output = pickup_debug_render();

    expect($output)->toContain('ss_shipping_store_pickup')
        ->and($output)->not->toContain('Shipping to closest pick-up point')
        ->and(implode("\n", pickup_debug_notice_texts()))->not->toContain('pick-up point lookup');
});

it('surfaces a success summary with the carrier and result count (#92)', function () {
    with_option('woocommerce_shipping_debug_mode', 'yes');
    with_ss_settings(['ss_debug' => 'yes']); // Debug-level log entries require the plugin debug setting.
    $spy = spy_on_logger();
    mock_smart_send_api(function () {
        return ss_api_response(200, ['data' => [sample_agent(), sample_agent(['agent_no' => '5678'])]]);
    });

    $output = pickup_debug_render();

    $expected = 'Smart Send: found 2 postnord pick-up points near the entered address.';

    expect($output)->toContain('ss_shipping_store_pickup')
        ->and(pickup_debug_notice_texts())->toContain($expected)
        ->and(implode("\n", pickup_debug_logged($spy, 'debug')))->toContain($expected);
});

it('adds no success summary notice when shipping debug mode is off', function () {
    with_option('woocommerce_shipping_debug_mode', 'no');
    spy_on_logger();
    mock_smart_send_api(function () {
        return ss_api_response(200, ['data' => [sample_agent()]]);
    });

    $output = pickup_debug_render();

    expect($output)->toContain('ss_shipping_store_pickup')
        ->and(wc_notice_count())->toBe(0);
});
