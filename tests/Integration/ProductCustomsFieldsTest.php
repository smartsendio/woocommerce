<?php

use Smart_Send\Admin\Product;
use Smart_Send\Booking\Order_Reader;

beforeEach(function (): void {
    $post = $_POST;
    $_POST = [];
    remember_cleanup_callback(function () use ($post): void {
        $_POST = $post;
    });
});

/** A persisted product with customs values, loaded through WooCommerce CRUD. */
function product_customs_fixture(): WC_Product
{
    $product = create_simple_product(['weight' => 1.25]);
    $product->update_meta_data('_ss_country_of_origin', 'DK');
    $product->update_meta_data('_ss_customs_desc', 'Cotton shirt');
    $product->update_meta_data('_ss_hs_code', '00123456');
    $product->save();

    return wc_get_product($product->get_id());
}

/** Read all three fields from a newly opened product, not its staged changes. */
function saved_product_customs(int $product_id): array
{
    $product = wc_get_product($product_id);

    return [
        '_ss_country_of_origin' => $product->get_meta('_ss_country_of_origin', true, 'edit'),
        '_ss_customs_desc' => $product->get_meta('_ss_customs_desc', true, 'edit'),
        '_ss_hs_code' => $product->get_meta('_ss_hs_code', true, 'edit'),
    ];
}

/** Render the real WooCommerce controls, restoring the surrounding admin globals. */
function render_product_customs(WC_Product $product): string
{
    require_once WC_ABSPATH . 'includes/admin/wc-meta-box-functions.php';

    $original = [];
    foreach (['post', 'thepostid', 'product_object'] as $name) {
        $original[$name] = [array_key_exists($name, $GLOBALS), $GLOBALS[$name] ?? null];
    }

    $GLOBALS['post'] = get_post($product->get_id());
    $GLOBALS['thepostid'] = 0;
    $GLOBALS['product_object'] = null;
    $post = $GLOBALS['post'];

    ob_start();
    try {
        (new Product())->additional_product_shipping_options();
        $html = ob_get_contents();

        expect($GLOBALS['post'])->toBe($post)
            ->and($GLOBALS['thepostid'])->toBe(0)
            ->and($GLOBALS['product_object'])->toBeNull();

        return $html;
    } finally {
        ob_end_clean();
        foreach ($original as $name => [$existed, $value]) {
            if ($existed) {
                $GLOBALS[$name] = $value;
            } else {
                unset($GLOBALS[$name]);
            }
        }
    }
}

function product_customs_document(string $html): DOMXPath
{
    $document = new DOMDocument();
    $document->loadHTML('<!doctype html><html><body>' . $html . '</body></html>');

    return new DOMXPath($document);
}

it('stages customs fields on the WooCommerce product object until WooCommerce saves it', function () {
    $product = product_customs_fixture();
    $original = saved_product_customs($product->get_id());
    $save_count = 0;
    $observe_save = function ($saving_product) use ($product, &$save_count): void {
        if ($saving_product->get_id() === $product->get_id()) {
            ++$save_count;
        }
    };
    add_action('woocommerce_before_product_object_save', $observe_save);
    remember_cleanup_callback(fn () => remove_action('woocommerce_before_product_object_save', $observe_save));

    $_POST = [
        '_ss_country_of_origin' => 'SE',
        '_ss_customs_desc' => 'Linen shirt',
        '_ss_hs_code' => '00620520',
    ];
    do_action('woocommerce_admin_process_product_object', $product);

    expect($product->get_meta('_ss_country_of_origin', true, 'edit'))->toBe('SE')
        ->and($product->get_meta('_ss_customs_desc', true, 'edit'))->toBe('Linen shirt')
        ->and($product->get_meta('_ss_hs_code', true, 'edit'))->toBe('00620520')
        ->and($save_count)->toBe(0)
        ->and(saved_product_customs($product->get_id()))->toBe($original);

    // WooCommerce's product editor performs this save after the action returns.
    $product->save();

    expect($save_count)->toBe(1)
        ->and(saved_product_customs($product->get_id()))->toBe($_POST);
});

