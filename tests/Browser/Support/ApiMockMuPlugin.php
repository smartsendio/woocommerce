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
 * Controlled by the single ss_test_api option:
 *
 *   array(
 *       'enabled'   => true,
 *       'scenarios' => array('booking' => '500', 'pickup-points' => '403'),
 *   )
 *
 * Only active while 'enabled' is truthy; every endpoint then returns its
 * success response unless the 'scenarios' map overrides that endpoint. A
 * scenario case is either a named case from the endpoint's list below, or
 * any three-digit HTTP status code (a generic error body with that status)
 * - so per-endpoint failures compose freely: authentication can succeed
 * while the pick-up point lookup 403s and booking 500s.
 *
 * The "Cases" lines below are machine-read by bin/demo-store.sh to validate
 * endpoint and case names - keep them in sync when adding either ('success'
 * means no override; three-digit codes are always valid on every endpoint).
 *
 * Cases authenticate: success 401
 * Cases pickup-points: success empty
 * Cases booking: success 422-wrong-zip
 * Cases labels-combine: success
 * Cases agent-lookup: success
 *
 * Named cases:
 *  - authenticate '401'      -> the real "Invalid API token provided" body
 *                               (also reachable as the generic 401; named so
 *                               the exact message is pinned for tests)
 *  - pickup-points 'empty'   -> an empty data set (no pickup points near the
 *                               address; a valid empty-collection response)
 *  - booking '422-wrong-zip' -> a validation failure in the shape the real
 *                               API produces (message + field errors)
 */
add_filter('pre_http_request', function ($pre, $args, $url) {
    $config = get_option('ss_test_api');
    if (empty($config['enabled']) || strpos($url, 'smartsend.io') === false) {
        return $pre;
    }

    $scenarios = (isset($config['scenarios']) && is_array($config['scenarios']))
        ? $config['scenarios']
        : array();

    $respond = function ($body, $code = 200) {
        return array(
            'response' => array('code' => $code, 'message' => $code === 200 ? 'OK' : 'Error'),
            'headers'  => array('content-type' => 'application/json'),
            'body'     => json_encode($body),
            'cookies'  => array(),
            'filename' => null,
        );
    };

    // Resolve the endpoint's case: a named case handled below, a bare
    // three-digit HTTP status code (generic error body), or 'success'.
    // An unknown case never falls through to success silently - that would
    // make a typo in a test/demo scenario indistinguishable from a pass.
    $case_of = function ($endpoint) use ($scenarios) {
        return isset($scenarios[$endpoint]) ? (string) $scenarios[$endpoint] : 'success';
    };
    $generic = function ($endpoint, $case, $named_cases) use ($respond) {
        if (preg_match('/^[0-9]{3}$/', $case) && $case !== '200') {
            return $respond(array('message' => 'Mocked HTTP ' . $case . ' (ss_test_api scenario for ' . $endpoint . ')'), (int) $case);
        }
        if ($case !== 'success' && !in_array($case, $named_cases, true)) {
            return $respond(array('message' => "Unknown ss_test_api scenario case '{$case}' for endpoint '{$endpoint}'"), 500);
        }

        return null;
    };

    if (strpos($url, 'agents/closest') !== false) {
        $case = $case_of('pickup-points');
        if ($error = $generic('pickup-points', $case, array('empty'))) {
            return $error;
        }
        if ($case === 'empty') {
            return $respond(array('data' => array()));
        }

        return $respond(array('data' => array(
            array('id' => 1, 'agent_no' => '1234', 'company' => 'Browser Test Shop', 'address_line1' => 'Main Street 1', 'address_line2' => null, 'postal_code' => '2300', 'city' => 'Copenhagen', 'country' => 'DK', 'distance' => 0.42),
            array('id' => 2, 'agent_no' => '5678', 'company' => 'Second Test Shop', 'address_line1' => 'Other Street 9', 'address_line2' => null, 'postal_code' => '2300', 'city' => 'Copenhagen', 'country' => 'DK', 'distance' => 1.2),
        )));
    }

    if (strpos($url, 'shipments/labels/combine') !== false) {
        $case = $case_of('labels-combine');
        if ($error = $generic('labels-combine', $case, array())) {
            return $error;
        }

        return $respond(array('data' => array(
            'pdf' => array('link' => 'https://mock.smartsend.test/labels/combined.pdf', 'base_64_encoded' => base64_encode('%PDF-combo')),
        )));
    }

    if (strpos($url, 'shipments/labels') !== false) {
        $case = $case_of('booking');
        if ($case === '422-wrong-zip') {
            // The resource throws a ValidationException which the booking
            // service renders into the meta box error div.
            return $respond(array(
                'message' => 'The given data was invalid.',
                'errors'  => array(
                    'receiver.zip_code' => array('The receiver zip code does not match the receiver country'),
                ),
            ), 422);
        }
        if ($error = $generic('booking', $case, array('422-wrong-zip'))) {
            return $error;
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
        $case = $case_of('agent-lookup');
        if ($error = $generic('agent-lookup', $case, array())) {
            return $error;
        }

        return $respond(array('data' => array(
            'id' => 1, 'agent_no' => '1234', 'company' => 'Browser Test Shop', 'address_line1' => 'Main Street 1', 'address_line2' => null, 'postal_code' => '2300', 'city' => 'Copenhagen', 'country' => 'DK',
        )));
    }

    // Anything else is the account/authenticate call (the API base URL with
    // no resource path - Smartsend\Resources\AccountResource::getAuthenticatedUser()).
    $case = $case_of('authenticate');
    if ($case === '401') {
        return $respond(array('message' => 'Invalid API token provided'), 401);
    }
    if ($error = $generic('authenticate', $case, array('401'))) {
        return $error;
    }

    return $respond(array('data' => array('id' => 1, 'email' => 'mock@smartsend.test', 'website' => 'localhost')));
}, 5, 3);
