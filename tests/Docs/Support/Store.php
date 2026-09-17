<?php

function docs_language(): string
{
    $locale = getenv('SS_DOCS_LOCALE') ?: 'en';
    if (!in_array($locale, ['en', 'da'], true)) {
        throw new RuntimeException('SS_DOCS_LOCALE must be en or da.');
    }
    return $locale;
}

function docs_text(string $english, string $danish): string
{
    return docs_language() === 'da' ? $danish : $english;
}

function docs_seed_store(array $config = []): array
{
    if (!getenv('SS_DOCS_ENABLED') || !ss_browser_store_manageable()) {
        throw new RuntimeException('Use composer test:docs to provision the documentation store.');
    }
    $guard = ss_browser_wp_eval("echo json_encode(array('docs' => (bool) get_option('ss_docs_store')));");
    if (!$guard['docs']) {
        throw new RuntimeException('Docs fixtures require the dedicated store created by bin/run-docs.sh.');
    }
    docs_cleanup_store();
    $muDirectory = ss_browser_wp_path() . '/wp-content/mu-plugins';
    if (!is_dir($muDirectory)) {
        mkdir($muDirectory, 0755, true);
    }
    if (!copy(__DIR__ . '/DocsMuPlugin.php', $muDirectory . '/ss-docs.php')) {
        throw new RuntimeException('Unable to install Docs isolation before seeding fixtures.');
    }
    ss_browser_wp_eval_file(__DIR__ . '/profile-store.php', [docs_language()]);
    $state = ss_browser_seed_store($config);
    ss_browser_wp_eval_file(__DIR__ . '/decorate-store.php', [docs_language()]);
    return $state;
}

function docs_cleanup_store(): void
{
    if (!ss_browser_store_manageable()) {
        return;
    }
    ss_browser_wp_eval_file(__DIR__ . '/cleanup-store.php');
    ss_browser_cleanup_store();
    $mock = ss_browser_wp_path() . '/wp-content/mu-plugins/ss-docs.php';
    if (is_file($mock)) {
        unlink($mock);
    }
}
