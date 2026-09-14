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

/*
 * The per-user dismissible "bulk label printing removed" notice on the
 * Orders list screen (#173).
 */

/**
 * Create a user with the given role and make them the current user; the
 * previous user is restored and this one deleted (with its meta) after the
 * test.
 */
function act_as_new_user(string $role): int
{
    $previous = get_current_user_id();
    $user_id  = wp_insert_user([
        'user_login' => 'ss-notices-' . uniqid(),
        'user_pass'  => wp_generate_password(),
        'role'       => $role,
    ]);
    expect($user_id)->toBeInt();

    wp_set_current_user($user_id);

    remember_cleanup_callback(function () use ($user_id, $previous): void {
        wp_set_current_user($previous);
        require_once ABSPATH . 'wp-admin/includes/user.php';
        wp_delete_user($user_id);
    });

    return $user_id;
}

/**
 * Make an admin screen current for the test, optionally with an `action`
 * query parameter (the HPOS single-order screen shares the list's id).
 */
function on_admin_screen(string $screen_id, ?string $action = null): void
{
    require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
    require_once ABSPATH . 'wp-admin/includes/screen.php';

    $GLOBALS['current_screen'] = WP_Screen::get($screen_id);

    if (null !== $action) {
        $_GET['action'] = $action;
    }

    remember_cleanup_callback(function (): void {
        $GLOBALS['current_screen'] = null;
        unset($_GET['action']);
    });
}

function render_bulk_labels_removed_notice(): string
{
    ob_start();
    SS_SHIPPING_WC()->admin_notices()->maybe_render_bulk_labels_removed_notice();

    return ob_get_clean();
}

it('registers the Orders screen notice renderer and its dismissal handler', function () {
    $notices = SS_SHIPPING_WC()->admin_notices();

    expect(has_action('admin_notices', [$notices, 'maybe_render_bulk_labels_removed_notice']))->not->toBeFalse()
        ->and(has_action('admin_init', [$notices, 'maybe_handle_dismiss']))->not->toBeFalse();
});

it('tells users who can manage WooCommerce on the Orders list that bulk printing was removed', function (string $screen_id) {
    act_as_new_user('shop_manager');
    on_admin_screen($screen_id);

    $output = render_bulk_labels_removed_notice();

    expect($output)->toContain('notice-warning')
        ->toContain('Bulk printing of shipping labels from the Orders screen has been removed in version 9.0.0.')
        ->toContain('We are building a much better version, and it is coming soon.')
        ->toContain('<a href="' . SS_Shipping_Admin_Notices::PREVIOUS_VERSIONS_URL . '" target="_blank">downgrade to version 8.1.3</a>')
        ->toContain('ss_shipping_dismiss_notice=bulk_labels_removed')
        ->toContain('_wpnonce=');
})->with([
    'legacy' => 'edit-shop_order',
    'HPOS'   => 'woocommerce_page_wc-orders',
]);

it('does not show the bulk printing notice outside the Orders list', function (string $screen_id, ?string $action) {
    act_as_new_user('shop_manager');
    on_admin_screen($screen_id, $action);

    expect(render_bulk_labels_removed_notice())->toBe('');
})->with([
    'dashboard'                => ['dashboard', null],
    'legacy single order'      => ['shop_order', null],
    'HPOS single order (edit)' => ['woocommerce_page_wc-orders', 'edit'],
    'HPOS new order'           => ['woocommerce_page_wc-orders', 'new'],
]);

it('does not show the bulk printing notice without a current screen', function () {
    act_as_new_user('shop_manager');

    expect(render_bulk_labels_removed_notice())->toBe('');
});

it('never shows the bulk printing notice to users who cannot manage WooCommerce', function (string $role) {
    act_as_new_user($role);
    on_admin_screen('edit-shop_order');

    expect(render_bulk_labels_removed_notice())->toBe('');
})->with(['editor', 'customer']);

