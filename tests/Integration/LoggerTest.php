<?php

/*
 * Tests for the static SS_Shipping_Logger, a thin wrapper around
 * wc_get_logger(): the debug setting gates only the debug level inside the
 * class while info/warning/error/critical always log, the smart_send_logging filter
 * can rewrite or suppress entries at all levels, structured data travels in the
 * context array (rendered natively by the WC log viewer), and API requests
 * made through Smartsend\Client produce one concise entry with method,
 * endpoint, HTTP status and timing.
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

        /**
         * The logger dispatches to wc_get_logger()'s level wrapper methods
         * (debug(), error(), ...); record them like log() calls.
         */
        public function __call(string $level, array $args): void
        {
            $this->log($level, $args[0], $args[1] ?? []);
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

it('writes a debug entry with the plugin version and source in the context when debug is enabled', function () {
    with_ss_settings(['ss_debug' => 'yes']);
    $spy = spy_on_logger();

    SS_Shipping_Logger::log('Hello from the test');

    expect($spy->entries)->toHaveCount(1);
    expect($spy->entries[0]['level'])->toBe('debug');
    expect($spy->entries[0]['message'])->toBe('Hello from the test');
    expect($spy->entries[0]['context']['source'])->toBe('smart-send-logistics');
    expect($spy->entries[0]['context']['version'])->toBe(SS_SHIPPING_VERSION);
});

it('merges caller-provided context into the entry', function () {
    with_ss_settings(['ss_debug' => 'yes']);
    $spy = spy_on_logger();

    SS_Shipping_Logger::debug('With context', ['order_id' => 42]);

    expect($spy->entries)->toHaveCount(1);
    expect($spy->entries[0]['context']['order_id'])->toBe(42);
    expect($spy->entries[0]['context']['source'])->toBe('smart-send-logistics');
});

it('gates only debug on the debug setting; info, warning, error and critical always log', function () {
    with_ss_settings(['ss_debug' => 'no']);
    $spy = spy_on_logger();

    SS_Shipping_Logger::log('gated');
    SS_Shipping_Logger::debug('gated');
    SS_Shipping_Logger::info('an info line');
    SS_Shipping_Logger::warning('a warning');
    SS_Shipping_Logger::error('an error');
    SS_Shipping_Logger::critical('a critical failure');

    expect(array_column($spy->entries, 'level'))->toBe(['info', 'warning', 'error', 'critical']);
});

it('logs debug and info when the debug setting is on', function () {
    with_ss_settings(['ss_debug' => 'yes']);
    $spy = spy_on_logger();

    SS_Shipping_Logger::debug('a trace');
    SS_Shipping_Logger::info('an info line');

    expect(array_column($spy->entries, 'level'))->toBe(['debug', 'info']);
});

it('can be suppressed by returning false from the smart_send_logging filter', function () {
    with_ss_settings(['ss_debug' => 'yes']);
    $spy = spy_on_logger();
    with_smart_send_logging_filter('__return_false', 1);

    SS_Shipping_Logger::log('Suppressed');
    SS_Shipping_Logger::error('Suppressed error');
    SS_Shipping_Logger::critical('Suppressed critical');

    expect($spy->entries)->toBeEmpty();
});

it('can be suppressed by returning null from the smart_send_logging filter', function () {
    with_ss_settings(['ss_debug' => 'yes']);
    $spy = spy_on_logger();
    with_smart_send_logging_filter('__return_null', 1);

    SS_Shipping_Logger::log('Suppressed');
    SS_Shipping_Logger::error('Suppressed error');

    expect($spy->entries)->toBeEmpty();
});

it('can rewrite the message through the smart_send_logging filter', function () {
    with_ss_settings(['ss_debug' => 'yes']);
    $spy = spy_on_logger();
    with_smart_send_logging_filter(function ($message) {
        return '[rewritten] ' . $message;
    }, 1);

    SS_Shipping_Logger::log('A trace');

    expect($spy->entries)->toHaveCount(1);
    expect($spy->entries[0]['message'])->toBe('[rewritten] A trace');
});

it('does not suppress the message "0"', function () {
    with_ss_settings(['ss_debug' => 'yes']);
    $spy = spy_on_logger();

    SS_Shipping_Logger::log('0');

    expect($spy->entries)->toHaveCount(1);
    expect($spy->entries[0]['message'])->toBe('0');
});

it('passes the message, level and context to the smart_send_logging filter', function () {
    with_ss_settings(['ss_debug' => 'yes']);
    spy_on_logger();
    $seen = [];
    with_smart_send_logging_filter(function ($message, $level, $context) use (&$seen) {
        $seen[] = ['message' => $message, 'level' => $level, 'context' => $context];

        return $message;
    });

    SS_Shipping_Logger::log('A trace', ['order_id' => 7]);
    SS_Shipping_Logger::error('A failure');

    expect($seen)->toHaveCount(2);
    expect($seen[0]['message'])->toBe('A trace');
    expect($seen[0]['level'])->toBe('debug');
    expect($seen[0]['context']['order_id'])->toBe(7);
    expect($seen[0]['context']['source'])->toBe('smart-send-logistics');
    expect($seen[1]['message'])->toBe('A failure');
    expect($seen[1]['level'])->toBe('error');
});

it('logs errors at the error level even when the debug setting is off', function () {
    with_ss_settings(['ss_debug' => 'no']);
    $spy = spy_on_logger();

    SS_Shipping_Logger::error('Something broke');

    expect($spy->entries)->toHaveCount(1);
    expect($spy->entries[0]['level'])->toBe('error');
    expect($spy->entries[0]['message'])->toBe('Something broke');
});

it('logs successful API calls as one concise line with method, path, HTTP status and timing', function () {
    with_ss_settings(['ss_debug' => 'yes']);
    $spy = spy_on_logger();
    mock_smart_send_api(function () {
        return ss_api_response(200, ['data' => ss_api_shipment_data(['shipment_id' => 'log-test-shipment'])]);
    });

    create_logging_api_client()->getAuthenticatedUser();

    expect($spy->entries)->toHaveCount(1);
    expect($spy->entries[0]['level'])->toBe('debug');
    expect($spy->entries[0]['message'])->toMatch('#^GET /api/v1/demo/website/example\.test/ → 200 \(\d+ms\)$#u');

    $context = $spy->entries[0]['context'];
    expect($context['source'])->toBe('smart-send-logistics');
    expect($context['status_code'])->toBe(200);
    expect($context['endpoint'])->toContain('https://app.smartsend.io/api/v1/demo/website/example.test/');
    expect($context['duration_ms'])->toBeInt();
    expect($context['response_body'])->toContain('log-test-shipment');
});

it('redacts the API token in both the message and the context', function () {
    with_ss_settings(['ss_debug' => 'yes']);
    $spy = spy_on_logger();
    mock_smart_send_api();

    create_logging_api_client('secret-token-123')->getAuthenticatedUser();

    expect($spy->entries)->toHaveCount(1);
    expect($spy->entries[0]['message'])->not->toContain('secret-token-123');
    expect($spy->entries[0]['context']['endpoint'])->toContain('api_token=[REDACTED]');
    expect(json_encode($spy->entries[0]['context']))->not->toContain('secret-token-123');
});

it('logs failed API calls at the error level with the error detail in context even when debug is off', function () {
    with_ss_settings(['ss_debug' => 'no']);
    $spy = spy_on_logger();
    mock_smart_send_api(function () {
        return ss_api_response(422, ss_api_error_body('The given data was invalid.'));
    });

    create_logging_api_client()->getAuthenticatedUser();

    expect($spy->entries)->toHaveCount(1);
    expect($spy->entries[0]['level'])->toBe('error');
    expect($spy->entries[0]['message'])->toContain('→ 422');

    $context = $spy->entries[0]['context'];
    expect($context['status_code'])->toBe(422);
    expect($context['error'])->toContain('The given data was invalid.');
    expect($context['response_body'])->toContain('The given data was invalid.');
});

it('includes the request body of POST requests in the context', function () {
    with_ss_settings(['ss_debug' => 'yes']);
    $spy = spy_on_logger();
    mock_smart_send_api();

    create_logging_api_client()->bookings()->combine(['shipment-1', 'shipment-2']);

    expect($spy->entries)->toHaveCount(1);
    expect($spy->entries[0]['message'])->toStartWith('POST ');
    expect($spy->entries[0]['context']['request_body'])->toContain('shipment-1');
});

it('exposes the WooCommerce log screen URL', function () {
    expect(SS_Shipping_Logger::get_log_url())->toContain('page=wc-status&tab=logs');
});
