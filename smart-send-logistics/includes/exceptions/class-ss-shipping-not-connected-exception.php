<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Thrown by SS_Shipping_Pickup_Point_Lookup when the plugin has no API
 * token configured (not connected to Smart Send) - the lookup is skipped
 * entirely, no API call is made.
 *
 * @package  SS_Shipping_Not_Connected_Exception
 * @category Shipping
 * @author   Smart Send
 */

// A second copy of the plugin may already have defined the class.
if ( ! class_exists( 'SS_Shipping_Not_Connected_Exception' ) ) :

	class SS_Shipping_Not_Connected_Exception extends \Exception {
	}

endif;
