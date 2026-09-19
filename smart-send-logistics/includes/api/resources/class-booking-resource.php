<?php

namespace Smart_Send\API\Resources;

use Smart_Send\API\Client;
use Smart_Send\API\Exceptions\Forbidden_Exception;
use Smart_Send\API\Exceptions\Request_Exception;
use Smart_Send\API\Exceptions\Server_Exception;
use Smart_Send\API\Exceptions\Unauthenticated_Exception;
use Smart_Send\API\Exceptions\Unexpected_Response_Exception;
use Smart_Send\API\Exceptions\Validation_Exception;
use Smart_Send\API\Models\Shipment;
use Smart_Send\API\Models\Shipment\Agent as Shipment_Agent;
use Smart_Send\API\Models\Shipment\Item;
use Smart_Send\API\Models\Shipment\Parcel;
use Smart_Send\API\Models\Shipment\Receiver;
use Smart_Send\API\Models\Shipment\Services;

/**
 * Books a shipment (and its labels) with the Smart Send API.
 *
 * This is the single point where the internal shipment representation
 * assembled by Smart_Send\Booking\Shipment_Builder (#113) - a typed WP-side value
 * object, \Smart_Send\Booking\Shipment, not to be confused with this namespace's
 * own Shipment (the v1 wire model, see the use statement below) - is
 * translated into the v1 wire request (Smart_Send\API\Models\Shipment and its
 * sub-models). The excl/incl price pairs the v1 API expects are
 * (re)computed here, once, from the representation's single net/tax
 * amounts. A future v2 client would replace from_shipment() with a
 * v2-shaped translation; nothing above this resource (\Smart_Send\Booking\Shipment,
 * Smart_Send\Booking\Shipment_Builder, Smart_Send\Booking\Order_Reader) would need to
 * change (#111).
 *
 * This class deliberately stays WordPress-light: it only depends on
 * \Smart_Send\Booking\Shipment by its fully-qualified name as a type hint,
 * and that class itself makes no WordPress calls - the dependency points
 * from the booking domain into this API layer, never the other way
 * around. It must never construct or return a WP-side type such as
 * Smart_Send\Booking\Booked_Shipment; that mapping is Smart_Send\Booking\Booking_Service's job.
 */
class Booking_Resource {

	/** @var Client */
	protected $client;

	public function __construct( Client $client ) {
		$this->client = $client;
	}

