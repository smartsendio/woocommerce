<?php

/*
 * Tests for the Smart Send client/resource error handling (issues #38 and
 * the exception refactor): transport WP_Errors throw Connection_Exception,
 * completed non-2xx exchanges throw Request_Exception (re-thrown by the
 * resources as the domain exceptions Unauthenticated_Exception /
 * Forbidden_Exception / Validation_Exception / Server_Exception), unusable
 * 2xx bodies throw Unexpected_Response_Exception from the resource layer,
 * and every failed request still lands in the log at the error level via
 * the injected request logger.
 */

use Smart_Send\API\API;
use Smart_Send\API\Exceptions\Connection_Exception;
use Smart_Send\API\Exceptions\Forbidden_Exception;
use Smart_Send\API\Exceptions\HTTP_Client_Exception;
use Smart_Send\API\Exceptions\Request_Exception;
use Smart_Send\API\Exceptions\Server_Exception;
use Smart_Send\API\Exceptions\Unauthenticated_Exception;
use Smart_Send\API\Exceptions\Unexpected_Response_Exception;
use Smart_Send\API\Exceptions\Validation_Exception;

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

        /**
         * The logger dispatches to wc_get_logger()'s level wrapper methods
         * (debug(), error(), ...); record them like log() calls.
         */
        public function __call(string $level, array $args): void
        {
            $this->log($level, $args[0], $args[1] ?? []);
        }
    };

    \Smart_Send\Support\Logger::$logger = $spy;

    remember_cleanup_callback(function (): void {
        \Smart_Send\Support\Logger::$logger = null;
    });

    return $spy;
}

/**
 * An API client wired to the plugin logger, plus a logger spy, like the
 * plugin wires it in \Smart_Send\Plugin::get_api_handle().
 *
 * @return array{0: API, 1: object} [client, logger spy]
 */
