<?php

/*
 * State-isolation characterization for the Smartsend API client (#141):
 * two consecutive calls on ONE Api instance must be fully isolated - the
 * outcome read for the second call must never inherit anything (data,
 * error, success flag) from the first call. Pinned against the pre-rework
 * clearAll() behavior; now asserts the immutable-Response guarantee: each
 * call returns its own Response, and an earlier call's Response is
 * untouched by later calls.
 */

use Smartsend\Api;
use Smartsend\Models\Error;

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
    expect($first->isSuccessful())->toBeTrue();
    expect($first->data()->shipment_id)->toBe('first-call-shipment');
    expect($first->error())->toBeNull();

    // Second call fails at the transport level: its outcome must not
    // inherit the first call's data or success flag.
    $second = $api->account()->getAuthenticatedUser();
    expect($second->isSuccessful())->toBeFalse();
    expect($second->data())->toBeNull();
    expect($second->error())->toBeInstanceOf(Error::class);
    expect($second->error()->code)->toBe('transport-connection');

    // And the first call's Response is untouched by the second call.
    expect($first->isSuccessful())->toBeTrue();
    expect($first->data()->shipment_id)->toBe('first-call-shipment');
    expect($first->error())->toBeNull();
});

it('does not leak the first call\'s error into a succeeding second call on one Api instance', function () {
    with_ss_settings();
    $api = new Api('secret-token-123', 'example.test', true);

    mock_smart_send_api_sequence([
        ss_api_response(422, ss_api_error_body('The given data was invalid.')),
        ss_api_response(200, ['data' => ss_api_shipment_data(['shipment_id' => 'second-call-shipment'])]),
    ]);

    // First call fails with a validation error.
    $first = $api->account()->getAuthenticatedUser();
    expect($first->isSuccessful())->toBeFalse();
    expect($first->error())->toBeInstanceOf(Error::class);

    // Second call succeeds: its outcome must not inherit the first
    // call's error.
    $second = $api->account()->getAuthenticatedUser();
    expect($second->isSuccessful())->toBeTrue();
    expect($second->error())->toBeNull();
    expect($second->data()->shipment_id)->toBe('second-call-shipment');

    // And the first call's Response is untouched by the second call.
    expect($first->isSuccessful())->toBeFalse();
    expect($first->error())->toBeInstanceOf(Error::class);
});
