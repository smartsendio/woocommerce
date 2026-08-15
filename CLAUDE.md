# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

The Smart Send WooCommerce plugin ("Smart Send Shipping for WooCommerce", slug `smart-send-logistics`). It adds Smart Send shipping methods to WooCommerce, shows carrier pick-up points at checkout, and generates shipping labels via the Smart Send API. Supported carriers: PostNord, GLS, DAO, Burd, Budbee, Bring.

The actual plugin lives entirely in `smart-send-logistics/` — that folder is what gets shipped to the WordPress.org plugin directory. The repo root only holds dev tooling (`composer.json` for phpcs, `scripts/svn-deploy.sh`, README).

## Development environment

There is no build step. Development is done against a local WordPress + WooCommerce install created by `bin/setup-local-dev.sh` (default location `./local-dev/wordpress`, plugin symlinked in and activated). The README.md has further details on the development environment.

## Testing

Three Pest suites live in `tests/` (run on modern PHP; the plugin's PHP 5.6 floor only applies to `smart-send-logistics/` code):

- **Browser** (`tests/Browser`) — end-to-end Playwright tests against a *running* store over HTTP (`WP_BASE_URL`, default `http://127.0.0.1:8181`). Few and slow; cover the big flows.
- **Integration** (`tests/Integration`) — WordPress + WooCommerce loaded *in-process* by `tests/bootstrap.php` from the `bin/setup-local-dev.sh` install (override with `WP_DEV_PATH`); no web server needed. This is where scenario coverage lives (orders with coupons, discounts, fees → shipment payload, filters). Fixtures are built via the factories in `tests/Integration/Helpers.php`, which force-delete everything they create after each test — always create test data through them.
- **Docs** (`tests/Docs`) — *not* a correctness suite. Drives Playwright through real admin UI flows (same running store as Browser) and captures named screenshots for documentation, one test per meaningful UI state (see `tests/Docs/ShippingMethod/ConfigureShippingMethodTest.php` for the pattern — configuring a Smart Send shipping method on a zone, step by step). Shared helpers live in `tests/Docs/Support/Screenshots.php`: `capture_doc_screenshot()` (asserts `assertNoJavaScriptErrors()` - not the stricter `assertNoSmoke()`, since WordPress admin always emits at least one benign console log - then saves under `docs/screenshots/<Area>/<name>.png`) and `highlight_element()` (spotlight-outlines the field a screenshot is calling out). Runs **headed** (a visible browser window) by default via `Playwright::headed()` in `tests/Pest.php` (a directory-level `tests/Docs/Pest.php` is never loaded by Pest for nested test files, so this lives at the root instead), so a developer regenerating screenshots locally can watch it work; falls back to headless when the `CI` env var is set (GitHub Actions sets this automatically) or when `SS_DOCS_HEADLESS` is set for a manual opt-out.

```bash
composer test:integration   # or: vendor/bin/pest --testsuite=Integration
composer test:browser       # needs the store running (see README)
composer test:docs          # needs the store running; opens a visible browser locally
composer test               # Integration + Browser (not Docs - see below)
```

Local runs are fast: every suite finishes in under 2 minutes. If a run takes longer, something is wrong (store not running, wrong `WP_BASE_URL`, a hung Playwright session) — kill it and investigate instead of waiting.

CI runs Integration and Browser (`.github/workflows/browser-tests.yml` and `integration-tests.yml`) on every pull request and on pushes to `main` and `develop`. **Docs is intentionally not part of that PR-blocking path** — screenshot generation is slow and a broken screenshot doesn't mean broken code (assertions only guard against a broken/erroring page, not visual regressions). It runs on demand from `.github/workflows/docs-screenshots.yml` (`workflow_dispatch` only, headless, uploads `docs/screenshots/` as a build artifact rather than committing — a human reviews and commits the images after eyeballing them) and locally via `composer test:docs`. Screenshots are committed to `docs/screenshots/` at the repo root (outside `smart-send-logistics/`, mirroring the existing plugin-vs-dev-tooling split) since the whole point of the suite is producing images for direct use in documentation, same as `smartsendio/dumbledore`'s `tests/Docs/`.

**v9 refactoring rule: no refactor PR merges without tests covering the moved behaviour.** The characterization suites (payload golden tests, rate calculation, order meta, label generation, frontend display — see `tests/Integration`) capture current behaviour; a refactor PR must keep them green, and any code it moves that is not yet covered must gain tests in the same PR. Tests assert *current* behaviour, including known oddities (marked `v8 oddity` in the test files) — changing such an expectation is a deliberate behaviour change and must be called out in the PR.

`composer.json` requires `squizlabs/php_codesniffer` + `wp-coding-standards/wpcs` as dev dependencies (no ruleset file committed); run `composer install` then `vendor/bin/phpcs --standard=WordPress smart-send-logistics` if linting is needed.

To point the plugin at Smart Send's sandbox API instead of production, use the `smart_send_api_endpoint` filter (see README "Sandbox environment"). Minimum PHP is 5.6 — avoid newer PHP syntax in plugin code.

## Architecture

Single-entry WordPress plugin. `smart-send-logistics/smart-send-logistics.php` defines the singleton `SS_Shipping_WC` (accessed globally as `SS_SHIPPING_WC()`), which is the composition root: it constructs every feature component (typed properties, same pattern as `ss_shipping_frontend`/`ss_shipping_wc_product`) and wires their hooks.

**Hook registration convention**: constructing a component has zero side effects - no `add_action`/`add_filter` calls in any constructor. Each component that owns hooks exposes an explicit `register_hooks(): void` method, and the composition root (`SS_Shipping_WC::init()`, via `register_component_hooks()`) calls it once on every component in one clear pass, at the point the singleton already wires its own hooks (`init_hooks()`). The only hooks left directly on the singleton are genuinely plugin-lifecycle ones (`init`, textdomain, HPOS declaration, plugin-row links, `before_woocommerce_init`) - everything feature-specific lives on its owning component.

**Loading**: there is no autoloader. `SS_Shipping_WC::includes()` loads every class file with explicit `require_once` calls (Composer cannot be assumed; the bespoke autoloader was removed in #43). The one exception is the shipping-method layer: `SS_Shipping_WC_Method` extends `WC_Shipping_Flat_Rate` and this plugin loads before WooCommerce, so `include_shipping_method_class()` requires it (and its three helper classes) lazily — from `init()` behind the bootstrap WooCommerce gate, and defensively from the `woocommerce_shipping_methods` filter callback. A new class file must be added to one of these two require lists.

**Folder layout** (agreed in #43): `admin/` for admin-only code (plus `admin/css/`, `admin/js/`), `public/` for checkout-side code (plus `public/css/`), `includes/` for code shared between the two (and the API client under `includes/lib/`). WordPress-style classes keep the `SS_Shipping_*` prefix, no namespace, files named `class-ss-shipping-*.php`.

1. **Admin classes** in `admin/`:
   - `SS_Shipping_WC_Method` — the shipping method, extends `WC_Shipping_Flat_Rate`; rate calculation (`calculate_shipping`, `is_available`, `is_free_shipping`) and the two protected rate reporters (log + checkout debug bar). WooCommerce's settings framework dispatches `validate_*_field`/`generate_*_html` on this instance, so it keeps thin wrappers that delegate to its components: `SS_Shipping_Method_Settings` (form field definitions + validation), `SS_Shipping_Method_Form_Renderer` (custom field type rendering), `SS_Shipping_Method_Catalog` (per-carrier method lists and code→name lookup).
   - The admin order integration is no longer a single facade class - the `SS_Shipping_WC` singleton constructs and owns each component directly, reachable via typed accessors: `SS_Shipping_Order_Meta` (the delivery-details repository, the SINGLE reader/writer of Smart-Send-owned order meta: `read()`/`write()` over `SS_Shipping_Delivery_Details`, frozen meta keys/formats, plus the META_* key classification — `order_meta()`), `SS_Shipping_Method_Resolver` (shipping method resolution from the order's shipping items: `resolve_outbound()`/`resolve_return()`, auto-return flag, the v8 return-pickup-point fallback — `method_resolver()`), `SS_Shipping_Shipment_Ids` (booked shipment id accessor; booking OUTCOMES, deliberately outside the delivery-details repository — `shipment_ids()`), `SS_Shipping_Pickup_Point_Validator` (agent-number validation on the order screen's Custom Fields box, covering both legacy meta hooks and the HPOS edit seams — `pickup_point_validator()`), `SS_Shipping_Order_Meta_Box` (order screen meta box — `meta_box()`), `SS_Shipping_Fulfillment_Service` (the label fulfillment workflow — `fulfillment()`), `SS_Shipping_Label_Creator` (the AJAX `wp_ajax_ss_shipping_generate_label` controller: nonce check, request parsing, translates the posted parcel rows into a `SS_Shipping_Parcel_Plan` and hands partial delivery details to the fulfillment service), `SS_Shipping_Order_Bulk_Actions` (bulk label actions on the Orders screen — temporarily single-order-only: selecting more than one order errors without processing, pending the Phase 7 bulk rebuild, #116 — `bulk_actions()`), `SS_Shipping_Admin_Notices` (flash notices store — `admin_notices()`). Supports both legacy post-based orders and HPOS (the plugin declares HPOS compatibility). WooCommerce Subscriptions integration lives on its own `SS_Shipping_Subscriptions_Compat` (in `includes/`, since the WC_Subscriptions plugin dependency is optional per the existence-guard policy), not on the singleton.
   - `SS_Shipping_Booking_Service` — the order-level booking orchestrator, stateless (constructed once, `WC_Order` passed per call): `book_outbound()`/`book_return()` build a shipment representation via `SS_Shipping_Shipment_Builder` (constructed per call, order injected), hand it to `Smartsend\Resources\BookingResource`, and wrap the outcome in a `SS_Shipping_Booking`.
   - `SS_Shipping_Shipment_Builder` — assembles the internal shipment representation (a typed `SS_Shipping_Shipment` value object) from `SS_Shipping_Order_Reader`'s output plus the merged delivery details (repository read + resolved method), passed through the `smart_send_delivery_details` filter, with the parcel plan resolved into typed `SS_Shipping_Parcel` rows; `build_outbound()`/`build_return()`. Nothing below the filter touches raw meta or `$_POST`.
   - `SS_Shipping_Shipment` — the internal shipment representation itself: a typed, WP-side value object (order/receiver/pickup-point/parcels/totals), zero WordPress dependencies. Its parcels are typed `SS_Shipping_Parcel` value objects (admin/).
   - `SS_Shipping_Booking` — wraps the outcome of a booking attempt (success flag, error message, raw API response data, translated v1 wire shipment).
   - `SS_Shipping_Booking_Exception` — thrown by `SS_Shipping_Method_Resolver::resolve_return()` when no return method is configured; caught by `SS_Shipping_Booking_Service` and converted into a failed `SS_Shipping_Booking`.
   - `SS_Shipping_WC_Product` — per-product shipping meta.
   - `SS_Plugins_Screen_Updates` — upgrade notices on the plugins screen.

2. **Frontend classes** in `public/`:
   - `SS_Shipping_Frontend` — checkout-side pick-up point selection display (rendering + checkout persistence; lookup and formatting are delegated).
   - `SS_Shipping_Pickup_Point_Lookup` — headless closest-pickup-points lookup: API call, lookup filters, failure reporting, session cache. The seam Phase 6's Checkout Block support builds on.

3. **Shared classes** in `includes/`: `SS_Shipping_Logger` (WC log; `debug` level gated on the "Debug Log" setting), `SS_Shipping_Checkout_Debug` (checkout shipping debug bar), `SS_Shipping_Admin_Notices` (flash notices store), `SS_Shipping_Order_Reader` (order → payload data extraction; the single reader of WC-native data), the delivery-details domain value objects (`SS_Shipping_Delivery_Details`, `SS_Shipping_Pickup_Point`, `SS_Shipping_Parcel_Plan`, `SS_Shipping_Parcel_Spec` — all serializable, none holds a live `WC_Order`; Phase 7 queues these), `SS_Shipping_Pickup_Point_Formatter` (the single place a pickup point becomes display text: checkout drop-down labels, order-details block, meta box block, settings option labels), `SS_Shipping_Label_Entry` (typed created-label data handed to `smart_send_shipping_label_created` listeners), `SS_Shipping_Fulfillment_Service` (stateless label fulfillment workflow: persists submitted delivery overrides through the repository, books via `SS_Shipping_Booking_Service`, then label PDF save, shipment id meta, order note, tracking push, status update, `smart_send_shipping_label_created`; owns the auto-generate-return-label decision; in `includes/` because Phase 7's async processing calls it outside admin), `SS_Shipping_Fulfillment_Result` (serializable fulfillment outcome; `to_legacy_response_array()` keeps the frozen AJAX JSON shape until #116), `SS_Shipping_Subscriptions_Compat` (prevents the Smart Send label meta from being copied onto WooCommerce Subscriptions renewal orders; the optional-plugin filter-name guard lives inside it).

4. **PSR-style API client** in `includes/lib/Smartsend/` — namespace `Smartsend`, classes `Api` (endpoint methods, extends `Client`) and `Client` (HTTP via `wp_remote_*` against `https://app.smartsend.io/api/v1/`), plus `Models/` value objects (`Shipment`, `Agent`, and their sub-models). This layer is deliberately WordPress-light; keep API concerns here rather than in the `SS_Shipping_*` classes.

Naming convention follows this same split: `SS_Shipping_*` (WordPress-style) code uses snake_case methods; the `Smartsend\` PSR-style lib uses camelCase — a call chain crossing the boundary (e.g. `SS_SHIPPING_WC()->get_api_handle()->bookings()->findByAgentNo()`) is expected to mix both, not a bug to fix.

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

Extension points are `smart_send_*` filters/actions (e.g. `smart_send_api_endpoint`, `smart_send_delivery_details`, `smart_send_shipping_label_created`, `smart_send_tracking_url`, `smart_send_order_note`). Preserve these when refactoring — merchants rely on them via code snippets. (The pre-9.0 `smart_send_shipping_label_args`, `smart_send_order_pickup_point`, `smart_send_order_parcels`, `smart_send_parcel_weight` and `smart_send_payload_parcels` filters were removed in #139, replaced by `smart_send_delivery_details`.)

## Releasing

Releases go to the WordPress.org SVN repo, not GitHub. Use `sh scripts/svn-deploy.sh` (interactive; copies `smart-send-logistics/` into an SVN checkout's trunk, tags, commits).

A version bump must update **three places in lockstep**:
- `smart-send-logistics/smart-send-logistics.php`: the `Version:` header and the private `$version` property
- `smart-send-logistics/readme.txt`: the `Stable tag:`

Also add a changelog entry under `== Changelog ==` in `readme.txt` (WordPress.org readme format, not Keep a Changelog). When compatibility is verified against newer versions, bump `Tested up to:` / `WC tested up to:` in both files.
