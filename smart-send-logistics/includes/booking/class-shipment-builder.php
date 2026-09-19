<?php
/**
 * WooCommerce Smart Send internal shipment representation builder.
 *
 * @package Smart_Send
 * @category Shipping
 * @author   Smart Send
 */

namespace Smart_Send\Booking;

use Smart_Send\Booking\Exceptions\Booking_Exception;
use Smart_Send\Delivery\Delivery_Details;
use Smart_Send\Delivery\Parcel_Plan;
use Smart_Send\Delivery\Parcel_Spec;
use Smart_Send\Delivery\Pickup_Point;
use Smart_Send\Fulfillment\Fulfillment_Service;
use Smart_Send\Shipping_Method\Method_Code;
use WC_Order;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Assembles the internal shipment representation (#113), a typed
 * Shipment value object, from Order_Reader's
 * output (the WC-native data) plus the Delivery_Details
 * fulfillment decided on (#139, #177): the shipping method, the
 * pickup point and the parcel plan, resolved here into typed
 * Parcel rows. The representation is shaped close to the
 * Smart Send v2 API schema: a single net amount + tax amount per level
 * (shipment/parcel/item), no excl/incl redundancy, and a pickup-point
 * section named after v2's PickupParty.service_point_code rather than
 * v1's agent_no/agent.
 *
 * The builder is booking-side and knows nothing about where the
 * delivery details come from: it never reads order meta, never
 * resolves the shipping method from the order and runs no filter -
 * Fulfillment_Service reads the stored configuration,
 * resolves the method and applies smart_send_delivery_details before
 * booking is called; Booking_Service runs
 * smart_send_booking_request on the built representation. The
 * details therefore arrive complete: build() throws
 * Booking_Exception when they carry no shipping method.
 *
 * This class does not talk to the API and does not know about the v1
 * wire format - translating the representation into the v1 request
 * body is \Smart_Send\API\Resources\Booking_Resource::from_shipment()'s job
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
 * subtotal calculated in Order_Reader::get_totals() is not
 * yet corrected to add the redemption back - a gift-card-funded order
 * still under-reports its shipment value today, same as before this
 * change. This is a documented follow-up, to be implemented against
 * this representation once the mechanism is confirmed (e.g. against a
 * real WC_Order captured from a store with the plugin installed).
 */
class Shipment_Builder {

	/**
	 * The WooCommerce order.
	 *
	 * @var WC_Order
	 */
	protected WC_Order $order;

	/**
	 * Order data access utility.
	 *
	 * @var Order_Reader
	 */
	protected Order_Reader $order_reader;

	/**
	 * Constructor.
	 *
	 * @param WC_Order                 $order        The WooCommerce order.
	 * @param Order_Reader $order_reader Order data access utility for the same order.
	 */
	public function __construct( WC_Order $order, Order_Reader $order_reader ) {
		$this->order        = $order;
		$this->order_reader = $order_reader;
	}

	/**
	 * Build the internal shipment representation from the delivery
	 * details: derive carrier/type from the shipping method, resolve
	 * the parcel plan into typed parcels, and combine with the
	 * receiver, item lines and totals from the order reader.
	 *
	 * @param Delivery_Details $details The delivery details to ship with (method set, pickup point and parcel plan as decided).
	 *
	 * @throws Booking_Exception When the details carry no shipping method.
	 *
	 * @return Shipment
	 */
	public function build( Delivery_Details $details ): Shipment {
		$order_id = $this->order_reader->get_order_id();

		if ( empty( $details->get_shipping_method() ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- caught by Fulfillment_Service and recorded on the result as data, never echoed directly.
			throw new Booking_Exception( __( 'No shipping method set', 'smart-send-logistics' ) );
		}

		// Determine shipping method and carrier.
		$method_code = new Method_Code( $details->get_shipping_method() );
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

		$parcels = $this->resolve_parcels( $details->get_parcel_plan(), $items_data, $order_note );

		$shipment = new Shipment();
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
	 * Resolve a complete plan, with line identity and quantities validated first.
	 *
	 * @param Parcel_Plan|null $plan       Planned parcels, or an implicit single parcel.
	 * @param array[]          $items_data Order item rows.
	 * @param string|null      $order_note Label freetext.
	 * @return Parcel[]
	 * @throws Booking_Exception When allocations no longer match the order.
	 */
	protected function resolve_parcels( $plan, array $items_data, $order_note ) {
		$plan   = null === $plan ? new Parcel_Plan() : $plan;
		$errors = $plan->validation_errors( $items_data );
		if ( array() !== $errors ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fulfillment renders these plain-text validation errors.
			throw new Booking_Exception( __( 'Review the parcel allocations before booking.', 'smart-send-logistics' ), $errors );
		}
		if ( $plan->is_empty() && empty( $items_data ) ) {
			return array();
		}

		$specs    = $plan->get_specs();
		$implicit = $plan->is_empty() || ( 1 === count( $specs ) && ! $specs[0]->has_items() );
		if ( $implicit ) {
			$spec = $plan->is_empty() ? ( new Parcel_Spec() )->set_reference( '1' ) : clone $specs[0];
			foreach ( $items_data as $item_row ) {
				$spec->add_item( $item_row['order_item_id'], $item_row['quantity'], $item_row['name'] ?? null );
			}
			$specs = array( $spec );
		}

		$item_lookup = array();
		foreach ( $items_data as $item_row ) {
			$item_lookup[ $item_row['order_item_id'] ] = $item_row;
		}

		$allocated = array();
		$parcels   = array();
		foreach ( $specs as $index => $spec ) {
			$parcels[] = $this->resolve_spec( $spec, $item_lookup, $order_note, $allocated, $index );
		}

		return $parcels;
	}

	/**
	 * Allocate actual quantities and their share of each order-line amount.
	 *
	 * Rounding is cumulative in parcel order. The final allocation receives
	 * the remaining amount at WooCommerce accounting precision so sub-cent
	 * tax amounts survive and each line reconciles without negative slices.
	 *
	 * @param Parcel_Spec $spec        Planned parcel.
	 * @param array       $item_lookup Order rows keyed by order_item_id.
	 * @param string|null $order_note  Label freetext.
	 * @param array       $allocated   Quantity and amounts assigned so far, per line.
	 * @param int         $index       Zero-based parcel position for validation errors.
	 * @return Parcel
	 * @throws Booking_Exception When an unknown item weight needs explicit input.
	 */
	protected function resolve_spec( Parcel_Spec $spec, array $item_lookup, $order_note, array &$allocated, int $index ) {
		$parcel = new Parcel();
		$parcel->set_internal_id( $this->value_or_null( $this->order_reader->get_order_id() ) )
			->set_internal_reference( $this->value_or_null( $this->order_reader->get_order_number() ) )
			->set_height( $spec->get_height() )
			->set_width( $spec->get_width() )
			->set_length( $spec->get_length() )
			->set_freetext( $this->value_or_null( $order_note ) );

		$item_net_total    = 0.0;
		$item_tax_total    = 0.0;
		$item_weight_total = 0.0;
		$unknown_weight    = false;
		$item_rows         = array();
		$precision         = wc_get_price_decimals();
		$amount_precision  = wc_get_rounding_precision();

		foreach ( $spec->get_items() as $allocation ) {
			$id       = $allocation['order_item_id'];
			$item_row = $item_lookup[ $id ];
			$previous = $allocated[ $id ] ?? array(
				'quantity'         => 0,
				'total_net_amount' => 0.0,
				'total_tax_amount' => 0.0,
			);
			$quantity = $previous['quantity'] + $allocation['quantity'];
			$current  = array( 'quantity' => $quantity );

			foreach ( array( 'total_net_amount', 'total_tax_amount' ) as $amount_key ) {
				$total                   = $item_row[ $amount_key ];
				$share                   = $quantity === (int) $item_row['quantity']
					? (float) $total
					: min( (float) $total, round( $total * $quantity / $item_row['quantity'], $precision ) );
				$item_row[ $amount_key ] = round( $share - $previous[ $amount_key ], $amount_precision );
				$current[ $amount_key ]  = $share;
			}

			$item_row['quantity'] = $allocation['quantity'];
			$allocated[ $id ]     = $current;
			$item_net_total      += $item_row['total_net_amount'];
			$item_tax_total      += $item_row['total_tax_amount'];
			if ( null === $item_row['unit_weight'] ) {
				$unknown_weight = true;
			} else {
				$item_weight_total += $allocation['quantity'] * (float) $item_row['unit_weight'];
			}
			$item_rows[] = $item_row;
		}

		$weight = $spec->get_weight();
		if ( $unknown_weight && ( null === $weight || $weight <= 0 ) ) {
			$message = __( 'Enter a parcel weight before booking because an allocated product no longer has a known weight.', 'smart-send-logistics' );
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fulfillment renders these plain-text validation errors.
			throw new Booking_Exception( $message, array( 'parcel_plan.specs.' . $index . '.weight' => array( $message ) ) );
		}
		if ( null === $weight ) {
			$weight = $this->value_or_null( $this->default_parcel_weight( $item_weight_total, $spec ) );
		}

		$parcel->set_weight( $weight )
			->set_items( $item_rows )
			->set_total_net_amount( $spec->has_items() ? round( $item_net_total, $amount_precision ) : null )
			->set_total_tax_amount( $spec->has_items() ? round( $item_tax_total, $amount_precision ) : null );

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
	 * @param Parcel_Spec $spec        The planned parcel the weight is for.
	 *
	 * @return float
	 */
	protected function default_parcel_weight( float $item_weight, Parcel_Spec $spec ): float {
		/*
		 * Filter the default weight of a parcel that has no explicit
		 * weight: the sum of the weights of the items allocated to it.
		 * Use it to add packaging weight or apply a minimum. A parcel
		 * spec with an explicit weight (set_weight() on the
		 * Parcel_Spec, e.g. entered in the order meta box)
		 * bypasses this filter entirely.
		 *
		 * @since 9.0.0
		 *
		 * @param float                   $weight The computed weight in kg (0 when the items have no weight or the parcel has no items).
		 * @param Parcel_Spec $spec   The planned parcel (reference, dimensions, item allocations).
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
	 * @param Pickup_Point $pickup_point The selected pickup point.
	 *
	 * @return array
	 */
	protected function build_pickup_point( Pickup_Point $pickup_point ) {
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
