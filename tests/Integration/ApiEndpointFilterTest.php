<?php

/*
 * Tests for the smart_send_api_endpoint contract (#170): since 9.0.0 the
 * filter receives and returns the HOST only (e.g. 'https://app.smartsend.io')
 * and the client appends the API version path ('/api/v1/') itself, so a
 * sandbox override survives the plugin moving to a newer API version. The
 * filter is applied once, by SS_Shipping_Api_Factory - the single
 * construction path for API clients - which also guards against the
 * pre-9.0 mistake of returning a full '/api/v1/' URL.
 */

/**
 * Capture every outgoing Smart Send API request regardless of host (the
 * shared mock_smart_send_api() helper only intercepts smartsend.io) and
 * answer it with a successful account response.
 */
function capture_api_requests_to_any_host(): object
{
    $capture = new class {
        public array $urls = [];
    };

    $filter = function ($pre, $args, $url) use ($capture) {
        if (strpos($url, 'website/') === false) {
            return $pre;
        }

        $capture->urls[] = $url;

        return ss_api_response(200, ['data' => ['id' => 1, 'email' => 'dev@smartsend.io']]);
    };
    add_filter('pre_http_request', $filter, 10, 3);
    remember_cleanup_callback(function () use ($filter): void {
        remove_filter('pre_http_request', $filter, 10);
    });

    return $capture;
}

/**
 * Register a smart_send_api_endpoint override for the test and record what
 * the filter receives.
 */
function override_api_endpoint(string $return_value): object
{
    $seen = new class {
        public array $received = [];
    };

    $filter = function ($api_host) use ($seen, $return_value) {
        $seen->received[] = $api_host;

        return $return_value;
    };
    add_filter('smart_send_api_endpoint', $filter);
    remember_cleanup_callback(function () use ($filter): void {
        remove_filter('smart_send_api_endpoint', $filter);
    });

    return $seen;
}


/**
 * Replace the logger's WC_Logger with a spy that records every entry.
 * Restored automatically after the test. (Local twin of the LoggerTest
 * helper so this file does not depend on test-file load order.)
 */
function spy_on_logger_for_endpoint(): object
{
    $spy = new class {
        public array $entries = [];

        public function log($level, $message, $context = []): void
        {
            $this->entries[] = ['level' => $level, 'message' => $message, 'context' => $context];
        }

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

beforeEach(function (): void {
    with_ss_settings();
});

it('talks to the production host with the API version path appended by default', function () {
    $capture = capture_api_requests_to_any_host();

    (new SS_Shipping_Api_Factory())->create()->account()->getAuthenticatedUser();

    expect($capture->urls)->toHaveCount(1)
        ->and($capture->urls[0])->toStartWith('https://app.smartsend.io/api/v1/website/');
});

it('passes the host only to smart_send_api_endpoint and appends the API version path itself', function () {
    $capture = capture_api_requests_to_any_host();
    $seen    = override_api_endpoint('https://app.smartsend.dev');

    (new SS_Shipping_Api_Factory())->create()->account()->getAuthenticatedUser();

    // The filter receives the bare host, never the versioned base URL.
    expect($seen->received)->toBe(['https://app.smartsend.io'])
        ->and($capture->urls)->toHaveCount(1)
        ->and($capture->urls[0])->toStartWith('https://app.smartsend.dev/api/v1/website/');
});

it('tolerates a trailing slash on the filtered host', function () {
    $capture = capture_api_requests_to_any_host();
    override_api_endpoint('https://app.smartsend.dev/');

    (new SS_Shipping_Api_Factory())->create()->account()->getAuthenticatedUser();

    expect($capture->urls[0])->toStartWith('https://app.smartsend.dev/api/v1/website/');
});

it('strips a pre-9.0 style /api/v1/ suffix from the filtered value and logs a warning', function ($filtered_value) {
    $spy     = spy_on_logger_for_endpoint();
    $capture = capture_api_requests_to_any_host();
    override_api_endpoint($filtered_value);

    (new SS_Shipping_Api_Factory())->create()->account()->getAuthenticatedUser();

    // Never '/api/v1/api/v1/'.
    expect($capture->urls)->toHaveCount(1)
        ->and($capture->urls[0])->toStartWith('https://app.smartsend.dev/api/v1/website/')
        ->and($capture->urls[0])->not->toContain('/api/v1/api/v1');

    $warnings = array_values(array_filter($spy->entries, fn ($entry) => $entry['level'] === 'warning'));
    expect($warnings)->toHaveCount(1)
        ->and($warnings[0]['message'])->toContain('smart_send_api_endpoint')
        ->and($warnings[0]['message'])->toContain('host only')
        ->and($warnings[0]['context']['filtered_value'])->toBe($filtered_value)
        ->and($warnings[0]['context']['api_host'])->toBe('https://app.smartsend.dev');
})->with([
    'with trailing slash'    => ['https://app.smartsend.dev/api/v1/'],
    'without trailing slash' => ['https://app.smartsend.dev/api/v1'],
]);

it('logs no warning for a plain host', function () {
    $spy = spy_on_logger_for_endpoint();
    capture_api_requests_to_any_host();
    override_api_endpoint('https://app.smartsend.dev');

    (new SS_Shipping_Api_Factory())->create()->account()->getAuthenticatedUser();

    $warnings = array_filter($spy->entries, fn ($entry) => $entry['level'] === 'warning');
    expect($warnings)->toBe([]);
});

it('builds the endpoint from host + API version path in the client itself', function () {
    // The lib-level guarantee behind the factory: the client owns the API
    // version path and normalizes a versioned host defensively too.
    $client = new \Smartsend\Client('token', 'example.test');
    expect($client->getApiHost())->toBe('https://app.smartsend.io')
        ->and($client->getApiEndpoint())->toBe('https://app.smartsend.io/api/v1/website/example.test/');

    $client = new \Smartsend\Client('token', 'example.test', 'https://sandbox.example/');
    expect($client->getApiHost())->toBe('https://sandbox.example')
        ->and($client->getApiEndpoint())->toBe('https://sandbox.example/api/v1/website/example.test/');

    $client = new \Smartsend\Client('token', 'example.test', 'https://sandbox.example/api/v1/');
    expect($client->getApiHost())->toBe('https://sandbox.example')
        ->and($client->getApiEndpoint())->toBe('https://sandbox.example/api/v1/website/example.test/');

    expect(\Smartsend\Client::hasApiVersionPath('https://sandbox.example/api/v1'))->toBeTrue()
        ->and(\Smartsend\Client::hasApiVersionPath('https://sandbox.example/'))->toBeFalse()
        ->and(\Smartsend\Client::hasApiVersionPath('https://sandbox.example'))->toBeFalse();
});