it('uses the product-object save action without retaining the post-ID save callback', function () {
    $callbacks = function (string $hook): array {
        $registered = [];
        foreach (($GLOBALS['wp_filter'][$hook]->callbacks ?? []) as $priority) {
            foreach ($priority as $callback) {
                $function = $callback['function'];
                if (is_array($function) && $function[0] instanceof Product) {
                    $registered[] = $function[1];
                }
            }
        }

        return $registered;
    };

    expect($callbacks('woocommerce_admin_process_product_object'))->toContain('save_additional_product_shipping_options')
        ->and($callbacks('woocommerce_process_product_meta'))->toBeEmpty()
        ->and($callbacks('woocommerce_admin_process_variation_object'))->toBeEmpty();
});

it('unslashes and cleans scalar customs input while retaining leading zeros in the HS code', function () {
    $product = product_customs_fixture();
    $_POST = wp_slash([
        '_ss_country_of_origin' => ' SE ',
        '_ss_customs_desc' => '  <b>Men\'s "cotton" \\ shirts</b>  ',
        '_ss_hs_code' => '  00012345  ',
    ]);

    do_action('woocommerce_admin_process_product_object', $product);
    $product->save();

    expect(saved_product_customs($product->get_id()))->toBe([
        '_ss_country_of_origin' => 'SE',
        '_ss_customs_desc' => 'Men\'s "cotton" \\ shirts',
        '_ss_hs_code' => '00012345',
    ]);
});

it('preserves absent customs fields when saving another product property', function () {
    $product = product_customs_fixture();
    $original = saved_product_customs($product->get_id());
    $product->set_name('Updated product name');
    $_POST = ['_regular_price' => '125'];

    do_action('woocommerce_admin_process_product_object', $product);
    $product->save();

    expect(wc_get_product($product->get_id())->get_name())->toBe('Updated product name')
        ->and(saved_product_customs($product->get_id()))->toBe($original);
});

it('updates an individual customs field without clearing the other two', function () {
    $product = product_customs_fixture();
    $_POST = ['_ss_customs_desc' => 'Replacement description'];

    do_action('woocommerce_admin_process_product_object', $product);
    $product->save();

    expect(saved_product_customs($product->get_id()))->toBe([
        '_ss_country_of_origin' => 'DK',
        '_ss_customs_desc' => 'Replacement description',
        '_ss_hs_code' => '00123456',
    ]);
});

it('clears customs fields only when empty strings are submitted', function () {
    $product = product_customs_fixture();
    $_POST = [
        '_ss_country_of_origin' => '',
        '_ss_customs_desc' => '',
        '_ss_hs_code' => '',
    ];

    do_action('woocommerce_admin_process_product_object', $product);
    $product->save();

    expect(saved_product_customs($product->get_id()))->toBe($_POST);
});

it('ignores non-string customs input and preserves the stored fields', function ($value) {
    $product = product_customs_fixture();
    $original = saved_product_customs($product->get_id());
    $_POST = array_fill_keys(array_keys($original), $value);

    do_action('woocommerce_admin_process_product_object', $product);
    $product->save();

    expect(saved_product_customs($product->get_id()))->toBe($original);
})->with([
    'array' => [['unexpected']],
    'nested array' => [['nested' => ['unexpected']]],
    'integer' => [123456],
    'float' => [1234.5],
    'boolean' => [false],
    'null' => [null],
    'object' => [new stdClass()],
]);

it('preserves the existing country string semantics without adding a whitelist or changing case', function (string $country) {
    $product = product_customs_fixture();
    $_POST = [
        '_ss_country_of_origin' => $country,
        '_ss_customs_desc' => 'Updated shirt',
        '_ss_hs_code' => '00009999',
    ];

    do_action('woocommerce_admin_process_product_object', $product);
    $product->save();

    expect(saved_product_customs($product->get_id()))->toBe([
        '_ss_country_of_origin' => $country,
        '_ss_customs_desc' => 'Updated shirt',
        '_ss_hs_code' => '00009999',
    ]);
})->with(['ZZ', 'Denmark', 'dk']);

