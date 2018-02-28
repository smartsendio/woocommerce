<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}


if ( ! class_exists( 'SS_Shipping_WC_Method' ) ) :

class SS_Shipping_WC_Method extends WC_Shipping_Flat_Rate {

	private $shipping_method = array(
		'PostNord' 	=> 
			array( 
				'postnord_pickuppoint'		=> 'PostNord: Pickup point',
				'postnord_closestpickup'	=> 'PostNord: Closest pickup point',
				'postnord_privatetohome'	=> 'PostNord: Private to home',
				'postnord_commercial'		=> 'PostNord: Commercial',
				'postnord_valuemail' 		=> 'PostNord: Valuemail',
				'postnord_valuemailsmall' 	=> 'PostNord: Small Valuemail',
			),
		'GLS'		=>
			array( 
				'gls_pickuppoint' 			=> 'GLS: Pickup point',
				'gls_closestpickup'	 		=> 'GLS: Closest pickup point',
				'gls_privatetohome'			=> 'GLS: Private to home',
				'gls_commercial' 			=> 'GLS: Commercial',
			),
		'Bring'		=>
			array( 
				'bring_pickuppoint'			=> 'Bring: Pickup point',
				'bring_closestpickup'		=> 'Bring: Closest pickup point',
				'bring_privatetohome'		=> 'Bring: Private to home',
				'bring_commercial'			=> 'Bring: Commercial',
			),
	);

