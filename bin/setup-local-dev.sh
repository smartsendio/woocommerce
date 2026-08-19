#!/usr/bin/env bash
#
# Set up a complete local WordPress + WooCommerce development store with the
# Smart Send plugin from this repository installed and activated.
#
# The script is built on WP-CLI (and its WooCommerce `wp wc` commands) and is
# idempotent: re-running it against an existing installation re-applies the
# configuration without reinstalling. Use --force to wipe and start over.
#
# By default the store uses SQLite via the official WordPress
# "SQLite Database Integration" plugin (https://github.com/WordPress/sqlite-database-integration)
# so no database server is required. Pass --db-engine=mysql together with the
# --db-* options to use a MySQL/MariaDB server instead.
#
# Requirements: bash, php (7.4+), curl, unzip.
#
set -euo pipefail

# ------------------------------------------------------------------------------
# Defaults
# ------------------------------------------------------------------------------
INSTALL_PATH="${WP_DEV_PATH:-./local-dev/wordpress}"
SITE_URL="http://localhost:8181"
SITE_TITLE="Smart Send"
ENV_NAME=""                   # "" -> .env, "testing" -> .env.testing
PATH_FROM_FLAG="false"
URL_FROM_FLAG="false"

# Defaults offered when creating a fresh .env interactively.
ENV_DEFAULT_PATH="../../playground/smart-send-woocommerce"
ENV_DEFAULT_URL="http://smart-send-woocommerce.test"
# Defaults written to a fresh .env.testing (matches CI).
ENV_TESTING_DEFAULT_PATH="./local-dev/wordpress"
ENV_TESTING_DEFAULT_URL="http://127.0.0.1:8181"

WP_VERSION="latest"
WC_VERSION="latest"

DB_ENGINE="sqlite"            # sqlite | mysql
DB_NAME="smartsend_woo_dev"
DB_USER="root"
DB_PASS=""
DB_HOST="127.0.0.1"
DB_PREFIX="wp_"

ADMIN_USER="admin"
ADMIN_PASS="password"
ADMIN_EMAIL="dev@smartsend.io"

FORCE="false"
SKIP_SEED="false"

# Checkout page type (classic|block) and whether product prices are entered
# including or excluding tax (include|exclude). Resolution order for each:
# --flag > exported environment variable (WP_CHECKOUT / WP_PRICES_TAX) >
# env file > default (block / include).
CHECKOUT_TYPE=""
CHECKOUT_FROM_FLAG="false"
PRICES_TAX=""
PRICES_FROM_FLAG="false"

