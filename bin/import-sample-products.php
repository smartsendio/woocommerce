<?php
/**
 * Import sample-data/products.csv into WooCommerce via `wp eval-file`.
 *
 * Usage: wp eval-file bin/import-sample-products.php <path-to-products.csv>
 *
 * Uses WooCommerce's own CSV importer so categories, attributes, variations
 * and grouped/external products are created exactly as the admin importer
 * would. The Images column contains bare filenames which the importer matches
 * against the media library - bin/setup-local-dev.sh imports
 * sample-data/images/ into the media library first.
 */

if ( empty( $args[0] ) || ! file_exists( $args[0] ) ) {
	WP_CLI::error( 'Usage: wp eval-file bin/import-sample-products.php <path-to-products.csv>' );
}
$csv_file = $args[0];

if ( ! class_exists( 'WC_Product_CSV_Importer' ) ) {
	include_once WC_ABSPATH . 'includes/import/abstract-wc-product-importer.php';
	include_once WC_ABSPATH . 'includes/import/class-wc-product-csv-importer.php';
}

// Explicit header -> product-property mapping (the auto-mapper lives in an
// admin-screen controller; hardcoding keeps this deterministic).
$mapping = array(
	'ID'                       => 'id',
	'Type'                     => 'type',
	'SKU'                      => 'sku',
	'Name'                     => 'name',
	'Published'                => 'published',
	'Is featured?'             => 'featured',
	'Visibility in catalog'    => 'catalog_visibility',
	'Short description'        => 'short_description',
	'Description'              => 'description',
	'Date sale price starts'   => 'date_on_sale_from',
	'Date sale price ends'     => 'date_on_sale_to',
	'Tax status'               => 'tax_status',
	'Tax class'                => 'tax_class',
	'In stock?'                => 'stock_status',
	'Stock'                    => 'stock_quantity',
	'Backorders allowed?'      => 'backorders',
	'Sold individually?'       => 'sold_individually',
	'Weight (kg)'              => 'weight',
	'Length (cm)'              => 'length',
	'Width (cm)'               => 'width',
	'Height (cm)'              => 'height',
	'Allow customer reviews?'  => 'reviews_allowed',
	'Purchase note'            => 'purchase_note',
	'Sale price'               => 'sale_price',
	'Regular price'            => 'regular_price',
	'Categories'               => 'category_ids',
	'Tags'                     => 'tag_ids',
	'Shipping class'           => 'shipping_class_id',
	'Images'                   => 'images',
	'Download limit'           => 'download_limit',
	'Download expiry days'     => 'download_expiry',
	'Parent'                   => 'parent_id',
	'Grouped products'         => 'grouped_products',
	'Upsells'                  => 'upsell_ids',
	'Cross-sells'              => 'cross_sell_ids',
	'External URL'             => 'product_url',
	'Button text'              => 'button_text',
	'Position'                 => 'menu_order',
	'Attribute 1 name'         => 'attributes:name1',
	'Attribute 1 value(s)'     => 'attributes:value1',
	'Attribute 1 visible'      => 'attributes:visible1',
	'Attribute 1 global'       => 'attributes:taxonomy1',
	'Attribute 2 name'         => 'attributes:name2',
	'Attribute 2 value(s)'     => 'attributes:value2',
	'Attribute 2 visible'      => 'attributes:visible2',
	'Attribute 2 global'       => 'attributes:taxonomy2',
	'Meta: _wpcom_is_markdown' => 'meta:_wpcom_is_markdown',
	'Download 1 name'          => 'downloads:name1',
	'Download 1 URL'           => 'downloads:url1',
	'Download 2 name'          => 'downloads:name2',
	'Download 2 URL'           => 'downloads:url2',
);

$importer = new WC_Product_CSV_Importer(
	$csv_file,
	array(
		'mapping'          => $mapping,
		'parse'            => true,
		'update_existing'  => false,
		'prevent_timeouts' => false,
	)
);

$results = $importer->import();

WP_CLI::log(
	sprintf(
		'Imported %d products (%d updated, %d skipped, %d failed).',
		count( $results['imported'] ),
		count( $results['updated'] ),
		count( $results['skipped'] ),
		count( $results['failed'] )
	)
);
foreach ( $results['failed'] as $error ) {
	WP_CLI::warning( $error->get_error_message() );
}
if ( count( $results['failed'] ) > 0 ) {
	exit( 1 );
}

// Give every product category a thumbnail (Storefront's homepage "Shop by
// Category" section shows grey placeholders otherwise): reuse the image of
// the first product found in the category.
$terms = get_terms(
	array(
		'taxonomy'   => 'product_cat',
		'hide_empty' => true,
	)
);
foreach ( $terms as $term ) {
	if ( get_term_meta( $term->term_id, 'thumbnail_id', true ) ) {
		continue;
	}
	$products = wc_get_products(
		array(
			'category' => array( $term->slug ),
			'limit'    => 10,
		)
	);
	foreach ( $products as $product ) {
		$image_id = $product->get_image_id();
		if ( $image_id ) {
			update_term_meta( $term->term_id, 'thumbnail_id', $image_id );
			WP_CLI::log( sprintf( 'Category thumbnail set: %s', $term->name ) );
			break;
		}
	}
}