	/**
	 * Init and hook in the integration.
	 */
	public function __construct( $instance_id = 0 ) {
		$this->id = SS_SHIPPING_METHOD_ID;
		$this->instance_id = absint( $instance_id );
		$this->method_title = __( 'Smart Send', 'smart-send-shipping' );
		$this->method_description = __( 'Advanced shipping solution for PostNord, GLS and Bring.', 'smart-send-shipping' );
		
		$this->supports           = array(
			'settings',
			'shipping-zones', // support shipping zones shipping method
			'instance-settings',
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
		// Admin script
		add_action( 'admin_enqueue_scripts', array( $this, 'load_admin_scripts' ) );
	}

	public function load_admin_scripts( $hook ) {
	    
	    if( 'woocommerce_page_wc-settings' != $hook ) {
			// Only applies to WC Settings panel
			return;
	    }

		wp_enqueue_script( 'smart-send-shipping-admin-js', SS_SHIPPING_PLUGIN_DIR_URL . '/assets/js/ss-shipping-admin.js', array('jquery'), SS_SHIPPING_VERSION );

	}

	/**
	 * Initialize integration settings form fields.
	 *
	 * @return void
	 */
	public function init_form_fields() {

		$this->form_fields = array(
			'title_labels'	=> array(
				'title'   		=> __( 'Shipping Labels','smart-send-shipping'),
				'type' 			=> 'title',
				'description' 	=> __( 'Settings for general shipping labels','smart-send-shipping' ),
			),
			'combine_pdf_files' => array(
				'title'    	=> __( 'Merge labels from multiple orders','smart-send-shipping'),
				'desc'     	=> __( 'Generate PDF file containing all labels or create a single PDF file for each order','smart-send-shipping'),
				'default' 	=> 'yes',
				'type'    	=> 'radio',
				'options' 	=> array(
					'yes'     	=> __( 'Merged PDF file','smart-send-shipping'),
					'no'      	=> __( 'Separate PDF files','smart-send-shipping'),
				),
				'desc_tip'        =>  true,
			),
			'order_status' => array(
				'title'    	=> __( 'Set order status after label print','smart-send-shipping'),
				'id'       	=> 'smartsend_logistics_order_status',
				'default'  	=> '0',
				'type'     	=> 'select',
				'class'    	=> 'wc-enhanced-select',
				'options'   => array_merge( array( '0' => __("Don't change order status",'smart-send-shipping') ), wc_get_order_statuses() )
			),
			'shipping_method_for_free_shipping' => array(
				'title'    	=> __( 'Shipping method used for WooCommerce method Free Shipping','smart-send-shipping'),
				'type'     	=> 'selectopt',
				'class'    	=> 'wc-enhanced-select',
				'description' => __( 'This controls the title which the user sees during checkout.', 'smart-send-shipping' ),
				'desc_tip' 	=> true,
				'options'         	=> $this->shipping_method
			),
			'include_order_comment' => array(
				'title'    	=> __( 'Include order comment on label','smart-send-shipping'),
				'default' 	=> 'yes',
				'type'    	=> 'checkbox',
				'desc_tip'        =>  false,
			),
			'title_pickup'	=> array(
				'title'   		=> __( 'Pickup Points','smart-send-shipping'),
				'type' 			=> 'title',
				'description' 	=> __( 'Settings for displaying pickup points during checkout.','smart-send-shipping' ),
			),
			'dropdown_display_mode' => array(
				'title'   	=> __( 'Pickup dropdown display place','smart-send-shipping'),
				'desc'    	=> __( 'This controls the display postion of pick-up point dropdown on checkout page.','smart-send-shipping'),
				'default' 	=> 'below_shipping',
				'type'    	=> 'radio',
				'options' 	=> array(
					'below_shipping'   	=> __( 'Below the shipping method','smart-send-shipping'),
					'custom_hook'     	=> __( 'User custom hook in theme: "do_action(\'smart_send_shipping_pickup_dropdown\')"','smart-send-shipping'),
				),
				'desc_tip'        =>  true,
			),
			'dropdown_display_format' => array(
				'title'    	=> __( 'Dropdown format','smart-send-shipping'),
				'desc'		=> __('How the pickup points are listed during checkout','smart-send-shipping'),
				'default'  	=> '4',
				'type'     	=> 'select',
				'class'    	=> 'wc-enhanced-select',
				'desc_tip' 	=> true,
				'options'   => array(
					'1' 		=> '#'.__('Company','smart-send-shipping').', #'.__('Street','smart-send-shipping'),
					'2'    		=> '#'.__('Company','smart-send-shipping').', #'.__('Street','smart-send-shipping').', #'.__('Zipcode','smart-send-shipping'),
					'3'    		=> '#'.__('Company','smart-send-shipping').', #'.__('Street','smart-send-shipping').', #'.__('City','smart-send-shipping'),
					'4'    		=> '#'.__('Company','smart-send-shipping').', #'.__('Street','smart-send-shipping').', #'.__('Zipcode','smart-send-shipping').' #'.__('City','smart-send-shipping'),
					'5'    		=> '#'.__('Company','smart-send-shipping').', #'.__('Zipcode','smart-send-shipping'),
					'6'    		=> '#'.__('Company','smart-send-shipping').', #'.__('Zipcode','smart-send-shipping').', #'.__('City','smart-send-shipping'),
					'7'    		=> '#'.__('Company','smart-send-shipping').', #'.__('City','smart-send-shipping'),
				)
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
		$wc_shipping = WC_Shipping::instance();
		$wc_shipping_classes = $wc_shipping->get_shipping_classes();
		$shipping_classes = wp_list_pluck($wc_shipping_classes, 'name', 'slug');

		global $wp_roles;
		$user_roles = $wp_roles->get_names();

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
				'description'     	=> __( 'This is the shipping method used when generating shipping labels.', 'smart-send-shipping' ),
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
			'cost_weight' => array(
				'type'        => 'cost_weight',
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
				'description' => __( 'Users will need to spend at least this amount (including VAT) to get free shipping (if enabled above).', 'smart-send-shipping' ),
				'default'     => '0',
				'desc_tip'    => true,
			),
			'advanced_title'     => array(
				'title'           => __( 'Advanced Settings', 'smart-send-shipping' ),
				'type'            => 'title',
				'description'     => __( 'Configure the advanced settings.', 'smart-send-shipping' ),
			),
			'advanced_settings_enable' => array(
				'title'             => __( 'Advanced Settings', 'smart-send-shipping' ),
				'type'              => 'checkbox',
				'label'             => __( 'Enable', 'smart-send-shipping' ),
				'default'           => 'no',
				'description'       => __( 'Enable/disable advanced settings and to show/hide settings.', 'smart-send-shipping' ),
				'desc_tip'          => false,
			),
			'display_shipping_class_opt'  => array(
				'title'           => __( 'Display shipping method if...', 'smart-send-shipping' ),
				'type'    		  => 'select',
				'class'   		  => 'wc-enhanced-select',
				'description'     => __( 'Select when to display the shipping method based on shipping class.', 'smart-send-shipping' ),
				'default'         => '',
				'options' => array(
					'no_shipping_class'		=> __( 'N/A', 'smart-send-shipping' ),
					'all_shipping_class'   	=> __( 'ALL products belong to one of the shipping classes', 'smart-send-shipping' ),
					'one_shipping_class' 	=> __( 'At least ONE product belongs to one of the shipping classes', 'smart-send-shipping' ),
					'nall_shipping_class'  	=> __( 'ALL products do NOT belong to one of the shipping classes', 'smart-send-shipping' ),
					'none_shipping_class' 	=> __( 'At least ONE product does NOT belongs to one of the shipping classes', 'smart-send-shipping' )
				),
				'desc_tip'          => true,
			),
			'display_shipping_class'	=> array(
				'title' 		=> __( 'Shipping classes', 'smart-send-shipping' ),
				'type'	 		=> 'multiselect',
				'class' 	    => 'wc-enhanced-select',
				'description'   => __( 'Shipping classes used to display the shipping method.', 'smart-send-shipping' ),
				'desc_tip'     	=> false,
				'options'		=> $shipping_classes,
			),/*
			'display_company_opt'  => array(
				'title'           => __( 'Display based on company field', 'smart-send-shipping' ),
				'type'            => 'radio',
				'description'     => __( 'Select when to display the shipping method based on company field.', 'smart-send-shipping' ),
				'class'			  => '',
				'default'         => 'no_company',
				'options' => array(
					'no_company'		=> __( 'Display regardless of company field', 'smart-send-shipping' ),
					'only_company'	   	=> __( 'ONLY display if company-field entered', 'smart-send-shipping' ),
					'not_company' 		=> __( 'Do NOT display if company-field entered', 'smart-send-shipping' ),
				),
				'desc_tip'          => true,
			),*/
			'user_roles'	=> array(
				'title' 			=> __( 'Exclude User role', 'smart-send-shipping' ),
				'type'	 			=> 'multiselect',
				'class' 	        => 'wc-enhanced-select',
				'description'     	=> __( 'Do NOT display shipping method for these user roles.', 'smart-send-shipping' ),
				'desc_tip'        	=> false,
				'options'			=> $user_roles,
			),
		);

		/*
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
		*/
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

		$cost_desc = __( 'Enter a cost (excl. tax) or sum, e.g. 10.00 * [qty].', 'smart-send-shipping' ) . '<br/><br/>' . __( 'Use [qty] for the number of items, <br/>[cost] for the total cost of items, and [fee percent=\'10\' min_fee=\'20\' max_fee=\'\'] for percentage based fees.', 'smart-send-shipping' );

		?>
		<tr valign="top">
			<th scope="row" class="titledesc"><?php _e( 'Cost based on weight', 'smart-send-shipping' ); ?>:</th>
			<td class="forminp" id="ss_cost_weight">
				<table class="widefat wc_input_table sortable" cellspacing="0">
					<thead>
						<tr>
							<th class="sort">&nbsp;</th>
							<th><?php _e( 'Minimum', 'smart-send-shipping' ) ?> [<?php echo get_option('woocommerce_weight_unit'); ?>]<a class="tips" data-tip="<?php _e('Cart weight should be equal to or larger than this value for the shipping rate to be applicable', 'smart-send-shipping'); ?>">[?]</a></th>
							<th><?php _e( 'Maximum', 'smart-send-shipping' ); ?> [<?php echo get_option('woocommerce_weight_unit'); ?>]<a class="tips" data-tip="<?php _e('Cart weight should be strictly less than this value for the shipping rate to be applicable', 'smart-send-shipping'); ?>">[?]</a></th>
							<th><?php _e( 'Cost', 'smart-send-shipping' ); ?><a class="tips" data-tip="<?php echo $cost_desc; ?>">[?]</a></th>
						</tr>
					</thead>
					<tbody class="ss_weight_cost">
						<?php
						$i = -1;
						
						$weight_costs = $this->get_option( 'cost_weight', 
							array(
								array(
									'ss_min_weight'		=> 0,
									'ss_max_weight'		=> 20,
									'ss_cost_weight'	=> 15,
								),
							) );

						if ( $weight_costs ) {
							foreach ( $weight_costs as $weight_cost ) {
								$i++;

								echo '<tr class="ss_weight_cost">
									<td class="sort"></td>
									<td><input type="text" value="' . esc_attr( $weight_cost['ss_min_weight'] ) . '" name="ss_min_weight[' . $i . ']" class ="wc_input_decimal" /></td>
									<td><input type="text" value="' . esc_attr( $weight_cost['ss_max_weight'] ) . '" name="ss_max_weight[' . $i . ']" class ="wc_input_decimal" /></td>
									<td><input type="text" value="' . esc_attr( $weight_cost['ss_cost_weight'] ) . '" name="ss_cost_weight[' . $i . ']"  class =""/></td>
								</tr>';
							}
						}
						?>
					</tbody>
					<tfoot>
						<tr>
							<th colspan="4"><a href="#" class="add button"><?php _e( '+ Add shipping rate', 'smart-send-shipping' ); ?></a> <a href="#" class="remove_rows button"><?php _e( 'Remove selected rate(s)', 'smart-send-shipping' ); ?></a></th>
						</tr>
					</tfoot>
				</table>
                <p class="description"><?php _e( 'Enter the shipping cost excluding tax', 'smart-send-shipping' ); ?></p>
				<script type="text/javascript">
					jQuery(function() {
						jQuery('#ss_cost_weight').on( 'click', 'a.add', function(){

							var size = jQuery('#ss_cost_weight').find('tbody .ss_weight_cost').length;

							jQuery('<tr class="ss_weight_cost">\
									<td class="sort"></td>\
									<td><input type="text" class ="wc_input_decimal" name="ss_min_weight[' + size + ']" /></td>\
									<td><input type="text" class ="wc_input_decimal" name="ss_max_weight[' + size + ']" /></td>\
									<td><input type="text" class ="" name="ss_cost_weight[' + size + ']" /></td>\
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
		$rate = array(
			'id' 	=> $this->get_rate_id() . '_' . $this->get_instance_option( 'method' ),
			'label'   => $this->title,
			'cost'    => 0,
			'package' => $package,
		);

		// Check if free shipping, otherwise claculate based on weight and evaluate formulas
		if( $this->is_free_shipping( $package ) ) {

			$rate[ 'taxes' ] = false;
			$this->add_rate( $rate );

		} else {
			$cart_weight = WC()->cart->get_cart_contents_weight();
			$weight_costs = $this->get_option( 'cost_weight', array() );
			// Set tax status based on selection otherwise always taxed
			$this->tax_status = $this->get_option( 'tax_status' );

			if ( $weight_costs ) {
				foreach ( $weight_costs as $weight_cost ) {

					// If empty ignore field and continue, otherwise check if equal or greater than
					if ( empty( $weight_cost['ss_min_weight'] ) || ( $cart_weight >= $weight_cost['ss_min_weight'] ) ) {
						// IF empty ignore field and contine, otherwise check if less than
						if ( empty( $weight_cost['ss_max_weight'] ) || ( $cart_weight < $weight_cost['ss_max_weight'] ) ) {
							// If cost NOT empty add a fee
							if ( ! empty( $weight_cost['ss_cost_weight'] ) ) {

								$rate['cost'] = $this->evaluate_cost( $weight_cost['ss_cost_weight'], array(
									'qty'  => $this->get_package_item_qty( $package ),
									'cost' => $package['contents_cost'],
								) );

								$this->add_rate( $rate );
							}		
						}
					}
				}
			}
		}

		/**
		 * Developers can add additional rates based on this one via this action
		 *
		 * This example shows how you can add an extra rate based on this flat rate via custom function:
		 *
		 * 		add_action( 'woocommerce_smart_send_shipping_shipping_add_rate', 'add_another_custom_rate', 10, 2 );
		 *
		 * 		function add_another_custom_rate( $method, $rate ) {
		 * 			$new_rate          = $rate;
		 * 			$new_rate['id']    .= ':' . 'custom_rate_name'; // Append a custom ID.
		 * 			$new_rate['label'] = 'Rushed Shipping'; // Rename to 'Rushed Shipping'.
		 * 			$new_rate['cost']  += 2; // Add $2 to the cost.
		 *
		 * 			// Add it to WC.
		 * 			$method->add_rate( $new_rate );
		 * 		}.
		 */
		do_action( 'woocommerce_' . $this->id . '_shipping_add_rate', $this, $rate );
	}

	public function is_available( $package ) {
		$is_available = true;
		$one_in_array = false;
		$all_in_array = true;

		if( $this->get_instance_option( 'advanced_settings_enable' ) == 'yes' ) {
			
			// Display based on shipping class
			$display_shipping_class = $this->get_instance_option( 'display_shipping_class' );
			if( ! empty( $display_shipping_class ) ) {
				
				foreach ( $package['contents'] as $item_id => $values ) {
					
					if ( $values['data']->needs_shipping() ) {
						$found_class = $values['data']->get_shipping_class();

						if ( in_array($found_class, $display_shipping_class) ) {
							$one_in_array = true;
						} else {
							$all_in_array = false;
						}

					}
				}

				$display_shipping_class_opt = $this->get_instance_option( 'display_shipping_class_opt' );

				switch ( $display_shipping_class_opt ) {
					case 'all_shipping_class' :
						$is_available = $all_in_array;
						break;
					case 'one_shipping_class' :
						$is_available = $one_in_array;
						break;
					case 'nall_shipping_class' :
						$is_available = ! $all_in_array;
						break;
					case 'none_shipping_class' :
						$is_available = ! $one_in_array;
						break;
				}
			}

			// Exclude customer roles
			$customer_role = WC()->customer->get_role();
			$exclude_roles = $this->get_instance_option( 'user_roles' );
			if ( ! empty( $exclude_roles ) && in_array( $customer_role, $exclude_roles) ) {
				$is_available = false;
			}

		}

		return apply_filters( 'woocommerce_shipping_' . $this->id . '_is_available', $is_available, $package, $this );
	}
	
	/**
	 * See if free shipping is available based on the package and cart.
	 *
	 * @param array $package Shipping package.
	 * @return bool
	 */
	public function is_free_shipping( $package ) {
		$has_coupon         = false;
		$has_met_min_amount = false;
		$requires = $this->get_instance_option( 'requires' );
		$min_amount = $this->get_instance_option( 'min_amount' );

		if ( in_array( $requires, array( 'coupon', 'either', 'both' ) ) ) {
			if ( $coupons = WC()->cart->get_coupons() ) {
				foreach ( $coupons as $code => $coupon ) {
					if ( $coupon->is_valid() && $coupon->get_free_shipping() ) {
						$has_coupon = true;
						break;
					}
				}
			}
		}

		if ( in_array( $requires, array( 'min_amount', 'either', 'both' ) ) ) {
			$total = WC()->cart->get_displayed_subtotal();

			if ( 'incl' === WC()->cart->tax_display_cart ) {
				$total = round( $total - ( WC()->cart->get_discount_total() + WC()->cart->get_discount_tax() ), wc_get_price_decimals() );
			} else {
				$total = round( $total - WC()->cart->get_discount_total(), wc_get_price_decimals() );
			}

			if ( $total >= $min_amount ) {
				$has_met_min_amount = true;
			}
		}

		switch ( $requires ) {
			case 'min_amount' :
				$is_available = $has_met_min_amount;
				break;
			case 'coupon' :
				$is_available = $has_coupon;
				break;
			case 'both' :
				$is_available = $has_met_min_amount && $has_coupon;
				break;
			case 'either' :
				$is_available = $has_met_min_amount || $has_coupon;
				break;
			default :
				$is_available = true;
				break;
		}

		return apply_filters( 'woocommerce_shipping_' . $this->id . '_is_free_shipping', $is_available, $package, $this );
	}
}

endif;
