<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Smart Send checkout options.
 *
 * The single place that decides which delivery-option sections the
 * CHECKOUT renders for a shipping method - deliberately checkout-scoped
 * (an admin-facing equivalent could diverge later), and deliberately not
 * on SS_Shipping_Method_Code, which expresses the booking service, not
 * checkout presentation. Today the only option is the pickup point
 * section; future options (delivery time windows, flexible delivery
 * locations) get their own show_*() method here.
 *
 * The class also owns the pickup point section's status vocabulary: the
 * PICKUP_POINT_STATUS_* slugs both checkout surfaces (classic and block) derive from
 * the lookup outcome, and the customer-facing text for each - one home
 * for the strings, so the two surfaces can never drift apart.
 *
 * @package  SS_Shipping_Checkout_Options
 * @category Shipping
 * @author   Smart Send
 */

// A second copy of the plugin may already have defined the class.
if ( ! class_exists( 'SS_Shipping_Checkout_Options' ) ) :

	class SS_Shipping_Checkout_Options {

		/**
		 * The lookup ran and found pickup points - the selector renders and
		 * a selection is required.
		 */
		const PICKUP_POINT_STATUS_FOUND = 'found';

		/**
		 * The shipping address is incomplete (no country, postcode or
		 * street) - no lookup ran.
		 */
		const PICKUP_POINT_STATUS_ADDRESS_INCOMPLETE = 'address_incomplete';

		/**
		 * The plugin has no API token configured - no lookup can run.
		 */
		const PICKUP_POINT_STATUS_NOT_CONNECTED = 'not_connected';

		/**
		 * The API rejected the lookup as unauthenticated (HTTP 401) - the
		 * token is wrong or revoked.
		 */
		const PICKUP_POINT_STATUS_AUTH_FAILED = 'auth_failed';

		/**
		 * The API rejected the lookup as unauthorized (HTTP 403) - the
		 * account has no access to pickup points.
		 */
		const PICKUP_POINT_STATUS_ACCESS_DENIED = 'access_denied';

		/**
		 * The lookup ran and found no pickup points near the address
		 * (typically an address the geocoder cannot resolve).
		 */
		const PICKUP_POINT_STATUS_NONE_FOUND = 'none_found';

		/**
		 * The lookup failed for any other reason (transport error, server
		 * error, unexpected response).
		 */
		const PICKUP_POINT_STATUS_LOOKUP_FAILED = 'lookup_failed';

		/**
		 * Whether the checkout should render the pickup point section for
		 * this shipping method.
		 *
		 * @param SS_Shipping_Method_Code $method_code The selected shipping method's code.
		 *
		 * @return bool
		 */
		public function show_pickup_points( SS_Shipping_Method_Code $method_code ): bool {
			return stripos( $method_code->type(), 'agent' ) !== false;
		}

		/**
		 * Map a pickup point lookup failure to its PICKUP_POINT_STATUS_* slug. Shared by
		 * both checkout surfaces so a given exception always renders as the
		 * same state.
		 *
		 * @param \Exception $e The exception thrown by the lookup.
		 *
		 * @return string One of the PICKUP_POINT_STATUS_* constants.
		 */
		public function pickup_point_status_for_exception( \Exception $e ): string {
			if ( $e instanceof SS_Shipping_Not_Connected_Exception ) {
				return self::PICKUP_POINT_STATUS_NOT_CONNECTED;
			}
			if ( $e instanceof \Smartsend\Exceptions\UnauthenticatedException ) {
				return self::PICKUP_POINT_STATUS_AUTH_FAILED;
			}
			if ( $e instanceof \Smartsend\Exceptions\ForbiddenException ) {
				return self::PICKUP_POINT_STATUS_ACCESS_DENIED;
			}

			return self::PICKUP_POINT_STATUS_LOOKUP_FAILED;
		}

		/**
		 * The customer-facing text of a pickup point section status, or null
		 * for PICKUP_POINT_STATUS_FOUND (the selector renders instead of a message).
		 *
		 * @param string $status One of the PICKUP_POINT_STATUS_* constants.
		 *
		 * @return string|null
		 */
		public function pickup_point_status_message( string $status ): ?string {
			switch ( $status ) {
				case self::PICKUP_POINT_STATUS_ADDRESS_INCOMPLETE:
					return __( 'Enter your shipping address to see available pickup points.', 'smart-send-logistics' );
				case self::PICKUP_POINT_STATUS_NOT_CONNECTED:
					return __( 'Connect the Smart Send plugin to enable pickup points.', 'smart-send-logistics' );
				case self::PICKUP_POINT_STATUS_AUTH_FAILED:
					return __( 'The shop is not correctly connected with Smart Send.', 'smart-send-logistics' );
				case self::PICKUP_POINT_STATUS_ACCESS_DENIED:
					return __( 'The shop does not have access to pickup points.', 'smart-send-logistics' );
				case self::PICKUP_POINT_STATUS_NONE_FOUND:
					return __( 'We could not find available pickup points. Please check that the entered address is correct. Your order will be shipped to the closest possible pickup point.', 'smart-send-logistics' );
				case self::PICKUP_POINT_STATUS_LOOKUP_FAILED:
					return __( 'Shipping to closest pickup point', 'smart-send-logistics' );
			}

			return null;
		}

		/**
		 * Whether a pickup point section status describes a shop-side error
		 * (rendered as an error box) rather than an informational state
		 * (rendered as an info box).
		 *
		 * @param string $status One of the PICKUP_POINT_STATUS_* constants.
		 *
		 * @return bool
		 */
		public function is_pickup_point_error_status( string $status ): bool {
			return in_array( $status, array( self::PICKUP_POINT_STATUS_NOT_CONNECTED, self::PICKUP_POINT_STATUS_AUTH_FAILED, self::PICKUP_POINT_STATUS_ACCESS_DENIED ), true );
		}
	}

endif;
