<?php

/*
 * Tests for the Smartsend\Client HTTP error handling (issue #38): every
 * failure path — API validation errors, transport WP_Errors (connection,
 * timeout, SSL), empty and malformed bodies — must produce a well-formed
 * Smartsend\Models\Error with a distinguishable code, keep getErrorString()
 * working, and land in the log at the error level.
 */

use Smartsend\Api;
use Smartsend\Models\Error;

/**
 * Replace the logger's WC_Logger with a spy that records every entry.
 * Restored automatically after the test. (Local twin of the LoggerTest
 * helper so this file does not depend on test-file load order.)
 */
function spy_on_ss_logger(): object
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
 * An API client wired to the plugin logger, plus a logger spy, like the
 * plugin wires it in SS_Shipping_WC::get_api_handle().
 *
 * @return array{0: Api, 1: object} [client, logger spy]
 */
function create_client_with_log_spy(): array
{
    $spy = spy_on_ss_logger();

    $api = new Api('secret-token-123', 'example.test', true);
    $api->setRequestLogger(['SS_Shipping_Logger', 'log_api_request']);

    return [$api, $spy];
}

/**
 * Respond to every Smart Send API request with a WP_Error carrying the
 * given code and message.
 */
function mock_smart_send_transport_error(string $code, string $message): object
{
    return mock_smart_send_api(function () use ($code, $message) {
        return new WP_Error($code, $message);
    });
}

it('returns data and no error on a successful response', function () {
    with_ss_settings(['ss_debug' => 'yes']);
    [$api, $spy] = create_client_with_log_spy();
    mock_smart_send_api(function () {
        return ss_api_response(200, ['data' => ss_api_shipment_data(['shipment_id' => 'success-shipment'])]);
    });

    $result = $api->getAuthenticatedUser();

    expect($result)->toBeTrue();
    expect($api->isSuccessful())->toBeTrue();
    expect($api->getError())->toBeNull();
    expect($api->getData()->shipment_id)->toBe('success-shipment');
    expect($spy->entries)->toHaveCount(1);
    expect($spy->entries[0]['level'])->toBe('debug');
});

it('normalises a 422 validation error body into a well-formed Error', function () {
    with_ss_settings(['ss_debug' => 'no']);
    [$api, $spy] = create_client_with_log_spy();
    mock_smart_send_api(function () {
        return ss_api_response(422, ss_api_error_body('The given data was invalid.'));
    });

    $result = $api->getAuthenticatedUser();

    expect($result)->toBeFalse();
    expect($api->isSuccessful())->toBeFalse();

    $error = $api->getError();
    expect($error)->toBeInstanceOf(Error::class);
    expect($error->code)->toBe('ValidationException');
    expect($error->message)->toBe('The given data was invalid.');
    expect($error->id)->toBe('test-error-id');
    expect((array) $error->errors)->toHaveKey('receiver.postal_code');

    // getErrorString keeps rendering message, about-link, id and field errors
    $error_string = $api->getErrorString();
    expect($error_string)->toContain('The given data was invalid.');
    expect($error_string)->toContain('ValidationException');
    expect($error_string)->toContain('test-error-id');
    expect($error_string)->toContain('The postal code is invalid.');

    // Logged at error level even with debug off, with HTTP status and detail
    expect($spy->entries)->toHaveCount(1);
    expect($spy->entries[0]['level'])->toBe('error');
    expect($spy->entries[0]['message'])->toContain('→ 422');
    expect($spy->entries[0]['context']['error'])->toBe('ValidationException - The given data was invalid.');
});

it('maps a connection failure WP_Error to a transport-connection Error', function () {
    with_ss_settings(['ss_debug' => 'no']);
    [$api, $spy] = create_client_with_log_spy();
    mock_smart_send_transport_error('http_request_failed', 'cURL error 7: Failed to connect to app.smartsend.io port 443: Connection refused');

    $result = $api->getAuthenticatedUser();

    expect($result)->toBeFalse();
    expect($api->isSuccessful())->toBeFalse();

    $error = $api->getError();
    expect($error)->toBeInstanceOf(Error::class);
    expect($error->code)->toBe('transport-connection');
    expect($error->message)->toContain('Could not connect to the Smart Send API');
    expect($error->errors['transport'][0])->toBe('http_request_failed: cURL error 7: Failed to connect to app.smartsend.io port 443: Connection refused');

    // The raw transport detail is available to support via getErrorString
    expect($api->getErrorString())->toContain('Connection refused');

    expect($spy->entries)->toHaveCount(1);
    expect($spy->entries[0]['level'])->toBe('error');
    expect($spy->entries[0]['message'])->toContain('→ n/a');
    expect($spy->entries[0]['context']['error'])->toContain('transport-connection');
});

