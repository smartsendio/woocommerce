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

**Loading**: there is no autoloader. `SS_Shipping_WC::includes()` loads every class file with explicit `require_once` calls (Composer cannot be assumed; the bespoke autoloader was removed in #43). The one exception is the shipping-method layer: `SS_Shipping_WC_Method` extends `WC_Shipping_Flat_Rate` and this plugin loads before WooCommerce, so `include_shipping_method_class()` requires it (and its three helper classes) lazily — from `init()` behind the bootstrap WooCommerce gate, and defensively from the `woocommerce_shipping_methods` filter callback. A new class file must be added to one of these two require lists.

**Folder layout** (agreed in #43): `admin/` for admin-only code (plus `admin/css/`, `admin/js/`), `public/` for checkout-side code (plus `public/css/`), `includes/` for code shared between the two (and the API client under `includes/lib/`). WordPress-style classes keep the `SS_Shipping_*` prefix, no namespace, files named `class-ss-shipping-*.php`.

1. **Admin classes** in `admin/`:
   - `SS_Shipping_WC_Method` — the shipping method, extends `WC_Shipping_Flat_Rate`; rate calculation (`calculate_shipping`, `is_available`, `is_free_shipping`) and the two protected rate reporters (log + checkout debug bar). WooCommerce's settings framework dispatches `validate_*_field`/`generate_*_html` on this instance, so it keeps thin wrappers that delegate to its components: `SS_Shipping_Method_Settings` (form field definitions + validation), `SS_Shipping_Method_Form_Renderer` (custom field type rendering), `SS_Shipping_Method_Catalog` (per-carrier method lists and code→name lookup).
   - `SS_Shipping_WC_Order` — the admin order integration facade; its public surface is the stable API reached via `SS_SHIPPING_WC()->get_ss_shipping_wc_order()`. It wires hooks and delegates to: `SS_Shipping_Order_Meta` (order meta access: method id, pick-up point agent, parcel split, shipment ids), `SS_Shipping_Order_Meta_Box` (order screen meta box), `SS_Shipping_Label_Creator` (AJAX `wp_ajax_ss_shipping_generate_label` + label creation flow), `SS_Shipping_Order_Bulk_Actions` (bulk label generation incl. combined PDF). Supports both legacy post-based orders and HPOS (the plugin declares HPOS compatibility). WooCommerce Subscriptions integration stays on the facade.
   - `SS_Shipping_Shipment` — builds the shipment payload from a WC order and sends it to the API.
   - `SS_Shipping_WC_Product` — per-product shipping meta.
   - `SS_Plugins_Screen_Updates` — upgrade notices on the plugins screen.

2. **Frontend classes** in `public/`:
   - `SS_Shipping_Frontend` — checkout-side pick-up point selection display.

3. **Shared classes** in `includes/`: `SS_Shipping_Logger` (WC log; `debug` level gated on the "Debug Log" setting), `SS_Shipping_Checkout_Debug` (checkout shipping debug bar), `SS_Shipping_Admin_Notices` (flash notices store), `SS_Shipping_Order_Data` (order → payload data extraction).

4. **PSR-style API client** in `includes/lib/Smartsend/` — namespace `Smartsend`, classes `Api` (endpoint methods, extends `Client`) and `Client` (HTTP via `wp_remote_*` against `https://app.smartsend.io/api/v1/`), plus `Models/` value objects (`Shipment`, `Agent`, and their sub-models). This layer is deliberately WordPress-light; keep API concerns here rather than in the `SS_Shipping_*` classes.

## Logging policy

Two decoupled surfaces with distinct audiences — place every log/notice call deliberately on one of them (see issue #92):

1. **Checkout shipping debug bar** (`SS_Shipping_Checkout_Debug::add_notice()`) — for the merchant diagnosing checkout live (WooCommerce → Settings → Shipping → "Enable debug mode"). Carries the rate evaluation trace, which Smart Send rates ended up offered for the package, the overlapping-weight-row outcome, and pick-up point lookup results/failures. It never writes to the log — call `SS_Shipping_Logger` separately when a log entry is also wanted. Notices are a no-op during checkout AJAX (matching WooCommerce core), so the log entry is then the only trace.
2. **The WooCommerce log** (`SS_Shipping_Logger`, source `smart-send-logistics`) — an audit trail of business events (`info`) plus developer trace (`debug`).

| Level | Use for | Example |
|---|---|---|
| `info` | Business events a merchant/support agent cares about after the fact; always logged (ungated audit trail) | "Shipping label created", "Tracking number stored", "Pick-up point selected at checkout" |
| `debug` | Developer trace only, non-polluting — the ONLY level gated on the plugin "Debug Log" setting | "No Smart Send shipping method on order - skipping meta box content", rate evaluation detail, API request cycles (via `log_api_request`) |
| `warning` | Failures the plugin recovers from; always logged | "Pick-up point not found - agent number rejected" |
| `error` / `critical` | API and transport failures, and recoverable-but-abnormal states; always logged | "POST /shipments → 422 (312ms)" (via `log_api_request`), "Failed to load WooCommerce order when deleting pick-up point meta" |

Keep messages concise and greppable; put structured data (order id, agent no, shipment id, carrier) in the context array — the WooCommerce log viewer renders it natively.

## Existence-guard policy

`function_exists`/`class_exists` guards appear **only** where existence genuinely varies across the supported range (see issue #90). The three legitimate categories:

1. **Optional third-party plugins** — e.g. `wc_st_add_tracking_number` (Shipment Tracking), `WC_Subscriptions`.
2. **WP/WC APIs newer than our floors** (PHP 7.4 / WC 4.7) — e.g. `FeaturesUtil` (WC 6.5+), the HPOS `CustomOrdersTableController`.
3. **Load-context differences** — e.g. `get_plugins()` outside admin (prefer `require_once` of the include over a silent guard).

Everything else — any WP or WC core API our floors guarantee — is called directly, with **no** guard: a guard on an impossible state silently swallows real bugs. Every kept guard carries a one-line comment naming why existence varies. Exactly one bootstrap-level WooCommerce-active check lives in `smart-send-logistics.php` (`init()`'s `WOOCOMMERCE_VERSION` gate — belt-and-braces for WP < 6.5, where `Requires Plugins: woocommerce` is not enforced); everything downstream assumes WooCommerce is present.

Extension points are `smart_send_*` filters/actions (e.g. `smart_send_api_endpoint`, `smart_send_shipping_label_args`, `smart_send_shipping_label_created`, `smart_send_tracking_url`, `smart_send_order_note`). Preserve these when refactoring — merchants rely on them via code snippets.

## Releasing

Releases go to the WordPress.org SVN repo, not GitHub. Use `sh scripts/svn-deploy.sh` (interactive; copies `smart-send-logistics/` into an SVN checkout's trunk, tags, commits).

A version bump must update **three places in lockstep**:
- `smart-send-logistics/smart-send-logistics.php`: the `Version:` header and the private `$version` property
- `smart-send-logistics/readme.txt`: the `Stable tag:`

Also add a changelog entry under `== Changelog ==` in `readme.txt` (WordPress.org readme format, not Keep a Changelog). When compatibility is verified against newer versions, bump `Tested up to:` / `WC tested up to:` in both files.
