<?php

use Automattic\WooCommerce\Internal\Admin\Orders\MetaBoxes\CustomMetaBox;

/** Terminate a simulated AJAX request without swallowing application errors. */
class Pickup_Admin_Ajax_Stopped extends RuntimeException {}

function pickup_admin_as_user(string $role = 'shop_manager', array $extra_caps = []): void
{
    $previous = get_current_user_id();
    $user_id = wp_insert_user([
        'user_login' => 'pickup-admin-' . uniqid(),
        'user_pass' => wp_generate_password(),
        'role' => $role,
    ]);
    expect($user_id)->toBeInt();
    $user = wp_set_current_user($user_id);
    foreach ($extra_caps as $cap) {
        $user->add_cap($cap);
    }
    remember_cleanup_callback(function () use ($previous, $user_id): void {
        wp_set_current_user($previous);
        require_once ABSPATH . 'wp-admin/includes/user.php';
        wp_delete_user($user_id);
    });
}

function pickup_admin_as_order_editor(): void
{
    // WC 8.2 maps HPOS orders through placeholder posts, so an order
    // editor also needs edit_others_posts there. Newer WooCommerce maps
    // these permissions directly to the order capabilities instead.
    pickup_admin_as_user('customer', ['edit_shop_orders', 'edit_others_shop_orders', 'edit_others_posts']);
}

function pickup_admin_fresh_order(int $order_id): WC_Order
{
    $order = new WC_Order($order_id);
    $order->read_meta_data(true);

    return $order;
}

function pickup_admin_order(bool $hpos = true): WC_Order
{
    with_option('woocommerce_custom_orders_table_enabled', $hpos ? 'yes' : 'no');
    $order = create_order(['shipping_method' => 'postnord_agent']);
    save_order_pickup_point($order->get_id(), sample_agent());

    return pickup_admin_fresh_order($order->get_id());
}

function pickup_admin_meta_id(WC_Order $order, string $key = SS_Shipping_Order_Meta::META_AGENT_NO): int
{
    foreach ($order->get_meta_data() as $meta) {
        if ($meta->key === $key) {
            return (int) $meta->id;
        }
    }
    throw new RuntimeException('Expected metadata row is missing: ' . $key);
}

function pickup_admin_payload(WC_Order $order, string $operation): array
{
    $meta_id = pickup_admin_meta_id($order);
    if ($operation === 'delete') {
        return [
            'order_id' => $order->get_id(),
            'id' => $meta_id,
            '_ajax_nonce' => wp_create_nonce('delete-meta_' . $meta_id),
        ];
    }

    return [
        'order_id' => $order->get_id(),
        'post_id' => $order->get_id(),
        'meta' => [$meta_id => ['key' => SS_Shipping_Order_Meta::META_AGENT_NO, 'value' => '5678']],
        '_ajax_nonce-add-meta' => wp_create_nonce('add-meta'),
    ];
}

/** Run the plugin interceptor followed by the real platform AJAX handler. */
function pickup_admin_ajax(array $payload, string $operation, bool $hpos = true): array
{
    require_once ABSPATH . 'wp-admin/includes/template.php';
    require_once ABSPATH . 'wp-admin/includes/post.php';
    require_once ABSPATH . 'wp-admin/includes/ajax-actions.php';

    $action = $operation === 'delete' ? 'delete' : 'add';
    $payload['action'] = $hpos ? 'woocommerce_order_' . $action . '_meta' : $action . '-meta';
    $previous_post = $_POST;
    $previous_request = $_REQUEST;
    $_POST = $payload;
    $_REQUEST = $payload;
    $die_handler = static fn () => static function ($message = ''): void {
        throw new Pickup_Admin_Ajax_Stopped((string) $message);
    };
    add_filter('wp_doing_ajax', '__return_true');
    add_filter('wp_die_handler', $die_handler);
    add_filter('wp_die_ajax_handler', $die_handler);
    ob_start();
    $result = ['died' => false, 'message' => ''];

    try {
        if ($hpos) {
            $validator = SS_SHIPPING_WC()->pickup_point_validator();
            $core = new CustomMetaBox();
            if ($operation === 'delete') {
                $validator->intercept_hpos_meta_delete();
                $core->delete_meta_ajax();
            } else {
                $validator->intercept_hpos_inline_meta_update();
                $core->add_meta_ajax();
            }
        } elseif ($operation === 'delete') {
            wp_ajax_delete_meta();
        } else {
            wp_ajax_add_meta();
        }
    } catch (Pickup_Admin_Ajax_Stopped $exception) {
        $result = ['died' => true, 'message' => $exception->getMessage()];
    } finally {
        $result['body'] = ob_get_clean();
        $_POST = $previous_post;
        $_REQUEST = $previous_request;
        remove_filter('wp_doing_ajax', '__return_true');
        remove_filter('wp_die_handler', $die_handler);
        remove_filter('wp_die_ajax_handler', $die_handler);
    }

    return $result;
}

