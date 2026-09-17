<?php

namespace Smart_Send\Exceptions;

use Smart_Send\Delivery_Options\Pickup_Point_Lookup;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Thrown by Pickup_Point_Lookup when the plugin has no API
 * token configured (not connected to Smart Send) - the lookup is skipped
 * entirely, no API call is made.
 *
 * @package Smart_Send
 * @category Shipping
 * @author   Smart Send
 */

class Not_Connected_Exception extends \Exception {
}
