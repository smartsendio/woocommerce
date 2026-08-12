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

if ( ! class_exists( 'SS_Shipping_Shipment' ) ) :

	/**
	 * Assembles the Smart Send API shipment model for a WooCommerce order and
	 * sends it to the API. All WooCommerce data access goes through
	 * SS_Shipping_Order_Data; this class only builds the API models.
	 */
	class SS_Shipping_Shipment {

		/**
		 * The WooCommerce order.
		 *
		 * @var WC_Order|null
		 */
		protected $order = null;

		/**
		 * Order data access utility.
		 *
		 * @var SS_Shipping_Order_Data|null
		 */
		protected $order_data = null;

		/**
		 * The admin order integration.
		 *
		 * @var SS_Shipping_WC_Order|null
		 */
		protected $shipping_order = null;

		/**
		 * The shipment model being assembled.
		 *
		 * @var \Smartsend\Models\Shipment|null
		 */
		protected $shipment = null;

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

			// New shipment model.
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
		 * Create Payload for API request.
		 *
		 * @param boolean $is_return Whether the label is a return label.
		 *
		 * @return array|void
		 */
		protected function make_single_shipment_api_payload( $is_return ) {
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
			$ss_args['ss_parcels'] = $this->shipping_order->get_ss_shipping_order_parcels( $order_id );

			/*
			 * Filter the arguments used when creating a shipping label
			 *
			 * @param array   $ss_args  contains info about shipping carrier, shipping method, agent and parcels
			 * @param int     $order_id Order ID
			 * @param boolean $is_return Whether or not the label is return (true) or normal (false)
			 */
			$ss_args = apply_filters( 'smart_send_shipping_label_args', $ss_args, $order_id, $is_return );

			// Add the receiver to the shipment.
			$receiver = $this->build_receiver();
			$this->shipment->setReceiver( $receiver );

			// Add the agent (pick-up point) to the shipment, if any.
			$ss_agent = apply_filters(
				'smart_send_order_agent',
				empty( $ss_args['ss_agent'] ) ? null : $ss_args['ss_agent'],
				$order_id
			);

			if ( ! empty( $ss_agent ) ) {
				$this->shipment->setAgent( $this->build_agent( $ss_agent ) );
			}

			// Item lines and totals.
			$items_data = $this->order_data->get_items_data();

			$parcels = array();
			$totals  = array(
				'subtotal_price_excluding_tax' => null,
				'subtotal_price_including_tax' => null,
				'subtotal_tax_amount'          => null,
				'shipping_price_excluding_tax' => null,
				'shipping_price_including_tax' => null,
				'shipping_tax_amount'          => null,
				'total_price_excluding_tax'    => null,
				'total_price_including_tax'    => null,
				'total_tax_amount'             => null,
				'currency'                     => null,
			);

			if ( ! empty( $items_data ) ) {
				$totals     = $this->order_data->get_totals();
				$order_note = $this->order_data->get_order_note();

				// Build the item models, keyed by product/variation id so a
				// parcel split can reference them.
				$items        = array();
				$item_lookup  = array();
				$weight_total = 0;

				foreach ( $items_data as $item_row ) {
					$item_model = $this->build_item( $item_row );

					$items[] = $item_model;

					$item_lookup[ $item_row['id'] ] = array(
						'row'   => $item_row,
						'model' => $item_model,
					);

					if ( $item_row['unit_weight'] ) {
						$weight_total += ( $item_row['quantity'] * $item_row['unit_weight'] );
					}
				}

				if ( ! empty( $ss_args['ss_parcels'] ) ) {
					if ( is_array( $ss_args['ss_parcels'] ) ) {
						$parcels = $this->build_split_parcels( $ss_args['ss_parcels'], $item_lookup, $order_note );
					}
				} else {
					// Create a single parcel containing the items just defined.
					$parcel = new \Smartsend\Models\Shipment\Parcel();
					$parcel->setInternalId( $this->value_or_null( $order_id ) )
						->setInternalReference( $this->value_or_null( $this->order_data->get_order_number() ) )
						->setWeight( $this->value_or_null( $weight_total ) )
						->setHeight( null )
						->setWidth( null )
						->setLength( null )
						->setFreetext( $this->value_or_null( $order_note ) )
						->setItems( $items )// Alternatively add each item using $parcel->addItem(Item $item).
						->setTotalPriceExcludingTax( $this->value_or_null( $totals['subtotal_price_excluding_tax'] ) )
						->setTotalPriceIncludingTax( $this->value_or_null( $totals['subtotal_price_including_tax'] ) )
						->setTotalTaxAmount( $this->value_or_null( $totals['subtotal_tax_amount'] ) );

					$parcels[] = $parcel;
				}
			}

			/*
			 * Filter the parcels section of the booking request.
			 *
			 * @param \Smartsend\Models\Shipment\Parcel[] $parcels The assembled parcel models.
			 * @param WC_Order                            $order   The WooCommerce order.
			 */
			$parcels = apply_filters( 'smart_send_payload_parcels', $parcels, $this->order );

			// Create services.
			$services = new \Smartsend\Models\Shipment\Services();
			$services->setSmsNotification( $receiver->getSms() )// Always enable SMS notification.
				->setEmailNotification( $receiver->getEmail() ); // Always enable Email notification.

			// Add final parameters to shipment.
			$this->shipment->setInternalId( $this->value_or_null( $order_id ) )
				->setInternalReference( $this->value_or_null( $this->order_data->get_order_number() ) )
				->setShippingCarrier( $this->value_or_null( $ss_args['ss_carrier'] ) )
				->setShippingMethod( $this->value_or_null( $ss_args['ss_type'] ) )
				->setShippingDate( gmdate( 'Y-m-d' ) )
				->setParcels( $parcels )// Alternatively add each parcel using $this->shipment->addParcel(Parcel $parcel).
				->setServices( $services )
				->setSubtotalPriceExcludingTax( $this->value_or_null( $totals['subtotal_price_excluding_tax'] ) )
				->setSubtotalPriceIncludingTax( $this->value_or_null( $totals['subtotal_price_including_tax'] ) )
				->setTotalPriceExcludingTax( $this->value_or_null( $totals['total_price_excluding_tax'] ) )
				->setTotalPriceIncludingTax( $this->value_or_null( $totals['total_price_including_tax'] ) )
				->setShippingPriceExcludingTax( $this->value_or_null( $totals['shipping_price_excluding_tax'] ) )
				->setShippingPriceIncludingTax( $this->value_or_null( $totals['shipping_price_including_tax'] ) )
				->setTotalTaxAmount( $this->value_or_null( $totals['total_tax_amount'] ) )
				->setCurrency( $this->value_or_null( $totals['currency'] ) );
		}

		/**
		 * Build the receiver model from the order data.
		 *
		 * @return \Smartsend\Models\Shipment\Receiver
		 */
		protected function build_receiver() {
			$receiver_data = $this->order_data->get_receiver_data();

			$receiver = new \Smartsend\Models\Shipment\Receiver();
			$receiver->setInternalId( $this->order_data->get_order_id() )
				->setInternalReference( $this->value_or_null( $this->order_data->get_order_number() ) )
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
		 * Build the agent (pick-up point) model from the stored agent object.
		 *
		 * @param object $ss_agent The agent object stored on the order.
		 *
		 * @return \Smartsend\Models\Shipment\Agent
		 */
		protected function build_agent( $ss_agent ) {
			$agent = new \Smartsend\Models\Shipment\Agent();
			$agent->setInternalId( isset( $ss_agent->id ) ? $ss_agent->id : $ss_agent->agent_no )
				->setInternalReference( isset( $ss_agent->id ) ? $ss_agent->id : $ss_agent->agent_no )
				->setAgentNo( $this->value_or_null( $ss_agent->agent_no ) )
				->setCompany( $this->value_or_null( $ss_agent->company ) )
				->setAddressLine1( $this->value_or_null( $ss_agent->address_line1 ) )
				->setAddressLine2( $this->value_or_null( $ss_agent->address_line2 ) )
				->setPostalCode( $this->value_or_null( $ss_agent->postal_code ) )
				->setCity( $this->value_or_null( $ss_agent->city ) )
				->setCountry( $this->value_or_null( $ss_agent->country ) );

			return $agent;
		}

		/**
		 * Build an item model from an item data row.
		 *
		 * @param array $item_row Item row from SS_Shipping_Order_Data::get_items_data().
		 *
		 * @return \Smartsend\Models\Shipment\Item
		 */
		protected function build_item( $item_row ) {
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
				->setUnitPriceExcludingTax( $this->value_or_null( $item_row['unit_price_excluding_tax'] ) )
				->setUnitPriceIncludingTax( $this->value_or_null( $item_row['unit_price_including_tax'] ) )
				->setQuantity( $this->value_or_null( $item_row['quantity'] ) )
				->setTotalPriceExcludingTax( $this->value_or_null( $item_row['total_price_excluding_tax'] ) )
				->setTotalPriceIncludingTax( $this->value_or_null( $item_row['total_price_including_tax'] ) )
				->setTotalTaxAmount( $this->value_or_null( $item_row['total_tax_amount'] ) );

			return $item;
		}

		/**
		 * Build one parcel per box from the parcel-split meta stored on the order.
		 *
		 * @param array       $ss_parcels  Parcel split meta rows (id, name, value).
		 * @param array       $item_lookup Item rows and models keyed by product/variation id.
		 * @param string|null $order_note  Freetext for the parcels.
		 *
		 * @return \Smartsend\Models\Shipment\Parcel[]
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
				$item_total_wo_tax   = 0;
				$item_total_tax      = 0;
				$item_total_incl_tax = 0;
				$item_weight_total   = 0;
				$product_items       = array();

				foreach ( $box_items as $box_item ) {
					$item_row = $item_lookup[ $box_item['id'] ]['row'];

					$item_total_wo_tax   += floatval( $item_row['unit_price_excluding_tax'] );
					$item_total_incl_tax += floatval( $item_row['unit_price_including_tax'] );
					$item_weight_total   += floatval( $item_row['unit_weight'] );

					// Compute for the total tax per individual.
					$item_total_tax += $item_total_incl_tax - $item_total_wo_tax;

					$product_items[] = $item_lookup[ $box_item['id'] ]['model'];
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

				$parcel = new \Smartsend\Models\Shipment\Parcel();
				$parcel->setInternalId( $this->value_or_null( $this->order_data->get_order_id() ) )
					->setInternalReference( $this->value_or_null( $this->order_data->get_order_number() ) )
					->setWeight( $parcel_total_weight )
					->setHeight( null )
					->setWidth( null )
					->setLength( null )
					->setFreetext( $this->value_or_null( $order_note ) )
					->setItems( $product_items )// Alternatively add each item using $parcel->addItem(Item $item).
					->setTotalPriceExcludingTax( $this->value_or_null( $item_total_wo_tax ) )
					->setTotalPriceIncludingTax( $this->value_or_null( $item_total_incl_tax ) )
					->setTotalTaxAmount( $this->value_or_null( $item_total_tax ) );

				$parcels[] = $parcel;
			}

			return $parcels;
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
		 * Return the value when truthy, null otherwise (the API models expect
		 * null instead of empty/zero values).
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
