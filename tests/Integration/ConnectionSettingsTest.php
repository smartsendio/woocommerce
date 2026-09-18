<?php

use Smart_Send\Shipping_Method\Method;
use Smart_Send\Shipping_Method\Test_Connection;

it('renders no connection status before validation', function () {
    expect((new Test_Connection())->result_html())->toBe('');
});

it('uses fallback feedback when the API supplies no message', function ($status, $message, $class) {
    with_ss_settings(['api_token' => 'connection-test-token']);
    mock_smart_send_api(fn () => ss_api_response($status, [
        'data' => ['email' => 'private@example.test', 'website' => 'example.test'],
    ]));
    $connection = new Test_Connection();
    $connection->validate_saved_token();

    expect($connection->result_html())->toContain($message, $class, 'role="status"')
        ->not->toContain('private@example.test', 'connection-test-token');
})->with([
    [200, 'Connected to Smart Send', 'notice-success'],
    [401, 'The API Token is invalid.', 'notice-error'],
    [403, 'Check the team access and subscription in Smart Send.', 'notice-error'],
    [404, 'No team is registered for this webshop', 'notice-error'],
    [429, 'could not be verified', 'notice-error'],
    [500, 'could not be verified', 'notice-error'],
    [201, 'could not be verified', 'notice-error'],
]);

it('handles transport failures and malformed successful responses', function ($response) {
    with_ss_settings(['api_token' => 'connection-test-token']);
    mock_smart_send_api(fn () => $response);
    $connection = new Test_Connection();
    $connection->validate_saved_token();

    expect($connection->result_html())->toContain('could not be verified', 'notice-error')
        ->not->toContain('Connected to Smart Send', 'invalid');
})->with([
    'timeout' => fn () => new WP_Error('http_request_failed', 'Request timed out'),
    'invalid body' => fn () => ss_api_response(200, ['data' => null]),
]);

it('prompts for an empty token without contacting the API', function () {
    with_ss_settings(['api_token' => '']);
    $capture = mock_smart_send_api();
    $connection = new Test_Connection();
    $connection->validate_saved_token();

    expect($capture->requests)->toBeEmpty()
        ->and($connection->result_html())->toContain('Enter an API Token');
});

it('validates the newly saved token on every global save including unchanged settings', function () {
    with_ss_settings(['api_token' => 'old-token']);
    $capture = mock_smart_send_api(fn () => ss_api_response(200, ['data' => ['website' => 'example.test']]));
    $method = new Method();
    $method->set_post_data(['woocommerce_smart_send_shipping_api_token' => 'new-token']);
    $method->process_admin_options();
    $method->process_admin_options();

    expect($capture->requests)->toHaveCount(2);
    foreach ($capture->requests as $request) {
        parse_str(parse_url($request['url'], PHP_URL_QUERY), $query);
        expect($query['api_token'])->toBe('new-token');
    }
    $html = $method->generate_text_html('api_token', $method->form_fields['api_token']);
    expect($html)->toContain('aria-describedby="ss-connection-result"', 'Connected to Smart Send')
        ->and(strpos($html, 'ss-connection-result" class='))->toBeLessThan(strpos($html, '</td>'))
        ->and($method->form_fields)->not->toHaveKey('api_token_validate');
});

it('replaces successful feedback after a failed save validation', function () {
    with_ss_settings(['api_token' => 'connection-test-token']);
    $status = 200;
    mock_smart_send_api(function () use (&$status) {
        return ss_api_response($status, ['data' => ['website' => 'example.test']]);
    });
    $connection = new Test_Connection();
    $connection->validate_saved_token();
    expect($connection->result_html())->toContain('Connected to Smart Send');
    $status = 401;
    $connection->validate_saved_token();
    expect($connection->result_html())->toContain('The API Token is invalid.')
        ->not->toContain('Connected to Smart Send');
});

it('does not validate the global connection when saving a shipping zone method', function () {
    $capture = mock_smart_send_api();
    $method = new Method(99871);
    remember_cleanup_callback(fn () => delete_option($method->get_instance_option_key()));
    $method->set_post_data([]);
    $method->process_admin_options();

    expect($capture->requests)->toBeEmpty();
});


it('displays the API error message as text for HTTP failures', function ($status, $message) {
    with_ss_settings(['api_token' => 'connection-test-token']);
    mock_smart_send_api(fn () => ss_api_response($status, ['message' => $message]));
    $connection = new Test_Connection();
    $connection->validate_saved_token();

    expect($connection->result_html())->toContain(esc_html($message), 'notice-error')
        ->not->toContain('<script>', '<a href=');
})->with([
    [401, 'This API token has expired.'],
    [403, 'The user does not have access to this team.'],
    [403, 'The team does not have a subscription.'],
    [403, '<script>alert("test")</script><a href="https://example.test">Subscribe</a>'],
    [404, 'No webshop team was found.'],
    [429, 'Too many requests. Please try again shortly.'],
    [500, 'Smart Send is temporarily unavailable.'],
]);

it('uses fallback feedback for empty API messages', function ($message) {
    with_ss_settings(['api_token' => 'connection-test-token']);
    mock_smart_send_api(fn () => ss_api_response(403, ['message' => $message]));
    $connection = new Test_Connection();
    $connection->validate_saved_token();

    expect($connection->result_html())->toContain('Check the team access and subscription in Smart Send.');
})->with([null, '', '   ']);
