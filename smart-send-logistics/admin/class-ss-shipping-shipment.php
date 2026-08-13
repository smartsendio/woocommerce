<?php
/**
 * WooCommerce Smart Send Shipping Order Payload.
 *
 * @package  SS_Shipping_Shipment
 * @category Shipping
 * @author   Smart Send
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// A second copy of the plugin may already have defined the class.
if ( ! class_exists( 'SS_Shipping_Shipment' ) ) :

	/**
	 * Sends a WooCommerce order to the Smart Send API as a shipment.
	 *
	 * SS_Shipping_Shipment_Builder assembles the new internal shipment
	 * representation (#113); this class is now only a thin adapter that
	 * translates that representation into the v1 wire request
	 * (Smartsend\Models\Shipment and its sub-models) and sends it. That
	 * translation exists here only because #112's resource layer, which is
	 * meant to own it permanently, is not part of this change - once #112
	 * lands, this class's translate_to_wire_shipment() and its helpers
	 * move there.
	 */
	class SS_Shipping_Shipment {

		/**
		 * The WooCommerce order.
		 *
		 * Deliberately untyped: wc_get_order() can return false (or a
		 * WC_Order_Refund), and the constructor relies on that falsy value.
		 *
		 * @var WC_Order|false|null
		 */
		protected $order = null;

		/**
		 * Order data access utility.
		 *
		 * @var SS_Shipping_Order_Data|null
		 */
		protected ?SS_Shipping_Order_Data $order_data = null;

		/**
		 * The admin order integration.
		 *
		 * @var SS_Shipping_WC_Order|null
		 */
		protected ?SS_Shipping_WC_Order $shipping_order = null;

		/**
		 * The internal shipment representation assembled by
		 * SS_Shipping_Shipment_Builder, or an error array.
		 *
		 * @var array|null
		 */
		protected ?array $representation = null;

		/**
		 * The v1 wire shipment model translated from the representation.
		 *
		 * @var \Smartsend\Models\Shipment|null
		 */
		protected ?\Smartsend\Models\Shipment $shipment = null;

		/**
		 * Init and hook in the integration.
		 *
		 * @param WC_Order|integer     $order          The order (object or id) to build a shipment for.
		 * @param SS_Shipping_WC_Order $shipping_order The admin order integration.
		 */
		public function __construct( $order, $shipping_order ) {
			if ( is_numeric( $order ) && $order > 0 ) {
				$this->order = wc_get_order( $order );

				if ( ! $this->order ) {
					return;
				}
			} elseif ( $order instanceof WC_Order ) {
				$this->order = $order;
			} else {
				return;
			}

			$this->order_data     = new SS_Shipping_Order_Data( $this->order );
			$this->shipping_order = $shipping_order;

			// Wire shipment model, empty until the representation is
			// translated. Kept even on a build error so a caller that
			// still sends the (empty) request behaves as it always has.
			$this->shipment = new \Smartsend\Models\Shipment();
		}

		/**
		 * Create single order.
		 *
		 * @param boolean $is_return Whether the label is a return label.
		 *
		 * @return boolean
		 */
		public function make_single_shipment_api_call( $is_return ) {
			$this->make_single_shipment_api_payload( $is_return );
			$this->make_single_shipment_api_request();

			if ( SS_SHIPPING_WC()->get_api_handle()->isSuccessful() ) {
				return true;
			} else {
				return false;
			}
		}

		/**
		 * Get API call data.
		 *
		 * @return object
		 */
		public function get_shipping_data() {
			return SS_SHIPPING_WC()->get_api_handle()->getData();
		}

		/**
		 * Get error message.
		 *
		 * @return string
		 */
		public function get_error_msg() {
			return SS_SHIPPING_WC()->get_api_handle()->getErrorString();
		}

		/**
		 * Get shipment object.
		 *
		 * @return \Smartsend\Models\Shipment
		 */
		public function get_shipment() {
			return $this->shipment;
		}

		/**
		 * Build the internal shipment representation and translate it into
		 * the v1 wire shipment model.
		 *
		 * @param boolean $is_return Whether the label is a return label.
		 *
		 * @return array|void The representation, only when it is an error.
		 */
		protected function make_single_shipment_api_payload( $is_return ) {
			$builder              = new SS_Shipping_Shipment_Builder( $this->order, $this->order_data, $this->shipping_order );
			$this->representation = $builder->build( $is_return );

			if ( isset( $this->representation['error'] ) ) {
				return $this->representation;
			}

			$this->shipment = $this->translate_to_wire_shipment( $this->representation );
		}

		/**
		 * Translate the internal shipment representation into the v1 wire
		 * shipment model. This is the single point where the excl/incl
		 * pairs are (re)computed from the representation's single net/tax
		 * amounts (#113) - the actual v1 wire payload is unchanged.
		 *
		 * @param array $representation The internal shipment representation from SS_Shipping_Shipment_Builder::build().
		 *
		 * @return \Smartsend\Models\Shipment
		 */
		protected function translate_to_wire_shipment( array $representation ) {
			$receiver = $this->build_receiver( $representation );

			$shipment = new \Smartsend\Models\Shipment();
			$shipment->setReceiver( $receiver );

			if ( ! empty( $representation['pickup_point'] ) ) {
				$shipment->setAgent( $this->build_agent( $representation['pickup_point'] ) );
			}

			$parcels = array();
			foreach ( $representation['parcels'] as $parcel_row ) {
				$parcels[] = $this->build_parcel( $parcel_row );
			}

			// Create services.
			$services = new \Smartsend\Models\Shipment\Services();
			$services->setSmsNotification( $receiver->getSms() )// Always enable SMS notification.
				->setEmailNotification( $receiver->getEmail() ); // Always enable Email notification.

			$shipment->setInternalId( $representation['internal_id'] )
				->setInternalReference( $this->value_or_null( $representation['internal_reference'] ) )
				->setShippingCarrier( $this->value_or_null( $representation['shipping_carrier'] ) )
				->setShippingMethod( $this->value_or_null( $representation['shipping_method'] ) )
				->setShippingDate( $representation['shipping_date'] )
				->setParcels( $parcels )// Alternatively add each parcel using $shipment->addParcel(Parcel $parcel).
				->setServices( $services )
				->setSubtotalPriceExcludingTax( $this->value_or_null( $representation['subtotal_net_amount'] ) )
				->setSubtotalPriceIncludingTax( $this->value_or_null( $this->net_plus_tax( $representation['subtotal_net_amount'], $representation['subtotal_tax_amount'] ) ) )
				->setTotalPriceExcludingTax( $this->value_or_null( $representation['total_net_amount'] ) )
				->setTotalPriceIncludingTax( $this->value_or_null( $this->net_plus_tax( $representation['total_net_amount'], $representation['total_tax_amount'] ) ) )
				->setShippingPriceExcludingTax( $this->value_or_null( $representation['shipping_net_amount'] ) )
				->setShippingPriceIncludingTax( $this->value_or_null( $this->net_plus_tax( $representation['shipping_net_amount'], $representation['shipping_tax_amount'] ) ) )
				->setTotalTaxAmount( $this->value_or_null( $representation['total_tax_amount'] ) )
				->setCurrency( $this->value_or_null( $representation['currency'] ) );

			return $shipment;
		}

		/**
		 * Build the receiver model from the representation's receiver
		 * section.
		 *
		 * @param array $representation The internal shipment representation.
		 *
		 * @return \Smartsend\Models\Shipment\Receiver
		 */
		protected function build_receiver( array $representation ) {
			$receiver_data = $representation['receiver'];

			$receiver = new \Smartsend\Models\Shipment\Receiver();
			$receiver->setInternalId( $representation['internal_id'] )
				->setInternalReference( $this->value_or_null( $representation['internal_reference'] ) )
				->setCompany( $this->value_or_null( $receiver_data['company'] ) )
				->setNameLine1( $this->value_or_null( $receiver_data['name_line1'] ) )
				->setNameLine2( $this->value_or_null( $receiver_data['name_line2'] ) )
				->setAddressLine1( $this->value_or_null( $receiver_data['address_line1'] ) )
				->setAddressLine2( $this->value_or_null( $receiver_data['address_line2'] ) )
				->setPostalCode( $this->value_or_null( $receiver_data['postal_code'] ) )
				->setCity( $this->value_or_null( $receiver_data['city'] ) )
				->setCountry( $this->value_or_null( $receiver_data['country'] ) )
				->setSms( $this->value_or_null( $receiver_data['phone'] ) )
				->setEmail( $this->value_or_null( $receiver_data['email'] ) );

			return $receiver;
		}

		/**
		 * Build the agent (pickup point) model from the representation's
		 * pickup-point section.
		 *
		 * @param array $pickup_point Pickup point data, see SS_Shipping_Shipment_Builder::build_pickup_point().
		 *
		 * @return \Smartsend\Models\Shipment\Agent
		 */
		protected function build_agent( array $pickup_point ) {
			$agent = new \Smartsend\Models\Shipment\Agent();
			$agent->setInternalId( $pickup_point['internal_id'] )
				->setInternalReference( $pickup_point['internal_reference'] )
				->setAgentNo( $pickup_point['service_point_code'] )
				->setCompany( $pickup_point['company'] )
				->setAddressLine1( $pickup_point['address_line1'] )
				->setAddressLine2( $pickup_point['address_line2'] )
				->setPostalCode( $pickup_point['postal_code'] )
				->setCity( $pickup_point['city'] )
				->setCountry( $pickup_point['country'] );

			return $agent;
		}

		/**
		 * Build an item model from an item row of the representation.
		 *
		 * @param array $item_row Item row, see SS_Shipping_Order_Data::get_items_data().
		 *
		 * @return \Smartsend\Models\Shipment\Item
		 */
		protected function build_item( array $item_row ) {
			$quantity = $item_row['quantity'] ? $item_row['quantity'] : 1;

			// The wire format's unit_price_* pair is recomputed here, once,
			// from the representation's single net/tax amount (#113).
			$unit_net_amount = $item_row['total_net_amount'] / $quantity;
			$unit_tax_amount = $item_row['total_tax_amount'] / $quantity;

			$item = new \Smartsend\Models\Shipment\Item();
			$item->setInternalId( $this->value_or_null( $item_row['id'] ) )
				->setInternalReference( $this->value_or_null( $item_row['id'] ) )
				->setSku( $this->value_or_null( $item_row['sku'] ) )
				->setName( $this->value_or_null( $item_row['name'] ) )
				->setDescription( $this->value_or_null( $item_row['description'] ) )// The product description can be used, but is often too long (255).
				->setHsCode( $this->value_or_null( $item_row['hs_code'] ) )
				->setCountryOfOrigin( $this->value_or_null( $item_row['country_of_origin'] ) )
				->setImageUrl( null )// The product image url can be used, but sometimes includes spaces (bug) which causes validation error.
				->setUnitWeight( $item_row['unit_weight'] > 0 ? $item_row['unit_weight'] : null )
				->setUnitPriceExcludingTax( $this->value_or_null( $unit_net_amount ) )
				->setUnitPriceIncludingTax( $this->value_or_null( $unit_net_amount + $unit_tax_amount ) )
				->setQuantity( $this->value_or_null( $item_row['quantity'] ) )
				->setTotalPriceExcludingTax( $this->value_or_null( $item_row['total_net_amount'] ) )
				->setTotalPriceIncludingTax( $this->value_or_null( $this->net_plus_tax( $item_row['total_net_amount'], $item_row['total_tax_amount'] ) ) )
				->setTotalTaxAmount( $this->value_or_null( $item_row['total_tax_amount'] ) );

			return $item;
		}

		/**
		 * Build a parcel model from a parcel row of the representation.
		 *
		 * @param array $parcel_row Parcel row, see SS_Shipping_Shipment_Builder::build().
		 *
		 * @return \Smartsend\Models\Shipment\Parcel
		 */
		protected function build_parcel( array $parcel_row ) {
			$items = array();
			foreach ( $parcel_row['items'] as $item_row ) {
				$items[] = $this->build_item( $item_row );
			}

			$parcel = new \Smartsend\Models\Shipment\Parcel();
			$parcel->setInternalId( $this->value_or_null( $parcel_row['internal_id'] ) )
				->setInternalReference( $this->value_or_null( $parcel_row['internal_reference'] ) )
				->setWeight( $this->value_or_null( $parcel_row['weight'] ) )
				->setHeight( $parcel_row['height'] )
				->setWidth( $parcel_row['width'] )
				->setLength( $parcel_row['length'] )
				->setFreetext( $this->value_or_null( $parcel_row['freetext'] ) )
				->setItems( $items )// Alternatively add each item using $parcel->addItem(Item $item).
				->setTotalPriceExcludingTax( $this->value_or_null( $parcel_row['total_net_amount'] ) )
				->setTotalPriceIncludingTax( $this->value_or_null( $this->net_plus_tax( $parcel_row['total_net_amount'], $parcel_row['total_tax_amount'] ) ) )
				->setTotalTaxAmount( $this->value_or_null( $parcel_row['total_tax_amount'] ) );

			return $parcel;
		}

		/**
		 * Call Smart Send Shipment API, log response.
		 *
		 * @return void
		 */
		protected function make_single_shipment_api_request() {
			// Make API Request. The request and response (incl. HTTP status
			// code and endpoint) are logged by the client's request logger.
			SS_SHIPPING_WC()->get_api_handle()->createShipmentAndLabels( $this->shipment );
		}

		/**
		 * Add a net amount and a tax amount together, treating a null
		 * operand as zero - the including-tax wire figure derived from the
		 * representation's single net/tax amount (#113).
		 *
		 * @param float|null $net_amount Net (excluding tax) amount.
		 * @param float|null $tax_amount Tax amount.
		 *
		 * @return float
		 */
		protected function net_plus_tax( $net_amount, $tax_amount ) {
			return (float) $net_amount + (float) $tax_amount;
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
