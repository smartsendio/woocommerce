<?php

/*
 * Tests for the Smartsend client/resource error handling (issues #38 and
 * the exception refactor): transport WP_Errors throw ConnectionException,
 * completed non-2xx exchanges throw RequestException (re-thrown by the
 * resources as the domain exceptions UnauthenticatedException /
 * ForbiddenException / ValidationException / ServerException), unusable
 * 2xx bodies throw UnexpectedResponseException from the resource layer,
 * and every failed request still lands in the log at the error level via
 * the injected request logger.
 */

use Smartsend\Api;
use Smartsend\Exceptions\ConnectionException;
use Smartsend\Exceptions\ForbiddenException;
use Smartsend\Exceptions\HttpClientException;
use Smartsend\Exceptions\RequestException;
use Smartsend\Exceptions\ServerException;
use Smartsend\Exceptions\UnauthenticatedException;
use Smartsend\Exceptions\UnexpectedResponseException;
use Smartsend\Exceptions\ValidationException;

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

/**
 * Run $call, asserting it throws an exception of $expected_class, and
 * return the caught exception for further assertions.
 */
function expect_api_exception(callable $call, string $expected_class): HttpClientException
{
    try {
        $call();
    } catch (HttpClientException $e) {
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

    $response = $api->account()->getAuthenticatedUser();

    expect($response->data()->shipment_id)->toBe('success-shipment');
    expect($response->statusCode())->toBe(200);
    expect($spy->entries)->toHaveCount(1);
    expect($spy->entries[0]['level'])->toBe('debug');
});

it('exposes the Response-ID header on successful responses', function () {
    with_ss_settings(['ss_debug' => 'no']);
    [$api] = create_client_with_log_spy();
    mock_smart_send_api(function () {
        return ss_api_response(200, ['data' => ss_api_shipment_data()], 'resp-id-success');
    });

    $response = $api->account()->getAuthenticatedUser();

    expect($response->responseId())->toBe('resp-id-success');
});

it('leaves responseId null when the API sends no Response-ID header', function () {
    with_ss_settings(['ss_debug' => 'no']);
    [$api] = create_client_with_log_spy();
    mock_smart_send_api();

    $response = $api->account()->getAuthenticatedUser();

    expect($response->responseId())->toBeNull();
});

it('throws a ValidationException carrying the response for a 422 body', function () {
    with_ss_settings(['ss_debug' => 'no']);
    [$api, $spy] = create_client_with_log_spy();
    mock_smart_send_api(function () {
        return ss_api_response(422, ss_api_error_body('The given data was invalid.'), 'test-response-id');
    });

    $e = expect_api_exception(function () use ($api) {
        $api->account()->getAuthenticatedUser();
    }, ValidationException::class);

    expect($e->getMessage())->toBe('The given data was invalid.');
    expect($e->errors())->toBe(['receiver.postal_code' => ['The postal code is invalid.']]);

    $response = $e->getResponse();
    expect($response->statusCode())->toBe(422);
    expect($response->message())->toBe('The given data was invalid.');
    expect($response->responseId())->toBe('test-response-id');

    // Logged at error level even with debug off, with HTTP status and detail
    expect($spy->entries)->toHaveCount(1);
    expect($spy->entries[0]['level'])->toBe('error');
    expect($spy->entries[0]['message'])->toContain('→ 422');
    expect($spy->entries[0]['context']['error'])->toBe('The given data was invalid.');
});

it('throws an UnauthenticatedException for a 401 response', function () {
    with_ss_settings(['ss_debug' => 'no']);
    [$api] = create_client_with_log_spy();
    mock_smart_send_api(function () {
        return ss_api_response(401, ['message' => 'Invalid API token.']);
    });

    $e = expect_api_exception(function () use ($api) {
        $api->account()->getAuthenticatedUser();
    }, UnauthenticatedException::class);

    expect($e->getMessage())->toBe('Invalid API token.');
    expect($e->getResponse()->statusCode())->toBe(401);
});

it('throws a ForbiddenException for a 403 response', function () {
    with_ss_settings(['ss_debug' => 'no']);
    [$api] = create_client_with_log_spy();
    mock_smart_send_api(function () {
        return ss_api_response(403, ['message' => 'Your plan does not include this feature.']);
    });

    $e = expect_api_exception(function () use ($api) {
        $api->pickupPoints()->findClosestByAddress('postnord', 'DK', '2300', 'Copenhagen', 'Islands Brygge 39');
    }, ForbiddenException::class);

    expect($e->getMessage())->toBe('Your plan does not include this feature.');
});

it('throws a plain RequestException for other 4xx responses', function () {
    with_ss_settings(['ss_debug' => 'no']);
    [$api] = create_client_with_log_spy();
    mock_smart_send_api(function () {
        return ss_api_response(404, ['message' => 'Agent number not found.']);
    });

    $e = expect_api_exception(function () use ($api) {
        $api->pickupPoints()->findByAgentNo('postnord', 'DK', '9999');
    }, RequestException::class);

    expect(get_class($e))->toBe(RequestException::class);
    expect($e->getMessage())->toBe('Agent number not found.');
});

