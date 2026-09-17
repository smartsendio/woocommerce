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
 * Cases booking: success 422-wrong-zip 422-agent-no 422-customs 500-return
 * Cases labels-combine: success
 * Cases agent-lookup: success not-found
 *
 * Named cases:
 *  - authenticate '401'      -> the real "Invalid API token provided" body
 *                               (also reachable as the generic 401; named so
 *                               the exact message is pinned for tests)
 *  - pickup-points 'empty'   -> an empty data set (no pickup points near the
 *                               address; a valid empty-collection response)
 *  - booking '422-wrong-zip' -> a validation failure in the shape the real
 *                               API produces (message + field errors) on a
 *                               field OUTSIDE the order meta box (the
 *                               receiver address)
 *  - booking '422-agent-no'  -> the same shape on the agent_no field, which
 *                               the meta box maps onto its pickup point
 *                               field (#182)
 *  - agent-lookup 'not-found' -> a 404 for the requested agent number
 *
 * Request capture: every booking request (POST shipments/labels) is
 * recorded - URL, method and decoded JSON body - in the ss_test_api_requests
 * option (newest last, capped at 20) so a test can assert what the plugin
 * actually sent, e.g. the parcels of a split (#182). Reset by
 * seed-store.php, deleted by cleanup-store.php.
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

    // Record a request (see the header: ss_test_api_requests).
    $record = function ($endpoint) use ($url, $args) {
        $requests = get_option('ss_test_api_requests', array());
        $requests = is_array($requests) ? $requests : array();
        $body = isset($args['body']) ? $args['body'] : null;
        $decoded = is_string($body) ? json_decode($body, true) : $body;
        $requests[] = array(
            'endpoint' => $endpoint,
            'url'      => $url,
            'method'   => isset($args['method']) ? $args['method'] : 'GET',
            'body'     => $decoded === null ? $body : $decoded,
        );
        update_option('ss_test_api_requests', array_slice($requests, -20), false);
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
        $record('booking');
        $case = $case_of('booking');
        $sent = json_decode(isset($args['body']) ? (string) $args['body'] : '', true);
        if ($case === '500-return' && strpos($sent['shipping_method'] ?? '', 'return') !== false) {
            return $respond(array('message' => 'The return booking failed.'), 500);
        }
        if ($case === '422-agent-no') {
            // A validation failure on a field the meta box owns: the
            // presenter maps agent_no onto pickup_point.agent_no.
            return $respond(array(
                'message' => 'The given data was invalid.',
                'errors'  => array(
                    'agent_no' => array('The selected pickup point is not available for this carrier'),
                ),
            ), 422);
        }
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
        if ($case === '422-customs') {
            return $respond(array(
                'message' => 'The given data was invalid.',
                'errors' => array(
                    'parcels.0.items.0.hs_code' => array('The HS code is required for customs.'),
                    'parcels.0.items.0.country_of_origin' => array('The country of origin is required for customs.'),
                ),
            ), 422);
        }

        if ($error = $generic('booking', $case, array('422-wrong-zip', '422-agent-no', '422-customs', '500-return'))) {
            return $error;
        }

        // One response parcel per request parcel, in the order they were
        // sent (what the real API does): the plugin correlates the two by
        // index to carry the booked weight/dimensions/reference onto the
        // booked parcels, so a split booking must answer with a split.
        $count = (isset($sent['parcels']) && is_array($sent['parcels'])) ? max(1, count($sent['parcels'])) : 1;

        $parcels = array();
        for ($i = 1; $i <= $count; $i++) {
            $parcels[] = array(
                'parcel_internal_id' => $i,
                'tracking_code'      => 'BROWSERTRACK' . $i,
                'tracking_link'      => 'https://mock.smartsend.test/track/' . $i,
            );
        }

        return $respond(array('data' => array(
            'shipment_id'  => 'browser-shipment-' . uniqid(),
            'carrier_name' => 'PostNord',
            'carrier_code' => 'postnord',
            'pdf'          => array('link' => 'https://mock.smartsend.test/labels/label.pdf', 'base_64_encoded' => base64_encode('%PDF-label')),
            'parcels'      => $parcels,
        )));
    }

    if (strpos($url, 'agents/carrier') !== false) {
        $case = $case_of('agent-lookup');
        if ($error = $generic('agent-lookup', $case, array('not-found'))) {
            return $error;
        }
        if ($case === 'not-found') {
            return $respond(array('message' => 'No pickup point found with the given agent number'), 404);
        }

        // Echo the requested agent number (…/agentno/{agent_no}) so a
        // lookup resolves to the point the merchant asked for: the two
        // numbers the pickup-points mock lists get their known addresses,
        // anything else a generic shop.
        $agent_no = preg_match('#/agentno/([^/?]+)#', $url, $m) ? rawurldecode($m[1]) : '1234';
        $known = array(
            '1234' => array('id' => 1, 'company' => 'Browser Test Shop', 'address_line1' => 'Main Street 1'),
            '5678' => array('id' => 2, 'company' => 'Second Test Shop', 'address_line1' => 'Other Street 9'),
        );
        $point = isset($known[$agent_no]) ? $known[$agent_no] : array('id' => 99, 'company' => 'Shop ' . $agent_no, 'address_line1' => 'Some Street ' . $agent_no);

        return $respond(array('data' => array_merge($point, array(
            'agent_no' => $agent_no, 'address_line2' => null, 'postal_code' => '2300', 'city' => 'Copenhagen', 'country' => 'DK',
        ))));
    }

    // Anything else is the account/authenticate call (the API base URL with
    // no resource path - Smart_Send\API\Resources\Account_Resource::get_authenticated_user()).
    $case = $case_of('authenticate');
    if ($case === '401') {
        return $respond(array('message' => 'Invalid API token provided'), 401);
    }
    if ($error = $generic('authenticate', $case, array('401'))) {
        return $error;
    }

    return $respond(array('data' => array('id' => 1, 'email' => 'mock@smartsend.test', 'website' => 'localhost')));
}, 5, 3);


// Exercise the real shared label hook through Store API JSON and React.
add_filter('smart_send_pickup_point_label', static function ($label) {
    $config = get_option('ss_test_api', array());
    return !empty($config['enabled']) && isset($config['pickup_label_suffix'])
        ? $label . ' ' . $config['pickup_label_suffix']
        : $label;
});
