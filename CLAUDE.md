# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

The Smart Send WooCommerce plugin ("Smart Send Shipping for WooCommerce", slug `smart-send-logistics`). It adds Smart Send shipping methods to WooCommerce, shows carrier pick-up points at checkout, and generates shipping labels via the Smart Send API. Supported carriers: PostNord, GLS, DAO, Burd, Budbee, Bring.

The actual plugin lives entirely in `smart-send-logistics/` — that folder is what gets shipped to the WordPress.org plugin directory. The repo root only holds dev tooling (`composer.json` for phpcs, `scripts/svn-deploy.sh`, README).

## Development environment

There is no build step. Development is done against a local WordPress + WooCommerce install created by `bin/setup-local-dev.sh` (default location `./local-dev/wordpress`, plugin symlinked in and activated). The README.md has further details on the development environment.

## Testing

Two Pest suites live in `tests/` (run on modern PHP; the plugin's PHP 5.6 floor only applies to `smart-send-logistics/` code):

- **Browser** (`tests/Browser`) — end-to-end Playwright tests against a *running* store over HTTP (`WP_BASE_URL`, default `http://127.0.0.1:8181`). Few and slow; cover the big flows.
- **Integration** (`tests/Integration`) — WordPress + WooCommerce loaded *in-process* by `tests/bootstrap.php` from the `bin/setup-local-dev.sh` install (override with `WP_DEV_PATH`); no web server needed. This is where scenario coverage lives (orders with coupons, discounts, fees → shipment payload, filters). Fixtures are built via the factories in `tests/Integration/Helpers.php`, which force-delete everything they create after each test — always create test data through them.

```bash
composer test:integration   # or: vendor/bin/pest --testsuite=Integration
composer test:browser       # needs the store running (see README)
composer test               # both
```

CI runs both suites (`.github/workflows/browser-tests.yml` and `integration-tests.yml`) on every pull request and on pushes to `main` and `develop`.

**v9 refactoring rule: no refactor PR merges without tests covering the moved behaviour.** The characterization suites (payload golden tests, rate calculation, order meta, label generation, frontend display — see `tests/Integration`) capture current behaviour; a refactor PR must keep them green, and any code it moves that is not yet covered must gain tests in the same PR. Tests assert *current* behaviour, including known oddities (marked `v8 oddity` in the test files) — changing such an expectation is a deliberate behaviour change and must be called out in the PR.

`composer.json` requires `squizlabs/php_codesniffer` + `wp-coding-standards/wpcs` as dev dependencies (no ruleset file committed); run `composer install` then `vendor/bin/phpcs --standard=WordPress smart-send-logistics` if linting is needed.

To point the plugin at Smart Send's sandbox API instead of production, use the `smart_send_api_endpoint` filter (see README "Sandbox environment"). Minimum PHP is 5.6 — avoid newer PHP syntax in plugin code.

## Architecture

Single-entry WordPress plugin. `smart-send-logistics/smart-send-logistics.php` defines the singleton `SS_Shipping_WC` (accessed globally as `SS_SHIPPING_WC()`), which wires all hooks and instantiates the other components.

Two class layers with different conventions:

1. **WordPress-style classes** in `includes/` — prefix `SS_Shipping_*`, no namespace, files named `class-ss-shipping-*.php`, loaded on demand by `SS_Shipping_Autoloader` (maps `SS_Shipping_Foo_Bar` → `class-ss-shipping-foo-bar.php`, searching `includes/` and `includes/frontend/`). New classes must follow this naming to be autoloadable.
   - `SS_Shipping_WC_Method` — the shipping method, extends `WC_Shipping_Flat_Rate`; holds all admin settings (API token, carrier/agent options, formats).
   - `SS_Shipping_WC_Order` — admin order screen: meta box, AJAX label generation (`wp_ajax_ss_shipping_generate_label`), bulk actions, tracking info, WooCommerce Subscriptions integration. Supports both legacy post-based orders and HPOS (the plugin declares HPOS compatibility).
   - `SS_Shipping_Shipment` — builds the shipment payload from a WC order and sends it to the API.
   - `SS_Shipping_Frontend` (`includes/frontend/`) — checkout-side pick-up point selection display.
   - `SS_Shipping_WC_Product` — per-product shipping meta.
   - `SS_Shipping_Logger` — logging via WC logger when "debug" is enabled in settings.

2. **PSR-style API client** in `includes/lib/Smartsend/` — namespace `Smartsend`, classes `Api` (endpoint methods, extends `Client`) and `Client` (HTTP via `wp_remote_*` against `https://app.smartsend.io/api/v1/`), plus `Models/` value objects (`Shipment`, `Agent`, and their sub-models). This layer is deliberately WordPress-light; keep API concerns here rather than in the `SS_Shipping_*` classes.

Extension points are `smart_send_*` filters/actions (e.g. `smart_send_api_endpoint`, `smart_send_shipping_label_args`, `smart_send_shipping_label_created`, `smart_send_tracking_url`, `smart_send_order_note`). Preserve these when refactoring — merchants rely on them via code snippets.

## Releasing

Releases go to the WordPress.org SVN repo, not GitHub. Use `sh scripts/svn-deploy.sh` (interactive; copies `smart-send-logistics/` into an SVN checkout's trunk, tags, commits).

A version bump must update **three places in lockstep**:
- `smart-send-logistics/smart-send-logistics.php`: the `Version:` header and the private `$version` property
- `smart-send-logistics/readme.txt`: the `Stable tag:`

Also add a changelog entry under `== Changelog ==` in `readme.txt` (WordPress.org readme format, not Keep a Changelog). When compatibility is verified against newer versions, bump `Tested up to:` / `WC tested up to:` in both files.
