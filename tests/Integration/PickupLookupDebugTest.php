<?php

/*
 * Pickup point lookup failure reasons (#47): when the agents request at
 * checkout fails, the shopper sees the section's status message (the quiet
 * "Shipping to closest pickup point" fallback for transport/API failures,
 * the check-your-address text when no points exist near the address, an
 * error box for authentication/authorization failures), while the
 * classified reason is logged - error level for failures, info level for
 * an empty result - and, with WooCommerce shipping debug mode enabled,
 * surfaced as a checkout debug notice via
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
 * Render the pickup point block for a Smart Send agent rate the way
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

const PICKUP_DEBUG_FALLBACK = '<div class="woocommerce-info ss-agent-info ss-agent-info--lookup_failed">Shipping to closest pickup point</div>';
const PICKUP_DEBUG_NONE_FOUND = '<div class="woocommerce-info ss-agent-info ss-agent-info--none_found">We could not find available pickup points. Please check that the entered address is correct. Your order will be shipped to the closest possible pickup point.</div>';

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

    $transport_detail = 'The connection to the Smart Send API timed out. Please try again. If the problem persists, ask your host '
        . 'whether outgoing requests to app.smartsend.io are blocked or slow.'
        . ' (http_request_failed: cURL error 28: Operation timed out after 30001 milliseconds)';

    $expected_log = 'Smart Send: pickup point lookup for postnord failed with a transport error: '
        . $transport_detail
        . ' Falling back to "Shipping to closest pickup point".';

    $expected_notice = 'Smart Send: Showing pickup point for "Smart Send" (smart_send_shipping:1). '
        . 'Failed with a transport error: ' . $transport_detail;

    expect($output)->toContain(PICKUP_DEBUG_FALLBACK)
        ->and(pickup_debug_notice_texts())->toContain($expected_notice)
        ->and(implode("\n", pickup_debug_logged($spy, 'error')))->toContain($expected_log);
});

it('surfaces an authentication failure as an error box, a debug notice and an error log entry', function () {
    with_option('woocommerce_shipping_debug_mode', 'yes');
    $spy = spy_on_logger();
    mock_smart_send_api(function () {
        return ss_api_response(401, [
            'message' => 'The API token is invalid.',
        ]);
    });

    $output = pickup_debug_render();

    $expected_log = 'Smart Send: pickup point lookup for postnord failed with an authentication error (HTTP 401): '
        . 'The API token is invalid.';

    $expected_notice = 'Smart Send: Showing pickup point for "Smart Send" (smart_send_shipping:1). '
        . 'Failed with an authentication error (HTTP 401): The API token is invalid.';

    expect($output)->toContain('<div class="woocommerce-error ss-agent-info ss-agent-info--auth_failed">The shop is not correctly connected with Smart Send.</div>')
        ->and(pickup_debug_notice_texts())->toContain($expected_notice)
        ->and(implode("\n", pickup_debug_logged($spy, 'error')))->toContain($expected_log);
});

it('surfaces an authorization failure as an error box, a debug notice and an error log entry', function () {
    with_option('woocommerce_shipping_debug_mode', 'yes');
    $spy = spy_on_logger();
    mock_smart_send_api(function () {
        return ss_api_response(403, [
            'message' => 'Your plan does not include pickup points.',
        ]);
    });

    $output = pickup_debug_render();

    $expected_log = 'Smart Send: pickup point lookup for postnord failed with an authorization error (HTTP 403): '
        . 'Your plan does not include pickup points.';

    $expected_notice = 'Smart Send: Showing pickup point for "Smart Send" (smart_send_shipping:1). '
        . 'Failed with an authorization error (HTTP 403): Your plan does not include pickup points.';

    expect($output)->toContain('<div class="woocommerce-error ss-agent-info ss-agent-info--access_denied">The shop does not have access to pickup points.</div>')
        ->and(pickup_debug_notice_texts())->toContain($expected_notice)
        ->and(implode("\n", pickup_debug_logged($spy, 'error')))->toContain($expected_log);
});

it('surfaces a missing API token as an error box and an error log entry without an API call', function () {
    with_option('woocommerce_shipping_debug_mode', 'yes');
    with_ss_settings(['api_token' => '', 'demo' => 'no']);
    $spy = spy_on_logger();
    $capture = mock_smart_send_api();

    $output = pickup_debug_render();

    $expected_log = 'Smart Send: pickup point lookup skipped - no API token configured (plugin not connected).';

    $expected_notice = 'Smart Send: Showing pickup point for "Smart Send" (smart_send_shipping:1). '
        . 'No API token configured (plugin not connected).';

    expect($output)->toContain('<div class="woocommerce-error ss-agent-info ss-agent-info--not_connected">Connect the Smart Send plugin to enable pickup points.</div>')
        ->and($capture->requests)->toBeEmpty()
        ->and(pickup_debug_notice_texts())->toContain($expected_notice)
        ->and(implode("\n", pickup_debug_logged($spy, 'error')))->toContain($expected_log);
});

it('reports a validation error body by its message', function () {
    with_option('woocommerce_shipping_debug_mode', 'yes');
    spy_on_logger();
    mock_smart_send_api(function () {
        return ss_api_response(422, ['message' => 'The given data was invalid.']);
    });

    $output = pickup_debug_render();

    expect($output)->toContain(PICKUP_DEBUG_FALLBACK)
        ->and(pickup_debug_notice_texts())->toContain(
            'Smart Send: Showing pickup point for "Smart Send" (smart_send_shipping:1). '
            . 'Failed with an API error: The given data was invalid.'
        );
});

it('reports an empty agent list as a debug notice and logs it at info level', function () {
    with_option('woocommerce_shipping_debug_mode', 'yes');
    $spy = spy_on_logger();
    mock_smart_send_api(function () {
        return ss_api_response(200, ['data' => []]);
    });

    $output = pickup_debug_render();

    $expected_log = 'Smart Send: no postnord pickup points found near the entered address.';

    $expected_notice = 'Smart Send: Showing pickup point for "Smart Send" (smart_send_shipping:1). '
        . 'No pickup points found near the entered address.';

    expect($output)->toContain(PICKUP_DEBUG_NONE_FOUND)
        ->and(pickup_debug_notice_texts())->toContain($expected_notice)
        // Worth noticing but not a fault: logged at info (always on), never error.
        ->and(implode("\n", pickup_debug_logged($spy, 'info')))->toContain($expected_log)
        ->and(implode("\n", pickup_debug_logged($spy, 'error')))->not->toContain('no postnord pickup points');
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
            'Smart Send: pickup point lookup for postnord failed with a transport error: '
            . 'Could not connect to the Smart Send API'
        );
});

it('adds no notice for an empty agent list when shipping debug mode is off', function () {
    with_option('woocommerce_shipping_debug_mode', 'no');
    spy_on_logger();
    mock_smart_send_api(function () {
        return ss_api_response(200, ['data' => []]);
    });

    $output = pickup_debug_render();

    expect($output)->toContain(PICKUP_DEBUG_NONE_FOUND)
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
        ->and($output)->not->toContain('Shipping to closest pickup point')
        ->and(implode("\n", pickup_debug_notice_texts()))->not->toContain('pickup point lookup');
});

it('surfaces a success summary with the method and result count (#92)', function () {
    with_option('woocommerce_shipping_debug_mode', 'yes');
    with_ss_settings(['ss_debug' => 'yes']); // Debug-level log entries require the plugin debug setting.
    $spy = spy_on_logger();
    mock_smart_send_api(function () {
        return ss_api_response(200, ['data' => [sample_agent(), sample_agent(['agent_no' => '5678'])]]);
    });

    $output = pickup_debug_render();

    $expected_log = 'Smart Send: found 2 postnord pickup points near the entered address.';

    $expected_notice = 'Smart Send: Showing pickup point for "Smart Send" (smart_send_shipping:1). '
        . 'Found 2 pickup points near the entered address.';

    expect($output)->toContain('ss_shipping_store_pickup')
        ->and(pickup_debug_notice_texts())->toContain($expected_notice)
        ->and(implode("\n", pickup_debug_logged($spy, 'debug')))->toContain($expected_log);
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
