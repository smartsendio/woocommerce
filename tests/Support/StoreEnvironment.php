<?php

/** Explicit modern or legacy environment variables win over the env file. */
function ss_test_load_store_environment(string $file): void
{
    if (!is_readable($file)) {
        return;
    }

    $legacy_names = ['WP_PATH' => 'WP_DEV_PATH', 'WP_URL' => 'WP_BASE_URL'];
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        if (isset($legacy_names[$key]) && getenv($key) === false && getenv($legacy_names[$key]) === false) {
            putenv($key . '=' . trim($value));
        }
    }
}

/** The same installation serves integration bootstrap and browser fixtures. */
function ss_test_wp_path(): string
{
    $path = getenv('WP_PATH') ?: getenv('WP_DEV_PATH') ?: './local-dev/wordpress';
    if (!str_starts_with($path, '/')) {
        $path = dirname(__DIR__, 2) . '/' . $path;
    }

    return rtrim($path, '/');
}
