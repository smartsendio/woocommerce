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
SITE_TITLE="Smart Send Dev Store"

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

usage() {
    cat <<'EOF'
Usage: bin/setup-local-dev.sh [options]

Set up a local WordPress + WooCommerce development store with the Smart Send
plugin from this repository symlinked in and activated, configured with
sensible shop settings (Danish store origin, DKK, metric units).

Options:
  --path <dir>          Install directory (default: ./local-dev/wordpress)
  --url <url>           Site URL (default: http://localhost:8181)
  --title <title>       Site title (default: "Smart Send Dev Store")

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

  --skip-seed           Skip creating sample products and shipping zone
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
        --path)         INSTALL_PATH="$2"; shift 2 ;;
        --url)          SITE_URL="$2"; shift 2 ;;
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
mkdir -p "$INSTALL_PATH"
INSTALL_PATH="$(cd "$INSTALL_PATH" && pwd)"

log()  { printf '\033[1;32m==>\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33mWarning:\033[0m %s\n' "$*" >&2; }

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
# 6. Symlink and activate the Smart Send plugin from this repository
# ------------------------------------------------------------------------------
PLUGIN_DEST="$INSTALL_PATH/wp-content/plugins/smart-send-logistics"
if [[ ! -e "$PLUGIN_DEST" ]]; then
    log "Symlinking smart-send-logistics from this repository"
    ln -s "$PLUGIN_SRC" "$PLUGIN_DEST"
fi
log "Activating Smart Send plugin"
wp plugin activate smart-send-logistics

# ------------------------------------------------------------------------------
# 7. Configure the shop (Danish store origin, DKK, metric units)
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
wp option update woocommerce_enable_checkout_login_reminder "yes" >/dev/null
wp option update woocommerce_enable_guest_checkout "yes" >/dev/null
wp option update woocommerce_allowed_countries "specific" >/dev/null
wp option update woocommerce_specific_allowed_countries '["DK","SE","NO","FI","DE"]' --format=json >/dev/null
wp option update woocommerce_ship_to_countries "specific" >/dev/null
wp option update woocommerce_specific_ship_to_countries '["DK","SE","NO","FI","DE"]' --format=json >/dev/null

# Skip the WooCommerce onboarding wizard.
wp option update woocommerce_onboarding_profile '{"skipped":true}' --format=json >/dev/null
wp option update woocommerce_task_list_hidden "yes" >/dev/null 2>&1 || true

# General site settings.
wp option update timezone_string "Europe/Copenhagen" >/dev/null
wp option update blogdescription "Local Smart Send test store" >/dev/null
wp rewrite structure '/%postname%/' --hard >/dev/null 2>&1 || wp rewrite structure '/%postname%/' >/dev/null

# ------------------------------------------------------------------------------
# 8. Seed sample data: products and a shipping zone (via WC CLI)
# ------------------------------------------------------------------------------
if [[ "$SKIP_SEED" == "true" ]]; then
    log "Skipping sample data (--skip-seed)"
else
    if [[ -z "$(wp post list --post_type=product --field=ID --posts_per_page=1 2>/dev/null)" ]]; then
        log "Creating sample products"
        wp wc product create --user="$ADMIN_USER" \
            --name="Sample Parcel Product" \
            --type=simple --regular_price=149.00 \
            --weight=1.5 \
            --dimensions='{"length":"30","width":"20","height":"10"}' \
            --manage_stock=true --stock_quantity=100 >/dev/null
        wp wc product create --user="$ADMIN_USER" \
            --name="Sample Letter Product" \
            --type=simple --regular_price=49.00 \
            --weight=0.2 \
            --dimensions='{"length":"20","width":"15","height":"2"}' \
            --manage_stock=true --stock_quantity=100 >/dev/null
    else
        log "Products already present, skipping sample products"
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

wp cache flush >/dev/null 2>&1 || true

# ------------------------------------------------------------------------------
# Done
# ------------------------------------------------------------------------------
PORT="$(echo "$SITE_URL" | sed -nE 's#.*:([0-9]+).*#\1#p')"
PORT="${PORT:-80}"
HOST="$(echo "$SITE_URL" | sed -E 's#https?://([^:/]+).*#\1#')"

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

Start the store with the PHP built-in server:

  $PHP_BIN -d memory_limit=512M "$WP_CLI_PHAR" --path="$INSTALL_PATH" server --host=$HOST --port=$PORT

Configure your Smart Send API token under:
  WooCommerce -> Settings -> Shipping -> Smart Send
------------------------------------------------------------------------------
EOF
