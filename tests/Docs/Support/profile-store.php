<?php

// WP-CLI snippet: common fixture values are identical locally and in CI.
$profile = json_decode(file_get_contents(dirname(__DIR__) . '/profile.json'), true);
$locale = $profile['locales'][$args[0]];
$options = array(
    'WPLANG' => $locale,
    'blogname' => 'Nordic Example Shop',
    'timezone_string' => $profile['timezone'],
    'woocommerce_currency' => 'DKK',
    'woocommerce_currency_pos' => 'right_space',
    'woocommerce_price_decimal_sep' => ',',
    'woocommerce_price_thousand_sep' => '.',
    'woocommerce_price_num_decimals' => '2',
    'woocommerce_calc_taxes' => 'yes',
    'woocommerce_prices_include_tax' => 'no',
    'woocommerce_tax_display_shop' => 'incl',
    'woocommerce_tax_display_cart' => 'incl',
    'woocommerce_tax_based_on' => 'shipping',
    'woocommerce_default_country' => 'DK',
    'woocommerce_default_customer_address' => 'base',
    'woocommerce_store_postcode' => '2300',
    'woocommerce_store_city' => 'Copenhagen',
    'woocommerce_weight_unit' => 'kg',
    'woocommerce_dimension_unit' => 'cm',
    'woocommerce_allowed_countries' => 'all',
    'woocommerce_ship_to_countries' => '',
);
$admin = get_user_by('login', 'admin');
$snapshot = array('options' => array(), 'admin_locale' => get_user_meta($admin->ID, 'locale', true));
foreach ($options as $key => $value) {
    $snapshot['options'][$key] = get_option($key);
}
update_option('ss_docs_snapshot', $snapshot);
foreach ($options as $key => $value) {
    update_option($key, $value);
}
update_user_meta($admin->ID, 'locale', $locale);
update_option('ss_docs_active', true);
delete_option('ss_docs_booking_count');

// This store is dedicated to Docs. Provision a single standard Danish rate.
foreach (WC_Tax::get_rates_for_tax_class('') as $rate) {
    WC_Tax::_delete_tax_rate($rate->tax_rate_id);
}
WC_Tax::_insert_tax_rate(array(
    'tax_rate_country' => 'DK', 'tax_rate_state' => '', 'tax_rate' => '25.0000',
    'tax_rate_name' => $args[0] === 'da' ? 'Moms' : 'VAT', 'tax_rate_priority' => 1,
    'tax_rate_compound' => 0, 'tax_rate_shipping' => 1, 'tax_rate_order' => 0, 'tax_rate_class' => '',
));
WC_Cache_Helper::invalidate_cache_group('taxes');
echo json_encode(array('locale' => $locale));
