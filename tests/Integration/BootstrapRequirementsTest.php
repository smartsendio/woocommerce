<?php

use Symfony\Component\Process\Process;

function isolated_plugin_bootstrap(string $version, bool $supported): array
{
    $process = new Process([
        PHP_BINARY,
        dirname(__DIR__) . '/Support/Bootstrap/wordpress.php',
        $version,
        $supported ? 'supported' : 'unsupported',
    ]);
    $process->mustRun();

    return json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
}

it('keeps WooCommerce integrations unloaded when the dependency is missing or unsupported', function (string $version) {
    $result = isolated_plugin_bootstrap($version, false);

    expect($result['early_shipping_class_loaded'])->toBeFalse()
        ->and($result['early_blocks_class_loaded'])->toBeFalse()
        ->and($result['shipping_methods'])->toBe(['other_shipping' => 'Other_Shipping_Method'])
        ->and($result['shipping_class_loaded'])->toBeFalse()
        ->and($result['blocks_class_loaded'])->toBeFalse()
        ->and($result['feature_hooks_registered'])->toBeFalse()
        ->and($result['compatibility'])->toBe([])
        ->and($result['notice'])->toBe('Smart Send requires WooCommerce 8.2.0 or newer to be installed and activated.');
})->with(['missing', '2.5.9', '2.6.0', '8.1.9', '8.2.0-rc.1']);

it('loads WooCommerce integrations at and above the supported minimum', function (string $version) {
    $result = isolated_plugin_bootstrap($version, true);

    expect($result['early_shipping_class_loaded'])->toBeFalse()
        ->and($result['early_blocks_class_loaded'])->toBeFalse()
        ->and($result['shipping_methods'])->toBe([
        'other_shipping' => 'Other_Shipping_Method',
        'smart_send_shipping' => \Smart_Send\Shipping_Method\Method::class,
    ])
        ->and($result['shipping_class_loaded'])->toBeTrue()
        ->and($result['blocks_class_loaded'])->toBeTrue()
        ->and($result['feature_hooks_registered'])->toBeTrue()
        ->and($result['compatibility'])->toBe([
            'custom_order_tables' => true,
            'cart_checkout_blocks' => true,
        ])
        ->and($result['notice'])->toBe('');
})->with(['8.2.0', '8.2.1', '11.1.0']);

it('declares matching WordPress PHP and WooCommerce support floors', function () {
    $plugin = get_file_data(SS_SHIPPING_PLUGIN_FILE, [
        'wordpress' => 'Requires at least',
        'php' => 'Requires PHP',
        'woocommerce' => 'WC requires at least',
        'dependency' => 'Requires Plugins',
    ]);
    $readme = get_file_data(dirname(SS_SHIPPING_PLUGIN_FILE) . '/readme.txt', [
        'wordpress' => 'Requires at least',
        'php' => 'Requires PHP',
        'woocommerce' => 'WC requires at least',
        'dependency' => 'Requires Plugins',
    ]);

    expect($plugin)->toBe([
        'wordpress' => '6.5',
        'php' => '7.4',
        'woocommerce' => '8.2.0',
        'dependency' => 'woocommerce',
    ])->and($readme)->toBe($plugin);
});