function create_client_with_log_spy(): array
{
    $spy = spy_on_ss_logger();

    $api = new API('secret-token-123', 'example.test');
    $api->set_request_logger([\Smart_Send\Support\Logger::class, 'log_api_request']);

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

/**
 * Run $call, asserting it throws an exception of $expected_class, and
 * return the caught exception for further assertions.
 */
function expect_api_exception(callable $call, string $expected_class): HTTP_Client_Exception
{
    try {
        $call();
    } catch (HTTP_Client_Exception $e) {
        expect($e)->toBeInstanceOf($expected_class);

        return $e;
    }

    test()->fail('Expected ' . $expected_class . ' to be thrown, but nothing was.');
}

it('returns data and no exception on a successful response', function () {
    with_ss_settings(['ss_debug' => 'yes']);
    [$api, $spy] = create_client_with_log_spy();
    mock_smart_send_api(function () {
        return ss_api_response(200, ['data' => ss_api_shipment_data(['shipment_id' => 'success-shipment'])]);
    });

    $response = $api->account()->get_authenticated_user();

    expect($response->data()->shipment_id)->toBe('success-shipment');
    expect($response->status_code())->toBe(200);
    expect($spy->entries)->toHaveCount(1);
    expect($spy->entries[0]['level'])->toBe('debug');
});

it('exposes the Response-ID header on successful responses', function () {
    with_ss_settings(['ss_debug' => 'no']);
    [$api] = create_client_with_log_spy();
    mock_smart_send_api(function () {
        return ss_api_response(200, ['data' => ss_api_shipment_data()], 'resp-id-success');
    });

    $response = $api->account()->get_authenticated_user();

    expect($response->response_id())->toBe('resp-id-success');
});

it('leaves response_id null when the API sends no Response-ID header', function () {
    with_ss_settings(['ss_debug' => 'no']);
    [$api] = create_client_with_log_spy();
    mock_smart_send_api();

    $response = $api->account()->get_authenticated_user();

    expect($response->response_id())->toBeNull();
});

it('throws a Validation_Exception carrying the response for a 422 body', function () {
    with_ss_settings(['ss_debug' => 'no']);
    [$api, $spy] = create_client_with_log_spy();
    mock_smart_send_api(function () {
        return ss_api_response(422, ss_api_error_body('The given data was invalid.'), 'test-response-id');
    });

    $e = expect_api_exception(function () use ($api) {
        $api->account()->get_authenticated_user();
    }, Validation_Exception::class);

    expect($e->getMessage())->toBe('The given data was invalid.');
    expect($e->errors())->toBe(['receiver.postal_code' => ['The postal code is invalid.']]);

    $response = $e->get_response();
    expect($response->status_code())->toBe(422);
    expect($response->message())->toBe('The given data was invalid.');
    expect($response->response_id())->toBe('test-response-id');

    // Logged at error level even with debug off, with HTTP status and detail
    expect($spy->entries)->toHaveCount(1);
    expect($spy->entries[0]['level'])->toBe('error');
    expect($spy->entries[0]['message'])->toContain('→ 422');
    expect($spy->entries[0]['context']['error'])->toBe('The given data was invalid.');
});

it('throws an Unauthenticated_Exception for a 401 response', function () {
    with_ss_settings(['ss_debug' => 'no']);
    [$api] = create_client_with_log_spy();
    mock_smart_send_api(function () {
        return ss_api_response(401, ['message' => 'Invalid API token.']);
    });

    $e = expect_api_exception(function () use ($api) {
        $api->account()->get_authenticated_user();
    }, Unauthenticated_Exception::class);

    expect($e->getMessage())->toBe('Invalid API token.');
    expect($e->get_response()->status_code())->toBe(401);
});

it('throws a Forbidden_Exception for a 403 response', function () {
    with_ss_settings(['ss_debug' => 'no']);
    [$api] = create_client_with_log_spy();
    mock_smart_send_api(function () {
        return ss_api_response(403, ['message' => 'Your plan does not include this feature.']);
    });

    $e = expect_api_exception(function () use ($api) {
        $api->pickup_points()->find_closest_by_address('postnord', 'DK', '2300', 'Copenhagen', 'Islands Brygge 39');
    }, Forbidden_Exception::class);

    expect($e->getMessage())->toBe('Your plan does not include this feature.');
});

it('throws a plain Request_Exception for other 4xx responses', function () {
    with_ss_settings(['ss_debug' => 'no']);
    [$api] = create_client_with_log_spy();
    mock_smart_send_api(function () {
        return ss_api_response(404, ['message' => 'Agent number not found.']);
    });

    $e = expect_api_exception(function () use ($api) {
        $api->pickup_points()->find_by_agent_no('postnord', 'DK', '9999');
    }, Request_Exception::class);

    expect(get_class($e))->toBe(Request_Exception::class);
    expect($e->getMessage())->toBe('Agent number not found.');
});

it('throws a Connection_Exception for a connection failure WP_Error', function () {
    with_ss_settings(['ss_debug' => 'no']);
    [$api, $spy] = create_client_with_log_spy();
    mock_smart_send_transport_error('http_request_failed', 'cURL error 7: Failed to connect to app.smartsend.io port 443: Connection refused');

    $e = expect_api_exception(function () use ($api) {
        $api->account()->get_authenticated_user();
    }, Connection_Exception::class);

    expect($e->getMessage())->toContain('Could not connect to the Smart Send API');
    // The raw transport detail is preserved for support
    expect($e->getMessage())->toContain('Connection refused');

    expect($spy->entries)->toHaveCount(1);
    expect($spy->entries[0]['level'])->toBe('error');
    expect($spy->entries[0]['message'])->toContain('→ n/a');
    expect($spy->entries[0]['context']['error'])->toContain('Could not connect');
});

it('classifies a timeout WP_Error into the timeout message', function () {
    with_ss_settings(['ss_debug' => 'no']);
    [$api, $spy] = create_client_with_log_spy();
    mock_smart_send_transport_error('http_request_failed', 'cURL error 28: Operation timed out after 30001 milliseconds with 0 bytes received');

    $e = expect_api_exception(function () use ($api) {
        $api->account()->get_authenticated_user();
    }, Connection_Exception::class);

    expect($e->getMessage())->toContain('timed out');
    expect($e->getMessage())->toContain('cURL error 28');

    expect($spy->entries)->toHaveCount(1);
    expect($spy->entries[0]['level'])->toBe('error');
    expect($spy->entries[0]['message'])->toContain('→ n/a');
});

it('classifies a stream timeout message from WP_Http_Streams as a timeout too', function () {
    with_ss_settings(['ss_debug' => 'no']);
    [$api] = create_client_with_log_spy();
    mock_smart_send_transport_error('http_request_failed', 'stream_socket_client(): unable to connect (Connection timed out)');

    $e = expect_api_exception(function () use ($api) {
        $api->account()->get_authenticated_user();
    }, Connection_Exception::class);

    expect($e->getMessage())->toContain('timed out');
});

it('classifies an SSL failure WP_Error into the SSL message', function () {
    with_ss_settings(['ss_debug' => 'no']);
    [$api, $spy] = create_client_with_log_spy();
    mock_smart_send_transport_error('http_request_failed', 'cURL error 35: SSL connect error');

    $e = expect_api_exception(function () use ($api) {
        $api->account()->get_authenticated_user();
    }, Connection_Exception::class);

    expect($e->getMessage())->toContain('SSL/TLS');
    expect($e->getMessage())->toContain('cURL error 35');

    expect($spy->entries)->toHaveCount(1);
    expect($spy->entries[0]['level'])->toBe('error');
});

it('classifies a certificate verification failure as an SSL failure', function () {
    with_ss_settings(['ss_debug' => 'no']);
    [$api] = create_client_with_log_spy();
    mock_smart_send_transport_error('http_request_failed', 'cURL error 60: SSL certificate problem: unable to get local issuer certificate');

    $e = expect_api_exception(function () use ($api) {
        $api->account()->get_authenticated_user();
    }, Connection_Exception::class);

    expect($e->getMessage())->toContain('SSL/TLS');
});

it('keeps the WP_Error code recognisable for unclassified transport failures', function () {
    with_ss_settings(['ss_debug' => 'no']);
    [$api] = create_client_with_log_spy();
    mock_smart_send_transport_error('http_request_not_executed', 'User has blocked requests through HTTP.');

    $e = expect_api_exception(function () use ($api) {
        $api->account()->get_authenticated_user();
    }, Connection_Exception::class);

    expect($e->getMessage())->toContain('User has blocked requests through HTTP.');
    expect($e->getMessage())->toContain('http_request_not_executed');
});

it('throws a Server_Exception for a non-2xx response with an empty body', function () {
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

    $e = expect_api_exception(function () use ($api) {
        $api->account()->get_authenticated_user();
    }, Server_Exception::class);

    expect($e->getMessage())->toContain('empty response');
    expect($e->getMessage())->toContain('HTTP 503');

    expect($spy->entries)->toHaveCount(1);
    expect($spy->entries[0]['level'])->toBe('error');
    expect($spy->entries[0]['message'])->toContain('→ 503');
});

it('throws a Server_Exception for a non-2xx response with a non-JSON body', function () {
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

    $e = expect_api_exception(function () use ($api) {
        $api->account()->get_authenticated_user();
    }, Server_Exception::class);

    expect($e->getMessage())->toContain('HTTP 502');
    // The raw body is preserved on the response for support
    expect($e->get_response()->raw_body())->toContain('502 Bad Gateway');

    expect($spy->entries)->toHaveCount(1);
    expect($spy->entries[0]['level'])->toBe('error');
    expect($spy->entries[0]['message'])->toContain('→ 502');
});

it('throws a Server_Exception for a non-2xx response with malformed JSON', function () {
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

    $e = expect_api_exception(function () use ($api) {
        $api->account()->get_authenticated_user();
    }, Server_Exception::class);

    expect($e->getMessage())->toContain('HTTP 500');
});

it('throws an Unexpected_Response_Exception for a 2xx response with malformed JSON', function () {
    // Debug on: the 2xx exchange itself is logged at the debug level.
    with_ss_settings(['ss_debug' => 'yes']);
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

    $e = expect_api_exception(function () use ($api) {
        $api->account()->get_authenticated_user();
    }, Unexpected_Response_Exception::class);

    expect($e->getMessage())->toContain('HTTP 200');
    expect($e->getMessage())->toContain('not json at all');

    // A 2xx exchange is logged as successful by the client; judging the
    // body is the resource's job and no additional log entry is made here.
    expect($spy->entries)->toHaveCount(1);
    expect($spy->entries[0]['level'])->toBe('debug');
});

it('throws an Unexpected_Response_Exception for a 2xx response with an empty body', function () {
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

    $e = expect_api_exception(function () use ($api) {
        $api->account()->get_authenticated_user();
    }, Unexpected_Response_Exception::class);

    expect($e->getMessage())->toContain('empty response');
});

it('treats a 2xx response with an empty data list as a successful empty collection', function () {
    // Debug on: the successful exchange is logged at the debug level.
    with_ss_settings(['ss_debug' => 'yes']);
    [$api, $spy] = create_client_with_log_spy();
    mock_smart_send_api(function () {
        return ss_api_response(200, ['data' => []]);
    });

    $response = $api->pickup_points()->find_closest_by_address('postnord', 'DK', '2300', 'Copenhagen', 'Islands Brygge 39');

    expect($response->data())->toBe([]);
    expect($spy->entries)->toHaveCount(1);
    expect($spy->entries[0]['level'])->toBe('debug');
});

it('throws an Unexpected_Response_Exception when an object endpoint returns an empty data list', function () {
    // get_authenticated_user() expects an account object; an empty list is
    // not a valid shape for that call even though it is for the pickup
    // point search.
    with_ss_settings(['ss_debug' => 'no']);
    [$api] = create_client_with_log_spy();
    mock_smart_send_api(function () {
        return ss_api_response(200, ['data' => []]);
    });

    expect_api_exception(function () use ($api) {
        $api->account()->get_authenticated_user();
    }, Unexpected_Response_Exception::class);
});

it('truncates huge non-JSON bodies embedded in the unexpected-response message', function () {
    with_ss_settings(['ss_debug' => 'no']);
    [$api] = create_client_with_log_spy();
    mock_smart_send_api(function () {
        return [
            'response' => ['code' => 200, 'message' => 'OK'],
            'headers'  => ['content-type' => 'text/html'],
            'body'     => str_repeat('x', 2000),
            'cookies'  => [],
            'filename' => null,
        ];
    });

    $e = expect_api_exception(function () use ($api) {
        $api->account()->get_authenticated_user();
    }, Unexpected_Response_Exception::class);

    expect($e->getMessage())->toContain('xxx...');
    expect(substr_count($e->getMessage(), 'x'))->toBeLessThanOrEqual(510);
    // The full body stays available on the response
    expect(strlen($e->get_response()->raw_body()))->toBe(2000);
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

    $response = $api->account()->get_authenticated_user();

    expect($seen_sslverify)->toBeFalse();
});
