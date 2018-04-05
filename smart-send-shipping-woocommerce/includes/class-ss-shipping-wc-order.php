<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * WooCommerce Smart Send Shipping Order.
 *
 * @package  SS_Shipping_WC_Order
 * @category Shipping
 * @author   Shadi Manna
 */

if ( ! class_exists( 'SS_Shipping_WC_Order' ) ) :

class SS_Shipping_WC_Order {
	
	protected $api_handle = null;
	protected $shipment = null;

	/**
	 * Init and hook in the integration.
	 */
	public function __construct( ) {
		// Initiate an API handle with the login credentials.
		$this->api_handle = new \Smartsend\Api('API_KEY');

		//New shipment model
		$this->shipment = new \Smartsend\Models\Shipment();


		$this->define_constants();
		$this->init_hooks();
	}

	protected function define_constants() {
		SS_SHIPPING_WC()->define( 'SS_SHIPPING_BUTTON_LABEL_GEN', __( 'Generate Label', 'smart-send-shipping' ) );
		SS_SHIPPING_WC()->define( 'SS_SHIPPING_BUTTON_LABEL_PRINT', __( 'Download Label', 'smart-send-shipping' ) );
	}

	public function init_hooks() {
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ), 20 );
		add_action( 'woocommerce_process_shop_order_meta', array( $this, 'save_meta_box' ), 0, 2 );

		// Order page metabox actions
		add_action( 'wp_ajax_ss_shipping_generate_label', array( $this, 'save_meta_box_ajax' ) );
		add_action( 'wp_ajax_ss_shipping_delete_label', array( $this, 'delete_label_ajax' ) );		

		$subs_version = class_exists( 'WC_Subscriptions' ) && ! empty( WC_Subscriptions::$version ) ? WC_Subscriptions::$version : null;

		// Prevent data being copied to subscriptions
		if ( null !== $subs_version && version_compare( $subs_version, '2.0.0', '>=' ) ) {
			add_filter( 'wcs_renewal_order_meta_query', array( $this, 'woocommerce_subscriptions_renewal_order_meta_query' ), 10 );
		} else {
			add_filter( 'woocommerce_subscriptions_renewal_order_meta_query', array( $this, 'woocommerce_subscriptions_renewal_order_meta_query' ), 10 );
		}

		// add bulk actions to the Orders screen table bulk action drop-downs
		add_action( 'admin_footer-edit.php', array( $this, 'add_order_bulk_actions' ) );

		// process orders bulk actions
		add_action( 'load-edit.php', array( $this, 'process_orders_bulk_actions' ) );

		// display admin notices for bulk actions
		add_action( 'admin_notices', array( $this, 'render_messages' ) );
	}

	/**
	 * Add the meta box for shipment info on the order page
	 *
	 * @access public
	 */
	public function add_meta_box() {
		global $woocommerce, $post;
		$order_id = $post->ID;

		$ss_shipping_method_id = $this->get_smart_send_method_id( $order_id );

		if( !empty($ss_shipping_method_id) ) {
			
			add_meta_box( 'woocommerce-ss-shipping-label', __( 'Smart Send Shipping', 'smart-send-shipping' ), array( $this, 'meta_box' ), 'shop_order', 'side', 'default' );
		}
	}

	/**
	 * Show the meta box for shipment info on the order page
	 *
	 * @access public
	 */
	public function meta_box() {
		global $woocommerce, $post;
		$order_id = $post->ID;
		
		$ss_shipping_method_id = $this->get_smart_send_method_id( $order_id );

		// Get saved label input fields or set default values
		$ss_shipping_order_agent = $this->get_ss_shipping_order_agent( $order_id );
		
		echo '<div id="ss-shipping-label-form">';

		// use to output esc_html_e!!!
		woocommerce_wp_hidden_input( array(
			'id'    => 'ss_shipping_label_nonce',
			'value' => wp_create_nonce( 'create-ss-shipping-label' )
		) );
		
		$shipping_method_carrier = ucfirst( SS_SHIPPING_WC()->get_shipping_method_carrier( $ss_shipping_method_id ) );
		$shipping_method_type = ucfirst( SS_SHIPPING_WC()->get_shipping_method_type( $ss_shipping_method_id ) );

		echo '<h3>' . __('Shipping Method', 'smart-send-shipping') . '</h3>';
		echo '<p>'. $shipping_method_carrier . ' - ' . $shipping_method_type. '</p>';
		
		// $ss_shipping_options = $this->get_ss_shipping_order_options( $order_id );

		if( !empty( $ss_shipping_order_agent ) ) {
			echo '<h3>' . __('Pickup Point', 'smart-send-shipping') . '</h3>';

			woocommerce_wp_text_input( array(
				'id'          		=> 'ss_shipping_agent_no',
				'label'       		=> __( 'Agent No.', 'smart-send-shipping' ),
				'placeholder' 		=> '',
				'description'		=> sprintf( __( 'Search for an "Agent No." <a href="%s" target="_blank">here</a>', 'smart-send-shipping' ), esc_url( 'https://smartsend.io/pick-up-points' ) ),
				'value'       		=> $ss_shipping_order_agent->agent_no,
				'class'				=> '',
				'type'				=> 'number'
			) );
			
			// error_log(print_r($ss_shipping_order_agent,true));
			echo $this->get_formatted_address( $ss_shipping_order_agent );
		}

		echo '<hr>';
		// echo wc_help_tip( $ss_shipping_method_id );
		echo '</p>';
		

		echo '<button id="ss-shipping-label-button" class="button button-primary button-save-form">' . SS_SHIPPING_BUTTON_LABEL_GEN . '</button>';

		wp_enqueue_script( 'ss-shipping-label-js', SS_SHIPPING_PLUGIN_DIR_URL . '/assets/js/ss-shipping-label.js', array(), SS_SHIPPING_VERSION );
		// wp_localize_script( 'ss-shipping-label-js', 'ss_shipping_label_data', $ss_shipping_label_data );
		
		echo '</div>';
		
	}
	
	protected function get_formatted_address( $ss_shipping_order_agent ) {

		if ( empty($ss_shipping_order_agent) ) {
			return '';
		}

		return '<p class="ss_agent_address">' . $ss_shipping_order_agent->company . '</br>' . $ss_shipping_order_agent->address_line1 . '</br>' . $ss_shipping_order_agent->postal_code . ' ' . $ss_shipping_order_agent->city . '</p>';
	}

	protected function get_smart_send_method_id( $order_id ) {
		$order = wc_get_order( $order_id );
		
		if( ! $order ) {
			return '';
		}

		// Get shipping id to make sure it's SS
		$order_shipping_methods = $order->get_shipping_methods();
		// error_log(print_r($order_shipping_methods,true));

		// $is_smart_send_shipping = false;
		if( !empty($order_shipping_methods) ) {
			foreach ( $order_shipping_methods as $item_id => $item ) {
				$shipping_method_id = ! empty( $item['method_id'] ) ? esc_html( $item['method_id'] ) : null;
				// $shipping_method =  ! empty( $item['name'] ) ? esc_html( $item['name'] ) : null;

				if ( stripos($shipping_method_id, 'smart_send_shipping') !== false ) {
					return $shipping_method_id;
					// $shipping_method_id_parts = explode(':', $shipping_method_id);
					// $shipping_method_ss = $shipping_method_id_parts[1];
					// break;
				} else {
					// If free shipping and setting to set free shipping to Send Smart
					if ( stripos($shipping_method_id, 'free_shipping') !== false ) {
						$ss_settings = SS_SHIPPING_WC()->get_ss_shipping_settings();
						
						if( ! empty( $ss_settings['shipping_method_for_free_shipping'] ) ) {
							return $ss_settings['shipping_method_for_free_shipping'];
						}
					}
				}
			}
		}

		return '';
	}

	public function save_meta_box( $post_id, $post = null ) {
		// error_log('save meta box');
		if( ! isset( $_POST[ 'ss_shipping_agent_no' ] ) ) {
			return;
		}

		$ss_shipping_agent_no = wc_clean( $_POST[ 'ss_shipping_agent_no' ] );
		$saved_ss_shipping_agent_no = $this->get_ss_shipping_order_agent_no( $post_id );

		if ( ! empty( $ss_shipping_agent_no ) && ( $ss_shipping_agent_no != $saved_ss_shipping_agent_no ) ){
            // API call to get agent info by agent no.
            // $carrier = SS_SHIPPING_WC()->get_shipping_method_carrier( $full_method_id );
            $ss_shipping_method_id = $this->get_smart_send_method_id( $post_id );

            if( !empty($ss_shipping_method_id) ) {

                $shipping_method_carrier = SS_SHIPPING_WC()->get_shipping_method_carrier( $ss_shipping_method_id );
                
                $order = wc_get_order( $post_id );
                $shipping_address = $order->get_address( 'shipping' );

                if( ! empty( $shipping_method_carrier ) && ! empty( $shipping_address['country'] ) ) {
                	
	                if( $this->api_handle->getAgentByAgentNo($shipping_method_carrier, $shipping_address['country'], $ss_shipping_agent_no) ) {
	                    $this->save_ss_shipping_order_agent_no( $post_id, $ss_shipping_agent_no );
	                    $this->save_ss_shipping_order_agent( $post_id, $this->api_handle->getData() );
	                } else {
	                    WC_Admin_Meta_Boxes::add_error( sprintf( __( 'The agent number entered, %s, was not found.', 'smart-send-shipping' ), $ss_shipping_agent_no ) );
	                }
                }
            }
		}
	}

	/**
	 * Order Tracking Save AJAX
	 *
	 * Function for saving tracking items
	 */
	public function save_meta_box_ajax( ) {
		check_ajax_referer( 'create-ss-shipping-label', 'ss_shipping_label_nonce' );
		$order_id = wc_clean( $_POST[ 'order_id' ] );

		// Save inputted data first
		$this->save_meta_box( $order_id );

        $args = $this->set_label_args( $order_id );
        error_log(print_r($this->shipment,true));
        if($this->api_handle->createShipmentAndLabels($this->shipment)) {

			$tracking_note = $this->get_tracking_link( $order_id, $this->api_handle->getData() );
			
			$shipment_tracking_details = $this->get_tracking_details( $this->api_handle->getData() );
			foreach($shipment_tracking_details as $parcel_tracking_details) {
                $this->save_tracking_in_shipment_tracking($order_id, $parcel_tracking_details['tracking_code'], $parcel_tracking_details['tracking_link'], $parcel_tracking_details['carrier_name'],$date_shipped=null);
            }

			$agent_address = $this->get_ss_shipping_order_agent( $order_id );
			$agent_address_formatted =  $this->get_formatted_address( $agent_address );

			$this->set_order_status_label( $order_id );



			$agent_address = $this->get_ss_shipping_order_agent( $order_id );
			$agent_address_formatted =  $this->get_formatted_address( $agent_address );

			$this->set_order_status_label( $order_id );

			do_action( 'ss_shipping_label_created', $order_id );

			wp_send_json( array( 
				'tracking_note'	  => $tracking_note,
				'agent_address'	  => $agent_address_formatted,
				'label_link'	  => $this->get_ss_shipping_label_link( $order_id )
			) );

        } else {
        	error_log(print_r($this->api_handle->getError(),true));
            wp_send_json( array( 'error' => $this->api_handle->getError() ) );
        }
		
		wp_die();
	}

	protected function set_order_status_label( $order_id ) {

		$ss_settings = SS_SHIPPING_WC()->get_ss_shipping_settings();
		
		if( ! empty( $ss_settings['order_status'] ) ) {		
			$order = wc_get_order( $order_id );

			$order->update_status( $ss_settings['order_status'] );
		}
	}

	protected function get_tracking_details( $shipment ) {
	    $tracking_array = array();
	    foreach($shipment->parcels as $parcel) {
            $tracking_array[$parcel->parcel_internal_id] = array(
                    'carrier_code' => $parcel->carrier_code,
                    'carrier_name' => $parcel->carrier_name,
                    'tracking_code' => $parcel->tracking_code,
                    'tracking_link' => apply_filters( 'smart_send_tracking_url', $parcel->tracking_link, $parcel->carrier_code ),
            );
        }
        return $tracking_array;
    }

	protected function get_tracking_link( $order_id, $new_shipment ) {
		// TODO: Each parcel will have a tracking number. All these tracking numbers muct be saved instead of just saving one

		$label_url = $this->save_label_file( $order_id, $new_shipment->parcels[0]->parcel_internal_id, $new_shipment->parcels[0]->pdf->base_64_encoded );
		$tracking_number = $new_shipment->parcels[0]->tracking_code;
		$tracking_link = $new_shipment->parcels[0]->tracking_link;

		$tracking_note = sprintf( __( '<label>Shipping label: </label><a href="%s" target="_blank">Download Label</a><br/><label>Tracking number: </label><a href="%s" target="_blank">%s</a>', 'smart-send-shipping' ), $label_url, $tracking_link, $tracking_number);
		
		return $tracking_note;
	}

	protected function save_label_file( $order_id, $label_id, $label_data ) {
		
		if ( empty($label_id) ) {
			throw new Exception( __('Label id is empty', 'smart-send-shipping' ) );
		}

		if ( empty($label_data) ) {
			throw new Exception( __('Label data empty', 'smart-send-shipping' ) );
		}

		$label_name = 'smart-send-label-' . $label_id . '.pdf';
		$upload_path = wp_upload_dir();
		$label_path = $upload_path['path'] . '/'. $label_name;
		$label_url = $upload_path['url'] . '/'. $label_name;

		if( validate_file($label_path) > 0 ) {
			throw new Exception( __('Invalid file path!', 'smart-send-shipping' ) );
		}

		$label_data_decoded = base64_decode($label_data);
		$file_ret = file_put_contents( $label_path, $label_data_decoded );
		
		if( empty( $file_ret ) ) {
			throw new Exception( __('Label file cannot be saved', 'smart-send-shipping' ) );
		}

		$this->save_ss_shipping_label( $order_id, $label_url );

		return $label_url;
	}

	/**
	 * Saves the label items array to post_meta.
	 *
	 * @param int   $order_id       Order ID
	 * @param array $agent_no 		Agent No.
	 *
	 * @return void
	 */
	public function save_ss_shipping_order_agent_no( $order_id, $agent_no ) {
		update_post_meta( $order_id, 'ss_shipping_order_agent_no', $agent_no );
	}

	/*
	 * Gets all label items from the post meta array for an order
	 *
	 * @param int  $order_id  Order ID
	 *
	 * @return Agent No
	 */
	public function get_ss_shipping_order_agent_no( $order_id ) {
		return get_post_meta( $order_id, 'ss_shipping_order_agent_no', true );
	}

	/**
	 * Saves the label items array to post_meta.
	 *
	 * @param int   $order_id       Order ID
	 * @param array $agent 			Agent Object
	 *
	 * @return void
	 */
	public function save_ss_shipping_order_agent( $order_id, $agent ) {
		update_post_meta( $order_id, '_ss_shipping_order_agent', $agent );
	}

	/*
	 * Gets all label itesm fron the post meta array for an order
	 *
	 * @param int  $order_id  Order ID
	 *
	 * @return Agent Object
	 */
	public function get_ss_shipping_order_agent( $order_id ) {
		return get_post_meta( $order_id, '_ss_shipping_order_agent', true );
	}

	/**
	 * Saves the label to post_meta.
	 *
	 * @param int   $order_id       Order ID
	 * @param string $label_url 	Label URL
	 *
	 * @return void
	 */
	public function save_ss_shipping_label( $order_id, $label_url ) {
		update_post_meta( $order_id, '_ss_shipping_label', $label_url );
	}

	/*
	 * Gets all label the post meta array for an order
	 *
	 * @param int  $order_id  Order ID
	 *
	 * @return Label URL
	 */
	public function get_ss_shipping_label( $order_id ) {
		return get_post_meta( $order_id, '_ss_shipping_label', true );
	}

	/*
	 * Get label link 
	 *
	 * @param int  $order_id  Order ID
	 *
	 * @return Label URL link
	 */
	public function get_ss_shipping_label_link( $order_id ) {
		return '<a href="' . $this->get_ss_shipping_label( $order_id ) . '" target="_blank">' . __('Download Shipping Label', 'smart-send-shipping') . '</a>';
	}


    /*
     * Save tracking number in Shipment Tracking
     *
     * @param int  $order_id  Order ID
     *
     * @return void
     */
    public function save_tracking_in_shipment_tracking( $order_id, $tracking_number, $tracking_url, $provider='Smart Send',$date_shipped=null ) {

        if(!$date_shipped) {
            $date_shipped = date("Y-m-d");
        }

        if ( function_exists( 'wc_st_add_tracking_number' ) ) {
            wc_st_add_tracking_number( $order_id, $tracking_number, $provider, $date_shipped, $tracking_url );
        }
    }

    // TODO: Test if this function is used at all?
	protected function calculate_order_weight( $order_id ) {
		$order = wc_get_order( $order_id );

		$ordered_items = $order->get_items( );

		$total_weight = 0;
		foreach ($ordered_items as $key => $item) {
					
			if( ! empty( $item['variation_id'] ) ) {
				$product = wc_get_product($item['variation_id']);
			} else {
				$product = wc_get_product( $item['product_id'] );
			}
			
			$product_weight = round(wc_get_weight($product->get_weight(), 'kg'),2);
			if( $product_weight ) {
				$total_weight += ( $item['qty'] * $product_weight );
			}
		}

		return $total_weight;
	}

	protected function set_label_args( $order_id ) {
		// Get settings from child implementation
		$ss_settings = SS_SHIPPING_WC()->get_ss_shipping_settings();		
		
		$order = wc_get_order( $order_id );
		$order_num = $order->get_order_number();

		// Get address related information 
		$billing_address = $order->get_address( );
		$shipping_address = $order->get_address( 'shipping' );

		// If shipping phone number doesn't exist, try to get billing phone number
		if( ! isset( $shipping_address['phone'] ) && isset( $billing_address['phone'] ) ) {
			$shipping_address['phone'] = $billing_address['phone'];			
		}

		// If shipping email doesn't exist, try to get billing email
		if( ! isset( $shipping_address['email'] ) && isset( $billing_address['email'] ) ) {
			$shipping_address['email'] = $billing_address['email'];
		}

		// Make receiver object.
		$receiver = new \Smartsend\Models\Shipment\Receiver();
		$receiver->setInternalId( $order_id )
		    ->setInternalReference( $order_id ?: null )
		    ->setCompany( $shipping_address['company'] ?: null )
		    ->setNameLine1( $shipping_address['first_name'] ?: null )
		    ->setNameLine2( $shipping_address['last_name'] ?: null )
		    ->setAddressLine1( $shipping_address['address_1'] ?: null )
		    ->setAddressLine2( $shipping_address['address_2'] ?: null )
		    ->setPostalCode( $shipping_address['postcode'] ?: null )
		    ->setCity( $shipping_address['city'] ?: null )
		    ->setCountry( $shipping_address['country'] ?: null )
		    ->setSms( $shipping_address['phone'] ?: null )
		    ->setEmail( $shipping_address['email'] ?: null );

		// Add the receiver to the shipment
		$this->shipment->setReceiver($receiver);

		// Add the sender to the shipment (we use the system default for now)
		//$this->shipment->setSender(Sender $sender);

		$ss_agent = $this->get_ss_shipping_order_agent( $order_id );

		if ( ! empty( $ss_agent ) ) {
			// Add an agent (pickup point) to the shipment
			$agent = new Smartsend\Models\Shipment\Agent();
			$agent->setInternalId( $ss_agent->id ?: null )
			    ->setInternalReference( $ss_agent->id ?: null )
                ->setAgentNo( $ss_agent->agent_no ?: null )
			    ->setCompany( $ss_agent->company ?: null )
			    // ->setNameLine1(null)
			    // ->setNameLine2(null)
			    ->setAddressLine1( $ss_agent->address_line1 ?: null )
			    ->setAddressLine2( $ss_agent->address_line2 ?: null )
			    ->setPostalCode( $ss_agent->postal_code ?: null )
			    ->setCity( $ss_agent->city ?: null )
			    ->setCountry( $ss_agent->country ?: null );
			    // ->setSms('30274735')
			    // ->setEmail('email@example.com');

			// Add the agent to the shipment
			$this->shipment->setAgent($agent);
		}

		// Get order item specific data
		$ordered_items = $order->get_items( );
		$args['items'] = array();
		// error_log(print_r($ordered_items,true));

		if ( !empty( $ordered_items ) ) {
			$items = array();
			$index = 0;
			$weight_total = 0;
			foreach ($ordered_items as $key => $item) {
				$product = wc_get_product( $item['product_id'] );
			
				// error_log(print_r($product,true));
				error_log(print_r($item,true));

				if( ! empty( $item['variation_id'] ) ) {
					$product_variation = wc_get_product( $item['variation_id'] );
					// Ensure id is string and not int
					$product_id = $item['variation_id'];
					$product_sku = empty( $product_variation->get_sku() ) ? strval( $item['variation_id'] ) : $product_variation->get_sku();

					// $product_attribute = wc_get_product_variation_attributes($item['variation_id']);
					// $product_description .= ' : ' . current( $product_attribute );

				} else {
					$product_variation = $product;
					$product_id = $item['product_id'];
					// Ensure id is string and not int
					$product_sku = empty( $product->get_sku() ) ? strval( $item['product_id'] ) : $product->get_sku();
				}
				
				$product_description = $product->get_title();

				// WC 3.0 code!
				if ( defined( 'WOOCOMMERCE_VERSION' ) && version_compare( WOOCOMMERCE_VERSION, '3.0', '>=' ) ) {
					// $product_val_tax = wc_get_price_including_tax( $product_variation );
					// $args['qty'] = $item['qty'];
					// $product_val_tax_total = wc_get_price_including_tax( $product_variation, $args );
					
					// Total w/o tax and individual w/o tax
					$product_val_total = (float) $item->get_subtotal();
					$product_val = $product_val_total / $item['qty'];
					
					// Total tax
					$product_tax_total = (float) $item->get_subtotal_tax();

					// Total w/ tax and indivdual w/ tax
					$product_val_tax_total = $product_val_total + $product_tax_total;
					$product_val_tax = $product_val_tax_total / $item['qty'];


					if( ! empty( $product->get_short_description() ) ) {
						$product_description = $product->get_short_description();
					} elseif ( ! empty( $product->get_description() ) ) {
						$product_description = $product->get_description();
					}

				} else {
					// Total w/o tax and individual w/o tax
					$product_val_total = (float) $item['line_subtotal'];
					$product_val = $product_val_total / $item['qty'];
					
					// Total tax
					$product_tax_total = (float) $item['line_tax'];

					// Total w/ tax and indivdual w/ tax
					$product_val_tax_total = $product_val_total + $product_tax_total;
					$product_val_tax = $product_val_tax_total / $item['qty'];

					if( ! empty( $product->post->post_excerpt ) ) {
						$product_description = $product->post->post_excerpt;
					} elseif ( ! empty( $product->post->post_content ) ) {
						$product_description = $product->post->post_content;
					}

				}
				
				$product_weight = round(wc_get_weight($product_variation->get_weight(), 'kg'),2);
				if( $product_weight ) {
					$weight_total += ( $item['qty'] * $product_weight );
				}

				$product_img_id = $product->get_image_id();
				$product_img_url = wp_get_attachment_url( $product_img_id );
				// error_log(print_r($product_img,true));
				
				$hs_code = get_post_meta( $item['product_id'], '_ss_hs_code', true );

				$items[ $index ] = new \Smartsend\Models\Shipment\Item();
				$items[ $index ]->setInternalId( $product_id ?: null )
				    ->setInternalReference( $product_id ?: null )
				    ->setSku( $product_sku ?: null )
				    ->setName( $product->get_title() ?: null )
				    ->setDescription( null ) //$product_description can be used, but is often to long (255)
				    ->setHsCode( $hs_code ?: null )
				    ->setImageUrl( $product_img_url ?: null )
				    ->setUnitWeight( $product_weight > 0 ? $product_weight : null )
				    ->setUnitPriceExcludingTax( $product_val ?: null )
				    ->setUnitPriceIncludingTax( $product_val_tax ?: null )
				    ->setQuantity( $item['qty'] ?: null )
				    ->setTotalPriceExcludingTax( $product_val_total ?: null )
				    ->setTotalPriceIncludingTax( $product_val_tax_total ?: null )
				    ->setTotalTaxAmount( $product_tax_total ?: null );

				$index++;
			}
			
			$order_note = null;
				// Create the parcels array
			if ( defined( 'WOOCOMMERCE_VERSION' ) && version_compare( WOOCOMMERCE_VERSION, '3.0', '>=' ) ) {
				if( $ss_settings['include_order_comment'] == 'yes' ) {
					$order_note = $order->get_customer_note();
				}
				$order_currency = $order->get_currency();
			} else {
				if( $ss_settings['include_order_comment'] == 'yes' ) {
					$order_note = $order->customer_note;
				}
				$order_currency = $order->get_order_currency();
			}

			$order_note = apply_filters( 'smart_send_order_note', $order_note, $order );

			// Order totals
			$order_total = $order->get_total();
			$order_total_tax = $order->get_total_tax();
			$order_total_excl = $order_total - $order_total_tax;
			
			// Shipping totals
			$order_shipping = $order->get_total_shipping();
			$order_shipping_tax = $order->get_shipping_tax();
			$order_shipping_excl = $order_shipping - $order_shipping_tax;

			// Order totals without shipping
			$order_subtotal = $order_total - $order_shipping;
			$order_subtotal_tax = $order_total_tax - $order_shipping_tax;
			$order_subtotal_excl = $order_total_excl - $order_subtotal_tax;

			$parcels = array();
			// Create a parcel containing the items just defined
			$parcels[0] = new \Smartsend\Models\Shipment\Parcel();
			$parcels[0]->setInternalId( $order_id ?: null )
			    ->setInternalReference( $order_num ?: null )
			    ->setWeight($weight_total ?: null)
			    ->setHeight(null)
			    ->setWidth(null)
			    ->setLength(null)
			    ->setFreetext1( $order_note ?: null )
			    ->setFreetext2(null)
			    ->setFreetext3(null)
			    ->setItems( $items ) // Alternatively add each item using $parcel->addItem(Item $item)
			    ->setTotalPriceExcludingTax( $order_subtotal_excl ?: null )
			    ->setTotalPriceIncludingTax( $order_subtotal ?: null )
			    ->setTotalTaxAmount( $order_subtotal_tax ?: null );
		}
		
		// Create services
		// $services = new \Smartsend\Models\Shipment\Services();
		// $services->setSmsNotification('3027 4735')
		    // ->setEmailNotification('notify-me@example.com');

		$ss_shipping_method_id = $this->get_smart_send_method_id( $order_id );			
		$shipping_method_carrier = SS_SHIPPING_WC()->get_shipping_method_carrier( $ss_shipping_method_id );
		$shipping_method_type = SS_SHIPPING_WC()->get_shipping_method_type( $ss_shipping_method_id );

		

		// Add final parameters to shipment
		$this->shipment->setInternalId( $order_id ?: null )
		    ->setInternalReference( $order_num ?: null )
		    ->setShippingCarrier( $shipping_method_carrier ?: null )
		    ->setShippingMethod( $shipping_method_type ?: null )
		    ->setShippingDate( date('Y-m-d') )
		    ->setParcels( $parcels ) // Alternatively add each parcel using $shipment->addParcel(Parcel $parcel);
		    // ->setServices( $services )
		    ->setSubTotalPriceExcludingTax( $order_subtotal_excl ?: null )
		    ->setSubTotalPriceIncludingTax( $order_subtotal ?: null )
		    ->setTotalPriceExcludingTax( $order_total_excl ?: null )
		    ->setTotalPriceIncludingTax( $order_total ?: null )
		    ->setShippingPriceExcludingTax( $order_shipping_excl ?: null )
		    ->setShippingPriceIncludingTax( $order_shipping ?: null )
		    ->setTotalTaxAmount( $order_total_tax ?: null )
		    ->setCurrency( $order_currency ?: null );

		// Send the shipment object. The new object will be almost identical, but will have 'id' and 'type' fields
	}

	/**
	 * Prevents data being copied to subscription renewals
	 */
	public function woocommerce_subscriptions_renewal_order_meta_query( $order_meta_query ) {
		$order_meta_query .= " AND `meta_key` NOT IN ( '_ss_shipping_label' )";

		return $order_meta_query;
	}

	// BULK FUNCTIONS
	public function add_order_bulk_actions() {
		global $post_type, $post_status;

		if ( $post_type === 'shop_order' && $post_status !== 'trash' ) :

			?>
			<script type="text/javascript">
				jQuery( document ).ready( function ( $ ) {
					$( 'select[name^=action]' ).append(
						<?php $index = count( $actions = $this->get_bulk_actions() ); ?>
						<?php foreach ( $actions as $action => $name ) : ?>
							$( '<option>' ).val( '<?php echo esc_js( $action ); ?>' ).text( '<?php echo esc_js( $name ); ?>' )
							<?php --$index; ?>
							<?php if ( $index ) { echo ','; } ?>
						<?php endforeach; ?>
					);
				} );
			</script>
			<?php

		endif;
	}

	public function get_bulk_actions() {

		$shop_manager_actions = array();

		$shop_manager_actions = array(
			'ss_shipping_label_bulk'      => __( 'Smart Send - Generate Labels', 'smart-send-shipping' ),
			'ss_shipping_return_bulk'      => __( 'Smart Send - Generate Return Labels', 'smart-send-shipping' ),
			'ss_shipping_label_return_bulk'      => __( 'Smart Send - Generate Normal and Return Labels', 'smart-send-shipping' )
		);

		return $shop_manager_actions;
	}

	public function process_orders_bulk_actions() {
		global $typenow;
		$array_messages = array( 'msg_user_id' => get_current_user_id() );

		if ( 'shop_order' === $typenow ) {

			// Get the bulk action
			$wp_list_table = _get_list_table( 'WP_Posts_List_Table' );
			$action        = $wp_list_table->current_action();
			$order_ids     = array();

			if ( ! $action || ! array_key_exists( $action, $this->get_bulk_actions() ) ) {
				return;
			}

			// Make sure order IDs are submitted
			if ( isset( $_REQUEST['post'] ) ) {
				$order_ids = array_map( 'absint', $_REQUEST['post'] );
			}

 			// Return if there are no orders to print
 			// if ( ! $order_ids ) {
				// return;
 			// }

			$redirect_url  = admin_url( 'edit.php?post_type=shop_order' );

			if ( 'ss_shipping_label_bulk' === $action || 'ss_shipping_return_bulk' === $action || 'ss_shipping_label_return_bulk' === $action ) {
				
				// Trigger an admin notice to have the user manually open a print window
				// $message = $this->get_print_confirmation_message( $order_ids, $redirect_url );
				$is_error = false;
				$orders_count = count( $order_ids );

				if ( $orders_count < 1 ) {
					$message = __( 'No orders selected, please select the orders to create label(s).', 'smart-send-shipping' );
					$is_error = true;

					$arr_message = array( 'message' => $message, 'is_error' => $is_error );
					array_push($array_messages, $arr_message);
				} elseif ( $orders_count > 8 ) {
					$message = __( 'At most 8 order can be selected, please select 8 orders or less and try again.', 'smart-send-shipping' );
					$is_error = true;

					$arr_message = array( 'message' => $message, 'is_error' => $is_error );
					array_push($array_messages, $arr_message);
				} else {

					// Ensure the selected orders have a label created, otherwise don't create handover
					foreach ( $order_ids as $order_id ) {

						$ss_shipping_method_id = $this->get_smart_send_method_id( $order_id );

						if( !empty($ss_shipping_method_id) ) {

	                        $args = $this->set_label_args( $order_id );
	                        error_log(print_r($this->shipment,true));
						    if( $this->api_handle->createShipmentAndLabels( $this->shipment ) ) {

								$tracking_note = $this->get_tracking_link( $order_id, $this->api_handle->getData() );

								$order = wc_get_order( $order_id );

								$order->add_order_note( $tracking_note, 0, true );

								// Add tracking information to Shipment Tracking
								$shipment_tracking_details = $this->get_tracking_details( $this->api_handle->getData() );
								foreach($shipment_tracking_details as $parcel_tracking_details) {
                                    $this->save_tracking_in_shipment_tracking($order_id, $parcel_tracking_details['tracking_code'], $parcel_tracking_details['tracking_link'], $parcel_tracking_details['carrier_name'],$date_shipped=null);
                                }

								$this->set_order_status_label( $order_id );

								$message = sprintf( __( 'Order #%s: Smart Shipping Label Created, %s', 'smart-send-shipping'), $order_id, $this->get_ss_shipping_label_link( $order_id ) );
								$is_error = false;
								// TODO: Insert success message with link to PDF label for each order

								$arr_message = array( 'message' => $message, 'is_error' => $is_error );
								array_push($array_messages, $arr_message);
							} else {
						        $error = $this->api_handle->getError();

								$message = sprintf( __( 'Order #%s: %s', 'smart-send-shipping'), $order_id, $error->message );

								error_log(print_r($error,true));
								foreach ($error->errors as $error_key => $error_value) {
									$message .= '<br/> - ' . $error_value[0];
								}

								$is_error = true;

								$arr_message = array( 'message' => $message, 'is_error' => $is_error );
								array_push($array_messages, $arr_message);
							}
						} else {
							$message = sprintf( __( 'Order #%s: The selected order did not include a Send Smart shipping method', 'smart-send-shipping'), $order_id );
							$is_error = true;
							$arr_message = array( 'message' => $message, 'is_error' => $is_error );
							array_push($array_messages, $arr_message);
						}
					}
				}

				/* @see render_messages() */
				update_option( '_ss_shipping_bulk_action_confirmation', $array_messages);

			}
			
 		}
	}

	
	public function render_messages( $current_screen = null ) {
		if ( ! $current_screen instanceof WP_Screen ) {
			$current_screen = get_current_screen();
		}

		if ( isset( $current_screen->id ) && in_array( $current_screen->id, array( 'shop_order', 'edit-shop_order' ), true ) ) {

			$bulk_action_message_opt = get_option( '_ss_shipping_bulk_action_confirmation' );

			if ( ( $bulk_action_message_opt ) && is_array( $bulk_action_message_opt ) ) {

				// $user_id = key( $bulk_action_message_opt );
				// remove first element from array and verify if it is the user id
				$user_id = array_shift( $bulk_action_message_opt );
				// error_log($user_id);
				if ( get_current_user_id() !== (int) $user_id ) {
					return;
				}

				foreach ($bulk_action_message_opt as $key => $value) {
					// error_log(print_r($value,true));
					$message = wp_kses_post( $value['message'] );
					$is_error = wp_kses_post( $value['is_error'] );
					
					if( $is_error ) {
						echo '<div class="error"><ul><li>' . $message . '</li></ul></div>';
					} else {
						echo '<div id="wp-admin-message-handler-message"  class="updated"><ul><li><strong>' . $message . '</strong></li></ul></div>';
					}
				}

				delete_option( '_ss_shipping_bulk_action_confirmation' );
			}
		}
	}
}

endif;