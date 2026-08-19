<?php

/*
 * Tests for SS_Shipping_Checkout_Options: the single place deciding which
 * delivery-option sections checkout renders for a shipping method, plus
 * the pickup point section's status vocabulary (exception mapping,
 * customer texts, error styling) shared by the classic and block surfaces.
 */

use Smartsend\Exceptions\ConnectionException;
use Smartsend\Exceptions\ForbiddenException;
use Smartsend\Exceptions\RequestException;
use Smartsend\Exceptions\ServerException;
use Smartsend\Exceptions\UnauthenticatedException;
use Smartsend\Response;

function checkout_options(): SS_Shipping_Checkout_Options
{
    return new SS_Shipping_Checkout_Options();
}

/**
 * A minimal non-2xx Response to construct request exceptions around.
 */
function checkout_options_response(int $status): Response
{
    return new Response(null, 'Test failure.', [], '{"message":"Test failure."}', $status, null, null, null);
}

it('shows the pickup point section for agent-type method codes only', function () {
    $options = checkout_options();

    expect($options->show_pickup_points(new SS_Shipping_Method_Code('postnord_agent')))->toBeTrue()
        ->and($options->show_pickup_points(new SS_Shipping_Method_Code('gls_agent')))->toBeTrue()
        ->and($options->show_pickup_points(new SS_Shipping_Method_Code('postnord_homedelivery')))->toBeFalse()
        ->and($options->show_pickup_points(new SS_Shipping_Method_Code('')))->toBeFalse()
        ->and($options->show_pickup_points(new SS_Shipping_Method_Code(null)))->toBeFalse();
});

it('keys the decision on the method TYPE, not the raw code', function () {
    // Deliberate behaviour change from v8: the classic checkout used to
    // stripos() the WHOLE code, so a hypothetical carrier whose NAME
    // contained "agent" would have matched even for a non-agent type. The
    // decision is now the type segment only, matching the Store API
    // surface.
    expect(checkout_options()->show_pickup_points(new SS_Shipping_Method_Code('agentcarrier_homedelivery')))->toBeFalse();
});

it('maps lookup exceptions to their section status', function () {
    $options = checkout_options();

    expect($options->pickup_point_status_for_exception(new SS_Shipping_Not_Connected_Exception()))
        ->toBe(SS_Shipping_Checkout_Options::PICKUP_POINT_STATUS_NOT_CONNECTED)
        ->and($options->pickup_point_status_for_exception(new UnauthenticatedException(checkout_options_response(401))))
        ->toBe(SS_Shipping_Checkout_Options::PICKUP_POINT_STATUS_AUTH_FAILED)
        ->and($options->pickup_point_status_for_exception(new ForbiddenException(checkout_options_response(403))))
        ->toBe(SS_Shipping_Checkout_Options::PICKUP_POINT_STATUS_ACCESS_DENIED)
        ->and($options->pickup_point_status_for_exception(new ConnectionException('timeout')))
        ->toBe(SS_Shipping_Checkout_Options::PICKUP_POINT_STATUS_LOOKUP_FAILED)
        ->and($options->pickup_point_status_for_exception(new ServerException(checkout_options_response(500))))
        ->toBe(SS_Shipping_Checkout_Options::PICKUP_POINT_STATUS_LOOKUP_FAILED)
        ->and($options->pickup_point_status_for_exception(new RequestException(checkout_options_response(404))))
        ->toBe(SS_Shipping_Checkout_Options::PICKUP_POINT_STATUS_LOOKUP_FAILED);
});

it('carries one customer text per non-found status and none for found', function () {
    $options = checkout_options();

    expect($options->pickup_point_status_message(SS_Shipping_Checkout_Options::PICKUP_POINT_STATUS_FOUND))->toBeNull()
        ->and($options->pickup_point_status_message(SS_Shipping_Checkout_Options::PICKUP_POINT_STATUS_ADDRESS_INCOMPLETE))
        ->toBe('Enter your shipping address to see available pickup points.')
        ->and($options->pickup_point_status_message(SS_Shipping_Checkout_Options::PICKUP_POINT_STATUS_NOT_CONNECTED))
        ->toBe('Connect the Smart Send plugin to enable pickup points.')
        ->and($options->pickup_point_status_message(SS_Shipping_Checkout_Options::PICKUP_POINT_STATUS_AUTH_FAILED))
        ->toBe('The shop is not correctly connected with Smart Send.')
        ->and($options->pickup_point_status_message(SS_Shipping_Checkout_Options::PICKUP_POINT_STATUS_ACCESS_DENIED))
        ->toBe('The shop does not have access to pickup points.')
        ->and($options->pickup_point_status_message(SS_Shipping_Checkout_Options::PICKUP_POINT_STATUS_NONE_FOUND))
        ->toBe('We could not find available pickup points. Please check that the entered address is correct. Your order will be shipped to the closest possible pickup point.')
        ->and($options->pickup_point_status_message(SS_Shipping_Checkout_Options::PICKUP_POINT_STATUS_LOOKUP_FAILED))
        ->toBe('Shipping to closest pickup point');
});

it('styles only the shop-side connection problems as errors', function () {
    $options = checkout_options();

    expect($options->is_pickup_point_error_status(SS_Shipping_Checkout_Options::PICKUP_POINT_STATUS_NOT_CONNECTED))->toBeTrue()
        ->and($options->is_pickup_point_error_status(SS_Shipping_Checkout_Options::PICKUP_POINT_STATUS_AUTH_FAILED))->toBeTrue()
        ->and($options->is_pickup_point_error_status(SS_Shipping_Checkout_Options::PICKUP_POINT_STATUS_ACCESS_DENIED))->toBeTrue()
        ->and($options->is_pickup_point_error_status(SS_Shipping_Checkout_Options::PICKUP_POINT_STATUS_ADDRESS_INCOMPLETE))->toBeFalse()
        ->and($options->is_pickup_point_error_status(SS_Shipping_Checkout_Options::PICKUP_POINT_STATUS_NONE_FOUND))->toBeFalse()
        ->and($options->is_pickup_point_error_status(SS_Shipping_Checkout_Options::PICKUP_POINT_STATUS_LOOKUP_FAILED))->toBeFalse()
        ->and($options->is_pickup_point_error_status(SS_Shipping_Checkout_Options::PICKUP_POINT_STATUS_FOUND))->toBeFalse();
});