	/**
	 * Book a shipment and create its labels in a single call.
	 *
	 * @param   Shipment $shipment The v1 wire shipment, see self::from_shipment().
	 * @return  \Smart_Send\API\Response The response; data() is the booked shipment object.
	 * @throws  \Smart_Send\API\Exceptions\HTTP_Client_Exception
	 */
	public function create( Shipment $shipment ) {
		try {
			$response = $this->client->http_post( 'shipments/labels', array(), array(), $shipment );
		} catch ( Request_Exception $e ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Carries structured API data; escaping belongs to the presentation layer.
			throw $this->domain_exception( $e );
		}

		if ( ! is_object( $response->data() ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Carries structured API data; escaping belongs to the presentation layer.
			throw new Unexpected_Response_Exception( $response );
		}

		return $response;
	}

	/**
	 * Combine the labels of multiple already-booked shipments into a
	 * single PDF.
	 *
	 * @param   string[] $shipment_ids
	 * @return  \Smart_Send\API\Response The response; data() is the combined-label object.
	 * @throws  \Smart_Send\API\Exceptions\HTTP_Client_Exception
	 */
	public function combine( array $shipment_ids ) {
		$request = array(
			'shipments' => array(),
		);

		foreach ( $shipment_ids as $shipment_id ) {
			$request['shipments'][] = array( 'shipment_id' => $shipment_id );
		}

		try {
			$response = $this->client->http_post( 'shipments/labels/combine', array(), array(), $request );
		} catch ( Request_Exception $e ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Carries structured API data; escaping belongs to the presentation layer.
			throw $this->domain_exception( $e );
		}

		if ( ! is_object( $response->data() ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Carries structured API data; escaping belongs to the presentation layer.
			throw new Unexpected_Response_Exception( $response );
		}

		return $response;
	}

	/**
	 * Re-throw a Request_Exception as the domain exception this resource's
	 * calls give the status code.
	 *
	 * @param   Request_Exception $e
	 * @return  Request_Exception
	 */
	private function domain_exception( Request_Exception $e ): Request_Exception {
		$status_code = (int) $e->get_response()->status_code();

		if ( 401 === $status_code ) {
			return new Unauthenticated_Exception( $e->get_response(), $e->getMessage() );
		}
		if ( 403 === $status_code ) {
			return new Forbidden_Exception( $e->get_response(), $e->getMessage() );
		}
		if ( 422 === $status_code ) {
			return new Validation_Exception( $e->get_response(), $e->getMessage() );
		}
		if ( $status_code >= 500 ) {
			return new Server_Exception( $e->get_response(), $e->getMessage() );
		}

		return $e;
	}

	/**
	 * Translate the internal shipment representation into the v1 wire
	 * shipment model.
	 *
	 * @param   \Smart_Send\Booking\Shipment $shipment The internal shipment representation from Smart_Send\Booking\Shipment_Builder.
	 * @return  Shipment
	 */
	public function from_shipment( \Smart_Send\Booking\Shipment $shipment ): Shipment {
		$receiver = $this->build_receiver( $shipment );

		$wire_shipment = new Shipment();
		$wire_shipment->set_receiver( $receiver );

		if ( ! empty( $shipment->get_pickup_point() ) ) {
			$wire_shipment->set_agent( $this->build_agent( $shipment->get_pickup_point() ) );
		}

		$parcels = array();
		foreach ( $shipment->get_parcels() as $parcel_row ) {
			$parcels[] = $this->build_parcel( $parcel_row );
		}

		// Create services.
		$services = new Services();
		$services->set_sms_notification( $receiver->get_sms() ) // Always enable SMS notification.
			->set_email_notification( $receiver->get_email() ); // Always enable Email notification.

		$wire_shipment->set_internal_id( $shipment->get_internal_id() )
			->set_internal_reference( $this->value_or_null( $shipment->get_internal_reference() ) )
			->set_shipping_carrier( $this->value_or_null( $shipment->get_shipping_carrier() ) )
			->set_shipping_method( $this->value_or_null( $shipment->get_shipping_method() ) )
			->set_shipping_date( $shipment->get_shipping_date() )
			->set_parcels( $parcels ) // Alternatively add each parcel using $shipment->add_parcel(Parcel $parcel).
			->set_services( $services )
			->set_subtotal_price_excluding_tax( $this->value_or_null( $shipment->get_subtotal_net_amount() ) )
			->set_subtotal_price_including_tax( $this->value_or_null( $this->net_plus_tax( $shipment->get_subtotal_net_amount(), $shipment->get_subtotal_tax_amount() ) ) )
			->set_total_price_excluding_tax( $this->value_or_null( $shipment->get_total_net_amount() ) )
			->set_total_price_including_tax( $this->value_or_null( $this->net_plus_tax( $shipment->get_total_net_amount(), $shipment->get_total_tax_amount() ) ) )
			->set_shipping_price_excluding_tax( $this->value_or_null( $shipment->get_shipping_net_amount() ) )
			->set_shipping_price_including_tax( $this->value_or_null( $this->net_plus_tax( $shipment->get_shipping_net_amount(), $shipment->get_shipping_tax_amount() ) ) )
			->set_total_tax_amount( $this->value_or_null( $shipment->get_total_tax_amount() ) )
			->set_currency( $this->value_or_null( $shipment->get_currency() ) );

		return $wire_shipment;
	}

	/**
	 * Build the receiver model from the representation's receiver section.
	 *
	 * @param   \Smart_Send\Booking\Shipment $shipment The internal shipment representation.
	 * @return  Receiver
	 */
	protected function build_receiver( \Smart_Send\Booking\Shipment $shipment ): Receiver {
		$receiver_data = $shipment->get_receiver();

		$receiver = new Receiver();
		$receiver->set_internal_id( $shipment->get_internal_id() )
			->set_internal_reference( $this->value_or_null( $shipment->get_internal_reference() ) )
			->set_company( $this->value_or_null( $receiver_data['company'] ) )
			->set_name_line1( $this->value_or_null( $receiver_data['name_line1'] ) )
			->set_name_line2( $this->value_or_null( $receiver_data['name_line2'] ) )
			->set_address_line1( $this->value_or_null( $receiver_data['address_line1'] ) )
			->set_address_line2( $this->value_or_null( $receiver_data['address_line2'] ) )
			->set_postal_code( $this->value_or_null( $receiver_data['postal_code'] ) )
			->set_city( $this->value_or_null( $receiver_data['city'] ) )
			->set_country( $this->value_or_null( $receiver_data['country'] ) )
			->set_sms( $this->value_or_null( $receiver_data['phone'] ) )
			->set_email( $this->value_or_null( $receiver_data['email'] ) );

		return $receiver;
	}

	/**
	 * Build the agent (pickup point) model from the representation's
	 * pickup-point section.
	 *
	 * @param   array $pickup_point Pickup point data, sourced from \Smart_Send\Booking\Shipment::get_pickup_point(), see Smart_Send\Booking\Shipment_Builder::build_pickup_point().
	 * @return  Shipment_Agent
	 */
	protected function build_agent( array $pickup_point ): Shipment_Agent {
		$agent = new Shipment_Agent();
		$agent->set_internal_id( $pickup_point['internal_id'] )
			->set_internal_reference( $pickup_point['internal_reference'] )
			->set_agent_no( $pickup_point['service_point_code'] )
			->set_company( $pickup_point['company'] )
			->set_address_line1( $pickup_point['address_line1'] )
			->set_address_line2( $pickup_point['address_line2'] )
			->set_postal_code( $pickup_point['postal_code'] )
			->set_city( $pickup_point['city'] )
			->set_country( $pickup_point['country'] );

		return $agent;
	}

	/**
	 * Build an item model from an item row of the representation.
	 *
	 * @param   array $item_row Item row, sourced from one of \Smart_Send\Booking\Shipment::get_parcels()'s 'items' arrays, see Smart_Send\Booking\Order_Reader::get_items_data().
	 * @return  Item
	 */
	protected function build_item( array $item_row ): Item {
		$quantity = $item_row['quantity'] ? $item_row['quantity'] : 1;

		// The wire format's unit_price_* pair is recomputed here, once,
		// from the representation's single net/tax amount (#113).
		$unit_net_amount = $item_row['total_net_amount'] / $quantity;
		$unit_tax_amount = $item_row['total_tax_amount'] / $quantity;

		$item = new Item();
		$item->set_internal_id( $this->value_or_null( $item_row['order_item_id'] ) )
			->set_internal_reference( $this->value_or_null( $item_row['order_item_id'] ) )
			->set_sku( $this->value_or_null( $item_row['sku'] ) )
			->set_name( $this->value_or_null( $item_row['name'] ) )
			->set_description( $this->value_or_null( $item_row['description'] ) ) // The product description can be used, but is often too long (255).
			->set_hs_code( $this->value_or_null( $item_row['hs_code'] ) )
			->set_country_of_origin( $this->value_or_null( $item_row['country_of_origin'] ) )
			->set_image_url( null ) // The product image url can be used, but sometimes includes spaces (bug) which causes validation error.
			->set_unit_weight( $item_row['unit_weight'] > 0 ? $item_row['unit_weight'] : null )
			->set_unit_price_excluding_tax( $unit_net_amount )
			->set_unit_price_including_tax( $unit_net_amount + $unit_tax_amount )
			->set_quantity( $this->value_or_null( $item_row['quantity'] ) )
			->set_total_price_excluding_tax( $item_row['total_net_amount'] )
			->set_total_price_including_tax( $this->net_plus_tax( $item_row['total_net_amount'], $item_row['total_tax_amount'] ) )
			->set_total_tax_amount( $item_row['total_tax_amount'] );

		return $item;
	}

	/**
	 * Build a parcel model from a resolved parcel of the representation.
	 *
	 * An item-less parcel (resolved from a parcel spec without item
	 * allocations, #139) serializes its items as null - the v1 wire
	 * convention for "not set" - and carries no amounts of its own.
	 *
	 * @param   \Smart_Send\Booking\Parcel $resolved_parcel Resolved parcel, sourced from \Smart_Send\Booking\Shipment::get_parcels().
	 * @return  Parcel
	 */
	protected function build_parcel( \Smart_Send\Booking\Parcel $resolved_parcel ): Parcel {
		$items = array();
		foreach ( $resolved_parcel->get_items() as $item_row ) {
			$items[] = $this->build_item( $item_row );
		}

		// The total_price_including_tax figure stays null (not 0.0) when
		// the parcel carries no amounts at all.
		$has_amounts = $resolved_parcel->get_total_net_amount() !== null || $resolved_parcel->get_total_tax_amount() !== null;

		$parcel = new Parcel();
		$parcel->set_internal_id( $this->value_or_null( $resolved_parcel->get_internal_id() ) )
			->set_internal_reference( $this->value_or_null( $resolved_parcel->get_internal_reference() ) )
			->set_weight( $this->value_or_null( $resolved_parcel->get_weight() ) )
			->set_height( $resolved_parcel->get_height() )
			->set_width( $resolved_parcel->get_width() )
			->set_length( $resolved_parcel->get_length() )
			->set_freetext( $this->value_or_null( $resolved_parcel->get_freetext() ) )
			->set_total_price_excluding_tax( $resolved_parcel->get_total_net_amount() )
			->set_total_price_including_tax( $has_amounts ? $this->net_plus_tax( $resolved_parcel->get_total_net_amount(), $resolved_parcel->get_total_tax_amount() ) : null )
			->set_total_tax_amount( $resolved_parcel->get_total_tax_amount() );

		if ( array() !== $items ) {
			$parcel->set_items( $items ); // Alternatively add each item using $parcel->add_item(Item $item).
		}

		return $parcel;
	}

	/**
	 * Add a net amount and a tax amount together, treating a null operand
	 * as zero - the including-tax wire figure derived from the
	 * representation's single net/tax amount (#113).
	 *
	 * @param   float|null $net_amount Net (excluding tax) amount.
	 * @param   float|null $tax_amount Tax amount.
	 * @return  float
	 */
	protected function net_plus_tax( $net_amount, $tax_amount ) {
		return (float) $net_amount + (float) $tax_amount;
	}

	/**
	 * Return the value when truthy, null otherwise (the API models expect
	 * null instead of empty/zero values).
	 *
	 * @param   mixed $value The value to check.
	 * @return  mixed|null
	 */
	protected function value_or_null( $value ) {
		if ( $value ) {
			return $value;
		}

		return null;
	}
}