usage() {
    cat <<'EOF'
Usage: bin/setup-local-dev.sh [options]

Set up a local WordPress + WooCommerce development store with the Smart Send
plugin from this repository symlinked in and activated, the Storefront theme
active, configured with sensible shop settings (Danish store origin, DKK,
metric units).

Options:
  --path <dir>          Install directory (default: WP_PATH from the env file)
  --url <url>           Site URL (default: WP_URL from the env file)
  --title <title>       Site title (default: "Smart Send")
  --env <name>          Read defaults from .env.<name> instead of .env
                        (e.g. --env testing -> .env.testing, the disposable
                        store rebuilt by every composer test:* run)

  --checkout <type>     Checkout page type: "classic" (the [woocommerce_checkout]
                        shortcode) or "block" (the WooCommerce Checkout block).
                        Default: the WP_CHECKOUT environment variable or env
                        file entry, else block
  --prices-tax <mode>   Whether product prices are entered "include"-ing or
                        "exclude"-ing tax (WooCommerce "Prices entered with
                        tax"). Default: the WP_PRICES_TAX environment variable
                        or env file entry, else include

  --wp-version <v>      WordPress version to install (default: latest)
  --wc-version <v>      WooCommerce version to install (default: latest)

  --db-engine <engine>  Database engine: sqlite or mysql (default: sqlite)
  --db-name <name>      MySQL database name (default: smartsend_woo_dev)
  --db-user <user>      MySQL user (default: root)
  --db-pass <pass>      MySQL password (default: empty)
  --db-host <host>      MySQL host, host:port supported (default: 127.0.0.1)
  --db-prefix <prefix>  Table prefix (default: wp_)

  --admin-user <user>   Admin username (default: admin)
  --admin-pass <pass>   Admin password (default: password)
  --admin-email <mail>  Admin email (default: dev@smartsend.io)

  --skip-seed           Skip importing the sample catalog and shipping zone
  --force               Delete an existing installation at --path first
  -h, --help            Show this help

Examples:
  bin/setup-local-dev.sh
  bin/setup-local-dev.sh --path ~/Sites/smartsend-dev --wp-version 6.8 --wc-version 9.8.5
  bin/setup-local-dev.sh --db-engine mysql --db-name wp_dev --db-user root --db-pass secret

After setup, start the store with:
  bin/setup-local-dev.sh ... && cd <path> && ../../bin/wp server   (or: wp server --host=localhost --port=8181)
EOF
}

# ------------------------------------------------------------------------------
# Argument parsing
# ------------------------------------------------------------------------------
while [[ $# -gt 0 ]]; do
    case "$1" in
        --path)         INSTALL_PATH="$2"; PATH_FROM_FLAG="true"; shift 2 ;;
        --url)          SITE_URL="$2"; URL_FROM_FLAG="true"; shift 2 ;;
        --env)          ENV_NAME="$2"; shift 2 ;;
        --title)        SITE_TITLE="$2"; shift 2 ;;
        --wp-version)   WP_VERSION="$2"; shift 2 ;;
        --wc-version)   WC_VERSION="$2"; shift 2 ;;
        --db-engine)    DB_ENGINE="$2"; shift 2 ;;
        --db-name)      DB_NAME="$2"; shift 2 ;;
        --db-user)      DB_USER="$2"; shift 2 ;;
        --db-pass)      DB_PASS="$2"; shift 2 ;;
        --db-host)      DB_HOST="$2"; shift 2 ;;
        --db-prefix)    DB_PREFIX="$2"; shift 2 ;;
        --admin-user)   ADMIN_USER="$2"; shift 2 ;;
        --admin-pass)   ADMIN_PASS="$2"; shift 2 ;;
        --admin-email)  ADMIN_EMAIL="$2"; shift 2 ;;
        --checkout)     CHECKOUT_TYPE="$2"; CHECKOUT_FROM_FLAG="true"; shift 2 ;;
        --prices-tax)   PRICES_TAX="$2"; PRICES_FROM_FLAG="true"; shift 2 ;;
        --skip-seed)    SKIP_SEED="true"; shift ;;
        --force)        FORCE="true"; shift ;;
        -h|--help)      usage; exit 0 ;;
        *) echo "Unknown option: $1" >&2; usage; exit 1 ;;
    esac
done

if [[ "$DB_ENGINE" != "sqlite" && "$DB_ENGINE" != "mysql" ]]; then
    echo "Error: --db-engine must be 'sqlite' or 'mysql' (got '$DB_ENGINE')" >&2
    exit 1
fi

# Resolve paths before changing directories.
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PLUGIN_SRC="$REPO_ROOT/smart-send-logistics"

log()  { printf '\033[1;32m==>\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33mWarning:\033[0m %s\n' "$*" >&2; }

# ------------------------------------------------------------------------------
# Env file (.env / .env.<name>): the persisted store location, shared with the
# test bootstrap (tests/bootstrap.php, tests/Pest.php) and bin/demo-store.sh.
# Explicit --path/--url flags always win; WP_PATH is resolved relative to the
# repository root.
# ------------------------------------------------------------------------------
ENV_FILE="$REPO_ROOT/.env${ENV_NAME:+.$ENV_NAME}"

env_get() { sed -n "s/^$1=//p" "$ENV_FILE" 2>/dev/null | tail -1; }

if [[ ! -f "$ENV_FILE" && ( "$PATH_FROM_FLAG" != "true" || "$URL_FROM_FLAG" != "true" ) ]]; then
    if [[ "$ENV_NAME" == "testing" ]]; then
        log "Creating $ENV_FILE with defaults"
        cat > "$ENV_FILE" <<EOF
