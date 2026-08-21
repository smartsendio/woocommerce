<?php
/**
 * WooCommerce Smart Send shipping method resolver.
 *
 * @package  SS_Shipping_Method_Resolver
 * @category Shipping
 * @author   Smart Send
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// A second copy of the plugin may already have defined the class.
if ( ! class_exists( 'SS_Shipping_Method_Resolver' ) ) :

	/**
	 * Resolves which Smart Send shipping method an order ships (and
	 * returns) with, from the order's shipping line items: a Smart Send
	 * shipping item's own meta, WooCommerce Free Shipping mapped through
	 * the "shipping method for free shipping" setting, or a vConnect
	 * PostNord item mapped to the matching Smart Send method.
	 *
	 * Extracted from SS_Shipping_Order_Meta::get_smart_send_method_id()
	 * (#139), replacing its boolean $return flag and array-or-string
	 * return with two honest entry points - resolve_outbound() and
	 * resolve_return() - plus the two derived questions callers actually
	 * ask: is_auto_return_enabled() (the auto-generate-return-label item
	 * meta) and return_uses_stored_pickup_point() (the v8 oddity where
	 * free-shipping/vConnect orders never resolve a dedicated return
	 * method, so a return label still selects the stored pickup point).
	 *
	 * This is business logic, not meta CRUD: the only order meta it reads
	 * is the third-party vConnect _vc_aio_options blob. The repository
	 * (SS_Shipping_Order_Meta) stays the single reader/writer of
	 * Smart-Send-owned order meta.
	 */
	class SS_Shipping_Method_Resolver {

		/**
		 * Typed plugin settings reader.
		 *
		 * @var SS_Shipping_Settings
		 */
		protected SS_Shipping_Settings $settings;

		/**
		 * The settings reader is stateless, so a fresh default is safe for
		 * ad-hoc construction.
		 *
		 * @param SS_Shipping_Settings|null $settings Typed plugin settings reader.
		 */
		public function __construct( ?SS_Shipping_Settings $settings = null ) {
			$this->settings = null === $settings ? new SS_Shipping_Settings() : $settings;
		}

		/**
		 * Resolve the Smart Send shipping method used for an outbound
		 * (normal) label.
		 *
		 * @param integer|WC_Order $order Order (or order id).
		 *
		 * @return string Unique Smart Send method id (e.g. 'postnord_agent'), or '' when the order has none.
		 */
		public function resolve_outbound( $order ) {
			$resolved = $this->resolve( $order, false );

			return is_string( $resolved ) ? $resolved : '';
		}

		/**
		 * Resolve the Smart Send shipping method used for a return label.
		 *
		 * For an order shipped with a Smart Send shipping item this is the
		 * item's configured return method; free-shipping/vConnect orders
		 * fall back to the concrete method id their outbound resolution
		 * produces (see return_uses_stored_pickup_point()).
		 *
		 * @param integer|WC_Order $order Order (or order id).
		 *
		 * @throws SS_Shipping_Booking_Exception When the order's Smart Send shipping item has no return method configured.
		 *
		 * @return string Unique Smart Send method id, or '' when the order has none.
		 */
		public function resolve_return( $order ) {
			$resolved = $this->resolve( $order, true );

			if ( is_array( $resolved ) ) {
				if ( empty( $resolved['smart_send_return_method'] ) ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the exception message is caught by SS_Shipping_Booking_Service and surfaced via SS_Shipping_Booking::get_error_message(), never echoed directly; escaping is not applicable here.
					throw new SS_Shipping_Booking_Exception( __( 'No return method set', 'smart-send-logistics' ) );
				}

				return $resolved['smart_send_return_method'];
			}

			return is_string( $resolved ) ? $resolved : '';
		}

		/**
		 * Whether a return label for the order keeps the stored pickup
		 * point selection.
		 *
		 * v8 oddity, preserved deliberately: free-shipping/vConnect orders
		 * never resolve to the dedicated smart_send_return_method item
		 * meta, so their return label runs the same pickup point selection
		 * as an outbound label. Orders with a real Smart Send shipping
		 * item never select a pickup point on return labels.
		 *
		 * @param integer|WC_Order $order Order (or order id).
		 *
		 * @return boolean
		 */
		public function return_uses_stored_pickup_point( $order ) {
			return ! is_array( $this->resolve( $order, true ) );
		}

		/**
		 * Whether the order's Smart Send shipping item has the
		 * auto-generate-return-label setting enabled.
		 *
		 * @param integer|WC_Order $order Order (or order id).
		 *
		 * @return boolean
		 */
		public function is_auto_return_enabled( $order ) {
			$resolved = $this->resolve( $order, true );

			return is_array( $resolved ) &&
				isset( $resolved['smart_send_auto_generate_return_label'] ) &&
				'yes' == $resolved['smart_send_auto_generate_return_label']; // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- pre-existing loose comparison; tightening is a behaviour change out of scope for this extraction.
		}

		/**
		 * The historic resolution walk over the order's shipping items,
		 * moved verbatim from SS_Shipping_Order_Meta::get_smart_send_method_id()
		 * so every branch (including the vConnect flex-delivery decision
		 * table) keeps its exact behaviour. Returns the raw historic
		 * shapes; the public methods above translate them.
		 *
		 * @param integer|WC_Order $order      Order (or order id).
		 * @param boolean          $for_return Whether the label is a return label.
		 *
		 * @return string|array '' when no Smart Send method is found; the method id string; or, for a Smart Send shipping item asked about its return configuration, the raw item-meta array.
		 */
		protected function resolve( $order, $for_return ) {
			$order = $order instanceof WC_Order ? $order : wc_get_order( $order );

			if ( ! $order ) {
				return '';
			}

			// Get shipping id to make sure its either Smart Send, Free Shipping or vConnect
			$order_shipping_methods = $order->get_shipping_methods();
			if ( ! empty( $order_shipping_methods ) ) {

				foreach ( $order_shipping_methods as $item_id => $item ) {
					// Array access on 'WC_Order_Item_Shipping' works because it implements backwards compatibility
					$shipping_method_id = ! empty( $item['method_id'] ) ? esc_html( $item['method_id'] ) : null;

					// If Smart Send found, return id
					if ( stripos( $shipping_method_id, 'smart_send_shipping' ) !== false ) {
						if ( $for_return ) {
							return array(
								'smart_send_return_method' => $item['smart_send_return_method'],
								'smart_send_auto_generate_return_label' => $item['smart_send_auto_generate_return_label'],
							);
						} else {
							return $item['smart_send_shipping_method'];
						}
					} elseif ( stripos( $shipping_method_id, 'free_shipping' ) !== false ) {
						// If free shipping, then filter the shipping method to the correct Smart Send method
						$free_shipping_method = $this->settings->shipping_method_for_free_shipping();

						if ( null !== $free_shipping_method ) {
							return $free_shipping_method;
						}
					} elseif ( stripos( $shipping_method_id, 'vconnect_postnord' ) !== false ) {
						// If vConnect, then filter the shipping method to the correct Smart Send method
						if ( $for_return ) {
							return 'postnord_returndropoff';
						} elseif ( stripos( $shipping_method_id, '_pickup' ) !== false ) {
								return 'postnord_agent';
						} elseif ( stripos( $shipping_method_id, '_dpd' ) !== false ) {
							return 'postnord_homedelivery';
						} elseif ( stripos( $shipping_method_id, '_commercial' ) !== false ) {
							return 'postnord_commercial';
						} elseif ( stripos( $shipping_method_id, '_privatehome' ) !== false ) {
							$vc_aio_options       = $order->get_meta( '_vc_aio_options', true );
							$flex_delivery        = false;
							$flex_delivery_option = false;
							$day_delivery         = false;
							if ( is_array( $vc_aio_options ) ) {
								foreach ( $vc_aio_options as $option ) {
									// Check if shipping method has flexDelivery enabled (the parcel can be left somewhere)
									// phpcs:ignore WordPress.PHP.StrictInArray.MissingTrueStrict -- pre-existing loose array_search; tightening is a behaviour change out of scope for the #139 move.
									if ( array_search(
										'flexDelivery',
										array_column( $option, 'value' )
									) !== false ) {
										$flex_delivery = true;
									}
									// Check if shipping method has dayDelivery enabled (customer will receive an SMS with possibility to choose)
									if ( array_search( 'dayDelivery', array_column( $option, 'value' ) ) !== false ) { // phpcs:ignore WordPress.PHP.StrictInArray.MissingTrueStrict -- pre-existing loose array_search; tightening is a behaviour change out of scope for the #139 move.
										$day_delivery = true;
									}
									// A flexDelivery option is chosen
									if ( ! empty( $option['typeId']['value'] ) && 'flexDelivery' == $option['typeId']['value'] // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- pre-existing loose comparison; tightening is a behaviour change out of scope for the #139 move.
										&& ! empty( $option['addressText']['value'] ) ) {
										$flex_delivery_option = true;
									}
								}
							}
							if ( ! $flex_delivery && ! $day_delivery && ! $flex_delivery_option ) {
								return 'postnord_homedelivery';
							} elseif ( ! $flex_delivery && ! $flex_delivery_option && $day_delivery ) {
								return 'postnord_flexhome';
							} elseif ( $flex_delivery && ! $flex_delivery_option && ! $day_delivery ) {
								return 'postnord_doorstep';
								// The chosen flexdelivy option must be used to tell PostNord where the parcel should be left
							} elseif ( $flex_delivery && $flex_delivery_option && ! $day_delivery ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedElseif -- pre-existing intentionally empty branch documenting an unhandled vConnect case.
								// The chosen flexdelivy option must be used to tell PostNord where the parcel should be left
							}
						}
					}
				}
			}

			return '';
		}
	}

endif;
