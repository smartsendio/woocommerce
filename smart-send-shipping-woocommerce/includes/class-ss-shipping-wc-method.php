<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}


if ( ! class_exists( 'SS_Shipping_WC_Method' ) ) :

class SS_Shipping_WC_Method extends WC_Shipping_Method {

	private $shipping_method = array( 'PostNord' 	=> 
											array( 
												'postnord_pickuppoint' 			=>	'Pickup point',
												'postnord_closestpickup'	 	=>	'Closest pickup point',
												'postnord_privatetohome'		=>	'Private to home',
												'postnord_commercial' 			=>	'Commercial',
												'postnord_valuemail' 			=>	'Valuemail',
												'postnord_valuemailsmall'		=>	'Small Valuemail',
											),
										'GLS'		=>
											array( 
												'gls_pickuppoint' 				=>	'Pickup point',
												'gls_closestpickup'	 			=>	'Closest pickup point',
												'gls_privatetohome'				=>	'Private to home',
												'gls_commercial' 				=>	'Commercial',
											),
										'Bring'		=>
											array( 
												'bring_pickuppoint' 			=>	'Pickup point',
												'bring_closestpickup'	 		=>	'Closest pickup point',
												'bring_privatetohome'			=>	'Private to home',
												'bring_commercial' 				=>	'Commercial',
											),
								);

	/**
	 * Init and hook in the integration.
	 */
	public function __construct( $instance_id = 0 ) {
		$this->id = SS_SHIPPING_METHOD_ID;
		$this->instance_id = absint( $instance_id );
		$this->method_title = __( 'Smart Send', 'smart-send-shipping' );
		$this->method_description = __( 'To start shipping via Smart Send configure settings below.', 'smart-send-shipping' );
		
		$this->supports           = array(
			'settings',
			'shipping-zones', // support shipping zones shipping method
			'instance-settings',
			// 'instance-settings-modal',
		);

		$this->init();
	}

