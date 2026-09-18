<?php
/** Documentation-store-only isolation and deterministic demonstration responses. */

// No fixture run may deliver email, including email triggered during seeding.
add_filter('pre_wp_mail', '__return_true');

// The deliberately pinned capture store should not advertise an unrelated
// core update above every guide. Booking/validation errors remain untouched.
add_action('admin_init', static function () {
    remove_action('admin_notices', 'update_nag', 3);
    remove_action('network_admin_notices', 'update_nag', 3);
});

add_filter('pre_http_request', static function ($pre, $args, $url) {
    $host = wp_parse_url($url, PHP_URL_HOST);
    if (in_array($host, array('app.smartsend.io', 'sandbox.smartsend.io'), true)) {
        return $pre; // The Browser API mock handles these; checked again below.
    }
    if ($host === '127.0.0.1' || $host === 'localhost') {
        return $pre;
    }
    return new WP_Error('docs_external_request', 'External requests are disabled in the Docs store.');
}, 1, 3);

add_filter('pre_http_request', static function ($pre, $args, $url) {
    $host = wp_parse_url($url, PHP_URL_HOST);
    if (!in_array($host, array('app.smartsend.io', 'sandbox.smartsend.io'), true)) {
        return $pre;
    }
    if (!is_array($pre) || !isset($pre['body'])) {
        return new WP_Error('docs_unmocked_request', 'A Smart Send request has no Docs fixture.');
    }
    $path = wp_parse_url($url, PHP_URL_PATH);
    if (!preg_match('~^/api/v1/website/[^/]+/(?:$|agents/closest(?:/|$)|agents/carrier/|shipments/labels(?:/|$))~', $path)) {
        return new WP_Error('docs_unknown_endpoint', 'Unknown Smart Send endpoint in the Docs store.');
    }
    $pre['body'] = str_replace(
        array('Browser Test Shop', 'Second Test Shop', 'Main Street 1', 'Other Street 9', 'mock@smartsend.test', 'localhost'),
        array('Harbour Parcel Shop', 'Central Parcel Shop', 'Harbour Street 1', 'Central Street 9', 'demo@example.test', 'shop.example.test'),
        $pre['body']
    );
    $body = json_decode($pre['body'], true);
    if (isset($body['data']['shipment_id'])) {
        $count = (int) get_option('ss_docs_booking_count', 0) + 1;
        update_option('ss_docs_booking_count', $count);
        $body['data']['shipment_id'] = 'docs-shipment-' . $count;
        $pre['body'] = wp_json_encode($body);
    }
    return $pre;
}, 9, 3);
