<?php
/**
 * Smart Send API mock, installed as a temporary mu-plugin.
 *
 * The single source of truth for the fake Smart Send API. Two consumers copy
 * this file into the store's wp-content/mu-plugins/ directory (as
 * ss-browser-test-api-mock.php) and remove it again when done:
 *
 *  - the Browser/Docs test suites, via ss_browser_install_api_mock() /
 *    ss_browser_remove_api_mock() in tests/Browser/Support/SmartSendStore.php
 *  - the manual-testing demo mode, via bin/demo-store.sh (demo:on/demo:off)
 *
 * Only active while the ss_test_api_mock option is 'yes'; failure scenarios
 * are switched on via the ss_test_api_scenario option. The line below is
 * machine-read by bin/demo-store.sh to validate scenario names - keep it in
 * sync when adding a scenario ('success' means the option is unset).
 *
 * Scenarios: success invalid-token booking-failure no-pickup-points
 *
 *  - 'invalid-token'    -> the account/authenticate call returns 401
 *  - 'booking-failure'  -> the shipments/labels booking call returns 422
 *                          with a validation message
 *  - 'no-pickup-points' -> the agents/closest lookup returns an empty data
 *                          set (no pickup points near the address; the
 *                          client maps this to the NoResults error)
 */
add_filter('pre_http_request', function ($pre, $args, $url) {
    if (get_option('ss_test_api_mock') !== 'yes' || strpos($url, 'smartsend.io') === false) {
        return $pre;
    }

    $respond = function ($body, $code = 200) {
        return array(
            'response' => array('code' => $code, 'message' => $code === 200 ? 'OK' : 'Error'),
            'headers'  => array('content-type' => 'application/json'),
            'body'     => json_encode($body),
            'cookies'  => array(),
            'filename' => null,
        );
    };

    $scenario = get_option('ss_test_api_scenario');

    if (strpos($url, 'agents/closest') !== false) {
        if ($scenario === 'no-pickup-points') {
            // An empty data set: Smartsend\Client maps this to the NoResults
            // error - the "no pickup points near the address" case.
            return $respond(array('data' => array()));
        }

        return $respond(array('data' => array(
            array('id' => 1, 'agent_no' => '1234', 'company' => 'Browser Test Shop', 'address_line1' => 'Main Street 1', 'address_line2' => null, 'postal_code' => '2300', 'city' => 'Copenhagen', 'country' => 'DK', 'distance' => 0.42),
            array('id' => 2, 'agent_no' => '5678', 'company' => 'Second Test Shop', 'address_line1' => 'Other Street 9', 'address_line2' => null, 'postal_code' => '2300', 'city' => 'Copenhagen', 'country' => 'DK', 'distance' => 1.2),
        )));
    }

    if (strpos($url, 'shipments/labels/combine') !== false) {
        return $respond(array('data' => array(
            'pdf' => array('link' => 'https://mock.smartsend.test/labels/combined.pdf', 'base_64_encoded' => base64_encode('%PDF-combo')),
        )));
    }

    if (strpos($url, 'shipments/labels') !== false) {
        if ($scenario === 'booking-failure') {
            // A validation failure in the shape the real API produces
            // (message + field errors); the plugin renders it through
            // Response::errorString() into the meta box error div.
            return $respond(array(
                'message' => 'The given data was invalid.',
                'errors'  => array(
                    'receiver.zip_code' => array('The receiver zip code does not match the receiver country'),
                ),
            ), 422);
        }

        return $respond(array('data' => array(
            'shipment_id'  => 'browser-shipment-' . uniqid(),
            'carrier_name' => 'PostNord',
            'carrier_code' => 'postnord',
            'pdf'          => array('link' => 'https://mock.smartsend.test/labels/label.pdf', 'base_64_encoded' => base64_encode('%PDF-label')),
            'parcels'      => array(
                array('parcel_internal_id' => 1, 'tracking_code' => 'BROWSERTRACK1', 'tracking_link' => 'https://mock.smartsend.test/track/1'),
            ),
        )));
    }

    if (strpos($url, 'agents/carrier') !== false) {
        return $respond(array('data' => array(
            'id' => 1, 'agent_no' => '1234', 'company' => 'Browser Test Shop', 'address_line1' => 'Main Street 1', 'address_line2' => null, 'postal_code' => '2300', 'city' => 'Copenhagen', 'country' => 'DK',
        )));
    }

    // Anything else is the account/authenticate call (the API base URL with
    // no resource path - Smartsend\Resources\AccountResource::getAuthenticatedUser()).
    if ($scenario === 'invalid-token') {
        return $respond(array('message' => 'Invalid API token provided'), 401);
    }

    return $respond(array('data' => array('id' => 1, 'email' => 'mock@smartsend.test', 'website' => 'localhost')));
}, 5, 3);
