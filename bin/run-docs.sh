#!/usr/bin/env bash
# Explicit documentation-image generation against a separate, disposable store.
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT"
PHP_BIN="${PHP_BIN:-php}"
DOCS_REUSE=false
DOCS_LOCALE=all
DOCS_FILTER=""
DOCS_OUTPUT=""
DOCS_SERVER_PID=""
DOCS_LOCK="$REPO_ROOT/local-dev/.docs-run.lock"
DOCS_LOCK_OWNED=false
export WP_PATH="$REPO_ROOT/local-dev/docs-wordpress"
export WP_URL="http://127.0.0.1:8182"
export SS_DOCS_ENABLED=1

usage() {
    cat <<'USAGE'
Usage: composer test:docs -- [--reuse] [--locale en|da|all] [--filter PATTERN] [--output DIRECTORY]

The default rebuilds the dedicated Docs store and generates both languages.
--reuse skips provisioning for iteration; it only accepts an existing Docs store.
--filter forwards a Pest test-name filter and marks the image bundle as partial.
--output must be absent or empty. Existing image bundles are never overwritten.
USAGE
}

fail() { printf 'Docs: %s\n' "$*" >&2; exit 1; }
while [[ $# -gt 0 ]]; do
    case "$1" in
        --reuse) DOCS_REUSE=true; shift ;;
        --locale) [[ $# -ge 2 ]] || fail '--locale needs a value'; DOCS_LOCALE="$2"; shift 2 ;;
        --filter) [[ $# -ge 2 ]] || fail '--filter needs a value'; DOCS_FILTER="$2"; shift 2 ;;
        --output) [[ $# -ge 2 ]] || fail '--output needs a value'; DOCS_OUTPUT="$2"; shift 2 ;;
        -h|--help) usage; exit 0 ;;
        *) fail "Unknown option: $1" ;;
    esac
done
case "$DOCS_LOCALE" in en|da|all) ;; *) fail '--locale must be en, da or all' ;; esac
DOCS_LOCALES="$DOCS_LOCALE"
[[ "$DOCS_LOCALE" != all ]] || DOCS_LOCALES='en,da'
[[ -x "$REPO_ROOT/vendor/bin/pest" ]] || fail 'Install Composer dependencies first.'
command -v "$PHP_BIN" >/dev/null || fail 'PHP is not available.'
command -v lsof >/dev/null || fail 'lsof is required to check the dedicated server port.'

mkdir -p "$REPO_ROOT/local-dev" "$REPO_ROOT/docs/screenshots/.runs"
if [[ -z "$DOCS_OUTPUT" ]]; then
    DOCS_OUTPUT="$(mktemp -d "$REPO_ROOT/docs/screenshots/.runs/$(date -u +%Y%m%dT%H%M%SZ)-XXXXXX")"
