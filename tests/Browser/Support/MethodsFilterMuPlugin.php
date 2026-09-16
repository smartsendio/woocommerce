<?php
/**
 * Plugin Name: Smart Send browser test - shipping methods filter
 * Description: A merchant snippet for the Browser suite: narrows the method drop-downs of the order screen's "Smart Send" box through the public smart_send_fulfillment_shipping_methods filter. Installed and removed per test by ss_browser_install_methods_filter() / ss_browser_remove_methods_filter(); what it keeps comes from the ss_test_methods_filter option, per direction:
 *
 *     array(
 *         'outbound' => array( 'carriers' => array( 'postnord' ), 'services' => array( 'agent', 'homedelivery' ) ),
 *         'return'   => array( 'carriers' => array( 'gls' ) ),
 *     )
 *
 * A direction the option does not mention is left untouched; 'services' is optional (every service of the kept carriers survives without it).
 *
 * @package smart-send-logistics
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter(
	'smart_send_fulfillment_shipping_methods',
	function ( $carriers, $order, $is_return ) {
		$config = get_option( 'ss_test_methods_filter', array() );
		$config = is_array( $config ) && isset( $config[ $is_return ? 'return' : 'outbound' ] )
			? $config[ $is_return ? 'return' : 'outbound' ]
			: null;

		if ( ! is_array( $config ) || ! isset( $config['carriers'] ) ) {
			return $carriers;
		}

		$kept = array();

		foreach ( $carriers as $carrier ) {
			if ( ! in_array( $carrier['code'], (array) $config['carriers'], true ) ) {
				continue;
			}

			if ( isset( $config['services'] ) ) {
				$carrier['services'] = array_values(
					array_filter(
						$carrier['services'],
						function ( $service ) use ( $config ) {
							return in_array( $service['code'], (array) $config['services'], true );
						}
					)
				);
			}

			$kept[] = $carrier;
		}

		return $kept;
	},
	10,
	3
);