it('maps a timeout WP_Error to a transport-timeout Error', function () {
    with_ss_settings(['ss_debug' => 'no']);
    [$api, $spy] = create_client_with_log_spy();
    mock_smart_send_transport_error('http_request_failed', 'cURL error 28: Operation timed out after 30001 milliseconds with 0 bytes received');

    $result = $api->getAuthenticatedUser();

    expect($result)->toBeFalse();
    $error = $api->getError();
    expect($error)->toBeInstanceOf(Error::class);
    expect($error->code)->toBe('transport-timeout');
    expect($error->message)->toContain('timed out');
    expect($error->errors['transport'][0])->toContain('cURL error 28');

    expect($spy->entries)->toHaveCount(1);
    expect($spy->entries[0]['level'])->toBe('error');
    expect($spy->entries[0]['message'])->toContain('→ n/a');
    expect($spy->entries[0]['context']['error'])->toContain('transport-timeout');
});

it('maps a stream timeout message from WP_Http_Streams to transport-timeout too', function () {
    with_ss_settings(['ss_debug' => 'no']);
    [$api] = create_client_with_log_spy();
    mock_smart_send_transport_error('http_request_failed', 'stream_socket_client(): unable to connect (Connection timed out)');

    $api->getAuthenticatedUser();

    expect($api->getError()->code)->toBe('transport-timeout');
});

it('maps an SSL failure WP_Error to a transport-ssl Error instead of the old curl-35 mapping', function () {
    with_ss_settings(['ss_debug' => 'no']);
    [$api, $spy] = create_client_with_log_spy();
    mock_smart_send_transport_error('http_request_failed', 'cURL error 35: SSL connect error');

    $result = $api->getAuthenticatedUser();

    expect($result)->toBeFalse();
    $error = $api->getError();
    expect($error)->toBeInstanceOf(Error::class);
    expect($error->code)->toBe('transport-ssl');
    expect($error->code)->not->toBe('curl-35');
    expect($error->message)->toContain('SSL/TLS');
    expect($error->errors['transport'][0])->toBe('http_request_failed: cURL error 35: SSL connect error');

    expect($spy->entries)->toHaveCount(1);
    expect($spy->entries[0]['level'])->toBe('error');
    expect($spy->entries[0]['message'])->toContain('→ n/a');
    expect($spy->entries[0]['context']['error'])->toContain('transport-ssl');
});

it('maps a certificate verification failure to transport-ssl', function () {
    with_ss_settings(['ss_debug' => 'no']);
    [$api] = create_client_with_log_spy();
    mock_smart_send_transport_error('http_request_failed', 'cURL error 60: SSL certificate problem: unable to get local issuer certificate');

    $api->getAuthenticatedUser();

    expect($api->getError()->code)->toBe('transport-ssl');
});

it('keeps the WP_Error code recognisable for unclassified transport failures', function () {
    with_ss_settings(['ss_debug' => 'no']);
    [$api] = create_client_with_log_spy();
    mock_smart_send_transport_error('http_request_not_executed', 'User has blocked requests through HTTP.');

    $api->getAuthenticatedUser();

    $error = $api->getError();
    expect($error)->toBeInstanceOf(Error::class);
    expect($error->code)->toBe('transport-http_request_not_executed');
    expect($error->message)->toContain('User has blocked requests through HTTP.');
});

it('produces a well-formed Error for a non-2xx response with an empty body', function () {
    with_ss_settings(['ss_debug' => 'no']);
    [$api, $spy] = create_client_with_log_spy();
    mock_smart_send_api(function () {
        return [
            'response' => ['code' => 503, 'message' => 'Service Unavailable'],
            'headers'  => [],
            'body'     => '',
            'cookies'  => [],
            'filename' => null,
        ];
    });

    $result = $api->getAuthenticatedUser();

    expect($result)->toBeFalse();
    $error = $api->getError();
    expect($error)->toBeInstanceOf(Error::class);
    expect($error->code)->toBe('api-empty-response');
    expect($error->message)->toContain('HTTP 503');
    expect($api->getErrorString())->toBeString();

    expect($spy->entries)->toHaveCount(1);
    expect($spy->entries[0]['level'])->toBe('error');
    expect($spy->entries[0]['message'])->toContain('→ 503');
    expect($spy->entries[0]['context']['error'])->toContain('api-empty-response');
});

it('produces a well-formed Error for a non-2xx response with a non-JSON body', function () {
    with_ss_settings(['ss_debug' => 'no']);
    [$api, $spy] = create_client_with_log_spy();
    mock_smart_send_api(function () {
        return [
            'response' => ['code' => 502, 'message' => 'Bad Gateway'],
            'headers'  => ['content-type' => 'text/html'],
            'body'     => '<html><body><h1>502 Bad Gateway</h1></body></html>',
            'cookies'  => [],
            'filename' => null,
        ];
    });

    $result = $api->getAuthenticatedUser();

    expect($result)->toBeFalse();
    $error = $api->getError();
    expect($error)->toBeInstanceOf(Error::class);
    expect($error->code)->toBe('api-malformed-response');
    expect($error->message)->toContain('HTTP 502');
    // The raw body is preserved (truncated) for support
    expect($error->errors['response'][0])->toContain('502 Bad Gateway');

    expect($spy->entries)->toHaveCount(1);
    expect($spy->entries[0]['level'])->toBe('error');
    expect($spy->entries[0]['message'])->toContain('→ 502');
    expect($spy->entries[0]['context']['error'])->toContain('api-malformed-response');
});