# Disposable testing store - rebuilt from scratch by every composer test:* run
# (bin/run-tests.sh). WP_PATH is resolved relative to the repository root.
# Point WP_URL at a localhost URL to have the test runner manage a PHP
# built-in server, or at a parked .test domain to let Laravel Herd serve it.
WP_PATH=$ENV_TESTING_DEFAULT_PATH
WP_URL=$ENV_TESTING_DEFAULT_URL
# Optional: checkout page type (classic|block) and whether product prices
# are entered including or excluding tax (include|exclude).
# Defaults: block / include.
#WP_CHECKOUT=block
#WP_PRICES_TAX=include
EOF
    elif [[ -z "$ENV_NAME" && -t 0 ]]; then
        log "No .env found - where should the local dev store live?"
        read -r -p "Install path [$ENV_DEFAULT_PATH]: " ANSWER_PATH
        read -r -p "Site URL [$ENV_DEFAULT_URL]: " ANSWER_URL
        cat > "$ENV_FILE" <<EOF
# Local dev store location, used by bin/setup-local-dev.sh and
# bin/demo-store.sh. WP_PATH is resolved relative to the repository root.
# The test suites use .env.testing instead (see bin/run-tests.sh).
WP_PATH=${ANSWER_PATH:-$ENV_DEFAULT_PATH}
WP_URL=${ANSWER_URL:-$ENV_DEFAULT_URL}
# Optional: checkout page type (classic|block) and whether product prices
# are entered including or excluding tax (include|exclude).
# Defaults: block / include.
#WP_CHECKOUT=block
#WP_PRICES_TAX=include
EOF
        log "Wrote $ENV_FILE"
    fi
fi