else
    [[ "$DOCS_OUTPUT" == /* ]] || DOCS_OUTPUT="$REPO_ROOT/$DOCS_OUTPUT"
    mkdir -p "$DOCS_OUTPUT"
    [[ -z "$(ls -A "$DOCS_OUTPUT")" ]] || fail '--output must be an empty directory.'
fi
export SS_DOCS_OUTPUT="$(cd "$DOCS_OUTPUT" && pwd)"

# Kill only descendants of the server this invocation started. Never terminate
# arbitrary listeners on a port, including a manually started Docs server.
stop_docs_process_tree() {
    local parent="$1" child
    for child in $(pgrep -P "$parent" 2>/dev/null || true); do
        stop_docs_process_tree "$child"
    done
    kill "$parent" 2>/dev/null || true
}

finish() {
    local status="$1" report_status=0
    trap - EXIT INT TERM
    [[ -z "$DOCS_SERVER_PID" ]] || stop_docs_process_tree "$DOCS_SERVER_PID"
    if [[ "$DOCS_LOCK_OWNED" == true ]]; then
        rm -f "$DOCS_LOCK/pid"
        rmdir "$DOCS_LOCK" 2>/dev/null || true
    fi
    local args=(--output "$SS_DOCS_OUTPUT" --locales "$DOCS_LOCALES" --test-exit "$status")
    [[ -z "$DOCS_FILTER" ]] || args+=(--filtered)
    "$PHP_BIN" "$REPO_ROOT/bin/docs-screenshots.php" "${args[@]}" || report_status=$?
    [[ "$status" -ne 0 ]] || status="$report_status"
    printf '\nDocs review bundle: %s/index.html\n' "$SS_DOCS_OUTPUT"
    exit "$status"
}
trap 'finish "$?"' EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

mkdir "$DOCS_LOCK" 2>/dev/null || fail "Another Docs run holds $DOCS_LOCK. If it was interrupted, check its recorded PID before removing that lock."
DOCS_LOCK_OWNED=true
printf '%s\n' "$$" > "$DOCS_LOCK/pid"
lsof -nP -iTCP:8182 -sTCP:LISTEN >/dev/null 2>&1 && fail 'Port 8182 is already in use; stop that server before generating screenshots.'

profile_value() { "$PHP_BIN" -r '$p=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR); echo $p[$argv[2]];' "$REPO_ROOT/tests/Docs/profile.json" "$1"; }
DOCS_WP_VERSION="$(profile_value wordpress)"
DOCS_WC_VERSION="$(profile_value woocommerce)"
DOCS_THEME="$(profile_value theme)"
DOCS_THEME_VERSION="$(profile_value theme_version)"

docs_wp() {
    "$PHP_BIN" -d memory_limit=512M -d error_reporting=0 -d display_errors=0 \
        "$WP_PATH/.wp-cli/wp-cli.phar" --path="$WP_PATH" "$@"
}

if [[ "$DOCS_REUSE" != true ]]; then
    printf 'Provisioning the pinned documentation store.\n'
    "$REPO_ROOT/bin/setup-local-dev.sh" --env docs --disposable \
        --path "$WP_PATH" --url "$WP_URL" --force \
        --wp-version "$DOCS_WP_VERSION" --wc-version "$DOCS_WC_VERSION" \
        --order-storage hpos --checkout classic --prices-tax exclude \
        --admin-email admin@example.test \
        2>&1 | tee "$SS_DOCS_OUTPUT/provisioning.log"
    docs_wp theme install "$DOCS_THEME" --version="$DOCS_THEME_VERSION" --force --activate
    docs_wp language core install da_DK
    docs_wp language plugin install woocommerce da_DK
    docs_wp language theme install "$DOCS_THEME" da_DK
    # The unreleased plugin version may not have a public language pack.
    # Record the actual installed assets below, including an explicit gap.
    if docs_wp language plugin install smart-send-logistics da_DK >"$SS_DOCS_OUTPUT/plugin-language-pack.log" 2>&1; then
        docs_wp option update ss_docs_plugin_language_pack installed
    else
        docs_wp option update ss_docs_plugin_language_pack unavailable
        printf 'Smart Send public da_DK language pack unavailable; untranslated interface strings will be recorded in the report.\n'
    fi
    docs_wp option update ss_docs_store true --format=json
else
    [[ -f "$WP_PATH/wp-load.php" ]] || fail '--reuse requires an existing Docs store.'
    [[ "$(docs_wp option get ss_docs_store)" == 1 ]] || fail 'The existing store is not marked as a disposable Docs store.'
fi

[[ "$(docs_wp core version)" == "$DOCS_WP_VERSION" ]] || fail 'WordPress version differs from tests/Docs/profile.json.'
[[ "$(docs_wp plugin get woocommerce --field=version)" == "$DOCS_WC_VERSION" ]] || fail 'WooCommerce version differs from tests/Docs/profile.json.'
[[ "$(docs_wp theme get "$DOCS_THEME" --field=version)" == "$DOCS_THEME_VERSION" ]] || fail 'Theme version differs from tests/Docs/profile.json.'
[[ "$(docs_wp option get stylesheet)" == "$DOCS_THEME" ]] || fail 'The pinned Docs theme is not active.'

cat > "$REPO_ROOT/.env.docs" <<EOF
# Dedicated disposable documentation store; never points at .env.testing.
WP_PATH=./local-dev/docs-wordpress
WP_URL=$WP_URL
WP_CHECKOUT=classic
WP_PRICES_TAX=exclude
WP_ORDER_STORAGE=hpos
EOF

# Capture installed versions and actual language assets, without configuration
# secrets. Fixture-specific pricing and locale settings are recorded per image.
docs_wp eval '
$theme = wp_get_theme();
foreach (array(
    WP_LANG_DIR . "/da_DK",
    WP_LANG_DIR . "/admin-da_DK",
    WP_LANG_DIR . "/plugins/woocommerce-da_DK",
    WP_LANG_DIR . "/themes/storefront-da_DK",
) as $translation) {
    if (!is_file($translation . ".mo") && !is_file($translation . ".l10n.php")) {
        WP_CLI::error("Required Docs translation is missing: " . basename($translation) . ". Rebuild the Docs store without --reuse.");
    }
}
$files = array_merge(
    glob(WP_LANG_DIR . "/*da_DK*") ?: array(),
    glob(WP_LANG_DIR . "/plugins/woocommerce-da_DK*") ?: array(),
    glob(WP_LANG_DIR . "/plugins/smart-send-logistics-da_DK*") ?: array(),
    glob(WP_LANG_DIR . "/themes/storefront-da_DK*") ?: array(),
    glob(WP_PLUGIN_DIR . "/smart-send-logistics/lang/*da_DK*") ?: array()
);
$translations = array();
foreach ($files as $file) {
    if (is_file($file)) {
        $translations[] = array("file" => str_replace(ABSPATH, "", $file), "sha256" => hash_file("sha256", $file));
    }
}
$plugin_pack = glob(WP_LANG_DIR . "/plugins/smart-send-logistics-da_DK*") ?: array();
$data = array(
    "wordpress" => get_bloginfo("version"), "woocommerce" => WC_VERSION,
    "plugin" => SS_SHIPPING_VERSION, "theme" => $theme->get_stylesheet(),
    "theme_version" => $theme->get("Version"), "php" => PHP_VERSION,
    "url" => home_url(), "disposable_docs_store" => (bool) get_option("ss_docs_store"),
    "plugin_language_pack" => $plugin_pack ? "installed" : "unavailable",
    "translation_assets" => $translations
);
file_put_contents(getenv("SS_DOCS_OUTPUT") . "/environment.json", json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
'

PHP_CLI_SERVER_WORKERS=6 "$PHP_BIN" -d memory_limit=512M \
    "$WP_PATH/.wp-cli/wp-cli.phar" --path="$WP_PATH" server --host=127.0.0.1 --port=8182 \
    >"$SS_DOCS_OUTPUT/server.log" 2>&1 &
DOCS_SERVER_PID=$!
DOCS_READY=false
for _ in {1..40}; do
    if curl -fsS -o /dev/null --max-time 2 "$WP_URL"; then DOCS_READY=true; break; fi
    kill -0 "$DOCS_SERVER_PID" 2>/dev/null || fail 'The Docs server exited during startup; see server.log.'
    sleep 1
done
[[ "$DOCS_READY" == true ]] || fail 'The documentation store did not become ready.'

DOCS_TEST_EXIT=0
IFS=',' read -r -a DOCS_LANGUAGE_LIST <<< "$DOCS_LOCALES"
for language in "${DOCS_LANGUAGE_LIST[@]}"; do
    export SS_DOCS_LOCALE="$language"
    args=(--configuration "$REPO_ROOT/phpunit.docs.xml.dist" --testsuite Docs)
    [[ -z "$DOCS_FILTER" ]] || args+=(--filter "$DOCS_FILTER")
    printf '\nGenerating %s documentation images.\n' "$language"
    "$PHP_BIN" "$REPO_ROOT/vendor/bin/pest" "${args[@]}" 2>&1 | tee "$SS_DOCS_OUTPUT/pest-$language.log" || DOCS_TEST_EXIT=$?
done
exit "$DOCS_TEST_EXIT"
