<?php

it('can log in to wp-admin', function () {
    login_as_admin()->assertSee('Dashboard');
});

it('has the Smart Send plugin active', function () {
    login_as_admin()
        ->navigate(base_url('/wp-admin/plugins.php'))
        ->assertSee('Smart Send Shipping for WooCommerce')
        ->assertDontSee('Activate Smart Send');
});

it('renders the Smart Send shipping settings page', function () {
    login_as_admin()
        ->navigate(base_url('/wp-admin/admin.php?page=wc-settings&tab=shipping&section=smart_send_shipping'))
        ->assertSee('Smart Send');
});
