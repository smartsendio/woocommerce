<?php

/*
 * Focused tests for SS_Shipping_Checkout_Debug, the class that surfaces
 * Smart Send diagnostics in WooCommerce's shipping debug mode checkout bar.
 *
 * add_notice() only shows checkout notices — it never writes to the Smart
 * Send log (call sites log explicitly via SS_Shipping_Logger). Gating
 * mirrors WooCommerce core: the woocommerce_shipping_debug_mode option,
 * never when WOOCOMMERCE_CHECKOUT or WC_DOING_AJAX is defined, and
 * deduplicated via wc_has_notice().
 *
 * The WC_DOING_AJAX gate is NOT exercised here: this file runs before
 * ShippingDebugModeTest (alphabetical order) and the constant cannot be
 * undefined once set, so that case lives in the tail of
 * ShippingDebugModeTest.php after its own WC_DOING_AJAX test.
 */

/**
 * Replace the logger's WC_Logger with a spy that records every entry.
 * Restored automatically after the test.
 */
function ss_checkout_debug_spy_logger(): object
{
    $spy = new class {
        public array $entries = [];

        public function log($level, $message, $context = []): void
        {
            $this->entries[] = ['level' => $level, 'message' => $message, 'context' => $context];
        }

        /**
         * The logger dispatches to wc_get_logger()'s level wrapper methods
         * (debug(), error(), ...); record them like log() calls.
         */
        public function __call(string $level, array $args): void
        {
            $this->log($level, $args[0], $args[1] ?? []);
        }
    };

    SS_Shipping_Logger::$logger = $spy;

    remember_cleanup_callback(function (): void {
        SS_Shipping_Logger::$logger = null;
    });

    return $spy;
}

/**
 * All queued notices of the default "success" type (the type wc_add_notice()
 * uses when none is given, matching core's debug notices), as strings.
 */
function ss_checkout_debug_notice_texts(): array
{
    return array_map(
        fn (array $notice): string => (string) $notice['notice'],
        wc_get_notices('success')
    );
}

beforeEach(function (): void {
    with_ss_settings();

    // Notices live in the WooCommerce session; make sure it exists and is
    // clean before and after every test.
    if (is_null(WC()->cart)) {
        wc_load_cart();
    }
    wc_clear_notices();
    remember_cleanup_callback(function (): void {
        wc_clear_notices();
    });
});

it('adds a checkout notice when shipping debug mode is on', function () {
    with_option('woocommerce_shipping_debug_mode', 'yes');

    SS_Shipping_Checkout_Debug::add_notice('Smart Send checkout debug test notice.');

    expect(ss_checkout_debug_notice_texts())->toBe(['Smart Send checkout debug test notice.']);
});

it('adds no notice when shipping debug mode is off', function () {
    with_option('woocommerce_shipping_debug_mode', 'no');

    SS_Shipping_Checkout_Debug::add_notice('Smart Send checkout debug test notice.');

    expect(wc_notice_count())->toBe(0);
});

it('adds no notice when the option is missing (defaults to off)', function () {
    with_option('woocommerce_shipping_debug_mode', 'no');
    delete_option('woocommerce_shipping_debug_mode');

    SS_Shipping_Checkout_Debug::add_notice('Smart Send checkout debug test notice.');

    expect(wc_notice_count())->toBe(0);
});

it('deduplicates identical messages via wc_has_notice', function () {
    with_option('woocommerce_shipping_debug_mode', 'yes');

    SS_Shipping_Checkout_Debug::add_notice('Repeated debug message.');
    SS_Shipping_Checkout_Debug::add_notice('Repeated debug message.');
    SS_Shipping_Checkout_Debug::add_notice('A different debug message.');

    expect(ss_checkout_debug_notice_texts())->toBe([
        'Repeated debug message.',
        'A different debug message.',
    ]);
});

it('never writes to the Smart Send log', function () {
    // Enable the plugin's debug logging so that IF add_notice logged
    // anything, the spy would record it.
    with_ss_settings(['ss_debug' => 'yes']);
    with_option('woocommerce_shipping_debug_mode', 'yes');

    $spy = ss_checkout_debug_spy_logger();

    SS_Shipping_Checkout_Debug::add_notice('Notice that must not be logged.');

    expect(ss_checkout_debug_notice_texts())->toBe(['Notice that must not be logged.'])
        ->and($spy->entries)->toBe([]);
});
