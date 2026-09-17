# Smart Send (WooCommerce plugin)

The Smart Send shipping plugin for WooCommerce. This README is for **developers of the plugin**: how to get a local shop running, how to test the plugin by hand without touching the Smart Send API, and how the automated tests work.

The plugin itself lives entirely in [`smart-send-logistics/`](smart-send-logistics/) — that folder is what ships to WordPress.org. Everything else in the repository is development tooling around it.

- [Quick start](#quick-start)
- [The local dev store](#the-local-dev-store)
- [Manual testing without the Smart Send API (demo mode)](#manual-testing-without-the-smart-send-api-demo-mode)
- [Automated tests](#automated-tests)
- [Coding standards and the JS build](#coding-standards-and-the-js-build)
- [Repository structure](#repository-structure)
- [Releasing](#releasing)

## Quick start

Requirements: PHP 8.3+ for the dev tooling (the plugin runtime supports PHP 7.4+, WordPress 6.5+ and WooCommerce 8.2+), Composer, Node (version in [`.nvmrc`](.nvmrc)). No database server is needed — the store runs on SQLite by default.

```bash
composer install && npm install && npx playwright install chromium
composer setup      # builds a complete local WooCommerce store with the plugin activated
composer demo:on    # fakes the Smart Send API + seeds a shipping method, product and checkout pages
```

That is it. `composer setup` prints the store URL and admin credentials (default `admin` / `password`); `composer demo:on` prints them again together with the checkout URLs. Open the shop, buy the sample product, pick a pick-up point at checkout, then open the order in admin and create a label — all without a Smart Send account or API token.

```bash
composer demo:off   # back to a plain store talking to the real API
```

## The local dev store

`composer setup` (shorthand for [`bin/setup-local-dev.sh`](bin/setup-local-dev.sh)) installs the latest WordPress and WooCommerce, symlinks `smart-send-logistics/` into `wp-content/plugins/` and activates it, activates the Storefront theme, configures a Danish store (DKK, kg/cm, 25% VAT, a Denmark shipping zone) and seeds a realistic product catalog with images from [`sample-data/`](sample-data/). It is idempotent: re-running re-applies configuration without reinstalling; `composer setup -- --force` starts over. `bin/setup-local-dev.sh --help` lists every option (WordPress/WooCommerce versions, MySQL instead of SQLite, admin credentials, and so on).

### Two stores: `.env` and `.env.testing`

Two git-ignored files at the repo root pin where the stores live (`WP_PATH`, relative to the repo root) and their URL (`WP_URL`). [`.env.example`](.env.example) documents every entry.

| File | Store | Used by |
|---|---|---|
| `.env` | **Your persistent dev store** for manual testing and demo mode. | `composer setup`, `composer demo:*` |
| `.env.testing` | **A disposable testing store**, rebuilt from scratch by every `composer test:*` run. Defaults match CI (`./local-dev/wordpress` at `http://127.0.0.1:8181`). | `composer test:*` |

The first interactive `composer setup` asks where to put the dev store and writes `.env`. Point `WP_URL` at a directory served by [Laravel Herd](https://herd.laravel.com) (parked or `herd link`ed, plain `http://`) and you never have to start a web server; with a localhost URL the test runner starts a PHP built-in server itself, and for manual testing you start one yourself from the store directory with `wp server --host=127.0.0.1 --port=8181` (or with `--path=<WP_PATH>` from anywhere).

Three knobs change how the store behaves, resolved as flag > exported environment variable > env file entry > default:

- `--checkout classic|block` / `WP_CHECKOUT` (default `block`) — whether the checkout page uses the WooCommerce Checkout block or the classic `[woocommerce_checkout]` shortcode. The plugin supports both, so test both.
- `--prices-tax include|exclude` / `WP_PRICES_TAX` (default `include`) — WooCommerce's "Prices entered with tax".
- `--order-storage hpos|posts|default` / `WP_ORDER_STORAGE` (default `default`, WooCommerce's own choice — HPOS on a fresh install) — the order storage backend. The order screen differs between High-Performance Order Storage and the legacy post-based storage, so the Browser suite runs against both in CI.

Example: `WP_CHECKOUT=classic composer setup`.

Disposable stores created with `--env testing` or `--disposable` have automatic WordPress, plugin and theme updates disabled. CI uses `--disposable`; ordinary development stores retain their existing update policy. Explicit `--wp-version` / `--wc-version` pins are checked against the installed versions, including reused stores, and setup fails on a mismatch. Its final output records the actual versions tested.

## Manual testing without the Smart Send API (demo mode)

### How the fake API works

The plugin talks to Smart Send through WordPress' HTTP layer (`wp_remote_*`). WordPress runs the `pre_http_request` filter before every such request, and a small must-use plugin, [`tests/Browser/Support/ApiMockMuPlugin.php`](tests/Browser/Support/ApiMockMuPlugin.php), hooks into it: every request to `smartsend.io` is short-circuited and answered with a canned response in the shape the real API produces. The plugin code is untouched and cannot tell it is being faked — the whole real client, including request logging, runs as in production. Any API token, even an empty one, is accepted because the token validation endpoint is faked too.

The mock is controlled by one WordPress option, `ss_test_api`:

```php
array(
    'enabled'   => true,
    'scenarios' => array( 'booking' => '500', 'pickup-points' => 'empty' ),
)
```

Every faked endpoint returns its success response unless `scenarios` overrides it. The endpoints are `authenticate`, `pickup-points`, `booking`, `labels-combine` and `agent-lookup`. A case is either a named case (`authenticate=401`, `pickup-points=empty`, `booking=422-wrong-zip`) or any three-digit HTTP status code, which yields a generic error body with that status. Overrides are per endpoint, so failures compose: authentication can succeed while the pick-up point lookup 403s and booking 500s. An unknown case name produces a 500 with an explanatory message rather than silently passing. The canonical endpoint/case list is in the mock file's header.

The same mock file is used by the Browser and Docs test suites, so demo mode always matches exactly what the tests exercise.

### Demo mode on the dev store

```bash
composer demo:on          # install the mock + seed the store; prints URLs and admin credentials
composer demo:off         # remove the mock and the demo fixtures again
composer demo:scenario    # show the active scenarios and the valid endpoint/case names
```

`demo:on` copies the mock into the dev store's `wp-content/mu-plugins/`, enables it, and seeds what a checkout-to-label walk-through needs: a Denmark zone with a Smart Send pick-up point method, a sample product, and both a classic and a block checkout page. It stays on until `demo:off`, which removes only what `demo:on` created — zones, products, pages and orders you built on top are left alone. Both commands are idempotent.

Simulate failures per endpoint:

```bash
composer demo:scenario -- booking=422-wrong-zip          # label booking fails with a realistic validation error
composer demo:scenario -- pickup-points=empty            # no pick-up points near the address
composer demo:scenario -- authenticate=401               # "Invalid API token provided"
composer demo:scenario -- booking=500 pickup-points=403  # any status code; overrides compose
composer demo:scenario -- booking=success                # drop one override, keep the rest
composer demo:scenario -- reset                          # everything back to success
```

Demo mode targets the store in `.env` (never the testing store, so demo state cannot leak into test runs) and refuses to run against a production environment or any site URL that is not localhost, 127.0.0.1 or `*.test`.

### The fake API on any other WordPress site

The mock is a single dependency-free PHP file, so it also works on a store that was not created by this repository:

1. Copy `tests/Browser/Support/ApiMockMuPlugin.php` into the site's `wp-content/mu-plugins/`. Must-use plugins are active immediately; nothing needs enabling in admin.
2. Turn it on, for example with WP-CLI:

```bash
wp option update ss_test_api '{"enabled":true,"scenarios":{}}' --format=json
```

Set scenarios in the same option, e.g. `{"enabled":true,"scenarios":{"booking":"500"}}`. Delete the file and the option to turn it off. You only get the mock this way, not the seeding — the store needs its own shipping zone with a Smart Send method.

### Using the Smart Send sandbox instead

When you want real API behaviour against Smart Send's sandbox (or a locally running Smart Send app), point the plugin there with the `smart_send_api_endpoint` filter, for example from a [Code Snippets](https://wordpress.org/plugins/code-snippets/) snippet set to run everywhere:

```php
add_filter( 'smart_send_api_endpoint', function ( $api_host ) {
    return 'https://app.smartsend.dev';
} );
```

The filter carries the **host only** (scheme + hostname, no `/api/v1/`): the plugin appends the API version path itself, so the override keeps working when the plugin moves to a newer Smart Send API version (#170). A value that still ends in `/api/v1/` (the pre-9.0 shape) is stripped to the host with a warning in the WooCommerce log rather than producing a `/api/v1/api/v1/` URL.

## Automated tests

Tests are written with [Pest](https://pestphp.com) and live in [`tests/`](tests/). There are three suites:

| Suite | What it is | How it runs |
|---|---|---|
| **Integration** (`tests/Integration`) | The bulk of the coverage. WordPress + WooCommerce are loaded **in-process** by `tests/bootstrap.php` from the testing store; tests build orders, carts and settings directly and assert on payloads, rates, order meta, label generation, frontend output. Fixtures come from the factories in `tests/Integration/Helpers.php`, which delete everything they created after each test. | No web server needed. |
| **Browser** (`tests/Browser`) | Few, slow end-to-end Playwright tests against a **running** store over HTTP: activation, settings, shipping method setup, classic and block checkout, label generation. They seed the store and install the API mock through WP-CLI (`tests/Browser/Support/`). `DevStoreTest.php` checks the store itself (theme, pages, no JS errors) without any Smart Send seeding — run it first when the store misbehaves. | Needs the store served; the runner handles that for localhost URLs. |
| **Docs** (`tests/Docs`) | Not a correctness suite. Drives real admin UI flows and saves named screenshots to `docs/screenshots/` for the documentation. Runs headed (visible browser) locally so you can watch; headless in CI or with `SS_DOCS_HEADLESS=1`. `SS_DOCS_SLOWMO=1500` pauses at each screenshot state. | Same as Browser. |

### Running them

```bash
composer test:integration   # fresh testing store, then the Integration suite
composer test:browser       # fresh testing store + managed web server, then the Browser suite
composer test               # Integration + Browser (what CI runs on every pull request)
composer test:docs          # regenerate documentation screenshots (opens a browser window)
```

Every `composer test:*` command goes through [`bin/run-tests.sh`](bin/run-tests.sh), which **rebuilds the testing store (`.env.testing`) from scratch first**, so runs never drift from earlier runs, demo mode or manual clicking. WP-CLI caches downloads. The integration suite normally takes one to two minutes, while the full browser suite can take several minutes. CI bounds each browser attempt to six minutes and retries once after restarting PHP-FPM.

For fast iteration against the *existing* testing store, call Pest directly and skip the rebuild:

```bash
vendor/bin/pest --testsuite=Integration
vendor/bin/pest tests/Integration/RateCalculationTest.php
vendor/bin/pest --testsuite=Browser      # you serve the store yourself in this case
```

Browser fixtures and the integration bootstrap share `WP_PATH` (resolved relative to the repository root), with `WP_DEV_PATH` retained as a fallback. Use `WP_URL` for the matching store URL. The Browser/Docs suites recover saved fixture snapshots before their first test, including after a killed attempt: a clean store without a snapshot is left untouched, and shipping methods/settings are restored before the baseline checks run.

### Rules for tests

- **No refactor merges without tests covering the moved behaviour.** The Integration suite contains characterization tests that pin the current behaviour of the core flows (shipment payload golden tests, rate calculation, order meta on legacy and HPOS storage, label generation, pick-up point display). Keep them green and extend them in the same PR for any code you move that is not yet covered.
- Tests assert *current* behaviour, including known oddities marked `v8 oddity` in the test files. Changing such an expectation is a deliberate behaviour change and must be called out in the PR.
- Create all test data through the factories in `tests/Integration/Helpers.php` so it is cleaned up.

### CI

`.github/workflows/integration-tests.yml` and `browser-tests.yml` run on every pull request and on pushes to `main` and `develop` (and on demand via `workflow_dispatch`); browser failure screenshots are uploaded as workflow artifacts. `coding-standards.yml` runs phpcs and the PHPCompatibility scan (`testVersion` 7.4 and up), and `js-build.yml` fails if the committed `build/` output drifts from `src/`. `docs-screenshots.yml` is manual only (`workflow_dispatch`): it uploads the screenshots as an artifact for a human to review and commit.

The matrices cover the two ends of the supported range (PHP 7.4+, WordPress 6.5+, WooCommerce 8.2+) rather than every combination: `latest` legs run each PHP version against the latest WordPress and WooCommerce, and one `floor` leg per workflow runs the oldest supported WordPress + WooCommerce (latest patch of each).

| Workflow | `latest` legs | `floor` leg |
|---|---|---|
| Browser (store on the matrix PHP, Pest on 8.4) | PHP 7.4, 8.0, 8.1, 8.2, 8.3, 8.4, 8.5 | PHP 7.4 / WP 6.5.x / WC 8.2.x |
| Integration (WordPress in the Pest process, so Pest's PHP 8.3 floor applies) | PHP 8.3, 8.4, 8.5 | PHP 8.3 / WP 6.5.x / WC 8.2.x |

A leg carrying `canary: true` in the matrix runs with `continue-on-error`: it reports its result but does not block merges — use it only with a comment naming the issue that turns it strict again. No leg is a canary today; the floor legs are strict.

The browser store runs on nginx + php-fpm with the opcache JIT and PCRE JIT turned off — the PHP 8.x fpm segfaults tracked in #79 resolved to the opcache tracing JIT that `setup-php` enables by default for PHP 8.x, which silently overrode the workflow's own ini — and the workflow asserts the effective worker settings before the suite starts.

## Coding standards and the JS build

The plugin follows the [WordPress Coding Standards](https://github.com/WordPress/WordPress-Coding-Standards); the ruleset is [`phpcs.xml.dist`](phpcs.xml.dist). The same rules apply to all first-party PHP, including API classes and filenames. Plugin code must stay PHP 7.4 compatible (`composer phpcs:compat`).

```bash
composer phpcs       # check
composer phpcs:fix   # auto-fix what can be fixed
```

The whole plugin is clean against the ruleset, so there is no baseline file any more — `composer phpcs` must simply pass. Deliberate exceptions are annotated inline with a `phpcs:ignore <sniff> -- <reason>` comment, which is how pre-existing loose comparisons and the handful of intentionally-unescaped outputs are kept.

The checkout-block scripts and the order-screen fulfillment app are compiled assets. Source is in [`src/pickup-point-block/`](src/pickup-point-block/) and [`src/order-fulfillment/`](src/order-fulfillment/); `npm run build` compiles both into `smart-send-logistics/build/`, which is **committed** so the plugin works without Node. After changing anything under `src/`, run the build and commit the output.

## Repository structure

```
.
├── smart-send-logistics/     # THE PLUGIN — the only folder shipped to WordPress.org
│   ├── includes/             # Composition root + domain code (booking, fulfillment, delivery,
│   │                         #   delivery-options, shipping-method, support, api)
│   │   └── autoload.php      # Local namespace loader, shipped as PHP source
│   ├── admin/ public/        # Admin and frontend controllers + UI
│   ├── build/                # Compiled checkout and order-screen JS (committed)
│   └── readme.txt            # WordPress.org readme (stable tag, changelog)
├── src/                      # Checkout and order-screen JS source
├── tests/                    # Integration, Browser and Docs suites (see above)
│   └── Browser/Support/      # Store seeding + the Smart Send API mock, shared with demo mode
├── bin/                      # setup-local-dev.sh, run-tests.sh, demo-store.sh, svn-deploy.sh, ...
├── sample-data/              # Vendored sample catalog used to seed stores (never edit by hand;
│                             #   refresh with bin/update-sample-data.sh)
├── docs/screenshots/         # Documentation screenshots produced by the Docs suite
├── .github/workflows/        # CI
├── .env / .env.testing       # Git-ignored store locations (see .env.example)
└── CLAUDE.md                 # Architecture notes and instructions for AI agents
```

All first-party PHP uses the `Smart_Send\` namespace and WordPress naming: `Class_Name`, `snake_case()` methods and properties, and lowercase `class-*.php` files. For example, `Smart_Send\Delivery\Pickup_Point` lives in `includes/delivery/class-pickup-point.php`. The same rules apply to the API client under `Smart_Send\API`.

The entry file registers the local loader from `includes/autoload.php`. It maps `Smart_Send\Admin\` to `admin/`, `Smart_Send\Frontend\` to `public/`, and the remaining `Smart_Send\` domains to `includes/`. Namespace components become lowercase, hyphenated directories; no class list or build step is needed. The packaged plugin runs without Composer or `vendor/`; Composer is only for development tools. The installed slug/text domain `smart-send-logistics`, persisted identifiers and the global `SS_SHIPPING_WC()` accessor stay unchanged.

The architecture of the plugin itself (domains, hook conventions, logging policy, extension points) is documented in [CLAUDE.md](CLAUDE.md). The namespace decision is tracked in [#195](https://github.com/smartsendio/woocommerce/issues/195), within the [v9 release plan](https://github.com/smartsendio/woocommerce/issues/172).

## Releasing

Releases go to the WordPress.org SVN repository, not GitHub:

```bash
bash bin/svn-deploy.sh
```

The script is interactive: it copies `smart-send-logistics/` into an SVN checkout's trunk, tags the version and commits. Before running it, bump the version in three places in lockstep — the `Version:` header in `smart-send-logistics/smart-send-logistics.php`, the `$version` property in `smart-send-logistics/includes/class-plugin.php`, and `Stable tag:` in `smart-send-logistics/readme.txt` — and add a changelog entry under `== Changelog ==` in `readme.txt`.

After rebuilding the JavaScript, regenerate the translation template from the shipped plugin (WP-CLI with its i18n command):

```bash
wp i18n make-pot smart-send-logistics smart-send-logistics/lang/smart-send-logistics.pot --domain=smart-send-logistics
```

The scan includes the committed JavaScript bundles, so PHP and browser strings share the same template with references to files that actually ship. Commit the template alongside the final source/build changes.

To export a given branch or tag as a plugin zip:

```bash
git archive v9.0.0 --output="smart-send-logistics-v9.0.0.zip" smart-send-logistics
```
