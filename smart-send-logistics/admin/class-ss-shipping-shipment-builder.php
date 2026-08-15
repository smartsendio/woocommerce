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
	 * output (the WC-native data) plus the order's delivery details (#139):
	 * the merged SS_Shipping_Delivery_Details from the repository and the
	 * method resolver, passed through the smart_send_delivery_details
	 * filter, with its parcel plan resolved into typed SS_Shipping_Parcel
	 * rows. The representation is shaped close to the Smart Send v2 API
	 * schema: a single net amount + tax amount per level (shipment/parcel/
	 * item), no excl/incl redundancy, and a pickup-point section named
	 * after v2's PickupParty.service_point_code rather than v1's
	 * agent_no/agent.
	 *
	 * This class does not talk to the API and does not know about the v1
	 * wire format - translating the representation into the v1 request
	 * body is \Smartsend\Resources\BookingResource::fromShipment()'s job
	 * (#112). Nothing below the smart_send_delivery_details filter touches
	 * raw meta or $_POST.
	 *
	 * Outbound and return bookings are two separate entry points -
	 * build_outbound() and build_return() - rather than a single build()
	 * taking an is_return boolean: they are expected to grow different
	 * do_action() calls and different business logic over time, and a
	 * boolean flag silently branching inside one method would hide that.
	 * Both share assemble_shipment() for the parts that don't differ
	 * (item/parcel/totals assembly) - only the shipping-method/pickup-point
	 * selection differs between the two.
	 *
	 * build_return() throws SS_Shipping_Booking_Exception when it cannot
	 * produce a valid shipment (no return method configured, raised by
	 * SS_Shipping_Method_Resolver::resolve_return()) - it is
	 * SS_Shipping_Booking_Service's job to catch that and convert it into
	 * a failed SS_Shipping_Booking; this class never returns an error
	 * array/value.
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
		 * Order meta repository.
		 *
		 * @var SS_Shipping_Order_Meta
		 */
		protected SS_Shipping_Order_Meta $order_meta;

		/**
		 * Shipping method resolver.
		 *
		 * @var SS_Shipping_Method_Resolver
		 */
		protected SS_Shipping_Method_Resolver $method_resolver;

		/**
		 * Constructor.
		 *
		 * @param WC_Order                    $order           The WooCommerce order.
		 * @param SS_Shipping_Order_Reader    $order_reader    Order data access utility for the same order.
		 * @param SS_Shipping_Order_Meta      $order_meta      Order meta repository.
		 * @param SS_Shipping_Method_Resolver $method_resolver Shipping method resolver.
		 */
		public function __construct( WC_Order $order, SS_Shipping_Order_Reader $order_reader, SS_Shipping_Order_Meta $order_meta, SS_Shipping_Method_Resolver $method_resolver ) {
			$this->order           = $order;
			$this->order_reader    = $order_reader;
			$this->order_meta      = $order_meta;
			$this->method_resolver = $method_resolver;
		}

		/**
		 * Build the internal shipment representation for an outbound
		 * (normal) shipping label: the shipping method comes from the
		 * order's Smart Send shipping method, and the stored pickup point
		 * is selected when one is stored on the order.
		 *
		 * @return SS_Shipping_Shipment
		 */
		public function build_outbound(): SS_Shipping_Shipment {
			$details = $this->order_meta->read( $this->order_reader->get_order_id() );
			$details->set_shipping_method( $this->method_resolver->resolve_outbound( $this->order ) );

			return $this->assemble_shipment( $details, false );
		}

		/**
		 * Build the internal shipment representation for a return label:
		 * the shipping method comes from the order's configured return
		 * method, and no pickup point is selected - unless the return
		 * method could not be resolved to the dedicated
		 * smart_send_return_method meta (free-shipping/vConnect orders,
		 * see SS_Shipping_Method_Resolver::return_uses_stored_pickup_point()),
		 * in which case the stored pickup point selection applies like on
		 * an outbound label.
		 *
		 * @throws SS_Shipping_Booking_Exception When no return method is configured.
		 *
		 * @return SS_Shipping_Shipment
		 */
		public function build_return(): SS_Shipping_Shipment {
			$return_method = $this->method_resolver->resolve_return( $this->order );

			$details = $this->order_meta->read( $this->order_reader->get_order_id() );
			$details->set_shipping_method( $return_method );

			if ( ! $this->method_resolver->return_uses_stored_pickup_point( $this->order ) ) {
				$details->set_pickup_point( null );
			}

			return $this->assemble_shipment( $details, true );
		}

		/**
		 * Assemble the shipment representation shared by build_outbound()
		 * and build_return(): run the smart_send_delivery_details filter,
		 * derive carrier/type, resolve the parcel plan into typed parcels,
		 * and combine with the receiver, item lines and totals from the
		 * order reader.
		 *
		 * @param SS_Shipping_Delivery_Details $details   The merged delivery details (method resolved, stored pickup point/plan applied).
		 * @param boolean                      $is_return Whether this is a return label.
		 *
		 * @return SS_Shipping_Shipment
		 */
		protected function assemble_shipment( SS_Shipping_Delivery_Details $details, $is_return ): SS_Shipping_Shipment {
			$order_id = $this->order_reader->get_order_id();

			/*
			 * Filter the delivery details used to book a shipping label,
			 * after the stored configuration and derived method have been
			 * merged and before the shipment representation is assembled.
			 * One typed extension point for everything Smart Send knows
			 * about how the order ships: override the shipping method,
			 * clear or replace the pickup point (SS_Shipping_Pickup_Point),
			 * or declare a parcel plan (SS_Shipping_Parcel_Plan of
			 * SS_Shipping_Parcel_Spec rows - specs may carry dimensions and
			 * an explicit weight with no item allocations at all).
			 *
			 * Replaces the removed smart_send_shipping_label_args,
			 * smart_send_order_parcels, smart_send_order_pickup_point and
			 * smart_send_parcel_weight filters (v9).
			 *
			 * @since 9.0.0
			 *
			 * @param SS_Shipping_Delivery_Details $details   The merged delivery details.
			 * @param WC_Order                     $order     The WooCommerce order.
			 * @param boolean                      $is_return Whether the label is a return label.
			 *
			 * @return SS_Shipping_Delivery_Details The delivery details to book with.
			 */
			$details = apply_filters( 'smart_send_delivery_details', $details, $this->order, $is_return );

			// Determine shipping method and carrier.
			$ss_carrier = SS_SHIPPING_WC()->get_shipping_method_carrier( $details->get_shipping_method() );
			$ss_type    = SS_SHIPPING_WC()->get_shipping_method_type( $details->get_shipping_method() );

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

			// A single parcel containing all the items.
			$weight_total = 0;
			foreach ( $items_data as $item_row ) {
				if ( $item_row['unit_weight'] ) {
					$weight_total += ( $item_row['quantity'] * $item_row['unit_weight'] );
				}
			}

			$parcel = new SS_Shipping_Parcel();
			$parcel->set_internal_id( $this->value_or_null( $this->order_reader->get_order_id() ) )
				->set_internal_reference( $this->value_or_null( $this->order_reader->get_order_number() ) )
				->set_weight( $this->value_or_null( $weight_total ) )
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
				// alone, amounts live at shipment level only.
				$parcel->set_weight( $spec->get_weight() );

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

			$parcel->set_weight( null !== $spec->get_weight() ? $spec->get_weight() : $this->value_or_null( $item_weight_total ) )
				->set_items( $item_rows )
				->set_total_net_amount( $this->value_or_null( $item_net_total ) )
				->set_total_tax_amount( $this->value_or_null( $item_tax_total ) );

			return $parcel;
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