it('hides the bulk printing notice once dismissed, per user', function () {
    $notices = SS_SHIPPING_WC()->admin_notices();
    on_admin_screen('edit-shop_order');

    $user_b = act_as_new_user('shop_manager');
    $user_a = act_as_new_user('shop_manager');

    $notices->dismiss(SS_Shipping_Admin_Notices::NOTICE_BULK_LABELS_REMOVED);

    expect(render_bulk_labels_removed_notice())->toBe('')
        ->and($notices->is_dismissed(SS_Shipping_Admin_Notices::NOTICE_BULK_LABELS_REMOVED, $user_a))->toBeTrue()
        ->and(get_user_meta($user_a, SS_Shipping_Admin_Notices::DISMISSED_META_KEY, true))->toBe([SS_Shipping_Admin_Notices::NOTICE_BULK_LABELS_REMOVED]);

    // Dismissing again does not duplicate the stored id.
    $notices->dismiss(SS_Shipping_Admin_Notices::NOTICE_BULK_LABELS_REMOVED);
    expect(get_user_meta($user_a, SS_Shipping_Admin_Notices::DISMISSED_META_KEY, true))->toBe([SS_Shipping_Admin_Notices::NOTICE_BULK_LABELS_REMOVED]);

    // Another user still sees it.
    wp_set_current_user($user_b);

    expect(render_bulk_labels_removed_notice())->toContain('Bulk printing of shipping labels');
});

/**
 * Simulate a click on a dismissal link: the request URI and query args of
 * $url become the current request. wp_redirect is intercepted with an
 * exception carrying the redirect location, since the handler exits after
 * redirecting. Returns the location, or null when no redirect happened.
 */
function follow_dismiss_link(string $url): ?string
{
    $original_uri = $_SERVER['REQUEST_URI'];

    $_SERVER['REQUEST_URI'] = $url;
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    $_GET = array_merge($_GET, $query);

    $interceptor = function (string $location): string {
        throw new RuntimeException($location);
    };
    add_filter('wp_redirect', $interceptor);

    remember_cleanup_callback(function () use ($original_uri, $query, $interceptor): void {
        $_SERVER['REQUEST_URI'] = $original_uri;
        foreach (array_keys($query) as $key) {
            unset($_GET[$key]);
        }
        remove_filter('wp_redirect', $interceptor);
    });

    try {
        SS_SHIPPING_WC()->admin_notices()->maybe_handle_dismiss();
    } catch (RuntimeException $redirect) {
        return $redirect->getMessage();
    }

    return null;
}

it('dismisses the notice from a valid dismissal link and redirects back without the dismissal parameters', function () {
    $notices = SS_SHIPPING_WC()->admin_notices();
    $user_id = act_as_new_user('shop_manager');

    $_SERVER['REQUEST_URI'] = '/wp-admin/edit.php?post_type=shop_order';
    $url = $notices->get_dismiss_url(SS_Shipping_Admin_Notices::NOTICE_BULK_LABELS_REMOVED);

    expect(follow_dismiss_link($url))->toBe('/wp-admin/edit.php?post_type=shop_order')
        ->and($notices->is_dismissed(SS_Shipping_Admin_Notices::NOTICE_BULK_LABELS_REMOVED, $user_id))->toBeTrue();
});

it('ignores dismissal links with an invalid nonce or an unknown notice', function (string $url) {
    $notices = SS_SHIPPING_WC()->admin_notices();
    $user_id = act_as_new_user('shop_manager');

    if (str_contains($url, '{valid-nonce-for-unknown}')) {
        $url = str_replace('{valid-nonce-for-unknown}', wp_create_nonce(SS_Shipping_Admin_Notices::DISMISS_NONCE_ACTION . 'unknown'), $url);
    }

    expect(follow_dismiss_link($url))->toBeNull()
        ->and($notices->is_dismissed(SS_Shipping_Admin_Notices::NOTICE_BULK_LABELS_REMOVED, $user_id))->toBeFalse()
        ->and(get_user_meta($user_id, SS_Shipping_Admin_Notices::DISMISSED_META_KEY, true))->toBe('');
})->with([
    'invalid nonce' => '/wp-admin/edit.php?ss_shipping_dismiss_notice=bulk_labels_removed&_wpnonce=invalid',
    'missing nonce' => '/wp-admin/edit.php?ss_shipping_dismiss_notice=bulk_labels_removed',
    'unknown notice' => '/wp-admin/edit.php?ss_shipping_dismiss_notice=unknown&_wpnonce={valid-nonce-for-unknown}',
]);
