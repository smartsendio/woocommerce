<?php

// Documentation runs use their own disposable store, never .env.testing.
require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__) . '/Support/StoreEnvironment.php';
ss_test_load_store_environment(dirname(__DIR__, 2) . '/.env.docs');

// Guard before tests/Pest.php registers its recovery hook. Checking only in
// docs_seed_store() is too late: recovery can already have changed fixtures in
// an inherited WP_PATH (or the default testing store when .env.docs is absent).
$docsRoot = dirname(__DIR__, 2) . '/local-dev/docs-wordpress';
$docsPath = ss_test_wp_path();
$docsOutput = getenv('SS_DOCS_OUTPUT');
if (
    getenv('SS_DOCS_ENABLED') !== '1'
    || !$docsOutput
    || !is_dir($docsOutput)
    || realpath($docsRoot) === false
    || is_link($docsRoot)
    || realpath($docsPath) !== realpath($docsRoot)
    || rtrim((string) getenv('WP_URL'), '/') !== 'http://127.0.0.1:8182'
) {
    throw new RuntimeException('Use composer test:docs: documentation captures require the dedicated Docs store, URL and fresh output directory.');
}
