#!/usr/bin/env bash
#
# Demo mode for manual testing: put the local development store into the same
# state the Browser test suite runs against - the Smart Send API mock (a
# mu-plugin faking the Smart Send API), a Denmark zone with a Smart Send
# agent method, a sample product and both checkout pages - and leave it on
# until switched off again.
#
#   bin/demo-store.sh on                              Seed the store + install the mock
#   bin/demo-store.sh off                             Remove the mock, undo the seeding
#   bin/demo-store.sh scenario [<endpoint>=<case>...] Show or set per-endpoint API scenarios
#   bin/demo-store.sh scenario reset                  Reset every endpoint to success
#
# Wrapped by the composer scripts demo:on / demo:off / demo:scenario.
#
# The mock and the seeding/cleanup logic are shared with the Browser suite -
# the single source of truth lives in tests/Browser/Support/
# (ApiMockMuPlugin.php and the Snippets/*.php eval-file scripts); this
# script only copies/runs those.
#
# What demo:off removes vs keeps (the same contract as the test cleanup,
# Snippets/cleanup-store.php): it deletes only what demo:on created - the
# seeded product (SKU SS-BROWSER-TEST), the two seeded checkout pages, orders
# created by the seeding or placed with the fixture billing email
# (ss-browser-test@smartsend.io), and the zone method instances *if* the
# seeding added them - and restores the plugin settings, COD gateway state
# and checkout-page option. Zones, products, pages and orders a developer
# built on top are left alone.
#
# Targets the dev store from .env's WP_PATH (default ./local-dev/wordpress;
# the WP_DEV_PATH env var still overrides), i.e. the store created by
# bin/setup-local-dev.sh. Local tool only: refuses to run against a
# production environment or a non-local site URL.
#
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ENV_WP_PATH="$(sed -n 's/^WP_PATH=//p' "$REPO_ROOT/.env" 2>/dev/null | tail -1)"
if [[ -n "$ENV_WP_PATH" && "$ENV_WP_PATH" != /* ]]; then
    ENV_WP_PATH="$REPO_ROOT/$ENV_WP_PATH"
fi
INSTALL_PATH="${WP_DEV_PATH:-${ENV_WP_PATH:-$REPO_ROOT/local-dev/wordpress}}"

SUPPORT_DIR="$REPO_ROOT/tests/Browser/Support"
MOCK_SRC="$SUPPORT_DIR/ApiMockMuPlugin.php"
SNIPPETS_DIR="$SUPPORT_DIR/Snippets"
STATE_OPTION="ss_demo_state"

log()  { printf '\033[1;32m==>\033[0m %s\n' "$*"; }
fail() { printf '\033[1;31mError:\033[0m %s\n' "$*" >&2; exit 1; }

usage() {
    cat <<'EOF'
Usage: bin/demo-store.sh <on|off|scenario> [<endpoint>=<case>... | reset]

Demo mode for manual testing: the browser-suite store state (Smart Send API
mock, Denmark zone with a Smart Send agent method, sample product, classic +
block checkout pages), left on until turned off.

  on                 Seed the store and install the API mock; prints URLs and
                     admin credentials. Idempotent.
  off                Remove the mock and the demo fixtures, restore what
                     demo:on changed. Idempotent.
  scenario           Show the active per-endpoint scenarios and the valid
                     endpoint/case names.
  scenario <endpoint>=<case>...
                     Override individual endpoints, e.g.
                     'scenario booking=500 pickup-points=403'. Endpoints not
                     mentioned keep their current case; <case> is a named
                     case, 'success', or any three-digit HTTP status code.
  scenario reset     Reset every endpoint back to success.

Composer wrappers: composer demo:on / demo:off / demo:scenario -- <args>
Target install: WP_DEV_PATH (default ./local-dev/wordpress).
EOF
}

# ------------------------------------------------------------------------------
# Target install checks
# ------------------------------------------------------------------------------
require_install() {
    [[ -f "$INSTALL_PATH/wp-load.php" ]] \
        || fail "No WordPress install found at $INSTALL_PATH (set WP_DEV_PATH or run bin/setup-local-dev.sh first)."
    INSTALL_PATH="$(cd "$INSTALL_PATH" && pwd)"
    WP_CLI_PHAR="$INSTALL_PATH/.wp-cli/wp-cli.phar"
    [[ -f "$WP_CLI_PHAR" ]] \
        || fail "No WP-CLI phar at $WP_CLI_PHAR - this tool targets stores created by bin/setup-local-dev.sh."
    MU_PLUGIN_PATH="$INSTALL_PATH/wp-content/mu-plugins/ss-browser-test-api-mock.php"
}

PHP_BIN="${PHP_BIN:-php}"

wp() {
    "$PHP_BIN" -d memory_limit=512M -d error_reporting=0 -d display_errors=0 \
        "$WP_CLI_PHAR" --path="$INSTALL_PATH" "$@" 2>/dev/null
}

# Local-only guard: never touch a production environment or a remote site.
guard_local() {
    local env_type site_url host
    env_type="$(wp eval 'echo wp_get_environment_type();' || true)"
    site_url="$(wp option get siteurl || true)"
    [[ -n "$site_url" ]] || fail "Could not read the site URL from $INSTALL_PATH - is this a working install?"
    host="$(echo "$site_url" | sed -E 's#https?://([^:/]+).*#\1#')"
    if [[ "$env_type" == "production" ]]; then
        fail "WP_ENVIRONMENT_TYPE is 'production' - demo mode is a local-only tool, refusing to run."
    fi
    # *.test covers Herd-served local stores (see .env / README).
    if [[ "$host" != "localhost" && "$host" != "127.0.0.1" && "$host" != *.localhost && "$host" != *.test ]]; then
        fail "Site URL is $site_url - demo mode only runs against localhost/127.0.0.1/*.test stores."
    fi
}

demo_is_on() {
    wp option get "$STATE_OPTION" >/dev/null 2>&1
}

current_scenarios() {
    # One "endpoint=case" per line for every overridden endpoint; empty
    # output means every endpoint is on its success response.
    wp eval '
$config = get_option("ss_test_api", array());
$scenarios = isset($config["scenarios"]) && is_array($config["scenarios"]) ? $config["scenarios"] : array();
foreach ($scenarios as $endpoint => $case) { echo "{$endpoint}={$case}\n"; }
' 2>/dev/null || true
}

print_current_scenarios() {
    local active
    active="$(current_scenarios)"
    if [[ -z "$active" ]]; then
        echo "Active scenarios: (none - every endpoint returns success)"
    else
        echo "Active scenarios:"
        sed 's/^/  /' <<<"$active"
    fi
}

valid_cases() {
    # Machine-read from the shared mock source's " * Cases <endpoint>: ..."
    # lines; prints "endpoint: named cases..." per endpoint. Any three-digit
    # HTTP status code is additionally valid on every endpoint.
    sed -n 's/^ \* Cases //p' "$MOCK_SRC"
}

cases_for_endpoint() {
    valid_cases | sed -n "s/^$1: //p"
}

install_mock() {
    mkdir -p "$(dirname "$MU_PLUGIN_PATH")"
    cp "$MOCK_SRC" "$MU_PLUGIN_PATH"
}

page_url() {
    wp post list --post_type=page --post__in="$1" --field=url
}

state_value() {
    wp option get "$STATE_OPTION" --format=json \
        | "$PHP_BIN" -r 'echo json_decode(stream_get_contents(STDIN), true)[$argv[1]] ?? "";' "$1"
}

print_info() {
    local site_url
    site_url="$(wp option get siteurl)"

    cat <<EOF

------------------------------------------------------------------------------
Demo mode is ON - the store now runs against a FAKE Smart Send API.

  Store:            $site_url
  Classic checkout: $(page_url "$(state_value checkout_page_id)")
  Block checkout:   $(page_url "$(state_value block_checkout_page_id)")
  Admin:            $site_url/wp-admin (${WP_ADMIN_USER:-admin} / ${WP_ADMIN_PASS:-password})

  $(print_current_scenarios | sed '2,$s/^/  /')
                    switch:  composer demo:scenario -- <endpoint>=<case>...
                    reset:   composer demo:scenario -- reset
$(valid_cases | sed 's/^/                    /')
                    (any three-digit HTTP status code is also valid)

Add the sample product "SS Browser Test Product" to the cart, then open a
checkout page to see pick-up point selection against the mocked API.

Remember: composer demo:off removes the fake API and the demo fixtures.
------------------------------------------------------------------------------
EOF
}

cmd_on() {
    if demo_is_on; then
        log "Demo mode is already on - refreshing the API mock mu-plugin only."
        install_mock
        print_info
        return
    fi
    log "Installing the Smart Send API mock mu-plugin"
    install_mock
    log "Seeding the store (shared with the Browser suite: Snippets/seed-store.php)"
    wp eval-file "$SNIPPETS_DIR/seed-store.php" '{}' "$STATE_OPTION" >/dev/null
    log "Creating the block checkout page"
    wp eval-file "$SNIPPETS_DIR/create-block-checkout-page.php" "$STATE_OPTION" >/dev/null
    print_info
}

cmd_off() {
    if demo_is_on; then
        log "Undoing the demo seeding (shared with the Browser suite: Snippets/cleanup-store.php)"
        wp eval-file "$SNIPPETS_DIR/cleanup-store.php" "$STATE_OPTION" >/dev/null
    else
        log "Demo mode is not on (no seeded state found)."
    fi
    if [[ -f "$MU_PLUGIN_PATH" ]]; then
        log "Removing the API mock mu-plugin"
        rm -f "$MU_PLUGIN_PATH"
    fi
    log "Demo mode is OFF - the store talks to the real Smart Send API again."
}

set_scenarios() {
    # Args are already-validated "endpoint=case" pairs (safe to embed: the
    # endpoint comes from the mock's Cases lines, the case from its list or
    # a three-digit code); "success" removes the override. Applied on top of
    # the current map so endpoints not mentioned keep their case.
    local pair php_pairs=""
    for pair in "$@"; do
        php_pairs+="'${pair%%=*}' => '${pair#*=}', "
    done
    wp eval "
\$config = get_option('ss_test_api', array());
\$scenarios = isset(\$config['scenarios']) && is_array(\$config['scenarios']) ? \$config['scenarios'] : array();
foreach (array($php_pairs) as \$endpoint => \$case) {
    if (\$case === 'success') { unset(\$scenarios[\$endpoint]); } else { \$scenarios[\$endpoint] = \$case; }
}
\$config['scenarios'] = \$scenarios;
update_option('ss_test_api', \$config);
" >/dev/null
}

cmd_scenario() {
    if [[ $# -eq 0 ]]; then
        print_current_scenarios
        echo "Valid cases per endpoint (any three-digit HTTP status code is also valid):"
        valid_cases | sed 's/^/  /'
        return
    fi

    if ! demo_is_on; then
        fail "Demo mode is not on - run 'composer demo:on' first."
    fi

    if [[ "$1" == "reset" ]]; then
        wp eval '
$config = get_option("ss_test_api", array());
$config["scenarios"] = array();
update_option("ss_test_api", $config);
' >/dev/null
        print_current_scenarios
        return
    fi

    local pair endpoint case cases
    for pair in "$@"; do
        [[ "$pair" == *=* ]] || fail "Expected <endpoint>=<case>, got '$pair'. See 'composer demo:scenario' for the valid list."
        endpoint="${pair%%=*}"
        case="${pair#*=}"
        cases="$(cases_for_endpoint "$endpoint")"
        [[ -n "$cases" ]] || fail "Unknown endpoint '$endpoint'. Valid endpoints: $(valid_cases | sed 's/:.*//' | paste -sd' ' -)"
        if ! printf ' %s ' "$cases" | grep -q " $case " && ! [[ "$case" =~ ^[0-9]{3}$ ]]; then
            fail "Unknown case '$case' for endpoint '$endpoint'. Valid: $cases (or any three-digit HTTP status code)"
        fi
    done

    set_scenarios "$@"
    print_current_scenarios
}

COMMAND="${1:-}"
case "$COMMAND" in
    -h|--help|help)
        usage
        exit 0
        ;;
    on|off|scenario)
        require_install
        guard_local
        shift
        "cmd_$COMMAND" "$@"
        ;;
    "")
        usage
        exit 1
        ;;
    *)
        fail "Unknown subcommand '$COMMAND'. Use: on | off | scenario [<name>]"
        ;;
esac
