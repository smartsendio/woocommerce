<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Smart Send order meta access.
 *
 * Owns everything stored on (or read from) a WooCommerce order for the
 * Smart Send integration: the resolved shipping method id, the selected
 * pick-up point (agent) meta, the parcel split and the shipment ids.
 *
 * @package  SS_Shipping_Order_Meta
 * @category Shipping
 * @author   Smart Send
 */

// A second copy of the plugin may already have defined the class.
if ( ! class_exists( 'SS_Shipping_Order_Meta' ) ) :

	class SS_Shipping_Order_Meta {

		/**
		 * Return ordered Smart Send shipping method, OR Free Shipping linked to Smart Send shipping method, otherwise empty string
		 *
		 * @param integer $order_id     Post object or post ID of the order.
		 * @param boolean $return       Whether or not the label is return (true) or normal (false)
		 * @return string               Unique Smart Send name of shipping method. Example 'postnord_agent'
		 */
		// phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.returnFound -- pre-existing public method signature, kept for backwards compatibility.
		public function get_smart_send_method_id( $order_id, $return = false ) {
			$order = wc_get_order( $order_id );//Accepts Post object or post ID of the order.

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
						if ( $return ) {
							return array(
								'smart_send_return_method' => $item['smart_send_return_method'],
								'smart_send_auto_generate_return_label' => $item['smart_send_auto_generate_return_label'],
							);
						} else {
							return $item['smart_send_shipping_method'];
						}
					} elseif ( stripos( $shipping_method_id, 'free_shipping' ) !== false ) {
							// If free shipping, then filter the shipping method to the correct Smart Send method

							$ss_settings = SS_SHIPPING_WC()->get_ss_shipping_settings();

						if ( ! empty( $ss_settings['shipping_method_for_free_shipping'] ) ) {
							return $ss_settings['shipping_method_for_free_shipping'];
						}
					} elseif ( stripos( $shipping_method_id, 'vconnect_postnord' ) !== false ) {
						// If vConnect, then filter the shipping method to the correct Smart Send method
						if ( $return ) {
							return 'postnord_returndropoff';
						} elseif ( stripos( $shipping_method_id, '_pickup' ) !== false ) {
								return 'postnord_agent';
						} elseif ( stripos( $shipping_method_id, '_dpd' ) !== false ) {
							return 'postnord_homedelivery';
						} elseif ( stripos( $shipping_method_id, '_commercial' ) !== false ) {
							return 'postnord_commercial';
						} elseif ( stripos( $shipping_method_id, '_privatehome' ) !== false ) {
							$order                = wc_get_order( $order_id );
							$vc_aio_options       = $order->get_meta( '_vc_aio_options', true );
							$flex_delivery        = false;
							$flex_delivery_option = false;
							$day_delivery         = false;
							if ( is_array( $vc_aio_options ) ) {
								foreach ( $vc_aio_options as $option ) {
									// Check if shipping method has flexDelivery enabled (the parcel can be left somewhere)
									// phpcs:ignore WordPress.PHP.StrictInArray.MissingTrueStrict -- pre-existing loose array_search; tightening is a behaviour change out of scope for the #43 move.
									if ( array_search(
										'flexDelivery',
										array_column( $option, 'value' )
									) !== false ) {
										$flex_delivery = true;
									}
									// Check if shipping method has dayDelivery enabled (customer will receive an SMS with possibility to choose)
									if ( array_search( 'dayDelivery', array_column( $option, 'value' ) ) !== false ) { // phpcs:ignore WordPress.PHP.StrictInArray.MissingTrueStrict -- pre-existing loose array_search; tightening is a behaviour change out of scope for the #43 move.
										$day_delivery = true;
									}
									// A flexDelivery option is chosen
									if ( ! empty( $option['typeId']['value'] ) && 'flexDelivery' == $option['typeId']['value'] // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- pre-existing loose comparison; tightening is a behaviour change out of scope for the #43 move.
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

		/**
		 * Agent meta data updated
		 *
		 *
		 * @since 5.0.0
		 *
		 * @param null|bool   $check      Whether to allow updating metadata for the given type.
		 * @param int         $meta_id    Meta ID.
		 * @param mixed       $meta_value Meta value. Must be serializable if non-scalar.
		 * @param string|bool $meta_key   Meta key, if provided.
		 * @return bool                   Returning a non-null value will effectively short-circuit the function.
		 */
		public function filter_update_agent_meta( $check, $meta_id, $meta_value, $meta_key ) {

			if ( 'ss_shipping_order_agent_no' == $meta_key ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- pre-existing loose comparison; tightening is a behaviour change out of scope for the #43 move.
				$meta      = get_metadata_by_mid( 'post', $meta_id );
				$object_id = $meta->post_id;
				if ( $this->save_shipping_agent( $object_id, true, $meta_value ) !== true ) {
					// the agent was not found so do NOT save the new agent_no
					$check = false;
				}
			}

			return $check;
		}

		/**
		 * Agent meta deleted
		 * Fires immediately after deleting metadata of a specific type.
		 *
		 * @since WP 2.9.0
		 *
		 * @param array  $meta_ids    An array of deleted metadata entry IDs.
		 * @param int    $object_id   Object ID.
		 * @param string $meta_key    Meta key.
		 * @param mixed  $_meta_value Meta value.
		 */
		// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $_meta_value is part of the deleted_post_meta hook signature.
		public function action_deleted_agent_meta( $meta_ids, $object_id, $meta_key, $_meta_value ) {

			if ( 'ss_shipping_order_agent_no' == $meta_key ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- pre-existing loose comparison; tightening is a behaviour change out of scope for the #43 move.
				$this->delete_ss_shipping_order_agent( $object_id );
			}
		}

		/**
		 * Call the API if needed and save the shipping agent address
		 *
		 * @param $post_id
		 * @param $doing_ajax
		 * @param $ss_shipping_agent_no
		 *
		 * @return bool|string         Returns true for success and false or a string when failing
		 */
		public function save_shipping_agent( $post_id, $doing_ajax, $ss_shipping_agent_no ) {

			$ss_shipping_method_id = $this->get_smart_send_method_id( $post_id );

			if ( ! empty( $ss_shipping_method_id ) ) {
				$shipping_method_carrier = SS_SHIPPING_WC()->get_shipping_method_carrier( $ss_shipping_method_id );

				$order            = wc_get_order( $post_id );
				$shipping_address = $order->get_address( 'shipping' );

				if ( ! empty( $shipping_method_carrier ) && ! empty( $shipping_address['country'] ) ) {

					// API call to get agent info by agent no.
					if ( SS_SHIPPING_WC()->get_api_handle()->getAgentByAgentNo( $shipping_method_carrier, $shipping_address['country'], $ss_shipping_agent_no ) ) {

						SS_Shipping_Logger::info(
							'Pick-up point changed on order',
							array(
								'order_id' => $post_id,
								'agent_no' => $ss_shipping_agent_no,
								'carrier'  => $shipping_method_carrier,
							)
						);

						$this->save_ss_shipping_order_agent(
							$post_id,
							SS_SHIPPING_WC()->get_api_handle()->getData()
						);
						return true;
					} else {

						SS_Shipping_Logger::warning(
							'Pick-up point not found - agent number rejected',
							array(
								'order_id' => $post_id,
								'agent_no' => $ss_shipping_agent_no,
								'carrier'  => $shipping_method_carrier,
							)
						);

						$error_msg = sprintf(
							/* translators: %s: the pick-up point agent number that was entered. */
							__(
								'The agent number entered, %s, was not found.',
								'smart-send-logistics'
							),
							$ss_shipping_agent_no
						);

						if ( $doing_ajax ) {
							return $error_msg;
						} else {
							WC_Admin_Meta_Boxes::add_error( $error_msg );
							return false;
						}
					}
				}
			}

			return false;
		}

		/**
		 * Saves the parcels input to post_meta
		 *
		 * @param int $order_id
		 * @param array $parcels
		 *
		 * @return void
		 */
		public function save_ss_shipping_order_parcels( $order_id, $parcels ) {
			$order = wc_get_order( $order_id );
			if ( ! $order instanceof WC_Order ) {
				return;
			}
			$order->update_meta_data( 'ss_shipping_order_parcels', $parcels );
			$order->save();
		}

		/**
		 * Gets parcels input from post_meta
		 *
		 * @param int $order_id
		 *
		 * @return mixed Parcels if present, false otherwise
		 */
		public function get_ss_shipping_order_parcels( $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! $order instanceof WC_Order ) {
				return false;
			}
			return $order->get_meta( 'ss_shipping_order_parcels', true );
		}

		/**
		 * Saves the label agent no to post_meta.
		 *
		 * @param int $order_id Order ID
		 * @param array $agent_no Agent No.
		 *
		 * @return void
		 */
		public function save_ss_shipping_order_agent_no( $order_id, $agent_no ) {
			$order = wc_get_order( $order_id );
			if ( ! $order instanceof WC_Order ) {
				return;
			}
			$order->update_meta_data( 'ss_shipping_order_agent_no', $agent_no );
			$order->save();
		}

		/*
		 * Gets agent no from the post meta array for an order
		 *
		 * @param int  $order_id  Order ID
		 *
		 * @return Agent No
		 */
		public function get_ss_shipping_order_agent_no( $order_id ) {
			// Fetch agent_no from meta field saved by Smart Send
			$order = wc_get_order( $order_id );

			// wc_get_order() returns false when the order does not exist, e.g. when
			// WooCommerce's email preview fires the order-details hooks with a
			// placeholder order ID. See issue #60.
			if ( ! $order instanceof WC_Order ) {
				return null;
			}

			$ss_agent_number = $order->get_meta( 'ss_shipping_order_agent_no', true );
			if ( $ss_agent_number ) {
				// Return the agent_no found
				return $ss_agent_number;
			} else {
				// No Smart Send agent_no was found, check if the order has a vConnect agent_no
				$vc_aio_meta = $order->get_meta( '_vc_aio_options', true );
				if ( ! empty( $vc_aio_meta['addressId']['value'] ) ) {
					return $vc_aio_meta['addressId']['value'];
				} else {
					return null;
				}
			}
		}

		/**
		 * Saves the agent object to post_meta.
		 *
		 * @param int $order_id Order ID
		 * @param array $agent Agent Object
		 *
		 * @return void
		 */
		public function save_ss_shipping_order_agent( $order_id, $agent ) {
			$order = wc_get_order( $order_id );
			if ( ! $order instanceof WC_Order ) {
				return;
			}
			$order->update_meta_data( '_ss_shipping_order_agent', $agent );
			$order->save();
		}

		/**
		 * Delete shippng agent object
		 *
		 * @param $order_id
		 */
		public function delete_ss_shipping_order_agent( $order_id ) {
			$order = wc_get_order( $order_id );

			// There are situations where the order has been deleted and cannot be found.
			// We should gracefully handle this situation of failing to load the order.
			if ( ! $order ) {
				SS_Shipping_Logger::error( 'Failed to load WooCommerce order when deleting pick-up point meta - skipping', array( 'order_id' => $order_id ) );

				return;
			}

			$order->delete_meta_data( '_ss_shipping_order_agent' );
		}

		/*
		 * Gets agent object from the post meta array for an order
		 *
		 * @param int  $order_id  Order ID
		 *
		 * @return Agent Object
		 */
		public function get_ss_shipping_order_agent( $order_id ) {
			// Fetch agent info from meta field saved by Smart Send
			$order = wc_get_order( $order_id );

			if ( ! $order instanceof WC_Order ) {
				return null;
			}

			$ss_agent_info = $order->get_meta( '_ss_shipping_order_agent', true );
			if ( $ss_agent_info ) {
				// Return the agent_no found
				return $ss_agent_info;
			} else {
				// No Smart Send agent_no was found, check if the order has a vConnect agent_no
				$vc_aio_meta = $order->get_meta( '_vc_aio_options', true );
				if ( ! empty( $vc_aio_meta['addressId']['value'] ) ) {
					return (object) array(
						'agent_no'      => isset( $vc_aio_meta['addressId']['value'] ) ? $vc_aio_meta['addressId']['value'] : null,
						'company'       => isset( $vc_aio_meta['name']['value'] ) ? $vc_aio_meta['name']['value'] : null,
						'address_line1' => isset( $vc_aio_meta['addressText']['value'] ) ? $vc_aio_meta['addressText']['value'] : null,
						'address_line2' => null,
						'city'          => isset( $vc_aio_meta['city']['value'] ) ? $vc_aio_meta['city']['value'] : null,
						'postal_code'   => isset( $vc_aio_meta['postcode']['value'] ) ? $vc_aio_meta['postcode']['value'] : null,
						'country'       => isset( $vc_aio_meta['country']['value'] ) ? $vc_aio_meta['country']['value'] : null,
					);
				} else {
					return null;
				}
			}
		}

		/**
		 * Saves the Shipment ID to post_meta.
		 *
		 * @param int $order_id Order ID
		 * @param string $shipment_id Shipment ID
		 * @param boolean $return Whether or not the label is return (true) or normal (false)
		 *
		 * @return void
		 */
		// phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.returnFound -- pre-existing public method signature, kept for backwards compatibility.
		public function save_ss_shipment_id_in_order_meta( $order_id, $shipment_id, $return ) {
			$order = wc_get_order( $order_id );
			if ( ! $order instanceof WC_Order ) {
				return;
			}
			if ( $return ) {
				$order->update_meta_data( '_ss_shipping_return_label_id', $shipment_id );
			} else {
				$order->update_meta_data( '_ss_shipping_label_id', $shipment_id );
			}
			$order->save();
		}
	}

endif;
