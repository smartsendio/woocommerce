<?php

/*
 * Run the shipped plugin in a fresh process with only the WordPress functions
 * needed at bootstrap. No database, WooCommerce autoloader or PHP constants
 * leak in from the Integration suite. Unsupported cases deliberately provide
 * no WooCommerce classes: an unguarded dependency is then a real PHP failure.
 */

error_reporting(E_ALL);
set_error_handler(function (int $severity, string $message, string $file, int $line): bool {
    if (! (error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

define('ABSPATH', __DIR__ . '/');
define('HOUR_IN_SECONDS', 3600);

$GLOBALS['bootstrap_hooks'] = [];
$GLOBALS['bootstrap_compatibility'] = [];

function add_filter(string $hook, $callback, int $priority = 10, int $accepted_args = 1): bool
{
    $GLOBALS['bootstrap_hooks'][$hook][$priority][] = $callback;

    return true;
}

function add_action(string $hook, $callback, int $priority = 10, int $accepted_args = 1): bool
{
    return add_filter($hook, $callback, $priority, $accepted_args);
}

function plugin_dir_path(string $file): string
{
    return dirname($file) . '/';
}

function plugin_basename(string $file): string
{
    return basename(dirname($file)) . '/' . basename($file);
}

function untrailingslashit(string $value): string
{
    return rtrim($value, '/');
}

function plugins_url(string $path, string $plugin): string
{
    return 'https://shop.test/wp-content/plugins/' . basename(dirname($plugin)) . '/' . ltrim($path, '/');
}

function did_action(string $hook): int
{
    return 0;
}

function esc_html__(string $text, string $domain): string
{
    return esc_html($text);
}

function esc_html(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

if ($argv[1] !== 'missing') {
    define('WOOCOMMERCE_VERSION', $argv[1]);
}

if ($argv[2] === 'supported') {
    require __DIR__ . '/woocommerce.php';
}

require dirname(__DIR__, 3) . '/smart-send-logistics/smart-send-logistics.php';

$plugin = SS_SHIPPING_WC();

$early_shipping_class_loaded = class_exists(\Smart_Send\Shipping_Method\Method::class, false);
$early_blocks_class_loaded = class_exists(\Smart_Send\Frontend\Block_Checkout::class, false);

// Exercise the two entry points that can precede the main init callback.
foreach ($GLOBALS['bootstrap_hooks']['before_woocommerce_init'][10] as $callback) {
    $callback();
}
$shipping_methods = ['other_shipping' => 'Other_Shipping_Method'];
foreach ($GLOBALS['bootstrap_hooks']['woocommerce_shipping_methods'][10] as $callback) {
    $shipping_methods = $callback($shipping_methods);
}

$plugin->init();

$notice_registered = in_array([$plugin, 'notice_wc_required'], $GLOBALS['bootstrap_hooks']['admin_notices'][10] ?? [], true);
ob_start();
if ($notice_registered) {
    $plugin->notice_wc_required();
}
$notice = ob_get_clean();

echo json_encode([
    'shipping_methods' => $shipping_methods,
    // Like WooCommerce, resolve a registered method only after the dependency gate.
    'shipping_class_loaded' => class_exists(\Smart_Send\Shipping_Method\Method::class, $argv[2] === 'supported'),
    'blocks_class_loaded' => class_exists(\Smart_Send\Frontend\Block_Checkout::class, false),
    'early_shipping_class_loaded' => $early_shipping_class_loaded,
    'early_blocks_class_loaded' => $early_blocks_class_loaded,
    'feature_hooks_registered' => isset($GLOBALS['bootstrap_hooks']['wp_ajax_ss_test_connection']),
    'compatibility' => $GLOBALS['bootstrap_compatibility'],
    'notice' => trim(strip_tags($notice)),
], JSON_THROW_ON_ERROR);
