<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

// A second copy of the plugin may already have defined the class.
if ( ! class_exists( 'SS_Shipping_WC_Method' ) ) :

	class SS_Shipping_WC_Method extends WC_Shipping_Flat_Rate {

		/**
		 * Shipping method catalogue (method code => human readable name).
		 *
		 * @var SS_Shipping_Method_Catalog
		 */
		protected SS_Shipping_Method_Catalog $catalog;

		/**
		 * Settings form field definitions and validation.
		 *
		 * @var SS_Shipping_Method_Settings
		 */
		protected SS_Shipping_Method_Settings $settings_builder;

		/**
		 * Renderer for the custom settings field types.
		 *
		 * @var SS_Shipping_Method_Form_Renderer
		 */
		protected SS_Shipping_Method_Form_Renderer $form_renderer;

		/**
		 * Init and hook in the integration.
		 */
		public function __construct( $instance_id = 0 ) {
			$this->id                 = SS_SHIPPING_METHOD_ID;
			$this->instance_id        = absint( $instance_id );
			$this->method_title       = __( 'Smart Send', 'smart-send-logistics' );
			$this->method_description = __(
				'Advanced shipping solution for PostNord, GLS and Bring.',
				'smart-send-logistics'
			);

			$this->supports = array(
				'settings',
				'shipping-zones', // support shipping zones shipping method
				'instance-settings',
			);

			$this->catalog          = new SS_Shipping_Method_Catalog();
			$this->settings_builder = new SS_Shipping_Method_Settings( $this, $this->catalog );
			$this->form_renderer    = new SS_Shipping_Method_Form_Renderer( $this );

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

			// $this->id is always SS_SHIPPING_METHOD_ID ('smart_send_shipping'), see #106.
			add_action( 'woocommerce_update_options_shipping_smart_send_shipping', array( $this, 'process_admin_options' ) );
			// Admin script
			add_action( 'admin_enqueue_scripts', array( $this, 'load_admin_scripts' ) );
		}

		/**
		 * load admin scripts on settings page only
		 */
		public function load_admin_scripts( $hook ) {

			if ( 'woocommerce_page_wc-settings' != $hook ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseNotEqual -- pre-existing loose comparison; tightening is a behaviour change out of scope for the #43 move.
				// Only applies to WC Settings panel
				return;
			}

			wp_enqueue_script(
				'smart-send-shipping-admin-js',
				SS_SHIPPING_PLUGIN_DIR_URL . '/admin/js/ss-shipping-admin.js',
				array( 'jquery' ),
				SS_SHIPPING_VERSION,
				false
			);

			$test_con_data = array(
				'ajax_url'              => admin_url( 'admin-ajax.php' ),
				'test_connection_nonce' => wp_create_nonce( 'ss-test-connection' ),
				'validating_connection' => __( 'Validating connection...', 'smart-send-logistics' ),
			);

			wp_enqueue_script(
				'smart-send-test-connection',
				SS_SHIPPING_PLUGIN_DIR_URL . '/admin/js/ss-shipping-test-connection.js',
				array( 'jquery' ),
				SS_SHIPPING_VERSION,
				false
			);
			wp_localize_script( 'smart-send-test-connection', 'ss_test_connection_obj', $test_con_data );
		}

		/**
		 * Initialize integration settings form fields.
		 *
		 * @see SS_Shipping_Method_Settings::build_form_fields()
		 *
		 * @return void
		 */
		public function init_form_fields() {
			$this->form_fields = $this->settings_builder->build_form_fields();
		}

		/**
		 * Initialize per-instance settings form fields.
		 *
		 * @see SS_Shipping_Method_Settings::build_instance_form_fields()
		 *
		 * @return void
		 */
		public function init_instance_form_fields() {
			$this->instance_form_fields = $this->settings_builder->build_instance_form_fields();
		}

		/**
		 * Validate the Demo Checkbox Field.
		 *
		 * WooCommerce's settings framework dispatches validate_{key}_field on
		 * this instance; the logic lives in the settings component.
		 *
		 * @see SS_Shipping_Method_Settings::validate_demo_field()
		 *
		 * @param  string $key
		 * @param  string|null $value Posted Value
		 * @return string
		 *
		 * @throws Exception
		 */
		public function validate_demo_field( $key, $value ) {
			return $this->settings_builder->validate_demo_field( $key, $value );
		}

		/**
		 * Validate the Method Title field.
		 *
		 * @see SS_Shipping_Method_Settings::validate_title_field()
		 *
		 * @throws Exception
		 */
		public function validate_title_field( $key, $title ) {
			return $this->settings_builder->validate_title_field( $key, $title );
		}

		/**
		 * Validate the Shipping Method field.
		 *
		 * @see SS_Shipping_Method_Settings::validate_method_field()
		 *
		 * @throws Exception
		 */
		public function validate_method_field( $key, $method ) {
			return $this->settings_builder->validate_method_field( $key, $method );
		}

		/**
		 * Validate the cost-per-weight table field.
		 *
		 * @see SS_Shipping_Method_Settings::validate_cost_weight_field()
		 */
		public function validate_cost_weight_field() {
			return $this->settings_builder->validate_cost_weight_field();
		}

		/**
		 * Generate Button HTML.
		 *
		 * WooCommerce's settings framework dispatches generate_{type}_html on
		 * this instance; the rendering lives in the form renderer component.
		 *
		 * @see SS_Shipping_Method_Form_Renderer::generate_button_html()
		 */
		public function generate_button_html( $key, $data ) {
			return $this->form_renderer->generate_button_html( $key, $data );
		}

		/**
		 * Generate grouped-select HTML.
		 *
		 * @see SS_Shipping_Method_Form_Renderer::generate_selectopt_html()
		 */
		public function generate_selectopt_html( $key, $data ) {
			return $this->form_renderer->generate_selectopt_html( $key, $data );
		}

		/**
		 * Generate cost weight html.
		 *
		 * @see SS_Shipping_Method_Form_Renderer::generate_cost_weight_html()
		 *
		 * @return string
		 */
		public function generate_cost_weight_html() {
			return $this->form_renderer->generate_cost_weight_html();
		}

		/**
		 * Generate radio HTML.
		 *
		 * @see SS_Shipping_Method_Form_Renderer::generate_radio_html()
		 */
		public function generate_radio_html( $key, $data ) {
			return $this->form_renderer->generate_radio_html( $key, $data );
		}

		public function calculate_shipping( $package = array() ) {
			$rate = array(
				'id'        => $this->get_rate_id(),
				'label'     => $this->title,
				'cost'      => 0,
				'meta_data' => array(
					'smart_send_shipping_method' => $this->get_instance_option( 'method' ),
					'smart_send_return_method'   => $this->get_instance_option( 'return_method' ),
					'smart_send_auto_generate_return_label' => $this->get_instance_option( 'auto_generate_return_label' ),
				),
				'package'   => $package,
			);

			// Log-file developer trace: the rate matched the zone, cost calculation starts.
			SS_Shipping_Logger::debug(
				sprintf( 'Calculating shipping cost for shipping rate %s', $rate['id'] ),
				array(
					'rate_id' => $rate['id'],
					'label'   => $rate['label'],
					'method'  => $rate['meta_data']['smart_send_shipping_method'],
				)
			);
			SS_Shipping_Checkout_Debug::add_notice(
				sprintf(
					/* translators: 1: shipping rate label, 2: Smart Send shipping method code, 3: shipping rate id. */
					__( 'Smart Send: evaluated method "%1$s" (%2$s, rate id %3$s).', 'smart-send-logistics' ),
					$rate['label'],
					$rate['meta_data']['smart_send_shipping_method'],
					$rate['id']
				)
			);

			// Set tax status based on selection otherwise always taxed
			$this->tax_status = $this->get_option( 'tax_status' );

			// Check if free shipping, otherwise claculate based on weight and evaluate formulas
			if ( $this->is_free_shipping( $package ) ) {

				$rate['cost'] = $this->get_option( 'flatfee_cost' );
				$this->add_rate( $rate );
				SS_Shipping_Logger::debug(
					sprintf( 'Smart Send "%1$s": free shipping applies - rate added with flat fee cost %2$s.', $rate['label'], $rate['cost'] ),
					array(
						'rate_id' => $rate['id'],
						'cost'    => $rate['cost'],
					)
				);
				SS_Shipping_Checkout_Debug::add_notice(
					sprintf(
						/* translators: 1: shipping rate label, 2: flat fee cost. */
						__( 'Smart Send "%1$s": free shipping applies - rate added with flat fee cost %2$s.', 'smart-send-logistics' ),
						$rate['label'],
						$rate['cost']
					)
				);

			} else {
				$cart_weight  = WC()->cart->get_cart_contents_weight();
				$weight_costs = $this->get_option( 'cost_weight', array() );

				$weight_unit  = get_option( 'woocommerce_weight_unit' );
				$rate_added   = false;
				$matched_rows = array();

				if ( $weight_costs ) {
					foreach ( $weight_costs as $weight_cost ) {

						// If empty ignore field and continue, otherwise check if equal or greater than
						if ( empty( $weight_cost['ss_min_weight'] ) || ( $cart_weight >= $weight_cost['ss_min_weight'] ) ) {
							// IF empty ignore field and contine, otherwise check if less than
							if ( empty( $weight_cost['ss_max_weight'] ) || ( $cart_weight < $weight_cost['ss_max_weight'] ) ) {
								// If cost NOT empty add a fee
								if ( ! empty( $weight_cost['ss_cost_weight'] ) ) {

									$rate['cost'] = $this->evaluate_cost(
										$weight_cost['ss_cost_weight'],
										array(
											'qty'  => $this->get_package_item_qty( $package ),
											'cost' => $package['contents_cost'],
										)
									);

									$this->add_rate( $rate );
									$rate_added     = true;
									$matched_row    = sprintf( '%1$s-%2$s %3$s', $weight_cost['ss_min_weight'], $weight_cost['ss_max_weight'], $weight_unit );
									$matched_rows[] = $matched_row;
									SS_Shipping_Logger::debug(
										sprintf(
											'Smart Send "%1$s": cart weight %2$s %3$s matched weight table row %4$s - rate added with cost %5$s.',
											$rate['label'],
											$cart_weight,
											$weight_unit,
											$matched_row,
											$rate['cost']
										),
										array(
											'rate_id'     => $rate['id'],
											'cost'        => $rate['cost'],
											'cart_weight' => $cart_weight,
										)
									);
									SS_Shipping_Checkout_Debug::add_notice(
										sprintf(
											/* translators: 1: shipping rate label, 2: cart weight, 3: weight unit, 4: matched weight table row as "min-max unit", 5: rate cost. */
											__( 'Smart Send "%1$s": cart weight %2$s %3$s matched weight table row %4$s - rate added with cost %5$s.', 'smart-send-logistics' ),
											$rate['label'],
											$cart_weight,
											$weight_unit,
											$matched_row,
											$rate['cost']
										)
									);
								}
							}
						}
					}
				}

				if ( count( $matched_rows ) > 1 ) {
					$last_row = $matched_rows[ count( $matched_rows ) - 1 ];
					SS_Shipping_Logger::debug(
						sprintf(
							'Smart Send "%1$s": %2$d overlapping weight table rows matched (%3$s) - the rows share rate id %4$s, so only the LAST matching row (%5$s) is offered and the earlier matches were overwritten.',
							$rate['label'],
							count( $matched_rows ),
							implode( ', ', $matched_rows ),
							$rate['id'],
							$last_row
						),
						array(
							'rate_id'      => $rate['id'],
							'matched_rows' => $matched_rows,
						)
					);
					SS_Shipping_Checkout_Debug::add_notice(
						sprintf(
							/* translators: 1: shipping rate label, 2: number of matched weight table rows, 3: list of matched rows, 4: shipping rate id, 5: last matched row. */
							__( 'Smart Send "%1$s": %2$d overlapping weight table rows matched (%3$s) - the rows share rate id %4$s, so only the LAST matching row (%5$s) is offered and the earlier matches were overwritten.', 'smart-send-logistics' ),
							$rate['label'],
							count( $matched_rows ),
							implode( ', ', $matched_rows ),
							$rate['id'],
							$last_row
						)
					);
				}

				if ( ! $rate_added ) {
					SS_Shipping_Logger::debug(
						sprintf( 'Smart Send "%1$s": no rate added - cart weight %2$s %3$s did not match any weight table row.', $rate['label'], $cart_weight, $weight_unit ),
						array(
							'rate_id'     => $rate['id'],
							'cart_weight' => $cart_weight,
						)
					);
					SS_Shipping_Checkout_Debug::add_notice(
						sprintf(
							/* translators: 1: shipping rate label, 2: cart weight, 3: weight unit. */
							__( 'Smart Send "%1$s": no rate added - cart weight %2$s %3$s did not match any weight table row.', 'smart-send-logistics' ),
							$rate['label'],
							$cart_weight,
							$weight_unit
						)
					);
				}
			}

			// Log-file developer trace: the outcome of the cost calculation.
			if ( isset( $this->rates[ $rate['id'] ] ) ) {
				SS_Shipping_Logger::debug(
					sprintf( 'Calculated shipping cost for shipping rate %1$s: %2$s', $rate['id'], $this->rates[ $rate['id'] ]->get_cost() ),
					array(
						'rate_id' => $rate['id'],
						'cost'    => $this->rates[ $rate['id'] ]->get_cost(),
					)
				);
			} else {
				SS_Shipping_Logger::debug(
					sprintf( 'Shipping rate %s is not available for this package - no rate added', $rate['id'] ),
					array( 'rate_id' => $rate['id'] )
				);
			}

			/**
			 * Developers can add additional rates based on this one via this action
			 *
			 * This example shows how you can add an extra rate based on this flat rate via custom function:
			 *
			 *        add_action( 'woocommerce_smart_send_shipping_shipping_add_rate', 'add_another_custom_rate', 10, 2 );
			 *
			 *        function add_another_custom_rate( $method, $rate ) {
			 *            $new_rate          = $rate;
			 *            $new_rate['id']    .= ':' . 'custom_rate_name'; // Append a custom ID.
			 *            $new_rate['label'] = 'Rushed Shipping'; // Rename to 'Rushed Shipping'.
			 *            $new_rate['cost']  += 2; // Add $2 to the cost.
			 *
			 *            // Add it to WC.
			 *            $method->add_rate( $new_rate );
			 *        }.
			 */
			// $this->id is always SS_SHIPPING_METHOD_ID ('smart_send_shipping'), see #106.
			do_action( 'woocommerce_smart_send_shipping_shipping_add_rate', $this, $rate );
		}

		public function is_available( $package ) {
			$is_available = true;
			$one_in_array = false;
			$all_in_array = true;

			if ( 'yes' == $this->get_instance_option( 'advanced_settings_enable' ) ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- pre-existing loose comparison; tightening is a behaviour change out of scope for the #43 move.

				// Display based on shipping class
				$display_shipping_class = $this->get_instance_option( 'display_shipping_class' );
				if ( ! empty( $display_shipping_class ) ) {

					foreach ( $package['contents'] as $item_id => $values ) {

						if ( $values['data']->needs_shipping() ) {
							$found_class = $values['data']->get_shipping_class();

							if ( in_array( $found_class, $display_shipping_class ) ) { // phpcs:ignore WordPress.PHP.StrictInArray.MissingTrueStrict -- pre-existing loose in_array; tightening is a behaviour change out of scope for the #43 move.
								$one_in_array = true;
							} else {
								$all_in_array = false;
							}
						}
					}

					$display_shipping_class_opt = $this->get_instance_option( 'display_shipping_class_opt' );

					switch ( $display_shipping_class_opt ) {
						case 'all_shipping_class':
							$is_available = $all_in_array;
							break;
						case 'one_shipping_class':
							$is_available = $one_in_array;
							break;
						case 'nall_shipping_class':
							$is_available = ! $one_in_array;
							break;
						case 'none_shipping_class':
							$is_available = ! $all_in_array;
							break;
					}

					$this->report_shipping_class_availability( $display_shipping_class_opt, $is_available );
				}

				// Exclude customer roles
				$exclude_roles = $this->get_instance_option( 'user_roles' );
				if ( ! empty( $exclude_roles ) ) {

					$user_id = get_current_user_id();
					if ( empty( $user_id ) ) {
						$customer_roles = $this->settings_builder->get_guest_role();
					} else {
						$user_meta      = get_userdata( $user_id );
						$customer_roles = $user_meta->roles; //array of roles the user is part of.
					}

					foreach ( $customer_roles as $key => $customer_role ) {
						$customer_role = strtolower( $customer_role ); // ensure all names are lowercase to compare keys correctly
						if ( in_array( $customer_role, $exclude_roles ) ) { // phpcs:ignore WordPress.PHP.StrictInArray.MissingTrueStrict -- pre-existing loose in_array; tightening is a behaviour change out of scope for the #43 move.
							$is_available = false;

							SS_Shipping_Logger::debug(
								sprintf( 'Smart Send "%1$s": shipping method is NOT available, because customer role "%2$s" is being excluded.', $this->title, $customer_role ),
								array( 'customer_role' => $customer_role )
							);
							SS_Shipping_Checkout_Debug::add_notice(
								sprintf(
									/* translators: 1: shipping method title, 2: customer role. */
									__( 'Smart Send "%1$s": shipping method is NOT available, because customer role "%2$s" is being excluded.', 'smart-send-logistics' ),
									$this->title,
									$customer_role
								)
							);
							break;
						}
					}
				}
			}

			// $this->id is always SS_SHIPPING_METHOD_ID ('smart_send_shipping'), see #106.
			return apply_filters( 'woocommerce_shipping_smart_send_shipping_is_available', $is_available, $package, $this );
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
			$requires           = $this->get_instance_option( 'requires' );
			$min_amount         = $this->get_instance_option( 'min_amount' );

			if ( in_array( $requires, array( 'coupon', 'either', 'both' ) ) ) { // phpcs:ignore WordPress.PHP.StrictInArray.MissingTrueStrict -- pre-existing loose in_array; tightening is a behaviour change out of scope for the #43 move.
				$coupons = WC()->cart->get_coupons();
				if ( $coupons ) {
					foreach ( $coupons as $code => $coupon ) {
						if ( $coupon->is_valid() && $coupon->get_free_shipping() ) {
							$has_coupon = true;
							break;
						}
					}
				}
			}

			if ( in_array( $requires, array( 'min_amount', 'either', 'both' ) ) ) { // phpcs:ignore WordPress.PHP.StrictInArray.MissingTrueStrict -- pre-existing loose in_array; tightening is a behaviour change out of scope for the #43 move.
				$total = WC()->cart->get_displayed_subtotal();

				if ( 'incl' === WC()->cart->get_tax_price_display_mode() ) {
					$total = round(
						$total - ( WC()->cart->get_cart_discount_total() + WC()->cart->get_cart_discount_tax_total() ),
						wc_get_price_decimals()
					);
				} else {
					$total = round( $total - WC()->cart->get_cart_discount_total(), wc_get_price_decimals() );
				}

				if ( $total >= $min_amount ) {
					$has_met_min_amount = true;
				}
			}

			switch ( $requires ) {
				case 'min_amount':
					$is_available = $has_met_min_amount;
					break;
				case 'coupon':
					$is_available = $has_coupon;
					break;
				case 'both':
					$is_available = $has_met_min_amount && $has_coupon;
					break;
				case 'either':
					$is_available = $has_met_min_amount || $has_coupon;
					break;
				case 'enabled':
					$is_available = true;
					break;
				case 'disabled':
				default:
					$is_available = false;
					break;
			}

			$this->report_free_shipping_availability( $requires, $is_available, isset( $total ) ? $total : null, $min_amount );

			return apply_filters(
				'woocommerce_shipping_' . $this->id . '_is_free_shipping',
				$is_available,
				$package,
				$this
			);
		}

		/**
		 * Report the shipping-class availability outcome to the log (English)
		 * and the checkout shipping debug bar (translated).
		 *
		 * @param string  $display_shipping_class_opt The configured shipping-class condition.
		 * @param boolean $is_available               Whether the method ended up available.
		 * @return void
		 */
		protected function report_shipping_class_availability( $display_shipping_class_opt, $is_available ) {
			switch ( $display_shipping_class_opt ) {
				case 'all_shipping_class':
					if ( $is_available ) {
						$log_message = sprintf( 'Smart Send "%s": shipping method IS available, because ALL products belong to one of the shipping classes', $this->title );
						/* translators: %s: shipping method title. */
						$notice_message = sprintf( __( 'Smart Send "%s": shipping method IS available, because ALL products belong to one of the shipping classes', 'smart-send-logistics' ), $this->title );
					} else {
						$log_message = sprintf( 'Smart Send "%s": shipping method is NOT available, because ALL products belong to one of the shipping classes', $this->title );
						/* translators: %s: shipping method title. */
						$notice_message = sprintf( __( 'Smart Send "%s": shipping method is NOT available, because ALL products belong to one of the shipping classes', 'smart-send-logistics' ), $this->title );
					}
					break;
				case 'one_shipping_class':
					if ( $is_available ) {
						$log_message = sprintf( 'Smart Send "%s": shipping method IS available, because at least ONE product belongs to one of the shipping classes', $this->title );
						/* translators: %s: shipping method title. */
						$notice_message = sprintf( __( 'Smart Send "%s": shipping method IS available, because at least ONE product belongs to one of the shipping classes', 'smart-send-logistics' ), $this->title );
					} else {
						$log_message = sprintf( 'Smart Send "%s": shipping method is NOT available, because at least ONE product belongs to one of the shipping classes', $this->title );
						/* translators: %s: shipping method title. */
						$notice_message = sprintf( __( 'Smart Send "%s": shipping method is NOT available, because at least ONE product belongs to one of the shipping classes', 'smart-send-logistics' ), $this->title );
					}
					break;
				case 'nall_shipping_class':
					if ( $is_available ) {
						$log_message = sprintf( 'Smart Send "%s": shipping method IS available, because ALL products do NOT belong to one of the shipping classes', $this->title );
						/* translators: %s: shipping method title. */
						$notice_message = sprintf( __( 'Smart Send "%s": shipping method IS available, because ALL products do NOT belong to one of the shipping classes', 'smart-send-logistics' ), $this->title );
					} else {
						$log_message = sprintf( 'Smart Send "%s": shipping method is NOT available, because ALL products do NOT belong to one of the shipping classes', $this->title );
						/* translators: %s: shipping method title. */
						$notice_message = sprintf( __( 'Smart Send "%s": shipping method is NOT available, because ALL products do NOT belong to one of the shipping classes', 'smart-send-logistics' ), $this->title );
					}
					break;
				case 'none_shipping_class':
					if ( $is_available ) {
						$log_message = sprintf( 'Smart Send "%s": shipping method IS available, because at least ONE product does NOT belongs to one of the shipping classes', $this->title );
						/* translators: %s: shipping method title. */
						$notice_message = sprintf( __( 'Smart Send "%s": shipping method IS available, because at least ONE product does NOT belongs to one of the shipping classes', 'smart-send-logistics' ), $this->title );
					} else {
						$log_message = sprintf( 'Smart Send "%s": shipping method is NOT available, because at least ONE product does NOT belongs to one of the shipping classes', $this->title );
						/* translators: %s: shipping method title. */
						$notice_message = sprintf( __( 'Smart Send "%s": shipping method is NOT available, because at least ONE product does NOT belongs to one of the shipping classes', 'smart-send-logistics' ), $this->title );
					}
					break;
				default:
					return;
			}

			SS_Shipping_Logger::debug( $log_message, array( 'shipping_class_option' => $display_shipping_class_opt ) );
			SS_Shipping_Checkout_Debug::add_notice( $notice_message );
		}

		/**
		 * Report the free-shipping (flat fee) availability outcome to the log
		 * (English) and the checkout shipping debug bar (translated).
		 *
		 * @param string     $requires     The configured free-shipping condition.
		 * @param boolean    $is_available Whether free shipping ended up available.
		 * @param mixed|null $total        The evaluated cart total (only set for amount-based conditions).
		 * @param mixed      $min_amount   The configured minimum order amount.
		 * @return void
		 */
		protected function report_free_shipping_availability( $requires, $is_available, $total, $min_amount ) {
			switch ( $requires ) {
				case 'min_amount':
					if ( $is_available ) {
						$log_message = sprintf( 'Smart Send "%1$s": free shipping (flat fee) IS available, because the total is %2$s a minimum order amount of %3$s is needed.', $this->title, $total, $min_amount );
						/* translators: 1: shipping method title, 2: cart total, 3: minimum order amount. */
						$notice_message = sprintf( __( 'Smart Send "%1$s": free shipping (flat fee) IS available, because the total is %2$s a minimum order amount of %3$s is needed.', 'smart-send-logistics' ), $this->title, $total, $min_amount );
					} else {
						$log_message = sprintf( 'Smart Send "%1$s": free shipping (flat fee) is NOT available, because the total is %2$s a minimum order amount of %3$s is needed.', $this->title, $total, $min_amount );
						/* translators: 1: shipping method title, 2: cart total, 3: minimum order amount. */
						$notice_message = sprintf( __( 'Smart Send "%1$s": free shipping (flat fee) is NOT available, because the total is %2$s a minimum order amount of %3$s is needed.', 'smart-send-logistics' ), $this->title, $total, $min_amount );
					}
					break;
				case 'coupon':
					if ( $is_available ) {
						$log_message = sprintf( 'Smart Send "%s": free shipping (flat fee) IS available, because a coupon is needed.', $this->title );
						/* translators: %s: shipping method title. */
						$notice_message = sprintf( __( 'Smart Send "%s": free shipping (flat fee) IS available, because a coupon is needed.', 'smart-send-logistics' ), $this->title );
					} else {
						$log_message = sprintf( 'Smart Send "%s": free shipping (flat fee) is NOT available, because a coupon is needed.', $this->title );
						/* translators: %s: shipping method title. */
						$notice_message = sprintf( __( 'Smart Send "%s": free shipping (flat fee) is NOT available, because a coupon is needed.', 'smart-send-logistics' ), $this->title );
					}
					break;
				case 'both':
					if ( $is_available ) {
						$log_message = sprintf( 'Smart Send "%1$s": free shipping (flat fee) IS available, because the total is %2$s a minimum order amount of %3$s is needed AND a coupon is needed.', $this->title, $total, $min_amount );
						/* translators: 1: shipping method title, 2: cart total, 3: minimum order amount. */
						$notice_message = sprintf( __( 'Smart Send "%1$s": free shipping (flat fee) IS available, because the total is %2$s a minimum order amount of %3$s is needed AND a coupon is needed.', 'smart-send-logistics' ), $this->title, $total, $min_amount );
					} else {
						$log_message = sprintf( 'Smart Send "%1$s": free shipping (flat fee) is NOT available, because the total is %2$s a minimum order amount of %3$s is needed AND a coupon is needed.', $this->title, $total, $min_amount );
						/* translators: 1: shipping method title, 2: cart total, 3: minimum order amount. */
						$notice_message = sprintf( __( 'Smart Send "%1$s": free shipping (flat fee) is NOT available, because the total is %2$s a minimum order amount of %3$s is needed AND a coupon is needed.', 'smart-send-logistics' ), $this->title, $total, $min_amount );
					}
					break;
				case 'either':
					if ( $is_available ) {
						$log_message = sprintf( 'Smart Send "%1$s": free shipping (flat fee) IS available, because the total is %2$s a minimum order amount of %3$s is needed OR a coupon is needed.', $this->title, $total, $min_amount );
						/* translators: 1: shipping method title, 2: cart total, 3: minimum order amount. */
						$notice_message = sprintf( __( 'Smart Send "%1$s": free shipping (flat fee) IS available, because the total is %2$s a minimum order amount of %3$s is needed OR a coupon is needed.', 'smart-send-logistics' ), $this->title, $total, $min_amount );
					} else {
						$log_message = sprintf( 'Smart Send "%1$s": free shipping (flat fee) is NOT available, because the total is %2$s a minimum order amount of %3$s is needed OR a coupon is needed.', $this->title, $total, $min_amount );
						/* translators: 1: shipping method title, 2: cart total, 3: minimum order amount. */
						$notice_message = sprintf( __( 'Smart Send "%1$s": free shipping (flat fee) is NOT available, because the total is %2$s a minimum order amount of %3$s is needed OR a coupon is needed.', 'smart-send-logistics' ), $this->title, $total, $min_amount );
					}
					break;
				case 'enabled':
					$log_message = sprintf( 'Smart Send "%s": free shipping (flat fee) IS available, because it is always enabled.', $this->title );
					/* translators: %s: shipping method title. */
					$notice_message = sprintf( __( 'Smart Send "%s": free shipping (flat fee) IS available, because it is always enabled.', 'smart-send-logistics' ), $this->title );
					break;
				case 'disabled':
					$log_message = sprintf( 'Smart Send "%s": free shipping (flat fee) is NOT available, because it is always disabled.', $this->title );
					/* translators: %s: shipping method title. */
					$notice_message = sprintf( __( 'Smart Send "%s": free shipping (flat fee) is NOT available, because it is always disabled.', 'smart-send-logistics' ), $this->title );
					break;
				default:
					$log_message = sprintf( 'Smart Send "%s": free shipping (flat fee) is NOT available, because it is not available.', $this->title );
					/* translators: %s: shipping method title. */
					$notice_message = sprintf( __( 'Smart Send "%s": free shipping (flat fee) is NOT available, because it is not available.', 'smart-send-logistics' ), $this->title );
					break;
			}

			SS_Shipping_Logger::debug( $log_message, array( 'requires' => $requires ) );
			SS_Shipping_Checkout_Debug::add_notice( $notice_message );
		}

		/**
		 * Get the human readable name of the Smart Send shipping method
		 * Example: 'PostNord: Closest pick-up point (MyPack Collect)'
		 *
		 * @see SS_Shipping_Method_Catalog::get_shipping_method_name()
		 *
		 * @param string $shipping_method_code    Id that identifies the Smart Send method. Example 'postnord_collect'
		 * @return string
		 */
		public function get_shipping_method_name( $shipping_method_code ) {
			return $this->catalog->get_shipping_method_name( $shipping_method_code );
		}
	}

endif;
