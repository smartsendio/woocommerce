<?php

/*
 * Exercise the existing product Shipping tab through WooCommerce's real
 * save flow. Sanitization and missing-field cases live in Integration;
 * these journeys prove the three fields survive saving and reopening,
 * accept empty values, and do not overwrite a variation's own metadata.
 */

function ss_product_customs_cleanup(): void
{
    ss_browser_wp_eval(<<<'PHP'
$ids = get_option('ss_browser_product_customs_ids', array());
foreach (array_reverse($ids) as $id) {
    $product = wc_get_product($id);
    if ($product) {
        $product->delete(true);
    }
}
delete_option('ss_browser_product_customs_ids');
$editor = get_option('ss_browser_product_customs_editor_state');
if (is_array($editor)) {
    delete_user_meta($editor['user_id'], 'rich_editing');
    foreach ($editor['rich_editing'] as $value) {
        add_user_meta($editor['user_id'], 'rich_editing', $value);
    }
    delete_option('ss_browser_product_customs_editor_state');
}
echo json_encode(array('cleaned' => true));
PHP);
}

function ss_product_customs_edit_url(int $product_id): string
{
    return base_url('/wp-admin/post.php?post=' . $product_id . '&action=edit');
}

function ss_product_customs_open_shipping_tab($page): void
{
    // WooCommerce initializes its tabs and product type in separate ready
    // callbacks, both of which select General. Wait until both have run so
    // late initialization cannot reset the tab while we edit its fields.
    ss_wait_for_script($page, <<<'JS'
        (function () {
            if (!window.jQuery) { return false; }
            var tab = document.querySelector('a[href="#shipping_product_data"]');
            var virtual = document.querySelector('#_virtual');
            if (!tab || !virtual) { return false; }
            var tabEvents = jQuery._data(tab, 'events') || {};
            var virtualEvents = jQuery._data(virtual, 'events') || {};
            return Boolean(tabEvents.click && virtualEvents.change);
        })()
        JS
    );

    $page->click('a[href="#shipping_product_data"]')
        ->assertVisible('#shipping_product_data');
}

function ss_product_customs_save_and_reopen($page, int $product_id): void
{
    $page->click('#publish')
        ->assertSeeIn('#message', 'Product updated.')
        ->navigate(base_url('/wp-admin/edit.php?post_type=product'))
        ->navigate(ss_product_customs_edit_url($product_id));

    ss_product_customs_open_shipping_tab($page);
}

beforeAll(function (): void {
    if (!ss_browser_store_manageable()) {
        return;
    }

    // Recorded IDs also let a subsequent run recover an interrupted one.
    ss_product_customs_cleanup();

    // The description editor is unrelated to these fields. Use WordPress's
    // supported plain-text preference to avoid TinyMCE iframe load events
    // racing Pest's navigation, and restore even an originally absent value.
    $admin_login = var_export(admin_username(), true);
    ss_browser_wp_eval(<<<PHP
\$admin = get_user_by('login', {$admin_login});
if (!\$admin) {
    throw new RuntimeException('Browser test administrator does not exist.');
}
update_option('ss_browser_product_customs_editor_state', array(
    'user_id' => \$admin->ID,
    'rich_editing' => get_user_meta(\$admin->ID, 'rich_editing', false),
));
update_user_meta(\$admin->ID, 'rich_editing', 'false');
echo json_encode(array('configured' => true));
PHP);

    $GLOBALS['ss_product_customs_ids'] = ss_browser_wp_eval(<<<'PHP'
$ids = array();
$remember = function ($key, $product) use (&$ids) {
    $product->save();
    $ids[$key] = $product->get_id();
    update_option('ss_browser_product_customs_ids', $ids);
};

$simple = new WC_Product_Simple();
$simple->set_name('SS Browser Customs Simple Product');
$simple->set_status('publish');
$simple->set_regular_price('100');
$remember('simple', $simple);

$attribute = new WC_Product_Attribute();
$attribute->set_name('Colour');
$attribute->set_options(array('Blue'));
$attribute->set_visible(true);
$attribute->set_variation(true);

$parent = new WC_Product_Variable();
$parent->set_name('SS Browser Customs Variable Product');
$parent->set_status('publish');
$parent->set_attributes(array($attribute));
$parent->update_meta_data('_ss_country_of_origin', 'PL');
$parent->update_meta_data('_ss_customs_desc', 'Parent customs description');
$parent->update_meta_data('_ss_hs_code', '61102091');
$remember('variable', $parent);

$variation = new WC_Product_Variation();
$variation->set_parent_id($parent->get_id());
$variation->set_attributes(array('colour' => 'Blue'));
$variation->set_regular_price('100');
$variation->update_meta_data('_ss_country_of_origin', 'SE');
$variation->update_meta_data('_ss_customs_desc', 'Variation-specific cotton shirt');
$variation->update_meta_data('_ss_hs_code', '61091010');
$remember('variation', $variation);

echo json_encode($ids);
PHP);
});

