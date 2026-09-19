<?php

/*
 * Tests for \Smart_Send\Delivery_Options\Checkout_Options: the single place deciding which
 * delivery-option sections checkout renders for a shipping method, plus
 * the pickup point section's status vocabulary (exception mapping,
 * customer texts, error styling) shared by the classic and block surfaces.
 */

use Smart_Send\API\Exceptions\Connection_Exception;
use Smart_Send\API\Exceptions\Forbidden_Exception;
use Smart_Send\API\Exceptions\Request_Exception;
use Smart_Send\API\Exceptions\Server_Exception;
use Smart_Send\API\Exceptions\Unauthenticated_Exception;
use Smart_Send\API\Response;

function checkout_options(): \Smart_Send\Delivery_Options\Checkout_Options
{
    return new \Smart_Send\Delivery_Options\Checkout_Options();
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

    expect($options->show_pickup_points(new \Smart_Send\Shipping_Method\Method_Code('postnord_agent')))->toBeTrue()
        ->and($options->show_pickup_points(new \Smart_Send\Shipping_Method\Method_Code('gls_agent')))->toBeTrue()
        ->and($options->show_pickup_points(new \Smart_Send\Shipping_Method\Method_Code('postnord_homedelivery')))->toBeFalse()
        ->and($options->show_pickup_points(new \Smart_Send\Shipping_Method\Method_Code('')))->toBeFalse()
        ->and($options->show_pickup_points(new \Smart_Send\Shipping_Method\Method_Code(null)))->toBeFalse();
});

it('keys the decision on the method TYPE, not the raw code', function () {
    // Deliberate behaviour change from v8: the classic checkout used to
    // stripos() the WHOLE code, so a hypothetical carrier whose NAME
    // contained "agent" would have matched even for a non-agent type. The
    // decision is now the type segment only, matching the Store API
    // surface.
    expect(checkout_options()->show_pickup_points(new \Smart_Send\Shipping_Method\Method_Code('agentcarrier_homedelivery')))->toBeFalse();
});

it('maps lookup exceptions to their section status', function () {
    $options = checkout_options();

    expect($options->pickup_point_status_for_exception(new \Smart_Send\Exceptions\Not_Connected_Exception()))
        ->toBe(\Smart_Send\Delivery_Options\Checkout_Options::PICKUP_POINT_STATUS_NOT_CONNECTED)
        ->and($options->pickup_point_status_for_exception(new Unauthenticated_Exception(checkout_options_response(401))))
        ->toBe(\Smart_Send\Delivery_Options\Checkout_Options::PICKUP_POINT_STATUS_AUTH_FAILED)
        ->and($options->pickup_point_status_for_exception(new Forbidden_Exception(checkout_options_response(403))))
        ->toBe(\Smart_Send\Delivery_Options\Checkout_Options::PICKUP_POINT_STATUS_ACCESS_DENIED)
        ->and($options->pickup_point_status_for_exception(new Connection_Exception('timeout')))
        ->toBe(\Smart_Send\Delivery_Options\Checkout_Options::PICKUP_POINT_STATUS_LOOKUP_FAILED)
        ->and($options->pickup_point_status_for_exception(new Server_Exception(checkout_options_response(500))))
        ->toBe(\Smart_Send\Delivery_Options\Checkout_Options::PICKUP_POINT_STATUS_LOOKUP_FAILED)
        ->and($options->pickup_point_status_for_exception(new Request_Exception(checkout_options_response(404))))
        ->toBe(\Smart_Send\Delivery_Options\Checkout_Options::PICKUP_POINT_STATUS_LOOKUP_FAILED);
});

it('carries one customer text per non-found status and none for found', function () {
    $options = checkout_options();

    expect($options->pickup_point_status_message(\Smart_Send\Delivery_Options\Checkout_Options::PICKUP_POINT_STATUS_FOUND))->toBeNull()
        ->and($options->pickup_point_status_message(\Smart_Send\Delivery_Options\Checkout_Options::PICKUP_POINT_STATUS_ADDRESS_INCOMPLETE))
        ->toBe('Enter your shipping address to see available pickup points.')
        ->and($options->pickup_point_status_message(\Smart_Send\Delivery_Options\Checkout_Options::PICKUP_POINT_STATUS_NOT_CONNECTED))
        ->toBe('Connect the Smart Send plugin to enable pickup points.')
        ->and($options->pickup_point_status_message(\Smart_Send\Delivery_Options\Checkout_Options::PICKUP_POINT_STATUS_AUTH_FAILED))
        ->toBe('The shop is not correctly connected with Smart Send.')
        ->and($options->pickup_point_status_message(\Smart_Send\Delivery_Options\Checkout_Options::PICKUP_POINT_STATUS_ACCESS_DENIED))
        ->toBe('The shop does not have access to pickup points.')
        ->and($options->pickup_point_status_message(\Smart_Send\Delivery_Options\Checkout_Options::PICKUP_POINT_STATUS_NONE_FOUND))
        ->toBe('We could not find available pickup points. Please check that the entered address is correct. Your order will be shipped to the closest possible pickup point.')
        ->and($options->pickup_point_status_message(\Smart_Send\Delivery_Options\Checkout_Options::PICKUP_POINT_STATUS_LOOKUP_FAILED))
        ->toBe('Shipping to closest pickup point');
});

it('styles only the shop-side connection problems as errors', function () {
    $options = checkout_options();

    expect($options->is_pickup_point_error_status(\Smart_Send\Delivery_Options\Checkout_Options::PICKUP_POINT_STATUS_NOT_CONNECTED))->toBeTrue()
        ->and($options->is_pickup_point_error_status(\Smart_Send\Delivery_Options\Checkout_Options::PICKUP_POINT_STATUS_AUTH_FAILED))->toBeTrue()
        ->and($options->is_pickup_point_error_status(\Smart_Send\Delivery_Options\Checkout_Options::PICKUP_POINT_STATUS_ACCESS_DENIED))->toBeTrue()
        ->and($options->is_pickup_point_error_status(\Smart_Send\Delivery_Options\Checkout_Options::PICKUP_POINT_STATUS_ADDRESS_INCOMPLETE))->toBeFalse()
        ->and($options->is_pickup_point_error_status(\Smart_Send\Delivery_Options\Checkout_Options::PICKUP_POINT_STATUS_NONE_FOUND))->toBeFalse()
        ->and($options->is_pickup_point_error_status(\Smart_Send\Delivery_Options\Checkout_Options::PICKUP_POINT_STATUS_LOOKUP_FAILED))->toBeFalse()
        ->and($options->is_pickup_point_error_status(\Smart_Send\Delivery_Options\Checkout_Options::PICKUP_POINT_STATUS_FOUND))->toBeFalse();
});
