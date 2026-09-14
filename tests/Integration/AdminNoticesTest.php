<?php

/*
 * Tests for SS_Shipping_Admin_Notices: transient-backed one-time admin
 * notices pushed after label-generation actions. Covers the full lifecycle
 * (push → marked redirect → rendered once → cleared), the query-parameter
 * gate that prevents transient lookups on unrelated admin requests, and
 * per-user isolation of pending notices.
 */

/**
 * A clean notices component for the current user; pending notices and the
 * marker query parameter are wiped again after the test.
 */
function clean_admin_notices(): SS_Shipping_Admin_Notices
{
    $notices = SS_SHIPPING_WC()->admin_notices();

    $notices->clear();

    remember_cleanup_callback(function () use ($notices): void {
        $notices->clear();
        unset($_GET[SS_Shipping_Admin_Notices::QUERY_ARG]);
    });

    return $notices;
}

it('registers the renderer on the admin_notices hook', function () {
    $notices = SS_SHIPPING_WC()->admin_notices();

    expect(has_action('admin_notices', [$notices, 'maybe_render']))->not->toBeFalse();
});

it('stores pushed notices in a per-user transient', function () {
    $notices = clean_admin_notices();

    $notices->push([['message' => 'First notice', 'type' => 'success']]);
    $notices->push([['message' => 'Second notice', 'type' => 'error']]);

    $stored = get_transient(SS_Shipping_Admin_Notices::TRANSIENT_PREFIX . get_current_user_id());

    expect($stored)->toBe([
        ['message' => 'First notice', 'type' => 'success'],
        ['message' => 'Second notice', 'type' => 'error'],
    ]);
});

it('does not create a transient when pushing an empty message list', function () {
    $notices = clean_admin_notices();

    $notices->push([]);

    expect(get_transient(SS_Shipping_Admin_Notices::TRANSIENT_PREFIX . get_current_user_id()))->toBeFalse();
});

it('adds the marker query parameter to redirect URLs', function () {
    $notices = SS_SHIPPING_WC()->admin_notices();

    expect($notices->add_notices_query_arg('/wp-admin/edit.php'))
        ->toBe('/wp-admin/edit.php?ss_shipping_notices=1');
    expect($notices->add_notices_query_arg('/wp-admin/edit.php?post_type=shop_order'))
        ->toBe('/wp-admin/edit.php?post_type=shop_order&ss_shipping_notices=1');
});

it('renders pending notices exactly once and clears them when the marker parameter is present', function () {
    $notices = clean_admin_notices();

    $notices->push([
        ['message' => 'Label created <a href="https://example.test/label.pdf">Download</a>', 'type' => 'success'],
        ['message' => 'Order #2: something failed', 'type' => 'error', 'dismissible' => true],
        ['message' => 'Heads up', 'type' => 'unknown-type'],
    ]);

    $_GET[SS_Shipping_Admin_Notices::QUERY_ARG] = '1';

    ob_start();
    $notices->maybe_render();
    $output = ob_get_clean();

    expect($output)->toContain('notice-success')
        ->toContain('<a href="https://example.test/label.pdf">Download</a>')
        ->toContain('notice-error')
        ->toContain('is-dismissible')
        // Unknown types fall back to a warning notice.
        ->toContain('notice-warning');

    // The transient is gone and a second render outputs nothing.
    expect(get_transient(SS_Shipping_Admin_Notices::TRANSIENT_PREFIX . get_current_user_id()))->toBeFalse();

    ob_start();
    $notices->maybe_render();
    expect(ob_get_clean())->toBe('');
});

it('does not look up pending notices without the marker parameter', function () {
    $notices = clean_admin_notices();

    $notices->push([['message' => 'Pending notice', 'type' => 'success']]);

    unset($_GET[SS_Shipping_Admin_Notices::QUERY_ARG]);

    // get_transient() runs the pre_transient_{name} filter on every lookup;
    // count invocations to prove maybe_render() never touches storage.
    $lookups = 0;
    $counter = function ($pre) use (&$lookups) {
        $lookups++;

        return $pre;
    };
    $filter = 'pre_transient_' . SS_Shipping_Admin_Notices::TRANSIENT_PREFIX . get_current_user_id();
    add_filter($filter, $counter);
    remember_cleanup_callback(function () use ($filter, $counter): void {
        remove_filter($filter, $counter);
    });

    ob_start();
    $notices->maybe_render();
    $output = ob_get_clean();

    expect($output)->toBe('')
        ->and($lookups)->toBe(0);

    // The notices stay pending for the marked request.
    expect($notices->get_pending())->toHaveCount(1);
});

it('keeps notices isolated per user', function () {
    $notices = clean_admin_notices();

    $user_a = get_current_user_id();
    $user_b = wp_insert_user([
        'user_login' => 'ss-notices-' . uniqid(),
        'user_pass'  => wp_generate_password(),
        'role'       => 'shop_manager',
    ]);
    expect($user_b)->toBeInt();

    remember_cleanup_callback(function () use ($user_b, $user_a, $notices): void {
        $notices->clear($user_b);
        wp_set_current_user($user_a);
        require_once ABSPATH . 'wp-admin/includes/user.php';
        wp_delete_user($user_b);
    });

    $notices->push([['message' => 'Notice for user A', 'type' => 'success']], $user_a);

    // User B sees nothing on a marked request, and user A's notice survives.
    wp_set_current_user($user_b);
    $_GET[SS_Shipping_Admin_Notices::QUERY_ARG] = '1';

    ob_start();
    $notices->maybe_render();
    $output = ob_get_clean();

    expect($output)->toBe('')
        ->and($notices->get_pending($user_a))->toHaveCount(1);

    // Back as user A the notice renders.
    wp_set_current_user($user_a);

    ob_start();
    $notices->maybe_render();
    $output = ob_get_clean();

    expect($output)->toContain('Notice for user A')
        ->and($notices->get_pending($user_a))->toBe([]);
});