it('throws a ConnectionException for a connection failure WP_Error', function () {
    with_ss_settings(['ss_debug' => 'no']);
    [$api, $spy] = create_client_with_log_spy();
    mock_smart_send_transport_error('http_request_failed', 'cURL error 7: Failed to connect to app.smartsend.io port 443: Connection refused');

    $e = expect_api_exception(function () use ($api) {
        $api->account()->getAuthenticatedUser();
    }, ConnectionException::class);

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
        $api->account()->getAuthenticatedUser();
    }, ConnectionException::class);

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
        $api->account()->getAuthenticatedUser();
    }, ConnectionException::class);

    expect($e->getMessage())->toContain('timed out');
});

it('classifies an SSL failure WP_Error into the SSL message', function () {
    with_ss_settings(['ss_debug' => 'no']);
    [$api, $spy] = create_client_with_log_spy();
    mock_smart_send_transport_error('http_request_failed', 'cURL error 35: SSL connect error');

    $e = expect_api_exception(function () use ($api) {
        $api->account()->getAuthenticatedUser();
    }, ConnectionException::class);

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
        $api->account()->getAuthenticatedUser();
    }, ConnectionException::class);

    expect($e->getMessage())->toContain('SSL/TLS');
});

it('keeps the WP_Error code recognisable for unclassified transport failures', function () {
    with_ss_settings(['ss_debug' => 'no']);
    [$api] = create_client_with_log_spy();
    mock_smart_send_transport_error('http_request_not_executed', 'User has blocked requests through HTTP.');

    $e = expect_api_exception(function () use ($api) {
        $api->account()->getAuthenticatedUser();
    }, ConnectionException::class);

    expect($e->getMessage())->toContain('User has blocked requests through HTTP.');
    expect($e->getMessage())->toContain('http_request_not_executed');
});

it('throws a ServerException for a non-2xx response with an empty body', function () {
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
        $api->account()->getAuthenticatedUser();
    }, ServerException::class);

    expect($e->getMessage())->toContain('empty response');
    expect($e->getMessage())->toContain('HTTP 503');

    expect($spy->entries)->toHaveCount(1);
    expect($spy->entries[0]['level'])->toBe('error');
    expect($spy->entries[0]['message'])->toContain('→ 503');
});

it('throws a ServerException for a non-2xx response with a non-JSON body', function () {
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
        $api->account()->getAuthenticatedUser();
    }, ServerException::class);

    expect($e->getMessage())->toContain('HTTP 502');
    // The raw body is preserved on the response for support
    expect($e->getResponse()->rawBody())->toContain('502 Bad Gateway');

    expect($spy->entries)->toHaveCount(1);
    expect($spy->entries[0]['level'])->toBe('error');
    expect($spy->entries[0]['message'])->toContain('→ 502');
});

it('throws a ServerException for a non-2xx response with malformed JSON', function () {
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
        $api->account()->getAuthenticatedUser();
    }, ServerException::class);

    expect($e->getMessage())->toContain('HTTP 500');
});

it('throws an UnexpectedResponseException for a 2xx response with malformed JSON', function () {
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
        $api->account()->getAuthenticatedUser();
    }, UnexpectedResponseException::class);

    expect($e->getMessage())->toContain('HTTP 200');
    expect($e->getMessage())->toContain('not json at all');

    // A 2xx exchange is logged as successful by the client; judging the
    // body is the resource's job and no additional log entry is made here.
    expect($spy->entries)->toHaveCount(1);
    expect($spy->entries[0]['level'])->toBe('debug');
});

it('throws an UnexpectedResponseException for a 2xx response with an empty body', function () {
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
        $api->account()->getAuthenticatedUser();
    }, UnexpectedResponseException::class);

    expect($e->getMessage())->toContain('empty response');
});

it('treats a 2xx response with an empty data list as a successful empty collection', function () {
    // Debug on: the successful exchange is logged at the debug level.
    with_ss_settings(['ss_debug' => 'yes']);
    [$api, $spy] = create_client_with_log_spy();
    mock_smart_send_api(function () {
        return ss_api_response(200, ['data' => []]);
    });

    $response = $api->pickupPoints()->findClosestByAddress('postnord', 'DK', '2300', 'Copenhagen', 'Islands Brygge 39');

    expect($response->data())->toBe([]);
    expect($spy->entries)->toHaveCount(1);
    expect($spy->entries[0]['level'])->toBe('debug');
});

it('throws an UnexpectedResponseException when an object endpoint returns an empty data list', function () {
    // getAuthenticatedUser() expects an account object; an empty list is
    // not a valid shape for that call even though it is for the pickup
    // point search.
    with_ss_settings(['ss_debug' => 'no']);
    [$api] = create_client_with_log_spy();
    mock_smart_send_api(function () {
        return ss_api_response(200, ['data' => []]);
    });

    expect_api_exception(function () use ($api) {
        $api->account()->getAuthenticatedUser();
    }, UnexpectedResponseException::class);
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
        $api->account()->getAuthenticatedUser();
    }, UnexpectedResponseException::class);

    expect($e->getMessage())->toContain('xxx...');
    expect(substr_count($e->getMessage(), 'x'))->toBeLessThanOrEqual(510);
    // The full body stays available on the response
    expect(strlen($e->getResponse()->rawBody()))->toBe(2000);
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

    $response = $api->account()->getAuthenticatedUser();

    expect($seen_sslverify)->toBeFalse();
});
