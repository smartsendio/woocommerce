<?php

/* 
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

/**
 * Check if WooCommerce is active
 */
if ( in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
    
	add_action( 'woocommerce_checkout_update_order_meta', 'Smartsend_Logistics_store_pickup_field_update_order_meta' );
	
	function Smartsend_Logistics_store_pickup_field_update_order_meta( $order_id ) {  
		if ( isset($_POST[ 'store_pickup' ]) &&  $_POST[ 'store_pickup' ] != ''){
			$store_pickup = sanitize_text_field($_POST[ 'store_pickup' ]);
			update_post_meta( $order_id, 'store_pickup', $store_pickup  );
		}
	}
	
	#Process the checkout and validate store location
	add_action('woocommerce_checkout_process', 'Smartsend_Logistics_pickup_checkout_field_process');
	function Smartsend_Logistics_pickup_checkout_field_process() {
		global $woocommerce;
		// Check if set, if its not set add an error. This one is only requite for companies
		if ( $_POST['billing_country'] == "DK" || $_POST['shipping_country'] == "DK" )
			if (isset($_POST['store_pickup']) && $_POST['store_pickup']=='') 
				$woocommerce->add_error( __(get_option('woocommerce_pickup_display_dropdown_error', 'Vælg venligst et udleveringssted på listen.'),'woocommerce') );
	}           
	
	# Show selected pickup location in customer's myaccount section
	add_action( 'woocommerce_order_details_after_order_table', 'Smartsend_Logistics_display_store_order_details' );
	function Smartsend_Logistics_display_store_order_details($order  ) {
			   
		$store_pickup = get_post_custom($order->id);
		$store_pickup = @unserialize($store_pickup['store_pickup'][0]);
		if(!is_array($store_pickup)) $store_pickup = unserialize($store_pickup);
	
		if(!empty($store_pickup)){
			echo '<br/>
			<b>'.__('Selected store location','woocommerce').':</b>
			<br/>';
			echo ' ID: ' . $store_pickup['id'] .'<br/>'.
			$store_pickup['company'] .'<br/>'.
			$store_pickup['street'] .'<br/>';
			if(isset($store_pickup['method_style'])){
				switch ($store_pickup['method_style']){
					case '2':
					 	echo $store_pickup['zip'] ;
						break;
					case '3':
					 	echo $store_pickup['city'] ;
						break;
					case '4':
					 	echo $store_pickup['zip'] .' '. $store_pickup['city'] ;
						break;
					case '5':
						echo $store_pickup['zip'] .' '. $store_pickup['city']. ' ('.$store_pickup['type'].')' ;
						break;
				}
			} else {
				 echo $store_pickup['zip'] .' '. $store_pickup['city'] ;
			} 
			echo "<br/><br/>";
		}
	}
		
	# add selected pickup location info with emails
	add_action( 'woocommerce_email_after_order_table', 'Smartsend_Logistics_display_store_order_details' );

	# hide custom field data in admin orders section
	add_filter('is_protected_meta', 'Smartsend_Logistics_my_is_protected_meta_filter', 10, 2);
	function Smartsend_Logistics_my_is_protected_meta_filter($protected, $meta_key) {
		return $meta_key == 'store_pickup' ? true : $protected;
	}
                
	#Add a custom setting in shipping section
	add_filter( 'woocommerce_shipping_settings', 'Smartsend_Logistics_add_order_number_start_setting' );
	function Smartsend_Logistics_add_order_number_start_setting( $settings ) {
		$updated_settings = array();
			  
	  	foreach ( $settings as $section ) {
				  
			if ( isset( $section['id'] ) && 'woocommerce_ship_to_countries' == $section['id'] && isset( $section['type'] ) && 'select' == $section['type'] ) {
				$updated_settings[] = array(
					'title'   	=> __( 'Smartsend Username', 'woocommerce' ),
					'desc'    	=> __( 'This is the legend of username for smartsend settings.', 'woocommerce' ),
					'id'      	=> 'smartsend_logistics_username',
					'default' 	=> '', //Choose Store Location
					'type'    	=> 'text',
					'desc_tip'        =>  true,
					'show_if_checked' => 'option',
				);
				
				$updated_settings[] = array(
					'title'   	=> __( 'Smartsend Licensekey', 'woocommerce' ),
					'desc'    	=> __( 'This is the legend of username for Licensekey settings', 'woocommerce' ),
					'id'      	=> 'smartsend_logistics_licencekey',
					'default' 	=> '', //Select pickup location
					'type'    	=> 'text',
					'desc_tip'        =>  true,
					'show_if_checked' => 'option',
				);
				$updated_settings[] = array(
					'title'    	=> __( 'Combine PDFs files', 'woocommerce' ),
					'desc'     	=> __( 'Combine all PDF files (or links) into one PDF file (or link)', 'woocommerce' ),
					'id'      	=> 'smartsend_logistics_combinepdf',
					'default' 	=> 'yes',
					'type'    	=> 'radio',
					'options' 	=> array(
						'yes'     	=> __( 'Combine all PDF files into one', 'woocommerce' ),
						'no'      	=> __( 'Sperate PDF files per order', 'woocommerce' ),
					),
					'autoload'        => false,
					'desc_tip'        =>  true,
					'show_if_checked' => 'option',
				);
				$updated_settings[] = array(
					'title'   	=> __( 'Store Location Display Mode', 'woocommerce' ),
					'desc'    	=> __( 'This controls display postion of store location dropdown on checkout page.', 'woocommerce' ),
					'id'      	=> 'woocommerce_pickup_display_mode1',
					'default' 	=> '0',
					'type'    	=> 'radio',
					'options' 	=> array(
						'0'     	=> __( 'Above the "Your Order" section on Checkout page', 'woocommerce' ),
						'1'      	=> __( "Add to specific location on Checkout page by using custom hook in your theme.(do_action( 'smartsend_logistics_dropdown_hook' ))", 'woocommerce' ),
					),
					'autoload'        => false,
					'desc_tip'        =>  true,
					'show_if_checked' => 'option',
				);
				$updated_settings[] = array(
					'title'   	=> __( 'Dropdown legend', 'woocommerce' ),
					'desc'    	=> __( 'This is the legend of the dropdown containing the pickup points.', 'woocommerce' ),
					'id'      	=> 'woocommerce_pickup_display_dropdown_legend',
					'default' 	=> 'Vælg udleveringssted', //Choose Store Location
					'type'    	=> 'text',
					'desc_tip'        =>  true,
					'show_if_checked' => 'option',
				);
				
				$updated_settings[] = array(
					'title'   	=> __( 'Dropdown text', 'woocommerce' ),
					'desc'    	=> __( 'This is what will be shown in the first row of the dropdown containing the pickup points.', 'woocommerce' ),
					'id'      	=> 'woocommerce_pickup_display_dropdown_text',
					'default' 	=> 'Klik og vælg udleveringssted', //Select pickup location
					'type'    	=> 'text',
					'desc_tip'        =>  true,
					'show_if_checked' => 'option',
				);
				$updated_settings[] = array(
					'title'   	=> __( 'Dropdown text', 'woocommerce' ),
					'desc'    	=> __( 'This is the error message shown if no pickup point is selected.', 'woocommerce' ),
					'id'      	=> 'woocommerce_pickup_display_dropdown_error',
					'default' 	=> 'Vælg venligst et udleveringssted på listen.', //Please select the store pickup loaction!
					'type'    	=> 'text',
					'desc_tip'        =>  true,
					'show_if_checked' => 'option',
				);
				$updated_settings[] = array(
					'title'    	=> __( 'Dropdown Format', 'woocommerce' ),
					'desc'     	=> sprintf( __( 'Choose carrier method format on frontend', 'woocommerce' ), admin_url( 'admin.php?page=wc-settings&tab=general' ) ),
					'id'       	=> 'woocommerce_pickup_display_dropdown_format',
					'default'  	=> '0',
					'type'     	=> 'select',
					'class'    	=> 'wc-enhanced-select',
					'desc_tip' 	=> false,
					'options'  	=> array(
						'0'    		=> __( 'Carrier - Method' ),
						'1'      	=> __( 'Carrier (Method)'),
						'2' 		=> __( 'Carrier - (Method)' ),
						'3'      	=> __( 'Carrier Method'),
						'4' 		=> __( 'Carrier-(Method)' )
						)
					);
			}
			$updated_settings[] = $section;
	  	}

		return $updated_settings;
	}
                
	add_action( 'add_meta_boxes', 'Smartsend_Logistics_add_meta_boxes' );

	function Smartsend_Logistics_add_meta_boxes(){

		add_meta_box(
			'woocommerce-order-shipping-my-custom',
			__( 'Smart Send Logistics' ),
			'Smartsend_Logistics_order_shipping_custom_metabox',
			'shop_order',
			'side',
			'default'
		);

	}

		function Smartsend_Logistics_order_shipping_custom_metabox( $post ){
	
	  	 	$order = wc_get_order( $post->ID );
   
		 	$line_items_shipping = $order->get_items( 'shipping' );
			$shipMethod = '';
			if(!empty($line_items_shipping)){
				foreach ( $line_items_shipping as $item_id => $item ) {
                	$shipMethod_id = ! empty( $item['method_id'] ) ? esc_html( $item['method_id'] ) : __( 'Shipping', 'woocommerce' );
				  	$shipMethod=  ! empty( $item['name'] ) ? esc_html( $item['name'] ) : __( 'Shipping', 'woocommerce' );
				}
			}
		
			$store_pickup = get_post_custom($order->id);
			
            echo '<p>Shipping Method: '.$shipMethod.' ('.$shipMethod_id.')</p>';
                       
			if(!empty($store_pickup ['store_pickup'][0])){
				$store_pickup = unserialize($store_pickup['store_pickup'][0]);
                                
				if(!is_array($store_pickup)) $store_pickup = unserialize($store_pickup);	
                
				echo 'Pickup ID: ' . $store_pickup['id'] .'<br/>'.
				 		$store_pickup['company'] .'<br/>'.
				 		$store_pickup['street'] .'<br/>'.
				 		$store_pickup['zip'] .' '. $store_pickup['city'] .'<br/>'.
				 		$store_pickup['country'];		
				if(!empty($store_pickup['method_style']) && $store_pickup['method_style'] == 5 ) {
					echo '<br/> ('.$store_pickup['carrier'].')';
				}
				echo '</p>';                   
			} else {
				echo '<p>Not a pickup point</p>';
			}
			echo '<br/>';
			echo '<a href="post.php?post='.$post->ID.'&action=edit&type=create_label" class="button button-primary">Create label</a><br/><br/>';
            echo '<a href="post.php?post='.$post->ID.'&action=edit&type=create_label_return" class="button">Create return label</a>'; 
    }
                
	# Show selected pickup location on the order edit page(woocommerce_admin_order_data_after_order_details)
	add_action( 'woocommerce_admin_order_data_after_billing_address', 'Smartsend_Logistics_my_custom_checkout_field_display_admin_order_meta', 10, 1 );
	function Smartsend_Logistics_my_custom_checkout_field_display_admin_order_meta($order){
		$line_items_shipping = $order->get_items( 'shipping' );
		$shipMethod = '';
		if(!empty($line_items_shipping)){
			foreach ( $line_items_shipping as $item_id => $item ) {
				$shipMethod=  ! empty( $item['name'] ) ? esc_html( $item['name'] ) : __( 'Shipping', 'woocommerce' );
			}
		}
					
		$store_pickup = get_post_custom($order->id);
				   
		if(!empty($store_pickup ['store_pickup'][0])){
			$store_pickup = unserialize($store_pickup['store_pickup'][0]);
							
			if(!is_array($store_pickup)) {
				$store_pickup = unserialize($store_pickup);
			}
			
			echo '<p><strong>'.__('Smart Send Logistics','woocommerce').'</strong><br/> 
				Shipping Method: '.$shipMethod.'<br/>
				Pickup ID: ' . $store_pickup['id'] .'<br/>'.
			 	$store_pickup['company'] .'<br/>'.
			 	$store_pickup['street'] .'<br/>'.
				$store_pickup['zip'] .' '. $store_pickup['city'] .'<br/>'.
				$store_pickup['country'];		
			if(!empty($store_pickup['method_style']) && $store_pickup['method_style'] == 5 ) {
				echo '<br/> ('.$store_pickup['carrier'].')';
			}
			echo '</p>'; 
		}
	} 
            
}