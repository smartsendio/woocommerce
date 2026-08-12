<?php

/*
 * Tests for the static SS_Shipping_Logger (modeled on WC_Stripe_Logger):
 * the debug setting is checked inside the class, the smart_send_logging
 * filter can force logging on/off, errors are logged at the error level
 * even when the debug setting is off, and API requests made through
 * Smartsend\Client produce entries with method, endpoint and HTTP status.
 */

/**
 * Replace the logger's WC_Logger with a spy that records every entry.
 * Restored automatically after the test.
 */
function spy_on_logger(): object
{
    $spy = new class {
        public array $entries = [];

        public function log($level, $message, $context = []): void
        {
            $this->entries[] = ['level' => $level, 'message' => $message, 'context' => $context];
        }
    };

    SS_Shipping_Logger::$logger = $spy;

    remember_cleanup_callback(function (): void {
        SS_Shipping_Logger::$logger = null;
    });

    return $spy;
}

/**
 * Add a smart_send_logging filter callback, removed after the test.
 */
function with_smart_send_logging_filter(callable $callback, int $accepted_args = 3): void
{
    add_filter('smart_send_logging', $callback, 10, $accepted_args);

    remember_cleanup_callback(function () use ($callback): void {
        remove_filter('smart_send_logging', $callback, 10);
    });
}

/**
 * A Smartsend API client wired to the plugin's logger, like
 * SS_Shipping_WC::get_api_handle() does it.
 */
function create_logging_api_client(string $token = 'secret-token-123'): \Smartsend\Api
{
    $api = new \Smartsend\Api($token, 'example.test', true);
    $api->setRequestLogger(['SS_Shipping_Logger', 'log_api_request']);

    return $api;
}

it('writes a version-stamped debug entry under the plugin log source when debug is enabled', function () {
    with_ss_settings(['ss_debug' => 'yes']);
    $spy = spy_on_logger();

    SS_Shipping_Logger::log('Hello from the test');

    expect($spy->entries)->toHaveCount(1);
    expect($spy->entries[0]['level'])->toBe('debug');
    expect($spy->entries[0]['message'])->toContain('====Smart Send Version: ' . SS_SHIPPING_VERSION . '====');
    expect($spy->entries[0]['message'])->toContain('====Start Log====');
    expect($spy->entries[0]['message'])->toContain('Hello from the test');
    expect($spy->entries[0]['message'])->toContain('====End Log====');
    expect($spy->entries[0]['context'])->toBe(['source' => 'smart-send-logistics']);
});

it('adds a timing block when a start time is given', function () {
    with_ss_settings(['ss_debug' => 'yes']);
    $spy = spy_on_logger();

    SS_Shipping_Logger::log('Timed message', time() - 120, time());

    expect($spy->entries)->toHaveCount(1);
    expect($spy->entries[0]['message'])->toContain('====Start Log ');
    expect($spy->entries[0]['message'])->toMatch('/====End Log .* \(2\)====/');
});

it('does not write debug entries when the debug setting is off', function () {
    with_ss_settings(['ss_debug' => 'no']);
    $spy = spy_on_logger();

    SS_Shipping_Logger::log('Should not appear');

    expect($spy->entries)->toBeEmpty();
});

it('can be force-enabled through the smart_send_logging filter', function () {
    with_ss_settings(['ss_debug' => 'no']);
    $spy = spy_on_logger();
    with_smart_send_logging_filter('__return_true', 1);

    SS_Shipping_Logger::log('Forced on');

    expect($spy->entries)->toHaveCount(1);
    expect($spy->entries[0]['message'])->toContain('Forced on');
});

it('can be suppressed through the smart_send_logging filter', function () {
    with_ss_settings(['ss_debug' => 'yes']);
    $spy = spy_on_logger();
    with_smart_send_logging_filter('__return_false', 1);

    SS_Shipping_Logger::log('Suppressed');
    SS_Shipping_Logger::error('Suppressed error');

    expect($spy->entries)->toBeEmpty();
});

it('passes the message and level to the smart_send_logging filter', function () {
    with_ss_settings(['ss_debug' => 'yes']);
    spy_on_logger();
    $seen = [];
    with_smart_send_logging_filter(function ($enabled, $message, $level) use (&$seen) {
        $seen[] = ['enabled' => $enabled, 'message' => $message, 'level' => $level];

        return $enabled;
    });

    SS_Shipping_Logger::log('A trace');
    SS_Shipping_Logger::error('A failure');

    expect($seen)->toHaveCount(2);
    expect($seen[0])->toBe(['enabled' => true, 'message' => 'A trace', 'level' => 'debug']);
    expect($seen[1])->toBe(['enabled' => true, 'message' => 'A failure', 'level' => 'error']);
});

it('logs errors at the error level even when the debug setting is off', function () {
    with_ss_settings(['ss_debug' => 'no']);
    $spy = spy_on_logger();

    SS_Shipping_Logger::error('Something broke');

    expect($spy->entries)->toHaveCount(1);
    expect($spy->entries[0]['level'])->toBe('error');
    expect($spy->entries[0]['message'])->toContain('Something broke');
});

it('logs successful API calls with method, endpoint and HTTP status', function () {
    with_ss_settings(['ss_debug' => 'yes']);
    $spy = spy_on_logger();
    mock_smart_send_api(function () {
        return ss_api_response(200, ['data' => ss_api_shipment_data(['shipment_id' => 'log-test-shipment'])]);
    });

    create_logging_api_client()->getAuthenticatedUser();

    expect($spy->entries)->toHaveCount(1);
    expect($spy->entries[0]['level'])->toBe('debug');
    expect($spy->entries[0]['message'])->toContain('GET https://app.smartsend.io/api/v1/demo/website/example.test/');
    expect($spy->entries[0]['message'])->toContain('[HTTP 200]');
    expect($spy->entries[0]['message'])->toContain('log-test-shipment');
});

it('redacts the API token from the logged endpoint', function () {
    with_ss_settings(['ss_debug' => 'yes']);
    $spy = spy_on_logger();
    mock_smart_send_api();

    create_logging_api_client('secret-token-123')->getAuthenticatedUser();

    expect($spy->entries)->toHaveCount(1);
    expect($spy->entries[0]['message'])->toContain('api_token=[REDACTED]');
    expect($spy->entries[0]['message'])->not->toContain('secret-token-123');
});

it('logs failed API calls at the error level even when the debug setting is off', function () {
    with_ss_settings(['ss_debug' => 'no']);
    $spy = spy_on_logger();
    mock_smart_send_api(function () {
        return ss_api_response(422, ss_api_error_body('The given data was invalid.'));
    });

    create_logging_api_client()->getAuthenticatedUser();

    expect($spy->entries)->toHaveCount(1);
    expect($spy->entries[0]['level'])->toBe('error');
    expect($spy->entries[0]['message'])->toContain('[HTTP 422]');
    expect($spy->entries[0]['message'])->toContain('The given data was invalid.');
});

it('logs the request body of POST requests', function () {
    with_ss_settings(['ss_debug' => 'yes']);
    $spy = spy_on_logger();
    mock_smart_send_api();

    create_logging_api_client()->combineLabelsForShipments(['shipment-1', 'shipment-2']);

    expect($spy->entries)->toHaveCount(1);
    expect($spy->entries[0]['message'])->toContain('POST ');
    expect($spy->entries[0]['message'])->toContain('Request body: ');
    expect($spy->entries[0]['message'])->toContain('shipment-1');
});

it('exposes the WooCommerce log screen URL', function () {
    expect(SS_Shipping_Logger::get_log_url())->toContain('page=wc-status&tab=logs');
});
