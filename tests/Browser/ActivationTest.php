<?php

/*
 * The setup script installs the plugin by symlinking it from the repository,
 * so the wp-admin activation path is never exercised elsewhere. This cycles
 * the plugin through deactivate -> activate on the plugins screen, which
 * catches fatals in the (de)activation hooks and on plugin load.
 *
 * The test is self-contained: it always leaves the plugin active for the
 * other tests, regardless of execution order. When the plugin gains an
 * onboarding flow, assert it here after the activation step.
 */

it('can deactivate and re-activate the Smart Send plugin from wp-admin', function () {
    $page = login_as_admin()
        ->navigate(base_url('/wp-admin/plugins.php'));

    $page->assertPresent('#deactivate-smart-send-logistics')
        ->click('#deactivate-smart-send-logistics')
        ->assertSee('Plugin deactivated.')
        ->assertPresent('#activate-smart-send-logistics')
        ->click('#activate-smart-send-logistics')
        ->assertSee('Plugin activated.')
        ->assertPresent('#deactivate-smart-send-logistics');
});
