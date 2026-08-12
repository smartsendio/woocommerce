<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Smart Send shipping method settings.
 *
 * Builds the global and per-instance settings form field definitions for
 * SS_Shipping_WC_Method and validates the custom fields on save. The
 * rendering of the custom field types lives in
 * SS_Shipping_Method_Form_Renderer; the WooCommerce settings framework
 * still dispatches through the shipping method instance, which delegates
 * here.
 *
 * @package  SS_Shipping_Method_Settings
 * @category Shipping
 * @author   Smart Send
 */

// A second copy of the plugin may already have defined the class.
if ( ! class_exists( 'SS_Shipping_Method_Settings' ) ) :

	class SS_Shipping_Method_Settings {

		/**
		 * Shipping-class requirement mode: no restriction ("N/A").
		 *
		 * @var string
		 */
		const SHIPPING_CLASS_OPT_NA = 'no_shipping_class';

		/**
		 * Shipping-class requirement mode: ALL products belong to one of the shipping classes.
		 *
		 * @var string
		 */
		const SHIPPING_CLASS_OPT_ALL = 'all_shipping_class';

		/**
		 * Shipping-class requirement mode: at least ONE product belongs to one of the shipping classes.
		 *
		 * @var string
		 */
		const SHIPPING_CLASS_OPT_ONE = 'one_shipping_class';

		/**
		 * Shipping-class requirement mode: ALL products do NOT belong to one of the shipping classes.
		 *
		 * Spelling ("nall") is preserved exactly as persisted in existing merchant
		 * settings - see the #109 investigation note in this class's docblock.
		 *
		 * @var string
		 */
		const SHIPPING_CLASS_OPT_NALL = 'nall_shipping_class';

		/**
		 * Shipping-class requirement mode: at least ONE product does NOT belong to one of the shipping classes.
		 *
		 * @var string
		 */
		const SHIPPING_CLASS_OPT_NONE = 'none_shipping_class';

		/**
		 * Free-shipping (flat fee) requirement mode: always disabled.
		 *
		 * @var string
		 */
		const REQUIRES_DISABLED = 'disabled';

		/**
		 * Free-shipping (flat fee) requirement mode: always enabled.
		 *
		 * @var string
		 */
		const REQUIRES_ENABLED = 'enabled';

		/**
		 * Free-shipping (flat fee) requirement mode: a valid free shipping coupon.
		 *
		 * @var string
		 */
		const REQUIRES_COUPON = 'coupon';

		/**
		 * Free-shipping (flat fee) requirement mode: a minimum order amount.
		 *
		 * @var string
		 */
		const REQUIRES_MIN_AMOUNT = 'min_amount';

		/**
		 * Free-shipping (flat fee) requirement mode: a minimum order amount OR a coupon.
		 *
		 * @var string
		 */
		const REQUIRES_EITHER = 'either';

		/**
		 * Free-shipping (flat fee) requirement mode: a minimum order amount AND a coupon.
		 *
		 * @var string
		 */
		const REQUIRES_BOTH = 'both';

		/**
		 * The shipping method instance the settings belong to.
		 *
		 * @var SS_Shipping_WC_Method
		 */
		protected SS_Shipping_WC_Method $method;

		/**
		 * Shipping method catalogue (drop-down options).
		 *
		 * @var SS_Shipping_Method_Catalog
		 */
		protected SS_Shipping_Method_Catalog $catalog;

		/**
		 * @param SS_Shipping_WC_Method      $method  The shipping method instance.
		 * @param SS_Shipping_Method_Catalog $catalog Shipping method catalogue.
		 */
		public function __construct( SS_Shipping_WC_Method $method, SS_Shipping_Method_Catalog $catalog ) {
			$this->method  = $method;
			$this->catalog = $catalog;
		}

		/**
		 * Build the integration settings form fields.
		 *
		 * @return array
		 */
		public function build_form_fields() {
			$log_path              = SS_Shipping_Logger::get_log_url();
			$agents_address_format = SS_SHIPPING_WC()->get_agents_address_format();

			return array(
				'api_token'                         => array(
					// Note that this can be input for multiple sites using
					// site1:apitoken1,site2:apitoken2,....
					'title'       => __( 'API Token', 'smart-send-logistics' ),
					'type'        => 'text',
					'default'     => '',
					'description' => sprintf(
						/* translators: %s: URL of the Smart Send website. */
						__(
							'Sign up for a Smart Send account <a href="%s" target="_blank">here</a>.',
							'smart-send-logistics'
						),
						esc_url( 'https://smartsend.io/' )
					),
					'desc_tip'    => false,
				),
				'api_token_validate'                => array(
					'title'             => __( 'Validate API Token', 'smart-send-logistics' ),
					'type'              => 'button',
					'custom_attributes' => array(
						'onclick' => "ssTestConnection('#woocommerce_smart_send_shipping_api_token_validate');",
					),
					'description'       => __(
						'Save the settings before clicking the button to validate API Token.',
						'smart-send-logistics'
					),
					'desc_tip'          => false,
				),
				'demo'                              => array(
					'title'       => __( 'Demo mode', 'smart-send-logistics' ),
					'description' => __(
						'Demo mode is used for testing on a staging site. No data will be send to the shipping carrier.',
						'smart-send-logistics'
					),
					'type'        => 'checkbox',
					'default'     => 'yes',
					'label'       => __( 'Enable demo mode', 'smart-send-logistics' ),
				),
				'ss_debug'                          => array(
					'title'       => __( 'Debug Log', 'smart-send-logistics' ),
					'type'        => 'checkbox',
					'label'       => __( 'Enable logging', 'smart-send-logistics' ),
					'default'     => 'no',
					'description' => sprintf(
						/* translators: 1: opening link tag pointing at the log file, 2: closing link tag. */
						__(
							'A log file containing the communication to the Smart Send server will be maintained if this option is checked. This can be used in case of technical issues and can be found %1$shere%2$s.',
							'smart-send-logistics'
						),
						'<a href="' . $log_path . '" target = "_blank">',
						'</a>'
					),
				),
				'title_labels'                      => array(
					'title'       => __( 'Shipping Labels', 'smart-send-logistics' ),
					'type'        => 'title',
					'description' => __( 'Settings for generating shipping labels.', 'smart-send-logistics' ),
				),
				'order_status'                      => array(
					'title'   => __( 'Set order status after label print', 'smart-send-logistics' ),
					'id'      => 'smart_send_shipping_order_status',
					'default' => 'no',
					'type'    => 'select',
					'class'   => 'wc-enhanced-select',
					'options' => array_merge(
						array( '0' => __( 'Do not change order status', 'smart-send-logistics' ) ),
						wc_get_order_statuses()
					),
				),
				'shipping_method_for_free_shipping' => array(
					'title'       => __(
						'Shipping method used for WooCommerce method Free Shipping',
						'smart-send-logistics'
					),
					'type'        => 'selectopt',
					'class'       => 'wc-enhanced-select',
					'description' => __(
						'Selecting a shipping method will make it possible to make shipping labels for order places with WooCommerces native Free Shipping method.',
						'smart-send-logistics'
					),
					'options'     => $this->catalog->get_shipping_methods(),
				),
				'include_order_comment'             => array(
					'title'    => __( 'Include order comment on label', 'smart-send-logistics' ),
					'default'  => 'no',
					'type'     => 'checkbox',
					'desc_tip' => false,
				),
				'save_shipping_labels_in_uploads'   => array(
					'title'       => __( 'Save a copy of the PDF', 'smart-send-logistics' ),
					'default'     => 'no',
					'type'        => 'checkbox',
					'description' => __(
						'This will save a copy of the generated PDF label in the WordPress uploads-folder',
						'smart-send-logistics'
					),
					'desc_tip'    => true,
				),
				'title_pickup'                      => array(
					'title'       => __( 'Pick-up Points', 'smart-send-logistics' ),
					'type'        => 'title',
					'description' => __(
						'Settings for displaying pick-up points during checkout.',
						'smart-send-logistics'
					),
				),
				'dropdown_display_format'           => array(
					'title'    => __( 'Dropdown format', 'smart-send-logistics' ),
					'desc'     => __( 'How the pick-up points are listed during checkout.', 'smart-send-logistics' ),
					'default'  => '4',
					'type'     => 'select',
					'class'    => 'wc-enhanced-select',
					'desc_tip' => true,
					'options'  => $agents_address_format,
				),
				'default_select_agent'              => array(
					'title'       => __( 'Select Default', 'smart-send-logistics' ),
					'label'       => __( 'Enable Select Default', 'smart-send-logistics' ),
					'description' => __( 'This will automatically select the closest pick-up point and let the customer change to a different pick-up point. This means that the customer will not be forced to select a pick-up point before completing the order.', 'smart-send-logistics' ),
					'default'     => 'no',
					'type'        => 'checkbox',
					'desc_tip'    => true,
				),
				'title_shipping_methods'            => array(
					'title'       => __( 'Shipping methods', 'smart-send-logistics' ),
					'type'        => 'title',
					'description' => __( 'Settings for shipping methods.', 'smart-send-logistics' ),
				),
				'sort_methods_by_cost'              => array(
					'title'       => __( 'Sort shipping methods', 'smart-send-logistics' ),
					'type'        => 'checkbox',
					'label'       => __( 'Enable automatic sorting by cost on checkout page', 'smart-send-logistics' ),
					'default'     => 'no',
					'description' => sprintf(
						__(
							'Shipping methods will be sorted in ascending order, according to the cost, instead of by order of appearance in Shipping Zone table as per default',
							'smart-send-logistics'
						),
						'<a href="' . $log_path . '" target = "_blank">',
						'</a>'
					),
				),
			);
		}

		/**
		 * Validate the Demo Checkbox Field.
		 *
		 * If not set, return "no", otherwise return "yes".
		 *
		 * @param  string $key
		 * @param  string|null $value Posted Value
		 * @return string
		 *
		 * @throws Exception
		 */
		public function validate_demo_field( $key, $value ) {

			//Trying to disable Demo-mode setting. Check if the API Token entered is valid
			if ( 0 == $value ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- pre-existing loose comparison; tightening is a behaviour change out of scope for the #43 move.
				$post_data = $this->method->get_post_data();
				if ( empty( $post_data['woocommerce_smart_send_shipping_api_token'] ) ) {
					// No API Token was provided, so need to shown an error and re-enable demo-mode
					WC_Admin_Settings::add_error(
						__(
							'Demo mode can only be disabled with a valid API Token. Please enter a valid API Token and save the settings again.',
							'smart-send-logistics'
						)
					);
					$value = 1;
				} else {
					//Check if API Token is valid
					$website_url = SS_SHIPPING_WC()->get_website_url();
					$api_token   = SS_SHIPPING_WC()->get_api_token_setting( $post_data['woocommerce_smart_send_shipping_api_token'] );
					$api_handle  = new \Smartsend\Api(
						$api_token,
						$website_url,
						false
					);
					if ( ! $api_handle->getAuthenticatedUser() ) {
						// The API Token was not valid for live mode, so need to shown an error and re-enable demo-mode
						WC_Admin_Settings::add_error(
							sprintf(
								/* translators: %s: website host name of the current site. */
								__(
									'Invalid API Token. Demo mode can only be disabled with a valid API Token for %s.',
									'smart-send-logistics'
								),
								$website_url
							)
						);
						$value = 1;
					}
				}
			}

			return $this->method->validate_checkbox_field( $key, $value );
		}

		public function get_guest_role() {
			return array( 'guest' => 'Guest' );
		}

		public function build_instance_form_fields() {
			// Get list of shipping classes
			$wc_shipping         = WC_Shipping::instance();
			$wc_shipping_classes = $wc_shipping->get_shipping_classes();
			$shipping_classes    = wp_list_pluck( $wc_shipping_classes, 'name', 'slug' );

			// Get list of user roles, including guest (not logged in)
			global $wp_roles;
			$user_roles = $this->get_guest_role() + $wp_roles->get_names();

			return array(
				'title'                      => array(
					'title'       => __( 'Method Title', 'smart-send-logistics' ),
					'type'        => 'text',
					'description' => __(
						'This controls the title which the user sees during checkout.',
						'smart-send-logistics'
					),
					'default'     => __( 'Smart Send', 'smart-send-logistics' ),
					'desc_tip'    => true,
				),
				'method'                     => array(
					'title'       => __( 'Shipping Method', 'smart-send-logistics' ),
					'type'        => 'selectopt',
					'class'       => 'wc-enhanced-select',
					'description' => __(
						'This is the shipping method used when generating shipping labels.',
						'smart-send-logistics'
					),
					'desc_tip'    => true,
					'options'     => $this->catalog->get_shipping_methods(),
				),
				'tax_status'                 => array(
					'title'       => __( 'Tax status', 'smart-send-logistics' ),
					'type'        => 'select',
					'class'       => 'wc-enhanced-select',
					'description' => __(
						'Determines if the shipping cost is taxable. Remember to set a shipping tax in WooCommerce.',
						'smart-send-logistics'
					),
					'default'     => 'taxable',
					'desc_tip'    => true,
					'options'     => array(
						'taxable' => __( 'Taxable', 'smart-send-logistics' ),
						'none'    => _x( 'None', 'Tax status', 'smart-send-logistics' ),
					),
				),
				'cost_title'                 => array(
					'title'       => __( 'Cost', 'smart-send-logistics' ),
					'type'        => 'title',
					'description' => __(
						'Configure the shipping method cost and free shipping.',
						'smart-send-logistics'
					),
					'class'       => '',
				),
				'cost_weight'                => array(
					'type' => 'cost_weight',
				),
				'requires'                   => array(
					'title'   => __( 'Flat rate requires...', 'smart-send-logistics' ),
					'type'    => 'select',
					'class'   => 'wc-enhanced-select',
					'default' => '',
					'options' => array(
						self::REQUIRES_DISABLED   => __( 'Always disabled', 'smart-send-logistics' ),
						self::REQUIRES_ENABLED    => __( 'Always enabled', 'smart-send-logistics' ),
						self::REQUIRES_COUPON     => __( 'A valid free shipping coupon', 'smart-send-logistics' ),
						self::REQUIRES_MIN_AMOUNT => __( 'A minimum order amount', 'smart-send-logistics' ),
						self::REQUIRES_EITHER     => __( 'A minimum order amount OR a coupon', 'smart-send-logistics' ),
						self::REQUIRES_BOTH       => __( 'A minimum order amount AND a coupon', 'smart-send-logistics' ),
					),
				),
				'flatfee_cost'               => array(
					'title'       => __( 'Flat fee cost', 'smart-send-logistics' ),
					'type'        => 'price',
					'placeholder' => wc_format_localized_price( 0 ),
					'description' => __(
						'Shipping method cost if rules apply. To apply free shipping the value must be "0".',
						'smart-send-logistics'
					),
					'default'     => '0',
					'desc_tip'    => true,
				),
				'min_amount'                 => array(
					'title'       => __( 'Minimum order amount', 'smart-send-logistics' ),
					'type'        => 'price',
					'placeholder' => wc_format_localized_price( 0 ),
					'description' => __(
						'Users will need to spend at least this amount (including VAT) to get free shipping (if enabled above).',
						'smart-send-logistics'
					),
					'default'     => '0',
					'desc_tip'    => true,
				),
				'advanced_title'             => array(
					'title'       => __( 'Advanced Settings', 'smart-send-logistics' ),
					'type'        => 'title',
					'description' => __( 'Configure the advanced settings.', 'smart-send-logistics' ),
				),
				'advanced_settings_enable'   => array(
					'title'       => __( 'Advanced Settings', 'smart-send-logistics' ),
					'type'        => 'checkbox',
					'label'       => __( 'Enable', 'smart-send-logistics' ),
					'default'     => 'no',
					'description' => __(
						'Enable/disable advanced settings and to show/hide settings.',
						'smart-send-logistics'
					),
					'desc_tip'    => false,
				),
				'display_shipping_class_opt' => array(
					'title'       => __( 'Display shipping method if...', 'smart-send-logistics' ),
					'type'        => 'select',
					'class'       => 'wc-enhanced-select',
					'description' => __(
						'Select when to display the shipping method based on shipping class.',
						'smart-send-logistics'
					),
					'default'     => '',
					'options'     => array(
						self::SHIPPING_CLASS_OPT_NA   => __( 'N/A', 'smart-send-logistics' ),
						self::SHIPPING_CLASS_OPT_ALL  => __(
							'ALL products belong to one of the shipping classes',
							'smart-send-logistics'
						),
						self::SHIPPING_CLASS_OPT_ONE  => __(
							'At least ONE product belongs to one of the shipping classes',
							'smart-send-logistics'
						),
						self::SHIPPING_CLASS_OPT_NALL => __(
							'ALL products do NOT belong to one of the shipping classes',
							'smart-send-logistics'
						),
						self::SHIPPING_CLASS_OPT_NONE => __(
							'At least ONE product does NOT belongs to one of the shipping classes',
							'smart-send-logistics'
						),
					),
					'desc_tip'    => true,
				),
				'display_shipping_class'     => array(
					'title'       => __( 'Shipping classes', 'smart-send-logistics' ),
					'type'        => 'multiselect',
					'class'       => 'wc-enhanced-select',
					'description' => __(
						'Shipping classes used to display the shipping method.',
						'smart-send-logistics'
					),
					'desc_tip'    => false,
					'options'     => $shipping_classes,
				),
				// phpcs:disable Squiz.PHP.CommentedOutCode.Found -- deliberately disabled setting kept as documentation of a shelved feature.
				/*
			'display_company_opt'  => array(
				'title'           => __('Display based on company field', 'smart-send-logistics'),
				'type'            => 'radio',
				'description'     => __('Select when to display the shipping method based on company field.', 'smart-send-logistics'),
				'class'           => '',
				'default'         => 'no_company',
				'options' => array(
					'no_company'        => __('Display regardless of company field', 'smart-send-logistics'),
					'only_company'      => __('ONLY display if company-field entered', 'smart-send-logistics'),
					'not_company'       => __('Do NOT display if company-field entered', 'smart-send-logistics'),
				),
				'desc_tip'          => true,
			),*/
				// phpcs:enable Squiz.PHP.CommentedOutCode.Found
				'user_roles'                 => array(
					'title'       => __( 'Exclude User role', 'smart-send-logistics' ),
					'type'        => 'multiselect',
					'class'       => 'wc-enhanced-select',
					'description' => __( 'Do NOT display shipping method for these user roles.', 'smart-send-logistics' ),
					'desc_tip'    => false,
					'options'     => $user_roles,
				),
				'return_title'               => array(
					'title'       => __( 'Return shipping', 'smart-send-logistics' ),
					'type'        => 'title',
					'description' => __( 'Configure how to handle return shipping.', 'smart-send-logistics' ),
					'class'       => '',
				),
				'return_method'              => array(
					'title'       => __( 'Return Shipping Method', 'smart-send-logistics' ),
					'type'        => 'selectopt',
					'class'       => 'wc-enhanced-select',
					'description' => __(
						'This is the shipping method used when generating a return shipping labels.',
						'smart-send-logistics'
					),
					'desc_tip'    => true,
					'options'     => $this->catalog->get_return_shipping_methods(),
				),

				'auto_generate_return_label' => array(
					'title'       => __( 'Auto Generate Return Label', 'smart-send-logistics' ),
					'type'        => 'checkbox',
					'label'       => __( 'Enable', 'smart-send-logistics' ),
					'default'     => 'no',
					'description' => __(
						'Should a return label automatically be generated whenever a normal shipping labels is generated.',
						'smart-send-logistics'
					),
					'desc_tip'    => false,
				),
			);
		}

		public function validate_title_field( $key, $title ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- pre-existing behaviour: WooCommerce's settings framework catches these exceptions and shows the message as a settings error; escaping is a behaviour change out of scope for the #43 move.

			if ( empty( $title ) ) {
				throw new Exception( __( '"Method Title" cannot be empty', 'smart-send-logistics' ) );
			}

			if ( $title == $this->method->method_title ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- pre-existing loose comparison; tightening is a behaviour change out of scope for the #43 move.
				throw new Exception(
					__(
						'Change the "Method Title" field to something human readable. This is what your customers see at checkout.',
						'smart-send-logistics'
					)
				);
			}

			return $this->method->validate_text_field( $key, $title );
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		public function validate_method_field( $key, $method ) {

			if ( empty( $method ) ) {
				throw new Exception( __( 'Select a "Shipping Method"', 'smart-send-logistics' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- pre-existing behaviour: WooCommerce's settings framework catches this exception and shows the message as a settings error.
			}

			return $this->method->validate_select_field( $key, $method );
		}

		public function validate_cost_weight_field() {

			$weight_costs = array();

			// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- pre-existing behaviour: WooCommerce's settings save flow verifies its own nonce and the values are wc_clean-ed plus validate_text_field-ed below; changing the input handling is out of scope for the #43 move.
			if ( isset( $_POST['ss_cost_weight'] ) ) {

				$ss_min_weights  = array_map( 'wc_clean', $_POST['ss_min_weight'] );
				$ss_max_weights  = array_map( 'wc_clean', $_POST['ss_max_weight'] );
				$ss_cost_weights = array_map( 'wc_clean', $_POST['ss_cost_weight'] );
				// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput

				foreach ( $ss_min_weights as $i => $name ) {

					if ( empty( $ss_cost_weights[ $i ] ) ) {
						continue;
					}

					$ss_min_weights[ $i ]  = $this->method->validate_text_field( 'ss_min_weight', $ss_min_weights[ $i ] );
					$ss_max_weights[ $i ]  = $this->method->validate_text_field( 'ss_max_weight', $ss_max_weights[ $i ] );
					$ss_cost_weights[ $i ] = $this->method->validate_text_field( 'ss_cost_weight', $ss_cost_weights[ $i ] );

					$weight_costs[] = array(
						'ss_min_weight'  => $ss_min_weights[ $i ],
						'ss_max_weight'  => $ss_max_weights[ $i ],
						'ss_cost_weight' => $ss_cost_weights[ $i ],
					);
				}
			}

			return $weight_costs;
		}
	}

endif;