/** The order edit controller has already checked its nonce and permissions. */
function pickup_admin_form(WC_Order $order, array $payload): array
{
    $previous_post = $_POST;
    $previous_errors = WC_Admin_Meta_Boxes::$meta_box_errors;
    $_POST = $payload;
    $_POST['action'] = 'edit_order';

    try {
        SS_SHIPPING_WC()->pickup_point_validator()->validate_hpos_form_meta_changes($order->get_id(), $order);
        (new CustomMetaBox())->handle_metadata_changes($order);

        return array_slice(WC_Admin_Meta_Boxes::$meta_box_errors, count($previous_errors));
    } finally {
        $_POST = $previous_post;
        WC_Admin_Meta_Boxes::$meta_box_errors = $previous_errors;
    }
}

function pickup_admin_assert_unchanged(WC_Order $order, object $capture): void
{
    $fresh = pickup_admin_fresh_order($order->get_id());
    expect($capture->requests)->toBe([])
        ->and($fresh->get_meta(SS_Shipping_Order_Meta::META_AGENT_NO, true))->toBe('1234')
        ->and($fresh->get_meta(SS_Shipping_Order_Meta::META_AGENT, true))->toEqual(sample_agent());
}

beforeEach(function (): void {
    with_ss_settings();
});

it('requires both WooCommerce permissions before HPOS pickup metadata side effects', function (string $operation, string $only_capability) {
    $order = pickup_admin_order();
    pickup_admin_as_user('customer', [$only_capability]);
    $capture = mock_smart_send_api(fn () => ss_api_response(200, ['data' => sample_agent(['agent_no' => '5678'])]));

    $response = pickup_admin_ajax(pickup_admin_payload($order, $operation), $operation);

    expect($response['died'])->toBeTrue();
    pickup_admin_assert_unchanged($order, $capture);
})->with(['update', 'delete'])->with(['manage_woocommerce', 'edit_others_shop_orders']);

it('rejects an invalid HPOS nonce before pickup metadata side effects', function (string $operation) {
    $order = pickup_admin_order();
    pickup_admin_as_user();
    $capture = mock_smart_send_api();
    $payload = pickup_admin_payload($order, $operation);
    $payload[$operation === 'delete' ? '_ajax_nonce' : '_ajax_nonce-add-meta'] = 'invalid';

    $response = pickup_admin_ajax($payload, $operation);

    expect($response['died'])->toBeTrue()->and($response['message'])->toBe('-1');
    pickup_admin_assert_unchanged($order, $capture);
})->with(['update', 'delete']);

it('rejects HPOS updates whose row is missing, belongs to another order, or has a different key', function (string $invalid_row) {
    $order = pickup_admin_order();
    $order->add_meta_data('other_custom_field', 'keep');
    $order->save_meta_data();
    $other = create_order(['shipping_method' => 'postnord_agent']);
    save_order_pickup_point($other->get_id(), sample_agent());
    $other = pickup_admin_fresh_order($other->get_id());
    pickup_admin_as_user();
    $capture = mock_smart_send_api(fn () => ss_api_response(200, ['data' => sample_agent(['agent_no' => '5678'])]));
    $meta_ids = [
        'missing' => 999999999,
        'other order' => pickup_admin_meta_id($other),
        'different key' => pickup_admin_meta_id($order, 'other_custom_field'),
    ];
    $payload = pickup_admin_payload($order, 'update');
    $payload['meta'] = [$meta_ids[$invalid_row] => ['key' => SS_Shipping_Order_Meta::META_AGENT_NO, 'value' => '5678']];

    $response = pickup_admin_ajax($payload, 'update');

    expect($response['died'])->toBeTrue();
    pickup_admin_assert_unchanged($order, $capture);
    pickup_admin_assert_unchanged($other, $capture);
    expect(pickup_admin_fresh_order($order->get_id())->get_meta('other_custom_field', true))->toBe('keep');
})->with(['missing', 'other order', 'different key']);

it('allows an authorized admin update and keeps the companion pickup point in sync', function (bool $hpos) {
    $order = pickup_admin_order($hpos);
    pickup_admin_as_user();
    $capture = mock_smart_send_api(fn () => ss_api_response(200, ['data' => sample_agent(['agent_no' => '5678'])]));

    $response = pickup_admin_ajax(pickup_admin_payload($order, 'update'), 'update', $hpos);

    $fresh = pickup_admin_fresh_order($order->get_id());
    expect($response['died'])->toBeTrue()
        ->and($capture->requests)->toHaveCount(1)
        ->and($fresh->get_meta(SS_Shipping_Order_Meta::META_AGENT_NO, true))->toBe('5678')
        ->and($fresh->get_meta(SS_Shipping_Order_Meta::META_AGENT, true)->agent_no)->toBe('5678');
})->with(['HPOS' => true, 'post tables' => false]);

