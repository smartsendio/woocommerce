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
	 * Assembles the internal shipment representation (#113), a typed
	 * SS_Shipping_Shipment value object, from SS_Shipping_Order_Reader's
	 * output (the WC-native data) plus the SS_Shipping_Delivery_Details
	 * fulfillment decided on (#139, #177): the shipping method, the
	 * pickup point and the parcel plan, resolved here into typed
	 * SS_Shipping_Parcel rows. The representation is shaped close to the
	 * Smart Send v2 API schema: a single net amount + tax amount per level
	 * (shipment/parcel/item), no excl/incl redundancy, and a pickup-point
	 * section named after v2's PickupParty.service_point_code rather than
	 * v1's agent_no/agent.
	 *
	 * The builder is booking-side and knows nothing about where the
	 * delivery details come from: it never reads order meta, never
	 * resolves the shipping method from the order and runs no filter -
	 * SS_Shipping_Fulfillment_Service reads the stored configuration,
	 * resolves the method and applies smart_send_delivery_details before
	 * booking is called; SS_Shipping_Booking_Service runs
	 * smart_send_booking_request on the built representation. The
	 * details therefore arrive complete: build() throws
	 * SS_Shipping_Booking_Exception when they carry no shipping method.
	 *
	 * This class does not talk to the API and does not know about the v1
	 * wire format - translating the representation into the v1 request
	 * body is \Smartsend\Resources\BookingResource::fromShipment()'s job
	 * (#112).
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
	 * subtotal calculated in SS_Shipping_Order_Reader::get_totals() is not
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
		 * @var SS_Shipping_Order_Reader
		 */
		protected SS_Shipping_Order_Reader $order_reader;

		/**
		 * Constructor.
		 *
		 * @param WC_Order                 $order        The WooCommerce order.
		 * @param SS_Shipping_Order_Reader $order_reader Order data access utility for the same order.
		 */
		public function __construct( WC_Order $order, SS_Shipping_Order_Reader $order_reader ) {
			$this->order        = $order;
			$this->order_reader = $order_reader;
		}

		/**
		 * Build the internal shipment representation from the delivery
		 * details: derive carrier/type from the shipping method, resolve
		 * the parcel plan into typed parcels, and combine with the
		 * receiver, item lines and totals from the order reader.
		 *
		 * @param SS_Shipping_Delivery_Details $details The delivery details to ship with (method set, pickup point and parcel plan as decided).
		 *
		 * @throws SS_Shipping_Booking_Exception When the details carry no shipping method.
		 *
		 * @return SS_Shipping_Shipment
		 */
		public function build( SS_Shipping_Delivery_Details $details ): SS_Shipping_Shipment {
			$order_id = $this->order_reader->get_order_id();

			if ( empty( $details->get_shipping_method() ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- caught by SS_Shipping_Fulfillment_Service and recorded on the result as data, never echoed directly.
				throw new SS_Shipping_Booking_Exception( __( 'No shipping method set', 'smart-send-logistics' ) );
			}

			// Determine shipping method and carrier.
			$method_code = new SS_Shipping_Method_Code( $details->get_shipping_method() );
			$ss_carrier  = $method_code->carrier();
			$ss_type     = $method_code->type();

			$pickup_point = null === $details->get_pickup_point()
				? null
				: $this->build_pickup_point( $details->get_pickup_point() );

			// Item lines and totals.
			$items_data = $this->order_reader->get_items_data();

			$totals = array(
				'subtotal_net_amount' => null,
				'subtotal_tax_amount' => null,
				'shipping_net_amount' => null,
				'shipping_tax_amount' => null,
				'total_net_amount'    => null,
				'total_tax_amount'    => null,
				'currency'            => null,
			);

			$order_note = null;
			if ( ! empty( $items_data ) ) {
				$totals     = $this->order_reader->get_totals();
				$order_note = $this->order_reader->get_order_note();
			}

			$parcels = $this->resolve_parcels( $details->get_parcel_plan(), $items_data, $totals, $order_note );

			$shipment = new SS_Shipping_Shipment();
			$shipment->set_internal_id( $this->value_or_null( $order_id ) )
				->set_internal_reference( $this->value_or_null( $this->order_reader->get_order_number() ) )
				->set_shipping_carrier( $this->value_or_null( $ss_carrier ) )
				->set_shipping_method( $this->value_or_null( $ss_type ) )
				->set_shipping_date( gmdate( 'Y-m-d' ) )
				->set_receiver( $this->order_reader->get_receiver_data() )
				->set_pickup_point( $pickup_point )
				->set_parcels( $parcels )
				->set_subtotal_net_amount( $totals['subtotal_net_amount'] )
				->set_subtotal_tax_amount( $totals['subtotal_tax_amount'] )
				->set_shipping_net_amount( $totals['shipping_net_amount'] )
				->set_shipping_tax_amount( $totals['shipping_tax_amount'] )
				->set_total_net_amount( $totals['total_net_amount'] )
				->set_total_tax_amount( $totals['total_tax_amount'] )
				->set_currency( $totals['currency'] );

			return $shipment;
		}

		/**
		 * Resolve the parcel plan into typed SS_Shipping_Parcel rows.
		 *
		 * An empty (or unspecified) plan resolves to a single parcel
		 * containing every item line, carrying the order-level subtotal
		 * amounts. A non-empty plan resolves one parcel per spec.
		 *
		 * @param SS_Shipping_Parcel_Plan|null $plan       The parcel plan, or null.
		 * @param array[]                      $items_data Item rows from the order reader.
		 * @param array                        $totals     Order totals from the order reader (null amounts when the order has no items).
		 * @param string|null                  $order_note Freetext for the parcels.
		 *
		 * @return SS_Shipping_Parcel[]
		 */
		protected function resolve_parcels( $plan, array $items_data, array $totals, $order_note ) {
			if ( null !== $plan && ! $plan->is_empty() ) {
				// Item rows, keyed by product/variation id so the specs'
				// item allocations can reference them.
				$item_lookup = array();
				foreach ( $items_data as $item_row ) {
					$item_lookup[ $item_row['id'] ] = $item_row;
				}

				$parcels = array();
				foreach ( $plan->get_specs() as $spec ) {
					$parcels[] = $this->resolve_spec( $spec, $item_lookup, $order_note );
				}

				return $parcels;
			}

			if ( empty( $items_data ) ) {
				return array();
			}

			// A single parcel containing all the items. The implicit spec
			// behind it (every unit, no explicit weight) is what the
			// default-weight filter receives, so a snippet adding packaging
			// weight sees the same spec shape whether or not a plan was
			// declared.
			$weight_total  = 0;
			$implicit_spec = new SS_Shipping_Parcel_Spec();
			$implicit_spec->set_reference( '1' );
			foreach ( $items_data as $item_row ) {
				if ( $item_row['unit_weight'] ) {
					$weight_total += ( $item_row['quantity'] * $item_row['unit_weight'] );
				}
				$implicit_spec->add_item( $item_row['id'], $item_row['quantity'], isset( $item_row['name'] ) ? $item_row['name'] : null );
			}

			$parcel = new SS_Shipping_Parcel();
			$parcel->set_internal_id( $this->value_or_null( $this->order_reader->get_order_id() ) )
				->set_internal_reference( $this->value_or_null( $this->order_reader->get_order_number() ) )
				->set_weight( $this->value_or_null( $this->default_parcel_weight( (float) $weight_total, $implicit_spec ) ) )
				->set_freetext( $this->value_or_null( $order_note ) )
				->set_items( array_values( $items_data ) )
				->set_total_net_amount( $totals['subtotal_net_amount'] )
				->set_total_tax_amount( $totals['subtotal_tax_amount'] );

			return array( $parcel );
		}

		/**
		 * Resolve one parcel spec into a typed parcel.
		 *
		 * Item allocations pull their rows from the order's item lines,
		 * accumulating a per-unit share of each (possibly multi-unit)
		 * line, one row per unit like the stored split meta - matching the
		 * pre-#113 split behaviour bug-for-bug (each unit embeds the full
		 * order-line item row, not a per-unit slice of it). An explicit
		 * spec weight always wins over the item-sum (packaging weight is
		 * real); a spec without item allocations produces a parcel without
		 * item rows and without amounts - declared amounts then live at
		 * shipment level only.
		 *
		 * @param SS_Shipping_Parcel_Spec $spec        The planned parcel.
		 * @param array                   $item_lookup Item rows keyed by product/variation id.
		 * @param string|null             $order_note  Freetext for the parcels.
		 *
		 * @return SS_Shipping_Parcel
		 */
		protected function resolve_spec( SS_Shipping_Parcel_Spec $spec, array $item_lookup, $order_note ) {
			$parcel = new SS_Shipping_Parcel();
			$parcel->set_internal_id( $this->value_or_null( $this->order_reader->get_order_id() ) )
				->set_internal_reference( $this->value_or_null( $this->order_reader->get_order_number() ) )
				->set_height( $spec->get_height() )
				->set_width( $spec->get_width() )
				->set_length( $spec->get_length() )
				->set_freetext( $this->value_or_null( $order_note ) );

			if ( ! $spec->has_items() ) {
				// No item allocations: dimensions/weight come from the spec
				// alone, amounts live at shipment level only. Without an
				// explicit weight the item-sum is 0 - the default-weight
				// filter is the only way to weigh such a parcel.
				$parcel->set_weight(
					null !== $spec->get_weight()
						? $spec->get_weight()
						: $this->value_or_null( $this->default_parcel_weight( 0.0, $spec ) )
				);

				return $parcel;
			}

			$item_net_total    = 0;
			$item_tax_total    = 0;
			$item_weight_total = 0;
			$item_rows         = array();

			foreach ( $spec->get_items() as $allocation ) {
				if ( ! isset( $item_lookup[ $allocation['id'] ] ) ) {
					continue; // The allocation references an item the order does not have.
				}

				$item_row = $item_lookup[ $allocation['id'] ];
				$quantity = $item_row['quantity'] ? $item_row['quantity'] : 1;

				for ( $unit = 0; $unit < $allocation['quantity']; $unit++ ) {
					$item_net_total    += ( $item_row['total_net_amount'] / $quantity );
					$item_tax_total    += ( $item_row['total_tax_amount'] / $quantity );
					$item_weight_total += floatval( $item_row['unit_weight'] );

					$item_rows[] = $item_row;
				}
			}

			$parcel->set_weight(
				null !== $spec->get_weight()
					? $spec->get_weight()
					: $this->value_or_null( $this->default_parcel_weight( (float) $item_weight_total, $spec ) )
			)
				->set_items( $item_rows )
				->set_total_net_amount( $this->value_or_null( $item_net_total ) )
				->set_total_tax_amount( $this->value_or_null( $item_tax_total ) );

			return $parcel;
		}

		/**
		 * The weight of a parcel whose spec declares none: the sum of the
		 * allocated items' weights, passed through the
		 * smart_send_parcel_default_weight filter. An explicit spec weight
		 * never reaches this method (it wins as declared, packaging
		 * included).
		 *
		 * @param float                   $item_weight The item-sum in kg (0 for a parcel without items).
		 * @param SS_Shipping_Parcel_Spec $spec        The planned parcel the weight is for.
		 *
		 * @return float
		 */
		protected function default_parcel_weight( float $item_weight, SS_Shipping_Parcel_Spec $spec ): float {
			/*
			 * Filter the default weight of a parcel that has no explicit
			 * weight: the sum of the weights of the items allocated to it.
			 * Use it to add packaging weight or apply a minimum. A parcel
			 * spec with an explicit weight (set_weight() on the
			 * SS_Shipping_Parcel_Spec, e.g. entered in the order meta box)
			 * bypasses this filter entirely.
			 *
			 * @since 9.0.0
			 *
			 * @param float                   $weight The computed weight in kg (0 when the items have no weight or the parcel has no items).
			 * @param SS_Shipping_Parcel_Spec $spec   The planned parcel (reference, dimensions, item allocations).
			 * @param WC_Order                $order  The WooCommerce order.
			 *
			 * @return float The weight to book the parcel with.
			 */
			return (float) apply_filters( 'smart_send_parcel_default_weight', $item_weight, $spec, $this->order );
		}

		/**
		 * Build the pickup-point section from the selected pickup point.
		 *
		 * Named after v2's PickupParty.service_point_code rather than v1's
		 * agent_no/agent (#111) - the v1 wire adapter still calls it
		 * agent_no on the wire.
		 *
		 * @param SS_Shipping_Pickup_Point $pickup_point The selected pickup point.
		 *
		 * @return array
		 */
		protected function build_pickup_point( SS_Shipping_Pickup_Point $pickup_point ) {
			$internal_id = null !== $pickup_point->get_internal_id()
				? $pickup_point->get_internal_id()
				: $pickup_point->get_agent_no();

			return array(
				'internal_id'        => $internal_id,
				'internal_reference' => $internal_id,
				'service_point_code' => $this->value_or_null( $pickup_point->get_agent_no() ),
				'company'            => $this->value_or_null( $pickup_point->get_company() ),
				'address_line1'      => $this->value_or_null( $pickup_point->get_address_line1() ),
				'address_line2'      => $this->value_or_null( $pickup_point->get_address_line2() ),
				'postal_code'        => $this->value_or_null( $pickup_point->get_postal_code() ),
				'city'               => $this->value_or_null( $pickup_point->get_city() ),
				'country'            => $this->value_or_null( $pickup_point->get_country() ),
			);
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
