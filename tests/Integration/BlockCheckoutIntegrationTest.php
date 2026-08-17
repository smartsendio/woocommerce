<?php

/*
 * Tests for the Checkout Block integration skeleton (PR 1 of issue #74):
 * SS_Shipping_Block_Checkout registers with the WooCommerce Blocks
 * integration registry, its built scripts exist in build/ and register with
 * their generated *.asset.php metadata, and the plugin declares
 * cart_checkout_blocks compatibility (which removes the block-editor
 * incompatibility warning).
 */

use Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface;
use Automattic\WooCommerce\Blocks\Integrations\IntegrationRegistry;
use Automattic\WooCommerce\Utilities\FeaturesUtil;

it('constructs the block checkout integration component', function () {
    expect(SS_SHIPPING_WC()->block_checkout())
        ->toBeInstanceOf(SS_Shipping_Block_Checkout::class)
        ->toBeInstanceOf(IntegrationInterface::class)
        ->and(SS_SHIPPING_WC()->block_checkout()->get_name())->toBe('smart-send');
});

it('registers with the Checkout block integration registry when the registration action fires', function () {
    $registry = new IntegrationRegistry();

    // The Checkout block fires this action when it initializes its
    // integration registry; the component's hook must be listening.
    do_action('woocommerce_blocks_checkout_block_registration', $registry);

    expect($registry->is_registered('smart-send'))->toBeTrue()
        ->and($registry->get_registered('smart-send'))
        ->toBe(SS_SHIPPING_WC()->block_checkout());
});

it('declares cart_checkout_blocks compatibility', function () {
    $compatible = FeaturesUtil::get_compatible_plugins_for_feature('cart_checkout_blocks');

    expect($compatible['compatible'])->toContain(
        plugin_basename(SS_SHIPPING_PLUGIN_FILE)
    );
});

it('still declares HPOS compatibility alongside the blocks declaration', function () {
    $compatible = FeaturesUtil::get_compatible_plugins_for_feature('custom_order_tables');

    expect($compatible['compatible'])->toContain(
        plugin_basename(SS_SHIPPING_PLUGIN_FILE)
    );
});

it('ships a built bundle and asset file for every exposed script handle', function () {
    $entries = [
        SS_Shipping_Block_Checkout::HANDLE_FRONTEND => 'pickup-point-block/frontend',
        SS_Shipping_Block_Checkout::HANDLE_EDITOR   => 'pickup-point-block/index',
    ];

    $integration = SS_SHIPPING_WC()->block_checkout();

    expect($integration->get_script_handles())->toBe([SS_Shipping_Block_Checkout::HANDLE_FRONTEND])
        ->and($integration->get_editor_script_handles())->toBe([SS_Shipping_Block_Checkout::HANDLE_EDITOR]);

    foreach ($entries as $entry) {
        $js    = SS_SHIPPING_PLUGIN_DIR_PATH . '/build/' . $entry . '.js';
        $asset = SS_SHIPPING_PLUGIN_DIR_PATH . '/build/' . $entry . '.asset.php';

        expect(file_exists($js))->toBeTrue("Missing built bundle: {$js}")
            ->and(file_exists($asset))->toBeTrue("Missing asset file: {$asset}");

        $meta = require $asset;

        expect($meta)->toHaveKeys(['dependencies', 'version'])
            ->and($meta['dependencies'])->toBeArray()
            ->and($meta['version'])->toBeString()->not->toBe('');
    }
});

it('initialize() registers the built scripts with their asset metadata', function () {
    $integration = SS_SHIPPING_WC()->block_checkout();

    $integration->initialize();

    remember_cleanup_callback(function (): void {
        wp_deregister_script(SS_Shipping_Block_Checkout::HANDLE_FRONTEND);
        wp_deregister_script(SS_Shipping_Block_Checkout::HANDLE_EDITOR);
    });

    foreach ([SS_Shipping_Block_Checkout::HANDLE_FRONTEND, SS_Shipping_Block_Checkout::HANDLE_EDITOR] as $handle) {
        expect(wp_script_is($handle, 'registered'))->toBeTrue("Script not registered: {$handle}");

        $script = wp_scripts()->registered[$handle];

        // Version comes from the generated *.asset.php content hash, and the
        // dependency-extraction plugin turned the @wordpress/i18n import of
        // the placeholder entries into a wp-i18n dependency.
        expect($script->ver)->toBeString()->not->toBe('')
            ->and($script->deps)->toContain('wp-i18n')
            ->and($script->src)->toContain('/smart-send-logistics/build/pickup-point-block/');
    }
});

it('opts the pickup point block into the Blocks data-attribute pass', function () {
    // WooCommerce's render_block filter only adds data-block-name (and the
    // saved attributes as data-* attributes) to woocommerce/* blocks by
    // default; the frontend cannot map our saved placeholder div to the
    // React component without this opt-in.
    $allowlist = apply_filters('__experimental_woocommerce_blocks_add_data_attributes_to_block', []);

    expect($allowlist)->toContain(SS_Shipping_Block_Checkout::BLOCK_NAME)
        ->and(SS_Shipping_Block_Checkout::BLOCK_NAME)->toBe('smart-send/pickup-point-block');
});

it('ships the block.json metadata in the built output', function () {
    $block_json = SS_SHIPPING_PLUGIN_DIR_PATH . '/build/pickup-point-block/block.json';

    expect(file_exists($block_json))->toBeTrue("Missing built block.json: {$block_json}");

    $metadata = json_decode((string) file_get_contents($block_json), true);

    expect($metadata['name'])->toBe(SS_Shipping_Block_Checkout::BLOCK_NAME)
        ->and($metadata['parent'])->toBe(['woocommerce/checkout-shipping-methods-block'])
        // The lock default is load-bearing: the checkout registry derives
        // the frontend force-render flag from it, and the editor's
        // forced-layout pass auto-inserts (and refuses to remove) blocks
        // carrying it.
        ->and($metadata['attributes']['lock']['default']['remove'])->toBeTrue();
});

it('exposes minimal script data for the client', function () {
    expect(SS_SHIPPING_WC()->block_checkout()->get_script_data())
        ->toBe(['pluginVersion' => SS_SHIPPING_VERSION]);
});