	/**
	 * init function.
	 */
	public function init() {
		
		$this->init_instance_form_fields();
		$this->init_form_fields();

		$this->init_settings();

		// Set title so can be viewed in zone screen
		$this->title = $this->get_option( 'title' );

		// add_action( 'admin_notices', array( $this, 'environment_check' ) );
		add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'load_admin_scripts' ) );

		
	}

	public function load_admin_scripts( $hook ) {
	    
	    if( 'woocommerce_page_wc-settings' != $hook ) {
			// Only applies to WC Settings panel
			return;
	    }
	    
	    $test_con_data = array( 
	    					'ajax_url' => admin_url( 'admin-ajax.php' ),
	    					'test_con_nonce' => wp_create_nonce( 'ss-shipping-test-con' ) 
	    				);

		// wp_enqueue_style( 'wc-shipment-as-label-css', SS_Shipping_PLUGIN_DIR_URL . '/assets/css/pr-as-admin.css' );		
		wp_enqueue_script( 'wc-shipment-as-testcon-js', SS_SHIPPING_PLUGIN_DIR_URL . '/assets/js/ss-shipping-test-connection.js', array('jquery'), SS_SHIPPING_VERSION );

		// in JavaScript, object properties are accessed as ajax_object.ajax_url, ajax_object.we_value
		wp_localize_script( 'wc-shipment-as-testcon-js', 'as_test_con_obj', $test_con_data );
	}

	/**
	 * Initialize integration settings form fields.
	 *
	 * @return void
	 */
	public function init_form_fields() {

		$this->form_fields = array(
			'title'	=> array(
				'name' 		=> __( 'Smart Send Logistics settings', 'smart-send-logistics' ),
				'type' 		=> 'title',
				'desc' 		=> __("If you don't have a Smart Send subscription please create one at our website", 'smart-send-logistics').': <a href="http://www.smartsend.dk/signup" target="_blank">Smart Send</a>',
				'id' 		=> 'smartsend_logistics_settings'
			),/*
			'username' => array(
				'title'   	=> __( 'Username','smart-send-logistics'),
				'id'      	=> 'smartsend_logistics_username',
				'default' 	=> '', //Choose Store Location
				'type'    	=> 'text',
				'desc_tip'        =>  false,
			),
			'licensekey' => array(
				'title'   	=> __( 'License key','smart-send-logistics'),
				'id'      	=> 'smartsend_logistics_licencekey',
				'default' 	=> '',
				'type'    	=> 'text',
				'desc_tip'        =>  false,
			),
            'validation' => array(
				'title'		=> __( 'Validation','smart-send-logistics'),
				'id'		=> 'smartsend_logistics_validation',
				'default'	=> '0',
				'type'		=> 'text',
				'desc_tip'	=>  false,
                'css'		=> 'box-shadow:none;width:255px; color: '.$validate_color.'; background: none repeat scroll 0 0 rgba(0, 0, 0, 0) !important; border: none;'
			),*/
			'combine_pdf_files' => array(
				'title'    	=> __( 'Merge labels from multiple orders','smart-send-logistics'),
				'desc'     	=> __( 'Generate PDF file containing all labels or create a single PDF file for each order','smart-send-logistics'),
				'default' 	=> 'yes',
				'type'    	=> 'radio',
				'options' 	=> array(
					'yes'     	=> __( 'Merged PDF file','smart-send-logistics'),
					'no'      	=> __( 'Separate PDF files','smart-send-logistics'),
				),
				'desc_tip'        =>  true,
			),
			'dropdown_display_mode' => array(
				'title'   	=> __( 'Pickup dropdown display place','smart-send-logistics'),
				'desc'    	=> __( 'This controls the display postion of pick-up point dropdown on checkout page.','smart-send-logistics'),
				'default' 	=> 'below_shipping',
				'type'    	=> 'radio',
				'options' 	=> array(
					'below_shipping'   	=> __( 'Below the shipping method','smart-send-logistics'),
					'custom_hook'     	=> __( 'User custom hook in theme: "do_action(\'smart_send_shipping_pickup_dropdown\')"','smart-send-logistics'),
				),
				'desc_tip'        =>  true,
			),
			'dropdown_display_format' => array(
				'title'    	=> __( 'Dropdown format','smart-send-logistics'),
				'desc'		=> __('How the pickup points are listed during checkout','smart-send-logistics'),
				'default'  	=> '4',
				'type'     	=> 'select',
				'class'    	=> 'wc-enhanced-select',
				'desc_tip' 	=> true,
				'options'   => array(
					'1' 		=> '#'.__('Company','smart-send-logistics').', #'.__('Street','smart-send-logistics'),
					'2'    		=> '#'.__('Company','smart-send-logistics').', #'.__('Street','smart-send-logistics').', #'.__('Zipcode','smart-send-logistics'),
					'3'    		=> '#'.__('Company','smart-send-logistics').', #'.__('Street','smart-send-logistics').', #'.__('City','smart-send-logistics'),
					'4'    		=> '#'.__('Company','smart-send-logistics').', #'.__('Street','smart-send-logistics').', #'.__('Zipcode','smart-send-logistics').' #'.__('City','smart-send-logistics'),
					'5'    		=> '#'.__('Company','smart-send-logistics').', #'.__('Zipcode','smart-send-logistics'),
					'6'    		=> '#'.__('Company','smart-send-logistics').', #'.__('Zipcode','smart-send-logistics').', #'.__('City','smart-send-logistics'),
					'7'    		=> '#'.__('Company','smart-send-logistics').', #'.__('City','smart-send-logistics'),
				)
			),
			'order_status' => array(
				'title'    	=> __( 'Set order status after label print','smart-send-logistics'),
				'id'       	=> 'smartsend_logistics_order_status',
				'default'  	=> '0',
				'type'     	=> 'select',
				'class'    	=> 'wc-enhanced-select',
				'options'   => array_merge( array( '0' => __("Don't change order status",'smart-send-logistics') ), wc_get_order_statuses() )
			),
			'shipping_method_for_free_shipping' => array(
				'title'    	=> __( 'Shipping method used for WooCommerce method Free Shipping','smart-send-logistics'),
				'type'     	=> 'selectopt',
				'class'    	=> 'wc-enhanced-select',
				'description' => __( 'This controls the title which the user sees during checkout.', 'smart-send-shipping' ),
				'desc_tip' 	=> true,
				'options'         	=> $this->shipping_method
			),
			'include_order_comment' => array(
				'title'    	=> __( 'Include order comment on label','smart-send-logistics'),
				'default' 	=> 'yes',
				'type'    	=> 'checkbox',
				'desc_tip'        =>  false,
			),
		);
	}

	/**
	 * Generate Button HTML.
	 *
	 * @access public
	 * @param mixed $key
	 * @param mixed $data
	 * @since 1.0.0
	 * @return string
	 */
	public function generate_button_html( $key, $data ) {
		$field    = $this->plugin_id . $this->id . '_' . $key;
		// error_log($field);
		$defaults = array(
			'class'             => 'button-secondary',
			'css'               => '',
			'custom_attributes' => array(),
			'desc_tip'          => false,
			'description'       => '',
			'title'             => '',
		);

		$data = wp_parse_args( $data, $defaults );

		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<label for="<?php echo esc_attr( $field ); ?>"><?php echo wp_kses_post( $data['title'] ); ?></label>
				<?php echo $this->get_tooltip_html( $data ); ?>
			</th>
			<td class="forminp">
				<fieldset>
					<legend class="screen-reader-text"><span><?php echo wp_kses_post( $data['title'] ); ?></span></legend>
					<button class="<?php echo esc_attr( $data['class'] ); ?>" type="button" name="<?php echo esc_attr( $field ); ?>" id="<?php echo esc_attr( $field ); ?>" style="<?php echo esc_attr( $data['css'] ); ?>" <?php echo $this->get_custom_attribute_html( $data ); ?>><?php echo wp_kses_post( $data['title'] ); ?></button>
					<?php echo $this->get_description_html( $data ); ?>
				</fieldset>
			</td>
		</tr>
		<?php
		return ob_get_clean();
	}

	public function init_instance_form_fields() {

		$this->instance_form_fields = array(
			'title'            	=> array(
				'title'           	=> __( 'Method Title', 'smart-send-shipping' ),
				'type'            	=> 'text',
				'description'     	=> __( 'This controls the title which the user sees during checkout.', 'smart-send-shipping' ),
				'default'         	=> __( 'Smart Send', 'smart-send-shipping' ),
				'desc_tip'        	=> true
			),
			'method'           	=> array(
				'title'           	=> __( 'Shipping Method', 'smart-send-shipping' ),
				'type'            	=> 'selectopt',
				'class' 	        => 'wc-enhanced-select',
				'description'     	=> __( 'This controls the title which the user sees during checkout.', 'smart-send-shipping' ),
				'desc_tip'        	=> true,
				'options'         	=> $this->shipping_method
			),
			'tax_status'		=> array(
				'title' 			=> __( 'Tax status', 'smart-send-shipping' ),
				'type'	 			=> 'select',
				'class' 	        => 'wc-enhanced-select',
				'description'     => __( 'This controls the title which the user sees during checkout.', 'smart-send-shipping' ),
				'default' 			=> 'taxable',
				'desc_tip'        	=> true,
				'options'			=> array(
					'taxable' 		=> __( 'Taxable', 'smart-send-shipping' ),
					'none' 			=> _x( 'None', 'Tax status', 'smart-send-shipping' ),
				)
			),
			'cost_title'     => array(
				'title'           => __( 'Cost', 'smart-send-shipping' ),
				'type'            => 'title',
				'description'     => __( 'Configure the shipping method cost and free shipping.', 'smart-send-shipping' ),
				'class'			  => '',
			),
			'requires' => array(
				'title'   => __( 'Free shipping requires...', 'smart-send-shipping' ),
				'type'    => 'select',
				'class'   => 'wc-enhanced-select',
				'default' => '',
				'options' => array(
					''           => __( 'N/A', 'smart-send-shipping' ),
					'coupon'     => __( 'A valid free shipping coupon', 'smart-send-shipping' ),
					'min_amount' => __( 'A minimum order amount', 'smart-send-shipping' ),
					'either'     => __( 'A minimum order amount OR a coupon', 'smart-send-shipping' ),
					'both'       => __( 'A minimum order amount AND a coupon', 'smart-send-shipping' ),
				),
			),
			'min_amount' => array(
				'title'       => __( 'Minimum order amount', 'smart-send-shipping' ),
				'type'        => 'price',
				'placeholder' => wc_format_localized_price( 0 ),
				'description' => __( 'Users will need to spend this amount to get free shipping (if enabled above).', 'smart-send-shipping' ),
				'default'     => '0',
				'desc_tip'    => true,
			),
			'cost_weight' => array(
				'type'        => 'cost_weight',
			),
			'advanced_title'     => array(
				'title'           => __( 'Advanced Validation', 'smart-send-shipping' ),
				'type'            => 'title',
				'description'     => __( 'Configure the advanced validation.', 'smart-send-shipping' ),
				'class'			  => '',
			),
			'advanced_validation_enable' => array(
				'title'             => __( 'Advanced Validation:', 'smart-send-shipping' ),
				'type'              => 'checkbox',
				'label'             => __( 'Enable', 'smart-send-shipping' ),
				'default'           => 'no',
				'description'       => __( 'Enable/disable advanced validation and click save to show/hide settings.', 'smart-send-shipping' ),
				'desc_tip'          => true,
			),
		);


		$advanced_validation_flag = 'no';
		// Load the advanced validation POST to see if it is enabled and load associated fields
		if( ! empty( $_POST ) ) {
			if( isset( $_POST[ $this->get_field_key('advanced_validation_enable') ] ) ) {
				$advanced_validation_flag = 'yes';
			}
		} else {
			$instance_settings = get_option( $this->get_instance_option_key(), null );
			$advanced_validation_flag = $instance_settings['advanced_validation_enable'];
		}
		
		if ( $advanced_validation_flag == 'yes' ) {
			$wc_shipping = WC_Shipping::instance();
			$wc_shipping_classes = $wc_shipping->get_shipping_classes();
			// error_log('shipping classes');
			// error_log(print_r($wc_shipping->get_shipping_classes(),true));
			$shipping_classes = wp_list_pluck($wc_shipping_classes, 'name');

			global $wp_roles;
			$user_roles = $wp_roles->get_names();

			$this->instance_form_fields += array(
					'validate_class'  => array(
						'title'           => __( 'Validate based on shipping classes', 'smart-send-shipping' ),
						'type'            => 'radio',
						'description'     => __( 'Configure the advanced validation.', 'smart-send-shipping' ),
						'class'			  => '',
						'default'         => 'no_shipping_class',
						'options' => array(
							'no_shipping_class'		=> __( 'Does not depend on shipping class', 'smart-send-shipping' ),
							'all_shipping_class'   	=> __( 'Valid if ALL products belong to one of the shipping classes', 'smart-send-shipping' ),
							'one_shipping_class' 	=> __( 'Valid if at least ONE product belongs to one of the shipping classes', 'smart-send-shipping' ),
							'nall_shipping_class'  	=> __( 'NOT valid if ALL products belong to one of the shipping classes', 'smart-send-shipping' ),
							'none_shipping_class' 	=> __( 'NOT valid if at least ONE product belongs to one of the shipping classes', 'smart-send-shipping' )
						),
						'desc_tip'          => true,
					),
					'ship_classes'	=> array(
						'title' 		=> __( 'Shipping classes', 'smart-send-shipping' ),
						'type'	 		=> 'multiselect',
						'class' 	    => 'wc-enhanced-select',
						'description'   => __( 'Shipping classes used for validatation', 'smart-send-shipping' ),
						'desc_tip'     	=> false,
						'options'		=> $shipping_classes,
					),
					'validate_company'  => array(
						'title'           => __( 'Validate based on company field', 'smart-send-shipping' ),
						'type'            => 'radio',
						'description'     => __( 'Configure the advanced validation.', 'smart-send-shipping' ),
						'class'			  => '',
						'default'         => 'no_company',
						'options' => array(
							'no_company'		=> __( 'Validate regardless of company-field', 'smart-send-shipping' ),
							'only_company'	   	=> __( 'ONLY valid if company-field entered', 'smart-send-shipping' ),
							'not_company' 		=> __( 'NOT valid if company-field entered', 'smart-send-shipping' ),
						),
						'desc_tip'          => true,
					),
					'user_roles'	=> array(
						'title' 			=> __( 'User role', 'smart-send-shipping' ),
						'type'	 			=> 'multiselect',
						'class' 	        => 'wc-enhanced-select',
						'description'     	=> __( 'Shipping method only valid for user roles', 'smart-send-shipping' ),
						'desc_tip'        	=> false,
						'options'			=> $user_roles,
					),
					'cost_formula' => array(
						'title'             => __( 'Cost formula', 'smart-send-shipping' ),
						'type'              => 'text',
						'description'       => __( 'Advanced calculations.', 'smart-send-shipping' ),
						'desc_tip'          => true,
						'default'           => ''
					),
			);
		}

	}
	
	public function generate_selectopt_html( $key, $data ) {
		$field_key = $this->get_field_key( $key );
		$defaults  = array(
			'title'             => '',
			'disabled'          => false,
			'class'             => '',
			'css'               => '',
			'placeholder'       => '',
			'type'              => 'text',
			'desc_tip'          => false,
			'description'       => '',
			'custom_attributes' => array(),
			'options'           => array(),
		);

		$data = wp_parse_args( $data, $defaults );

		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<?php echo $this->get_tooltip_html( $data ); ?>
				<label for="<?php echo esc_attr( $field_key ); ?>"><?php echo wp_kses_post( $data['title'] ); ?></label>
			</th>
			<td class="forminp">
				<fieldset>
					<legend class="screen-reader-text"><span><?php echo wp_kses_post( $data['title'] ); ?></span></legend>
					<select class="select <?php echo esc_attr( $data['class'] ); ?>" name="<?php echo esc_attr( $field_key ); ?>" id="<?php echo esc_attr( $field_key ); ?>" style="<?php echo esc_attr( $data['css'] ); ?>" <?php disabled( $data['disabled'], true ); ?> <?php echo $this->get_custom_attribute_html( $data ); ?>>

						<?php foreach ( (array) $data['options'] as $optgroup_key => $optgroup_value ) : ?>

							<?php echo '<optgroup label="' . esc_attr( $optgroup_key ) . '">'; ?>

								<?php foreach ( (array) $optgroup_value as $option_key => $option_value ) : ?>
								
									<option value="<?php echo esc_attr( $option_key ); ?>" <?php selected( $option_key, esc_attr( $this->get_option( $key ) ) ); ?>><?php echo esc_attr( $option_value ); ?></option>

								<?php endforeach; ?>
									
							<?php echo '</optgroup>'; ?>

						<?php endforeach; ?>

					</select>
					<?php echo $this->get_description_html( $data ); ?>
				</fieldset>
			</td>
		</tr>
		<?php

		return ob_get_clean();
	}


	/**
	 * Generate cost weight html.
	 *
	 * @return string
	 */
	public function generate_cost_weight_html() {

		ob_start();

		?>
		<tr valign="top">
			<th scope="row" class="titledesc"><?php _e( 'Cost based on weight', 'woocommerce' ); ?>:</th>
			<td class="forminp" id="ss_cost_weight">
				<table class="widefat wc_input_table sortable" cellspacing="0">
					<thead>
						<tr>
							<th class="sort">&nbsp;</th>
							<th><?php _e( 'Minimum [kg]', 'woocommerce' ); ?></th>
							<th><?php _e( 'Maximum [kg]', 'woocommerce' ); ?></th>
							<th><?php _e( 'Cost', 'woocommerce' ); ?> <a class="tips" data-tip="<?php _e('Products must belong to this shipping class', 'smart-send-logistics'); ?>">[?]</a></th>
						</tr>
					</thead>
					<tbody class="ss_weight_cost">
						<?php
						$i = -1;
						
						$weight_costs = $this->get_option( 'cost_weight', array() );

						if ( $weight_costs ) {
							foreach ( $weight_costs as $weight_cost ) {
								$i++;

								echo '<tr class="ss_weight_cost">
									<td class="sort"></td>
									<td><input type="text" value="' . esc_attr( $weight_cost['ss_min_weight'] ) . '" name="ss_min_weight[' . $i . ']" class ="wc_input_decimal" /></td>
									<td><input type="text" value="' . esc_attr( $weight_cost['ss_max_weight'] ) . '" name="ss_max_weight[' . $i . ']" class ="wc_input_decimal" /></td>
									<td><input type="text" value="' . esc_attr( $weight_cost['ss_cost_weight'] ) . '" name="ss_cost_weight[' . $i . ']"  class ="wc_input_price"/></td>
								</tr>';
							}
						}
						?>
					</tbody>
					<tfoot>
						<tr>
							<th colspan="4"><a href="#" class="add button"><?php _e( '+ Add shipping rate', 'woocommerce' ); ?></a> <a href="#" class="remove_rows button"><?php _e( 'Remove selected rate(s)', 'woocommerce' ); ?></a></th>
						</tr>
					</tfoot>
				</table>
				<script type="text/javascript">
					jQuery(function() {
						jQuery('#ss_cost_weight').on( 'click', 'a.add', function(){

							var size = jQuery('#ss_cost_weight').find('tbody .ss_weight_cost').length;

							jQuery('<tr class="ss_weight_cost">\
									<td class="sort"></td>\
									<td><input type="text" class ="wc_input_decimal" name="ss_min_weight[' + size + ']" /></td>\
									<td><input type="text" class ="wc_input_decimal" name="ss_max_weight[' + size + ']" /></td>\
									<td><input type="text" class ="wc_input_price" name="ss_cost_weight[' + size + ']" /></td>\
								</tr>').appendTo('#ss_cost_weight table tbody');

							return false;
						});
					});
				</script>
			</td>
		</tr>
		<?php
		return ob_get_clean();

	}

	public function validate_cost_weight_field() {
		error_log('validate_cost_weight_field');
		$weight_costs = array();

		if ( isset( $_POST['ss_min_weight'] ) ) {

			$ss_min_weights = array_map( 'wc_clean', $_POST['ss_min_weight'] );
			$ss_max_weights = array_map( 'wc_clean', $_POST['ss_max_weight'] );
			$ss_cost_weights = array_map( 'wc_clean', $_POST['ss_cost_weight'] );

			foreach ( $ss_min_weights as $i => $name ) {
				if ( ! isset( $ss_min_weights[ $i ] ) ) {
					continue;
				}

				$weight_costs[] = array(
					'ss_min_weight'		=> $ss_min_weights[ $i ],
					'ss_max_weight'		=> $ss_max_weights[ $i ],
					'ss_cost_weight'	=> $ss_cost_weights[ $i ],
				);
			}
		}

		return $weight_costs;
		// update_option( 'woocommerce_ss_shipping_weight_cost', $weight_costs );

	}

	/**
	 * Generate Select HTML.
	 *
	 * @param  mixed $key
	 * @param  mixed $data
	 * @since  1.0.0
	 * @return string
	 */
	public function generate_radio_html( $key, $data ) {
		$field_key = $this->get_field_key( $key );
		// error_log($key);
		// error_log(print_r($data,true));
		$defaults  = array(
			'title'             => '',
			'disabled'          => false,
			'class'             => '',
			'css'               => '',
			'placeholder'       => '',
			'type'              => 'text',
			'desc_tip'          => false,
			'description'       => '',
			'custom_attributes' => array(),
			'options'           => array(),
		);

		$data = wp_parse_args( $data, $defaults );
		// error_log($this->get_option( $key ));

		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<?php echo $this->get_tooltip_html( $data ); ?>
				<label for="<?php echo esc_attr( $field_key ); ?>"><?php echo esc_html( $data['title'] ); ?></label>
			</th>
			<td class="forminp forminp-<?php echo sanitize_title( $data['type'] ) ?>">
				<fieldset>
					<ul>
					<?php
						foreach ( $data['options'] as $option_key => $option_value ) {
							?>
							<li>
								<label><input
									name="<?php echo esc_attr( $field_key ); ?>"
									value="<?php echo esc_attr( $option_key ); ?>"
									type="radio"
									style="<?php echo esc_attr( $data['css'] ); ?>"
									class="<?php echo esc_attr( $data['class'] ); ?>"
									<?php echo $this->get_custom_attribute_html( $data ); ?>
									<?php checked( $option_key, esc_attr( $this->get_option( $key ) ) ); ?>
									/> <?php echo esc_attr( $option_value ); ?></label>
							</li>
							<?php
						}
					?>
					</ul>
					<?php echo $this->get_description_html( $data ); ?>
				</fieldset>
			</td>
		</tr>

		<?php

		return ob_get_clean();
	}

	public function calculate_shipping( $package = array() ) {
		// error_log('cacluate shipping');
		// error_log(print_r($package,true));
		// error_log(print_r($this->instance_form_fields,true));
		$selected_carrier = strtolower( $this->instance_form_fields['carrier']['options'][ $this->get_instance_option( 'carrier' ) ] );
		$selected_type = strtolower( $this->instance_form_fields['type']['options'][ $this->get_instance_option( 'type' ) ] );

		$selected_carrier = str_replace( ' ', '', $selected_carrier );
		$selected_type = str_replace( ' ', '', $selected_type );

		$this->add_rate( array(
			'id' 	=> $this->get_rate_id() . '_' . $selected_carrier . '_' . $selected_type,
			'label' => $this->title,
			'cost' 	=> 10,
			'sort'  => 0
		) );
	}

}

endif;