afterAll(function (): void {
    if (ss_browser_store_manageable()) {
        ss_product_customs_cleanup();
    }
    unset($GLOBALS['ss_product_customs_ids']);
});

beforeEach(function (): void {
    ss_browser_skip_unless_store_manageable($this);
});

it('saves and clears simple product customs fields through the Shipping tab', function () {
    $product_id = $GLOBALS['ss_product_customs_ids']['simple'];
    $page = login_as_admin()
        ->navigate(ss_product_customs_edit_url($product_id));

    ss_product_customs_open_shipping_tab($page);

    $page->assertSeeIn('#shipping_product_data', 'Country of origin')
        ->assertSeeIn('#shipping_product_data', 'Customs description')
        ->assertSeeIn('#shipping_product_data', 'Harmonized Tariff Schedule')
        ->select('#_ss_country_of_origin', 'DK')
        ->fill('#_ss_customs_desc', 'Cotton T-shirt')
        ->fill('#_ss_hs_code', '61091000');

    ss_product_customs_save_and_reopen($page, $product_id);

    $page->assertValue('#_ss_country_of_origin', 'DK')
        ->assertValue('#_ss_customs_desc', 'Cotton T-shirt')
        ->assertValue('#_ss_hs_code', '61091000')
        ->select('#_ss_country_of_origin', '')
        ->fill('#_ss_customs_desc', '')
        ->fill('#_ss_hs_code', '');

    ss_product_customs_save_and_reopen($page, $product_id);

    $page->assertValue('#_ss_country_of_origin', '')
        ->assertValue('#_ss_customs_desc', '')
        ->assertValue('#_ss_hs_code', '');
});

it('saves variable parent customs fields without changing variation overrides', function () {
    $ids = $GLOBALS['ss_product_customs_ids'];
    $page = login_as_admin()
        ->navigate(ss_product_customs_edit_url($ids['variable']));

    ss_product_customs_open_shipping_tab($page);

    $page->assertValue('#_ss_country_of_origin', 'PL')
        ->assertValue('#_ss_customs_desc', 'Parent customs description')
        ->assertValue('#_ss_hs_code', '61102091')
        ->select('#_ss_country_of_origin', 'DK')
        ->fill('#_ss_customs_desc', 'Cotton T-shirt')
        ->fill('#_ss_hs_code', '61091000');

    ss_product_customs_save_and_reopen($page, $ids['variable']);

    $page->assertValue('#_ss_country_of_origin', 'DK')
        ->assertValue('#_ss_customs_desc', 'Cotton T-shirt')
        ->assertValue('#_ss_hs_code', '61091000');

    // The variation has no customs UI of its own. Read its saved object
    // after the parent's real form submission to detect accidental writes.
    $variation_id = (int) $ids['variation'];
    $saved = ss_browser_wp_eval(<<<PHP
\$variation = wc_get_product({$variation_id});
echo json_encode(array(
    'parent_id' => \$variation->get_parent_id(),
    'country' => \$variation->get_meta('_ss_country_of_origin', true, 'edit'),
    'description' => \$variation->get_meta('_ss_customs_desc', true, 'edit'),
    'hs_code' => \$variation->get_meta('_ss_hs_code', true, 'edit'),
));
PHP);

    expect($saved['parent_id'])->toBe($ids['variable'])
        ->and($saved['country'])->toBe('SE')
        ->and($saved['description'])->toBe('Variation-specific cotton shirt')
        ->and($saved['hs_code'])->toBe('61091010');
});
