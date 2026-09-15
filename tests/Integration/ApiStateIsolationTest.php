<?php

/*
 * State-isolation characterization for the Smartsend API client (#141):
 * two consecutive calls on ONE Api instance must be fully isolated - the
 * outcome read for the second call must never inherit anything (data,
 * exception, status) from the first call. Pinned against the pre-rework
 * clearAll() behavior; now asserts the immutable-Response guarantee: each
 * call returns (or attaches to its exception) its own Response, and an
 * earlier call's Response is untouched by later calls.
 */

use Smartsend\Api;
use Smartsend\Exceptions\ConnectionException;
use Smartsend\Exceptions\ValidationException;

/**
 * Respond to the Nth Smart Send API request (1-based) with the matching
 * entry of $responses; each entry is a pre_http_request-shaped array or
 * WP_Error.
 */
function mock_smart_send_api_sequence(array $responses): void
{
    $call = 0;

    mock_smart_send_api(function () use (&$call, $responses) {
        $response = $responses[$call] ?? end($responses);
        $call++;

        return $response;
    });
}

it('does not leak the first call\'s data into a failing second call on one Api instance', function () {
    with_ss_settings();
    $api = new Api('secret-token-123', 'example.test', true);

    mock_smart_send_api_sequence([
        ss_api_response(200, ['data' => ss_api_shipment_data(['shipment_id' => 'first-call-shipment'])]),
        new WP_Error('http_request_failed', 'cURL error 7: Failed to connect to app.smartsend.io port 443: Connection refused'),
    ]);

    // First call succeeds and carries data.
    $first = $api->account()->getAuthenticatedUser();
    expect($first->data()->shipment_id)->toBe('first-call-shipment');
    expect($first->statusCode())->toBe(200);

    // Second call fails at the transport level: it must throw its own
    // ConnectionException, not surface anything from the first call.
    expect(function () use ($api) {
        $api->account()->getAuthenticatedUser();
    })->toThrow(ConnectionException::class);

    // And the first call's Response is untouched by the second call.
    expect($first->data()->shipment_id)->toBe('first-call-shipment');
    expect($first->statusCode())->toBe(200);
});

it('does not leak the first call\'s failure into a succeeding second call on one Api instance', function () {
    with_ss_settings();
    $api = new Api('secret-token-123', 'example.test', true);

    mock_smart_send_api_sequence([
        ss_api_response(422, ss_api_error_body('The given data was invalid.')),
        ss_api_response(200, ['data' => ss_api_shipment_data(['shipment_id' => 'second-call-shipment'])]),
    ]);

    // First call fails with a validation error.
    $first_exception = null;
    try {
        $api->account()->getAuthenticatedUser();
    } catch (ValidationException $e) {
        $first_exception = $e;
    }
    expect($first_exception)->toBeInstanceOf(ValidationException::class);
    expect($first_exception->getResponse()->statusCode())->toBe(422);

    // Second call succeeds: its outcome must not inherit the first
    // call's failure.
    $second = $api->account()->getAuthenticatedUser();
    expect($second->data()->shipment_id)->toBe('second-call-shipment');
    expect($second->statusCode())->toBe(200);

    // And the first call's exception/Response is untouched by the second
    // call.
    expect($first_exception->getMessage())->toBe('The given data was invalid.');
    expect($first_exception->getResponse()->statusCode())->toBe(422);
});