it('renders editable product values without applying storefront metadata filters', function () {
    $product = product_customs_fixture();
    $original = saved_product_customs($product->get_id());
    $filtered = [
        '_ss_country_of_origin' => 'SE',
        '_ss_customs_desc' => 'Filtered customs description',
        '_ss_hs_code' => '00009999',
    ];
    foreach ($filtered as $key => $value) {
        $filter = function ($stored, $reading_product) use ($product, $value) {
            return $reading_product->get_id() === $product->get_id() ? $value : $stored;
        };
        add_filter('woocommerce_product_get_' . $key, $filter, 10, 2);
        remember_cleanup_callback(fn () => remove_filter('woocommerce_product_get_' . $key, $filter, 10));
    }

    $document = product_customs_document(render_product_customs($product));

    expect($document->evaluate('string(//select[@id="_ss_country_of_origin"]/option[@selected]/@value)'))->toBe('DK')
        ->and($document->evaluate('string(//input[@id="_ss_customs_desc"]/@value)'))->toBe('Cotton shirt')
        ->and($document->evaluate('string(//input[@id="_ss_hs_code"]/@value)'))->toBe('00123456')
        ->and(saved_product_customs($product->get_id()))->toBe($original);
});

it('renders WooCommerce product metadata even when direct post-meta reads return different values', function () {
    $product = product_customs_fixture();
    $original = saved_product_customs($product->get_id());
    $read = function ($value, $post_id, $key) use ($product) {
        $raw_values = [
            '_ss_country_of_origin' => 'NO',
            '_ss_customs_desc' => 'Value from raw post metadata',
            '_ss_hs_code' => '00009999',
        ];
        if ($post_id === $product->get_id() && isset($raw_values[$key])) {
            return $raw_values[$key];
        }

        return $value;
    };
    add_filter('get_post_metadata', $read, 10, 3);
    remember_cleanup_callback(fn () => remove_filter('get_post_metadata', $read));

    $document = product_customs_document(render_product_customs($product));

    expect(get_post_meta($product->get_id(), '_ss_hs_code', true))->toBe('00009999')
        ->and($document->evaluate('string(//select[@id="_ss_country_of_origin"]/option[@selected]/@value)'))->toBe('DK')
        ->and($document->evaluate('string(//input[@id="_ss_customs_desc"]/@value)'))->toBe('Cotton shirt')
        ->and($document->evaluate('string(//input[@id="_ss_hs_code"]/@value)'))->toBe('00123456')
        ->and(saved_product_customs($product->get_id()))->toBe($original);
});

it('escapes stored customs text through the WooCommerce form controls', function () {
    $product = product_customs_fixture();
    $description = 'Shirt " onfocus="alert(1) & <script>alert(2)</script>';
    $hs_code = '001234" autofocus onfocus="alert(3)';
    $product->update_meta_data('_ss_customs_desc', $description);
    $product->update_meta_data('_ss_hs_code', $hs_code);
    $product->save();

    $html = render_product_customs($product);
    $document = product_customs_document($html);

    expect($document->evaluate('string(//input[@id="_ss_customs_desc"]/@value)'))->toBe($description)
        ->and($document->evaluate('string(//input[@id="_ss_hs_code"]/@value)'))->toBe($hs_code)
        ->and($document->query('//script|//*[@onfocus]|//*[@autofocus]')->length)->toBe(0)
        ->and($html)->toContain('&lt;script&gt;', '&quot;');
});

it('saves parent customs data without copying the parent POST into its variations', function () {
    [$parent, $variation] = create_variable_product(['weight' => 2.5]);
    $variation->update_meta_data('_ss_hs_code', '00987654');
    $variation->update_meta_data('_ss_customs_desc', '');
    $variation->update_meta_data('_ss_country_of_origin', '');
    $variation->save();
    $_POST = [
        '_ss_country_of_origin' => 'SE',
        '_ss_customs_desc' => 'Parent customs description',
        '_ss_hs_code' => '00123456',
    ];

    do_action('woocommerce_admin_process_product_object', $parent);
    $parent->save();
    do_action('woocommerce_admin_process_variation_object', $variation, 0);
    $variation->save();

    expect(saved_product_customs($parent->get_id()))->toBe($_POST)
        ->and(saved_product_customs($variation->get_id()))->toBe([
            '_ss_country_of_origin' => '',
            '_ss_customs_desc' => '',
            '_ss_hs_code' => '00987654',
        ]);

    $order = create_order(['products' => [$variation]]);
    $row = (new Order_Reader(wc_get_order($order->get_id())))->get_items_data()[0];

    expect($row['hs_code'])->toBe('00987654')
        ->and($row['description'])->toBe('Parent customs description')
        ->and($row['country_of_origin'])->toBe('SE')
        ->and($row['unit_weight'])->toBe(2.5)
        ->and($row['product_missing'])->toBeFalse();
});