it('validates an authorized HPOS addition from either custom field key control', function (string $key_control) {
    $order = pickup_admin_order();
    SS_SHIPPING_WC()->order_meta()->write($order->get_id(), (new SS_Shipping_Delivery_Details())->clear_pickup_point());
    pickup_admin_as_user();
    $capture = mock_smart_send_api(fn () => ss_api_response(200, ['data' => sample_agent(['agent_no' => '5678'])]));

    $response = pickup_admin_ajax([
        'order_id' => $order->get_id(),
        $key_control => SS_Shipping_Order_Meta::META_AGENT_NO,
        'metavalue' => '5678',
        '_ajax_nonce-add-meta' => wp_create_nonce('add-meta'),
    ], 'update');

    $fresh = pickup_admin_fresh_order($order->get_id());
    expect($response['died'])->toBeTrue()
        ->and($capture->requests)->toHaveCount(1)
        ->and($fresh->get_meta(SS_Shipping_Order_Meta::META_AGENT_NO, true))->toBe('5678')
        ->and($fresh->get_meta(SS_Shipping_Order_Meta::META_AGENT, true)->agent_no)->toBe('5678');
})->with(['metakeyinput', 'metakeyselect']);

it('persists deletion of the pickup number and companion through the complete admin handler chain', function (bool $hpos) {
    $order = pickup_admin_order($hpos);
    pickup_admin_as_user();
    $capture = mock_smart_send_api();

    $response = pickup_admin_ajax(pickup_admin_payload($order, 'delete'), 'delete', $hpos);

    $fresh = pickup_admin_fresh_order($order->get_id());
    expect($response['died'])->toBeTrue()->and($response['message'])->toBe('1')
        ->and($capture->requests)->toBe([])
        ->and($fresh->meta_exists(SS_Shipping_Order_Meta::META_AGENT_NO))->toBeFalse()
        ->and($fresh->meta_exists(SS_Shipping_Order_Meta::META_AGENT))->toBeFalse();
})->with(['HPOS' => true, 'post tables' => false]);

it('lets WordPress reject unauthorized legacy admin requests before generic metadata hooks', function (string $operation) {
    $order = pickup_admin_order(false);
    pickup_admin_as_user('customer');
    $capture = mock_smart_send_api(fn () => ss_api_response(200, ['data' => sample_agent(['agent_no' => '5678'])]));

    $response = pickup_admin_ajax(pickup_admin_payload($order, $operation), $operation, false);

    expect($response['died'])->toBeTrue()->and($response['message'])->toBe('-1');
    pickup_admin_assert_unchanged($order, $capture);
})->with(['update', 'delete']);

it('validates full-form edits for an order editor without manage_woocommerce', function (bool $valid_number) {
    $order = pickup_admin_order();
    pickup_admin_as_order_editor();
    expect(current_user_can('edit_shop_order', $order->get_id()))->toBeTrue()
        ->and(current_user_can('manage_woocommerce'))->toBeFalse();
    $capture = mock_smart_send_api(fn () => $valid_number
        ? ss_api_response(200, ['data' => sample_agent(['agent_no' => '5678'])])
        : ss_api_response(404, ['code' => 'NoResults', 'message' => 'The agent was not found.']));

    $errors = pickup_admin_form($order, pickup_admin_payload($order, 'update'));

    $fresh = pickup_admin_fresh_order($order->get_id());
    $expected_number = $valid_number ? '5678' : '1234';
    expect($capture->requests)->toHaveCount(1)
        ->and($errors)->toHaveCount($valid_number ? 0 : 1)
        ->and($fresh->get_meta(SS_Shipping_Order_Meta::META_AGENT_NO, true))->toBe($expected_number)
        ->and($fresh->get_meta(SS_Shipping_Order_Meta::META_AGENT, true)->agent_no)->toBe($expected_number);
})->with(['valid number' => true, 'invalid number' => false]);

it('rejects full-form pickup edits targeting another order or a different metadata key', function (string $invalid_row) {
    $order = pickup_admin_order();
    $order->add_meta_data('other_custom_field', 'keep');
    $order->save_meta_data();
    $other = create_order(['shipping_method' => 'postnord_agent']);
    save_order_pickup_point($other->get_id(), sample_agent());
    $other = pickup_admin_fresh_order($other->get_id());
    pickup_admin_as_order_editor();
    expect(current_user_can('edit_shop_order', $order->get_id()))->toBeTrue()
        ->and(current_user_can('manage_woocommerce'))->toBeFalse();
    $capture = mock_smart_send_api(fn () => ss_api_response(200, ['data' => sample_agent(['agent_no' => '5678'])]));
    $meta_id = $invalid_row === 'other order'
        ? pickup_admin_meta_id($other)
        : pickup_admin_meta_id($order, 'other_custom_field');

    pickup_admin_form($order, [
        'meta' => [$meta_id => ['key' => SS_Shipping_Order_Meta::META_AGENT_NO, 'value' => '5678']],
    ]);

    pickup_admin_assert_unchanged($order, $capture);
    pickup_admin_assert_unchanged($other, $capture);
    expect(pickup_admin_fresh_order($order->get_id())->get_meta('other_custom_field', true))->toBe('keep');
})->with(['other order', 'different key']);
