<?php
/**
 * WooCommerce Smart Send internal shipment representation builder.
 *
 * @package  SS_Shipping_Shipment_Builder
 * @category Shipping
 * @author   Smart Send
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// A second copy of the plugin may already have defined the class.
if ( ! class_exists( 'SS_Shipping_Shipment_Builder' ) ) :

	/**
	 * Assembles the new internal shipment representation (#113) from
	 * SS_Shipping_Order_Data's output plus the agent/pick-up-point
	 * selection and parcel split. The representation is a plain array
	 * shaped close to the Smart Send v2 API schema: a single net amount +
	 * tax amount per level (shipment/parcel/item), no excl/incl
	 * redundancy, and a pickup-point section named after v2's
	 * PickupParty.service_point_code rather than v1's agent_no/agent.
	 *
	 * This class does not talk to the API and does not know about the v1
	 * wire format - translating the representation into the v1 request
	 * body is SS_Shipping_Shipment's job (a thin adapter, kept only
	 * because #112's resource layer, which will own that translation
	 * permanently, is not part of this change).
	 *
	 * Gift card exclusion (#128) is NOT implemented here: WooCommerce
	 * Gift Cards redemptions are not order items (confirmed against the
	 * plugin's public FAQ: "cart/order line items are not discounted and
	 * product/order revenue is recorded in full" - the plugin instead
	 * "modifies the order total of every order that is paid with gift
	 * cards" to work around WooCommerce not supporting multiple payment
	 * methods per order), so they never reach get_items_data() and never
	 * lower an item price. But the exact order-level representation
	 * (fee line, meta, or something else) could not be confirmed with
	 * confidence without the plugin installed, so the shipment/parcel
	 * subtotal calculated in SS_Shipping_Order_Data::get_totals() is not
	 * yet corrected to add the redemption back - a gift-card-funded order
	 * still under-reports its shipment value today, same as before this
	 * change. This is a documented follow-up, to be implemented against
	 * this representation once the mechanism is confirmed (e.g. against a
	 * real WC_Order captured from a store with the plugin installed).
	 */
	class SS_Shipping_Shipment_Builder {

		/**
		 * The WooCommerce order.
		 *
		 * @var WC_Order
		 */
		protected WC_Order $order;

		/**
		 * Order data access utility.
		 *
		 * @var SS_Shipping_Order_Data
		 */
		protected SS_Shipping_Order_Data $order_data;

		/**
		 * The admin order integration.
		 *
		 * @var SS_Shipping_WC_Order
		 */
		protected SS_Shipping_WC_Order $shipping_order;

		/**
		 * Constructor.
		 *
		 * @param WC_Order               $order          The WooCommerce order.
		 * @param SS_Shipping_Order_Data $order_data     Order data access utility for the same order.
		 * @param SS_Shipping_WC_Order   $shipping_order The admin order integration.
		 */
		public function __construct( WC_Order $order, SS_Shipping_Order_Data $order_data, SS_Shipping_WC_Order $shipping_order ) {
			$this->order          = $order;
			$this->order_data     = $order_data;
			$this->shipping_order = $shipping_order;
		}

		/**
		 * Build the internal shipment representation.
		 *
		 * @param boolean $is_return Whether the label is a return label.
		 *
		 * @return array {
		 *     The internal shipment representation, or an error.
		 *
		 *     @type string     $error               Set instead of every other key when no return method is configured.
		 *     @type string     $internal_id         Order id.
		 *     @type string     $internal_reference  Order number.
		 *     @type string|null $shipping_carrier   Smart Send carrier code.
		 *     @type string|null $shipping_method    Smart Send shipping method code.
		 *     @type string     $shipping_date       Shipping date (Y-m-d).
		 *     @type array      $receiver            Receiver data, see SS_Shipping_Order_Data::get_receiver_data().
		 *     @type array|null $pickup_point        Pickup point data (service_point_code, address, ...), or null.
		 *     @type array[]    $parcels             One row per parcel: internal_id, internal_reference, weight,
		 *                                            height, width, length, freetext, items (item rows),
		 *                                            total_net_amount, total_tax_amount.
		 *     @type float|null $subtotal_net_amount Order total excl. shipping, excl. tax.
		 *     @type float|null $subtotal_tax_amount Tax on the order total excl. shipping.
		 *     @type float|null $shipping_net_amount Shipping cost excl. tax.
		 *     @type float|null $shipping_tax_amount Tax on the shipping cost.
		 *     @type float|null $total_net_amount    Order total excl. tax.
		 *     @type float|null $total_tax_amount    Total tax on the order.
		 *     @type string|null $currency           Order currency code.
		 * }
		 */
		public function build( $is_return ) {
			$order_id = $this->order_data->get_order_id();

			$ss_args = array();

			// Get shipping method.
			$ss_shipping_method_id = $this->shipping_order->get_smart_send_method_id( $order_id, $is_return );

			if ( $is_return && isset( $ss_shipping_method_id['smart_send_return_method'] ) ) {
				// If no return method set return error.
				if ( empty( $ss_shipping_method_id['smart_send_return_method'] ) ) {
					return array( 'error' => __( 'No return method set', 'smart-send-logistics' ) );
				} else {
					$ss_shipping_method_id = $ss_shipping_method_id['smart_send_return_method'];
				}
			} else {
				$ss_args['ss_agent'] = $this->shipping_order->get_ss_shipping_order_agent( $order_id );
			}

			// Determine shipping method and carrier from return settings.
			$ss_args['ss_carrier'] = SS_SHIPPING_WC()->get_shipping_method_carrier( $ss_shipping_method_id );
			$ss_args['ss_type']    = SS_SHIPPING_WC()->get_shipping_method_type( $ss_shipping_method_id );

			/*
			 * Filter the parcel split used for the order before the shipment
			 * representation is assembled. The split is the value stored by the
			 * "Split into parcels" admin option: an array of rows, each with
			 * keys 'id' (product/variation id), 'name' (product name) and
			 * 'value' (box number, 1-9). An empty value means a single parcel
			 * containing all items. Runs before smart_send_shipping_label_args,
			 * which receives the filtered split as $ss_args['ss_parcels'] and
			 * keeps the final say.
			 *
			 * @since 9.0.0
			 *
			 * @param array|string|false $parcels   The stored parcel split rows, or an empty value when the order is not split.
			 * @param int                $order_id  Order ID.
			 * @param boolean            $is_return Whether the label is a return label.
			 *
			 * @return array|string|false The parcel split rows to use.
			 */
			$ss_args['ss_parcels'] = apply_filters(
				'smart_send_order_parcels',
				$this->shipping_order->get_ss_shipping_order_parcels( $order_id ),
				$order_id,
				$is_return
			);

			/*
			 * Filter the arguments used when creating a shipping label
			 *
			 * @param array   $ss_args  contains info about shipping carrier, shipping method, agent and parcels
			 * @param int     $order_id Order ID
			 * @param boolean $is_return Whether or not the label is return (true) or normal (false)
			 */
			$ss_args = apply_filters( 'smart_send_shipping_label_args', $ss_args, $order_id, $is_return );

			$receiver = $this->order_data->get_receiver_data();

			// Add the pickup point to the shipment, if any.
			$ss_agent = apply_filters(
				'smart_send_order_pickup_point',
				empty( $ss_args['ss_agent'] ) ? null : $ss_args['ss_agent'],
				$order_id
			);

			$pickup_point = empty( $ss_agent ) ? null : $this->build_pickup_point( $ss_agent );

			// Item lines and totals.
			$items_data = $this->order_data->get_items_data();

			$parcels = array();
			$totals  = array(
				'subtotal_net_amount' => null,
				'subtotal_tax_amount' => null,
				'shipping_net_amount' => null,
				'shipping_tax_amount' => null,
				'total_net_amount'    => null,
				'total_tax_amount'    => null,
				'currency'            => null,
			);

			if ( ! empty( $items_data ) ) {
				$totals     = $this->order_data->get_totals();
				$order_note = $this->order_data->get_order_note();

				// Item rows, keyed by product/variation id so a parcel
				// split can reference them.
				$item_lookup  = array();
				$weight_total = 0;

				foreach ( $items_data as $item_row ) {
					$item_lookup[ $item_row['id'] ] = $item_row;

					if ( $item_row['unit_weight'] ) {
						$weight_total += ( $item_row['quantity'] * $item_row['unit_weight'] );
					}
				}

				if ( ! empty( $ss_args['ss_parcels'] ) && is_array( $ss_args['ss_parcels'] ) ) {
					$parcels = $this->build_split_parcels( $ss_args['ss_parcels'], $item_lookup, $order_note );
				} else {
					// A single parcel containing all the items just defined.
					$parcels[] = array(
						'internal_id'        => $this->value_or_null( $order_id ),
						'internal_reference' => $this->value_or_null( $this->order_data->get_order_number() ),
						'weight'             => $this->value_or_null( $weight_total ),
						'height'             => null,
						'width'              => null,
						'length'             => null,
						'freetext'           => $this->value_or_null( $order_note ),
						'items'              => array_values( $items_data ),
						'total_net_amount'   => $totals['subtotal_net_amount'],
						'total_tax_amount'   => $totals['subtotal_tax_amount'],
					);
				}
			}

			/*
			 * Filter the parcels section of the booking request. Unlike
			 * before #113, this now operates on the internal representation
			 * (plain parcel arrays, each with an 'items' array of item
			 * rows) rather than assembled Smartsend\Models\Shipment\Parcel
			 * objects - the smart_send_payload_* filters are unreleased
			 * (#73/#111) so this signature change is not a breaking change.
			 *
			 * @since 9.0.0
			 *
			 * @param array[]  $parcels The assembled parcel rows (see the return doc of self::build()).
			 * @param WC_Order $order   The WooCommerce order.
			 */
			$parcels = apply_filters( 'smart_send_payload_parcels', $parcels, $this->order );

			return array(
				'internal_id'         => $this->value_or_null( $order_id ),
				'internal_reference'  => $this->value_or_null( $this->order_data->get_order_number() ),
				'shipping_carrier'    => $this->value_or_null( $ss_args['ss_carrier'] ),
				'shipping_method'     => $this->value_or_null( $ss_args['ss_type'] ),
				'shipping_date'       => gmdate( 'Y-m-d' ),
				'receiver'            => $receiver,
				'pickup_point'        => $pickup_point,
				'parcels'             => $parcels,
				'subtotal_net_amount' => $totals['subtotal_net_amount'],
				'subtotal_tax_amount' => $totals['subtotal_tax_amount'],
				'shipping_net_amount' => $totals['shipping_net_amount'],
				'shipping_tax_amount' => $totals['shipping_tax_amount'],
				'total_net_amount'    => $totals['total_net_amount'],
				'total_tax_amount'    => $totals['total_tax_amount'],
				'currency'            => $totals['currency'],
			);
		}

		/**
		 * Build the pickup-point section from the stored agent object.
		 *
		 * Named after v2's PickupParty.service_point_code rather than v1's
		 * agent_no/agent (#111) - the v1 wire adapter still calls it
		 * agent_no on the wire.
		 *
		 * @param object $ss_agent The agent object stored on the order.
		 *
		 * @return array
		 */
		protected function build_pickup_point( $ss_agent ) {
			return array(
				'internal_id'        => isset( $ss_agent->id ) ? $ss_agent->id : $ss_agent->agent_no,
				'internal_reference' => isset( $ss_agent->id ) ? $ss_agent->id : $ss_agent->agent_no,
				'service_point_code' => $this->value_or_null( $ss_agent->agent_no ),
				'company'            => $this->value_or_null( $ss_agent->company ),
				'address_line1'      => $this->value_or_null( $ss_agent->address_line1 ),
				'address_line2'      => $this->value_or_null( $ss_agent->address_line2 ),
				'postal_code'        => $this->value_or_null( $ss_agent->postal_code ),
				'city'               => $this->value_or_null( $ss_agent->city ),
				'country'            => $this->value_or_null( $ss_agent->country ),
			);
		}

		/**
		 * Build one parcel per box from the parcel-split meta stored on the
		 * order.
		 *
		 * @param array       $ss_parcels  Parcel split meta rows (id, name, value).
		 * @param array       $item_lookup Item rows keyed by product/variation id.
		 * @param string|null $order_note  Freetext for the parcels.
		 *
		 * @return array[]
		 */
		protected function build_split_parcels( $ss_parcels, $item_lookup, $order_note ) {
			$parcels = array();

			$boxes = array();
			foreach ( $ss_parcels as $parcel_meta ) {
				$boxes[ $parcel_meta['value'] ][] = array(
					'id'   => $parcel_meta['id'],
					'name' => $parcel_meta['name'],
				);
			}

			foreach ( $boxes as $box_items ) {
				$item_net_total    = 0;
				$item_tax_total    = 0;
				$item_weight_total = 0;
				$box_item_rows     = array();

				foreach ( $box_items as $box_item ) {
					$item_row = $item_lookup[ $box_item['id'] ];
					$quantity = $item_row['quantity'] ? $item_row['quantity'] : 1;

					// Per-unit share of the (possibly multi-unit) line, one
					// row per unit like the stored split meta - matches the
					// pre-#113 behaviour bug-for-bug (each box embeds the
					// full order-line item row, not a per-unit slice of it).
					$item_net_total    += ( $item_row['total_net_amount'] / $quantity );
					$item_tax_total    += ( $item_row['total_tax_amount'] / $quantity );
					$item_weight_total += floatval( $item_row['unit_weight'] );

					$box_item_rows[] = $item_row;
				}

				/*
				 * Filter the weight of a parcel.
				 *
				 * @param float|null $parcel_weight Total weight of the items in the parcel.
				 * @param int        $order_id      Order ID.
				 */
				$parcel_total_weight = apply_filters(
					'smart_send_parcel_weight',
					$this->value_or_null( $item_weight_total ),
					$this->order_data->get_order_id()
				);

				$parcels[] = array(
					'internal_id'        => $this->value_or_null( $this->order_data->get_order_id() ),
					'internal_reference' => $this->value_or_null( $this->order_data->get_order_number() ),
					'weight'             => $parcel_total_weight,
					'height'             => null,
					'width'              => null,
					'length'             => null,
					'freetext'           => $this->value_or_null( $order_note ),
					'items'              => $box_item_rows,
					'total_net_amount'   => $this->value_or_null( $item_net_total ),
					'total_tax_amount'   => $this->value_or_null( $item_tax_total ),
				);
			}

			return $parcels;
		}

		/**
		 * Return the value when truthy, null otherwise (the API models
		 * expect null instead of empty/zero values).
		 *
		 * @param mixed $value The value to check.
		 *
		 * @return mixed|null
		 */
		protected function value_or_null( $value ) {
			if ( $value ) {
				return $value;
			}

			return null;
		}
	}

endif;
