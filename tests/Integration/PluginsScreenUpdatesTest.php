<?php

/*
 * Tests for SS_Plugins_Screen_Updates: the plugins-screen "in plugin update
 * message" notice. The notice is computed purely from a semver major-version
 * comparison between the installed version (SS_SHIPPING_VERSION) and the
 * version offered by the update response - no external request is made.
 * See issue #104.
 */

/**
 * Build a fake plugin-update response object as WordPress core would pass it
 * to the `in_plugin_update_message-{plugin}` hook.
 */
function ss_fake_update_response(string $new_version): stdClass
{
    $response              = new stdClass();
    $response->new_version = $new_version;

    return $response;
}

it('shows the upgrade notice when the available version bumps the major component', function () {
    $updates = new SS_Plugins_Screen_Updates();

    $major = (int) explode('.', SS_SHIPPING_VERSION)[0];

    ob_start();
    $updates->in_plugin_update_message([], ss_fake_update_response(($major + 1) . '.0.0'));
    $output = ob_get_clean();

    expect($output)->toContain('major version upgrade')
        ->toContain('wc_plugin_upgrade_notice')
        ->toContain('https://wordpress.org/plugins/smart-send-logistics/');
});

it('shows no notice when only the minor version changes', function () {
    $updates = new SS_Plugins_Screen_Updates();

    $parts       = explode('.', SS_SHIPPING_VERSION);
    $new_version = $parts[0] . '.' . ((int) ($parts[1] ?? 0) + 1) . '.0';

    ob_start();
    $updates->in_plugin_update_message([], ss_fake_update_response($new_version));
    $output = ob_get_clean();

    expect($output)->toBe('');
});

it('shows no notice when only the patch version changes', function () {
    $updates = new SS_Plugins_Screen_Updates();

    $parts       = explode('.', SS_SHIPPING_VERSION);
    $new_version = $parts[0] . '.' . ($parts[1] ?? '0') . '.' . ((int) ($parts[2] ?? 0) + 1);

    ob_start();
    $updates->in_plugin_update_message([], ss_fake_update_response($new_version));
    $output = ob_get_clean();

    expect($output)->toBe('');
});

it('shows no notice when the version is unchanged', function () {
    $updates = new SS_Plugins_Screen_Updates();

    ob_start();
    $updates->in_plugin_update_message([], ss_fake_update_response(SS_SHIPPING_VERSION));
    $output = ob_get_clean();

    expect($output)->toBe('');
});

it('makes no HTTP request to compute the notice', function () {
    $updates = new SS_Plugins_Screen_Updates();

    $requests = 0;
    $recorder = function ($preempt) use (&$requests) {
        $requests++;

        return $preempt;
    };
    add_filter('pre_http_request', $recorder, 10, 1);
    remember_cleanup_callback(function () use ($recorder): void {
        remove_filter('pre_http_request', $recorder, 10);
    });

    $major = (int) explode('.', SS_SHIPPING_VERSION)[0];

    ob_start();
    $updates->in_plugin_update_message([], ss_fake_update_response(($major + 1) . '.0.0'));
    ob_get_clean();

    expect($requests)->toBe(0);
});

it('registers the notice on the plugin-specific update message hook', function () {
    $updates = new SS_Plugins_Screen_Updates();

    expect(has_action('in_plugin_update_message-smart-send-logistics/smart-send-logistics.php', [$updates, 'in_plugin_update_message']))->not->toBeFalse();
});