if [[ -f "$ENV_FILE" ]]; then
    if [[ "$PATH_FROM_FLAG" != "true" && -n "$(env_get WP_PATH)" ]]; then
        INSTALL_PATH="$(env_get WP_PATH)"
        [[ "$INSTALL_PATH" != /* ]] && INSTALL_PATH="$REPO_ROOT/$INSTALL_PATH"
    fi
    if [[ "$URL_FROM_FLAG" != "true" && -n "$(env_get WP_URL)" ]]; then
        SITE_URL="$(env_get WP_URL)"
    fi
fi

# Checkout type and price-entry tax mode: --flag > exported environment
# variable > env file entry > default. The exported variable outranks the env
# file so one-off runs work without editing the file
# (e.g. WP_CHECKOUT=block bin/setup-local-dev.sh).
if [[ "$CHECKOUT_FROM_FLAG" != "true" ]]; then
    CHECKOUT_TYPE="${WP_CHECKOUT:-$(env_get WP_CHECKOUT)}"
fi
CHECKOUT_TYPE="${CHECKOUT_TYPE:-block}"

if [[ "$PRICES_FROM_FLAG" != "true" ]]; then
    PRICES_TAX="${WP_PRICES_TAX:-$(env_get WP_PRICES_TAX)}"
fi
PRICES_TAX="${PRICES_TAX:-include}"

if [[ "$CHECKOUT_TYPE" != "classic" && "$CHECKOUT_TYPE" != "block" ]]; then
    echo "Error: --checkout / WP_CHECKOUT must be 'classic' or 'block' (got '$CHECKOUT_TYPE')" >&2
    exit 1
fi

if [[ "$PRICES_TAX" != "include" && "$PRICES_TAX" != "exclude" ]]; then
    echo "Error: --prices-tax / WP_PRICES_TAX must be 'include' or 'exclude' (got '$PRICES_TAX')" >&2
    exit 1
fi

mkdir -p "$INSTALL_PATH"
INSTALL_PATH="$(cd "$INSTALL_PATH" && pwd)"

# ------------------------------------------------------------------------------
# WP-CLI bootstrap (pinned phar, independent of any globally installed wp)
# ------------------------------------------------------------------------------
PHP_BIN="${PHP_BIN:-php}"
if ! command -v "$PHP_BIN" >/dev/null 2>&1; then
    echo "Error: php not found on PATH. Install PHP 7.4+ first." >&2
    exit 1
fi

WP_CLI_DIR="$INSTALL_PATH/.wp-cli"
WP_CLI_PHAR="$WP_CLI_DIR/wp-cli.phar"
if [[ ! -f "$WP_CLI_PHAR" ]]; then
    log "Downloading WP-CLI"
    mkdir -p "$WP_CLI_DIR"
    curl -fsSL -o "$WP_CLI_PHAR" \
        "https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar"
fi

# Silence PHP deprecation noise from WP-CLI on newer PHP versions, and make
# sure subprocesses spawned by WP-CLI itself use the same settings.
# WP-CLI re-enables E_ALL internally, so on recent PHP versions it prints
# deprecation notices from WordPress/plugin code. Filter them from both output
# streams; this also keeps them out of captured --porcelain values.
export WP_CLI_PHP="$PHP_BIN"
export WP_CLI_PHP_ARGS="-d memory_limit=512M"
wp() {
    "$PHP_BIN" -d memory_limit=512M "$WP_CLI_PHAR" --path="$INSTALL_PATH" "$@" \
        1> >(grep -vE '^(Deprecated|Notice): |^$' || true) \
        2> >(grep -vE '^(Deprecated|Notice): ' >&2 || true)
}

# ------------------------------------------------------------------------------
# Fresh start?
# ------------------------------------------------------------------------------
if [[ "$FORCE" == "true" && -f "$INSTALL_PATH/wp-load.php" ]]; then
    log "Removing existing installation at $INSTALL_PATH (--force)"
    find "$INSTALL_PATH" -mindepth 1 -maxdepth 1 ! -name '.wp-cli' -exec rm -rf {} +
fi

# ------------------------------------------------------------------------------
# 1. Download WordPress core
# ------------------------------------------------------------------------------
if [[ -f "$INSTALL_PATH/wp-load.php" ]]; then
    log "WordPress core already present, skipping download"
else
    log "Downloading WordPress ($WP_VERSION)"
    if [[ "$WP_VERSION" == "latest" ]]; then
        wp core download
    else
        wp core download --version="$WP_VERSION"
    fi
fi

# ------------------------------------------------------------------------------
# 2. SQLite drop-in (before wp-config, so the config check uses SQLite too)
# ------------------------------------------------------------------------------
SQLITE_PLUGIN_DIR="$INSTALL_PATH/wp-content/plugins/sqlite-database-integration"
if [[ "$DB_ENGINE" == "sqlite" ]]; then
    if [[ ! -d "$SQLITE_PLUGIN_DIR" ]]; then
        log "Installing SQLite Database Integration plugin"
        SQLITE_ZIP="$WP_CLI_DIR/sqlite-database-integration.zip"
        curl -fsSL -o "$SQLITE_ZIP" \
            "https://downloads.wordpress.org/plugin/sqlite-database-integration.latest-stable.zip"
        unzip -qo "$SQLITE_ZIP" -d "$INSTALL_PATH/wp-content/plugins/"
    fi
    if [[ ! -f "$INSTALL_PATH/wp-content/db.php" ]]; then
        log "Configuring SQLite db.php drop-in"
        # The plugin ships a db.copy template with placeholders for its location.
        sed -e "s#{SQLITE_IMPLEMENTATION_FOLDER_PATH}#$SQLITE_PLUGIN_DIR#g" \
            -e "s#{SQLITE_MAIN_FILE}#sqlite-database-integration/load.php#g" \
            "$SQLITE_PLUGIN_DIR/db.copy" > "$INSTALL_PATH/wp-content/db.php"
    fi
fi

# ------------------------------------------------------------------------------
# 3. wp-config.php
# ------------------------------------------------------------------------------
if [[ -f "$INSTALL_PATH/wp-config.php" ]]; then
    log "wp-config.php already present, skipping"
else
    log "Creating wp-config.php"
    # With the SQLite drop-in the MySQL credentials are ignored, but WordPress
    # still requires them to be defined.
    wp config create \
        --dbname="$DB_NAME" \
        --dbuser="$DB_USER" \
        --dbpass="$DB_PASS" \
        --dbhost="$DB_HOST" \
        --dbprefix="$DB_PREFIX" \
        --skip-check \
        --extra-php <<'PHP'
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );
define( 'WP_ENVIRONMENT_TYPE', 'local' );
define( 'FS_METHOD', 'direct' );
PHP
fi

# ------------------------------------------------------------------------------
# 4. Create database (MySQL only) and install WordPress
# ------------------------------------------------------------------------------
if [[ "$DB_ENGINE" == "mysql" ]]; then
    log "Ensuring MySQL database '$DB_NAME' exists"
    wp db create 2>/dev/null || log "Database already exists (or create skipped)"
fi

if wp core is-installed 2>/dev/null; then
    log "WordPress already installed, skipping install"
else
    log "Installing WordPress at $SITE_URL"
    wp core install \
        --url="$SITE_URL" \
        --title="$SITE_TITLE" \
        --admin_user="$ADMIN_USER" \
        --admin_password="$ADMIN_PASS" \
        --admin_email="$ADMIN_EMAIL" \
        --skip-email
fi

# ------------------------------------------------------------------------------
# 5. Install WooCommerce
# ------------------------------------------------------------------------------
if wp plugin is-installed woocommerce 2>/dev/null; then
    log "WooCommerce already installed, skipping"
else
    log "Installing WooCommerce ($WC_VERSION)"
    if [[ "$WC_VERSION" == "latest" ]]; then
        wp plugin install woocommerce --activate
    else
        wp plugin install woocommerce --version="$WC_VERSION" --activate
    fi
fi
wp plugin activate woocommerce >/dev/null 2>&1 || true

# ------------------------------------------------------------------------------
# 6. Install and activate the Storefront theme (WooCommerce's reference theme,
#    used for development, tests and documentation screenshots)
# ------------------------------------------------------------------------------
if wp theme is-installed storefront 2>/dev/null; then
    log "Storefront theme already installed, skipping install"
else
    log "Installing Storefront theme"
    wp theme install storefront
fi
wp theme activate storefront

# ------------------------------------------------------------------------------
# 7. Symlink and activate the Smart Send plugin from this repository
# ------------------------------------------------------------------------------
PLUGIN_DEST="$INSTALL_PATH/wp-content/plugins/smart-send-logistics"
if [[ ! -e "$PLUGIN_DEST" ]]; then
    log "Symlinking smart-send-logistics from this repository"
    ln -s "$PLUGIN_SRC" "$PLUGIN_DEST"
fi
log "Activating Smart Send plugin"
wp plugin activate smart-send-logistics

# ------------------------------------------------------------------------------
# 8. Configure the shop (Danish store origin, DKK, metric units)
# ------------------------------------------------------------------------------
log "Configuring store settings"
wp option update woocommerce_store_address "Islands Brygge 39" >/dev/null
wp option update woocommerce_store_city "Copenhagen" >/dev/null
wp option update woocommerce_store_postcode "2300" >/dev/null
wp option update woocommerce_default_country "DK" >/dev/null
wp option update woocommerce_currency "DKK" >/dev/null
wp option update woocommerce_weight_unit "kg" >/dev/null
wp option update woocommerce_dimension_unit "cm" >/dev/null
wp option update woocommerce_price_num_decimals "2" >/dev/null
wp option update woocommerce_calc_taxes "yes" >/dev/null
wp option update woocommerce_prices_include_tax "$( [[ "$PRICES_TAX" == "include" ]] && echo "yes" || echo "no" )" >/dev/null
wp option update woocommerce_enable_checkout_login_reminder "yes" >/dev/null
wp option update woocommerce_enable_guest_checkout "yes" >/dev/null
wp option update woocommerce_allowed_countries "specific" >/dev/null
wp option update woocommerce_specific_allowed_countries '["DK","SE","NO","FI","DE"]' --format=json >/dev/null
wp option update woocommerce_ship_to_countries "specific" >/dev/null
wp option update woocommerce_specific_ship_to_countries '["DK","SE","NO","FI","DE"]' --format=json >/dev/null

# Launch the store: WooCommerce enables "Coming soon" mode by default.
wp option update woocommerce_coming_soon "no" >/dev/null

# Skip the WooCommerce onboarding wizard.
wp option update woocommerce_onboarding_profile '{"skipped":true}' --format=json >/dev/null
wp option update woocommerce_task_list_hidden "yes" >/dev/null 2>&1 || true

# The checkout page: WooCommerce created it on install (block markup on
# modern WooCommerce); (re)write its content to the configured type so the
# store checks out through the surface under test.
log "Configuring the $CHECKOUT_TYPE checkout page"
wp eval-file "$REPO_ROOT/bin/configure-checkout-page.php" "$CHECKOUT_TYPE" >/dev/null

# General site settings.
wp option update blogname "$SITE_TITLE" >/dev/null
wp option update timezone_string "Europe/Copenhagen" >/dev/null
wp option update blogdescription "Demo store" >/dev/null
wp rewrite structure '/%postname%/' --hard >/dev/null 2>&1 || wp rewrite structure '/%postname%/' >/dev/null

# ------------------------------------------------------------------------------
# 9. Seed sample data: the vendored WooCommerce sample catalog (sample-data/,
#    refresh with bin/update-sample-data.sh) and a shipping zone (via WC CLI)
# ------------------------------------------------------------------------------
if [[ "$SKIP_SEED" == "true" ]]; then
    log "Skipping sample data (--skip-seed)"
else
    if [[ -z "$(wp post list --post_type=product --field=ID --posts_per_page=1 2>/dev/null)" ]]; then
        log "Importing sample product images into the media library"
        wp media import "$REPO_ROOT"/sample-data/images/*.jpg --user="$ADMIN_USER" --porcelain >/dev/null
        log "Importing sample products (WooCommerce CSV importer)"
        wp eval-file "$REPO_ROOT/bin/import-sample-products.php" "$REPO_ROOT/sample-data/products.csv" --user="$ADMIN_USER"
    else
        log "Products already present, skipping sample products"
    fi

    # A standard 25% Danish VAT rate, so the tax settings (incl. the
    # prices-entered-with-tax mode) actually take effect at checkout. Dev
    # stores only: the disposable testing store stays tax-rate-free - the
    # characterization suites (payload golden tests, order totals) pin
    # behaviour against untaxed fixtures and create their own tax setup when
    # a test needs one.
    if [[ "$ENV_NAME" != "testing" && -z "$(wp wc tax list --user="$ADMIN_USER" --field=id 2>/dev/null | head -1)" ]]; then
        log "Creating a standard 25% Danish VAT rate"
        wp wc tax create --user="$ADMIN_USER" --country="DK" --rate="25" --name="VAT" --shipping=true >/dev/null
    fi

    if [[ -z "$(wp wc shipping_zone list --user="$ADMIN_USER" --field=id 2>/dev/null | sed '/^0$/d')" ]]; then
        log "Creating Denmark shipping zone with a flat rate method"
        ZONE_ID="$(wp wc shipping_zone create --user="$ADMIN_USER" --name="Denmark" --porcelain)"
        ZONE_ID="${ZONE_ID//[^0-9]/}"
        wp wc shipping_zone_location update "$ZONE_ID" --user="$ADMIN_USER" \
            --location='[{"code":"DK","type":"country"}]' >/dev/null 2>&1 || \
            wp eval '
                $zone = new WC_Shipping_Zone( '"$ZONE_ID"' );
                $zone->set_zone_locations( array( (object) array( "code" => "DK", "type" => "country" ) ) );
                $zone->save();
            '
        wp wc shipping_zone_method create "$ZONE_ID" --user="$ADMIN_USER" \
            --method_id=flat_rate --settings='{"title":"Flat rate","cost":"39"}' >/dev/null
    else
        log "Shipping zone already present, skipping"
    fi
fi

# ------------------------------------------------------------------------------
# 10. Front page and menu: Storefront's Homepage template renders a hero plus
#     product category / featured / recent product sections automatically once
#     products exist, which makes the store look like a real webshop.
# ------------------------------------------------------------------------------
# Remove WordPress's default placeholder content ("Hello world!" post with its
# comment, "Sample Page") - it makes sidebars/widgets look like a fresh install.
for slug_type in "hello-world:post" "sample-page:page"; do
    slug="${slug_type%%:*}"; ptype="${slug_type##*:}"
    DEFAULT_ID="$(wp post list --post_type="$ptype" --name="$slug" --field=ID --posts_per_page=1 2>/dev/null)"
    if [[ -n "$DEFAULT_ID" ]]; then
        log "Deleting default WordPress content: $slug"
        wp post delete "$DEFAULT_ID" --force >/dev/null
    fi
done

HOME_ID="$(wp post list --post_type=page --name=home --field=ID --posts_per_page=1 2>/dev/null)"
if [[ -z "$HOME_ID" ]]; then
    log "Creating front page (Storefront Homepage template)"
    HOME_ID="$(wp post create --post_type=page --post_status=publish --porcelain \
        --post_title="Welcome to our store" \
        --post_name=home \
        --post_content="<p>Quality goods, delivered with Smart Send. Free shipping on orders over 500 kr.</p>")"
    wp post meta update "$HOME_ID" _wp_page_template template-homepage.php >/dev/null
else
    log "Front page already present, skipping"
fi
wp option update show_on_front "page" >/dev/null
wp option update page_on_front "$HOME_ID" >/dev/null

if ! wp menu list --fields=slug --format=csv 2>/dev/null | grep -q "^primary-menu$"; then
    log "Creating primary menu"
    wp menu create "Primary Menu" >/dev/null
    wp menu item add-post primary-menu "$HOME_ID" --title="Home" >/dev/null
    wp menu item add-post primary-menu "$(wp option get woocommerce_shop_page_id)" --title="Shop" >/dev/null
    wp menu item add-post primary-menu "$(wp option get woocommerce_myaccount_page_id)" --title="My account" >/dev/null
    wp menu location assign primary-menu primary >/dev/null
else
    log "Primary menu already present, skipping"
fi

# ------------------------------------------------------------------------------
# 11. Branding from sample-data/branding/: smart-send-logo.png (Storefront
#     header logo; the SVG source sits next to it for reference) and,
#     optionally, site-icon.png (favicon, square PNG >= 512x512).
# ------------------------------------------------------------------------------
BRANDING_DIR="$REPO_ROOT/sample-data/branding"
if [[ -f "$BRANDING_DIR/smart-send-logo.png" && -z "$(wp theme mod get custom_logo --format=json 2>/dev/null | grep -o '[0-9]\+')" ]]; then
    log "Setting site logo from sample-data/branding/smart-send-logo.png"
    LOGO_ID="$(wp media import "$BRANDING_DIR/smart-send-logo.png" --user="$ADMIN_USER" --porcelain)"
    wp theme mod set custom_logo "$LOGO_ID" >/dev/null
fi
if [[ -f "$BRANDING_DIR/site-icon.png" && "$(wp option get site_icon 2>/dev/null || echo 0)" == "0" ]]; then
    log "Setting site icon from sample-data/branding/site-icon.png"
    ICON_ID="$(wp media import "$BRANDING_DIR/site-icon.png" --user="$ADMIN_USER" --porcelain)"
    wp option update site_icon "$ICON_ID" >/dev/null
fi

# ------------------------------------------------------------------------------
# 12. Suppress default onboarding/marketing admin notices (never anything that
#     indicates an error) so admin screenshots are clean.
# ------------------------------------------------------------------------------
log "Suppressing onboarding and marketing admin notices"
wp option update fresh_site "0" >/dev/null
wp option update storefront_nux_dismissed "1" >/dev/null
wp option update woocommerce_show_marketplace_suggestions "no" >/dev/null
wp option update woocommerce_extended_task_list_hidden "yes" >/dev/null 2>&1 || true

wp cache flush >/dev/null 2>&1 || true

# ------------------------------------------------------------------------------
# Done
# ------------------------------------------------------------------------------
PORT="$(echo "$SITE_URL" | sed -nE 's#.*:([0-9]+).*#\1#p')"
PORT="${PORT:-80}"
HOST="$(echo "$SITE_URL" | sed -E 's#https?://([^:/]+).*#\1#')"

if [[ "$HOST" == "localhost" || "$HOST" == "127.0.0.1" ]]; then
    SERVE_HINT="Start the store with the PHP built-in server:

  PHP_CLI_SERVER_WORKERS=6 $PHP_BIN -d memory_limit=512M \"$WP_CLI_PHAR\" --path=\"$INSTALL_PATH\" server --host=$HOST --port=$PORT"
else
    SERVE_HINT="Served by your local web server (e.g. Laravel Herd) at $SITE_URL
(park or link the install directory there if you have not already)."
fi

cat <<EOF

------------------------------------------------------------------------------
Local development store is ready!

  Path:        $INSTALL_PATH
  URL:         $SITE_URL
  Admin:       $SITE_URL/wp-admin ($ADMIN_USER / $ADMIN_PASS)
  Database:    $DB_ENGINE$( [[ "$DB_ENGINE" == "mysql" ]] && echo " ($DB_NAME @ $DB_HOST)" )
  WordPress:   $(wp core version 2>/dev/null)
  WooCommerce: $(wp plugin get woocommerce --field=version 2>/dev/null)
  Smart Send:  symlinked from $PLUGIN_SRC
  Checkout:    $CHECKOUT_TYPE (--checkout / WP_CHECKOUT)
  Prices:      entered $( [[ "$PRICES_TAX" == "include" ]] && echo "including" || echo "excluding" ) tax (--prices-tax / WP_PRICES_TAX)

$SERVE_HINT

Configure your Smart Send API token under:
  WooCommerce -> Settings -> Shipping -> Smart Send
------------------------------------------------------------------------------
EOF
