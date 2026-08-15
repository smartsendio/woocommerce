<?php
/**
 * Plugin Name: Smart Send Shipping for WooCommerce
 * Plugin URI: https://wordpress.org/plugins/smart-send-logistics/
 * Description: Smart Send Shipping for WooCommerce
 * Author: Smart Send ApS
 * Author URI: https://www.smartsend.io
 * Text Domain: smart-send-logistics
 * Version: 8.2.0
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * WC requires at least: 4.7.0
 * WC tested up to: 11.0
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// The plugin entry file, from which WordPress derives the plugin's basename
// and directory paths. The composition root (SS_Shipping_WC) lives in
// includes/class-ss-shipping-wc.php and reads this constant wherever it
// historically used __FILE__.
if ( ! defined( 'SS_SHIPPING_PLUGIN_FILE' ) ) {
	define( 'SS_SHIPPING_PLUGIN_FILE', __FILE__ );
}

require_once plugin_dir_path( __FILE__ ) . 'includes/class-ss-shipping-wc.php';

/**
 * The global accessor to the plugin singleton - the stable entry point for
 * merchant code snippets and the test suites. Its surface is the component
 * accessors plus the bootstrap.
 *
 * @return SS_Shipping_WC
 */
function SS_SHIPPING_WC() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- pre-existing global accessor name, frozen public API.
	return SS_Shipping_WC::instance();
}

$SS_Shipping_WC = SS_SHIPPING_WC(); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- pre-existing global variable name, kept for backwards compatibility.
