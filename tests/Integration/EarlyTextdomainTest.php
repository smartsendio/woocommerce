<?php

/*
 * Regression test for issue #58: since WordPress 6.7, any translation call
 * (__(), esc_html__(), ...) for the smart-send-logistics domain that runs
 * before the init action triggers a _doing_it_wrong notice for
 * _load_textdomain_just_in_time. The plugin bootstrap (the SS_Shipping_WC
 * constructor, executed when the plugin file is loaded — long before init)
 * must therefore not evaluate any translated string.
 *
 * The test bootstrap loads WordPress (and with it the plugin) before any test
 * code runs, so the original bootstrap-time notice cannot be observed
 * directly. Instead the pre-init environment is recreated: translations for
 * the domain are made discoverable (as on a non-English production site —
 * without a discoverable translation path WordPress never emits the notice,
 * which is why an en_US dev install hides the bug), the init /
 * after_setup_theme action counters are temporarily reset, and the plugin
 * bootstrap is re-run exactly as WordPress runs it when loading the plugin
 * file.
 */

it('does not trigger a too-early textdomain notice during plugin bootstrap', function () {
    global $wp_textdomain_registry;

    // Simulate a Danish site with translations available for our domain.
    $locale_filter = function () {
        return 'da_DK';
    };
    add_filter('locale', $locale_filter);
    $wp_textdomain_registry->set('smart-send-logistics', 'da_DK', sys_get_temp_dir());

    // Make the domain lazily-loadable again (reloadable, so the just-in-time
    // loader is not short-circuited by the unload).
    unload_textdomain('smart-send-logistics', true);

    // Pretend the request has not reached init yet, as is the case while
    // WordPress loads plugin files. (WP 6.7 keys the too-early check on the
    // init action; newer versions on after_setup_theme — reset both.)
    $saved_actions = [];
    foreach (['init', 'after_setup_theme'] as $action) {
        $saved_actions[$action] = $GLOBALS['wp_actions'][$action] ?? null;
        unset($GLOBALS['wp_actions'][$action]);
    }

    $notices = [];
    $capture = function ($function_name, $message) use (&$notices) {
        if ($function_name === '_load_textdomain_just_in_time'
            && strpos((string) $message, 'smart-send-logistics') !== false
        ) {
            $notices[] = $message;
        }
    };
    add_action('doing_it_wrong_run', $capture, 10, 2);
    add_filter('doing_it_wrong_trigger_error', '__return_false');

    try {
        // Re-run the plugin bootstrap. Before the fix this evaluated
        // translated strings in the constructor / define_constants() and
        // fired the notice; after the fix it must not translate anything.
        new SS_Shipping_WC();
    } finally {
        remove_action('doing_it_wrong_run', $capture);
        remove_filter('doing_it_wrong_trigger_error', '__return_false');

        foreach ($saved_actions as $action => $count) {
            if ($count !== null) {
                $GLOBALS['wp_actions'][$action] = $count;
            }
        }

        // Undo the simulated Danish locale so later tests run untouched.
        $wp_textdomain_registry->set('smart-send-logistics', 'da_DK', false);
        remove_filter('locale', $locale_filter);
    }

    expect($notices)->toBe([]);
});
