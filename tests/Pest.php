<?php

/*
|--------------------------------------------------------------------------
| Pest configuration
|--------------------------------------------------------------------------
|
| The browser tests run against a local development store created by
| bin/setup-local-dev.sh. Point WP_BASE_URL at the store (and adjust the
| admin credentials) if you deviate from the script's defaults.
|
*/

function base_url(string $path = '/'): string
{
    $base = getenv('WP_BASE_URL') ?: 'http://127.0.0.1:8181';

    return rtrim($base, '/') . $path;
}

function admin_username(): string
{
    return getenv('WP_ADMIN_USER') ?: 'admin';
}

function admin_password(): string
{
    return getenv('WP_ADMIN_PASS') ?: 'password';
}