it('produces a well-formed Error for a non-2xx response with malformed JSON', function () {
    with_ss_settings(['ss_debug' => 'no']);
    [$api] = create_client_with_log_spy();
    mock_smart_send_api(function () {
        return [
            'response' => ['code' => 500, 'message' => 'Internal Server Error'],
            'headers'  => ['content-type' => 'application/json'],
            'body'     => '{"message": "broken json',
            'cookies'  => [],
            'filename' => null,
        ];
    });

    $result = $api->getAuthenticatedUser();

    expect($result)->toBeFalse();
    $error = $api->getError();
    expect($error)->toBeInstanceOf(Error::class);
    expect($error->code)->toBe('api-malformed-response');
    expect($error->message)->toContain('HTTP 500');
});

it('produces a well-formed Error for a 2xx response with malformed JSON', function () {
    with_ss_settings(['ss_debug' => 'no']);
    [$api, $spy] = create_client_with_log_spy();
    mock_smart_send_api(function () {
        return [
            'response' => ['code' => 200, 'message' => 'OK'],
            'headers'  => ['content-type' => 'application/json'],
            'body'     => 'not json at all',
            'cookies'  => [],
            'filename' => null,
        ];
    });

    $result = $api->getAuthenticatedUser();

    expect($result)->toBeFalse();
    $error = $api->getError();
    expect($error)->toBeInstanceOf(Error::class);
    expect($error->code)->toBe('api-malformed-response');
    expect($error->message)->toContain('HTTP 200');

    expect($spy->entries)->toHaveCount(1);
    expect($spy->entries[0]['level'])->toBe('error');
});

it('produces a well-formed Error for a 2xx response with an empty body', function () {
    with_ss_settings(['ss_debug' => 'no']);
    [$api] = create_client_with_log_spy();
    mock_smart_send_api(function () {
        return [
            'response' => ['code' => 200, 'message' => 'OK'],
            'headers'  => ['content-type' => 'application/json'],
            'body'     => '',
            'cookies'  => [],
            'filename' => null,
        ];
    });

    $result = $api->getAuthenticatedUser();

    expect($result)->toBeFalse();
    $error = $api->getError();
    expect($error)->toBeInstanceOf(Error::class);
    expect($error->code)->toBe('api-empty-response');
});

it('keeps the NoResults error for a 2xx response with an empty data set', function () {
    with_ss_settings(['ss_debug' => 'no']);
    [$api] = create_client_with_log_spy();
    mock_smart_send_api(function () {
        return ss_api_response(200, ['data' => []]);
    });

    $result = $api->getAuthenticatedUser();

    expect($result)->toBeFalse();
    $error = $api->getError();
    expect($error)->toBeInstanceOf(Error::class);
    expect($error->code)->toBe('NoResults');
    expect($error->message)->toBe('No results found');
});

it('truncates huge non-JSON bodies embedded in the error details', function () {
    with_ss_settings(['ss_debug' => 'no']);
    [$api] = create_client_with_log_spy();
    mock_smart_send_api(function () {
        return [
            'response' => ['code' => 502, 'message' => 'Bad Gateway'],
            'headers'  => ['content-type' => 'text/html'],
            'body'     => str_repeat('x', 2000),
            'cookies'  => [],
            'filename' => null,
        ];
    });

    $api->getAuthenticatedUser();

    $details = $api->getError()->errors['response'][0];
    expect(strlen($details))->toBeLessThanOrEqual(503);
    expect($details)->toEndWith('...');
});

it('still honours the smart_send_sslverify filter', function () {
    [$api] = create_client_with_log_spy();

    $seen_sslverify = null;
    $filter = function ($pre, $args, $url) use (&$seen_sslverify) {
        if (strpos($url, 'smartsend.io') === false) {
            return $pre;
        }
        $seen_sslverify = $args['sslverify'] ?? null;

        return ss_api_response(200, ['data' => ss_api_shipment_data()]);
    };
    add_filter('pre_http_request', $filter, 10, 3);
    remember_cleanup_callback(function () use ($filter): void {
        remove_filter('pre_http_request', $filter, 10);
    });

    add_filter('smart_send_sslverify', '__return_false');
    remember_cleanup_callback(function (): void {
        remove_filter('smart_send_sslverify', '__return_false');
    });

    $api->getAuthenticatedUser();

    expect($seen_sslverify)->toBeFalse();
});
