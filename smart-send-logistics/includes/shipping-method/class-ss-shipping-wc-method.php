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
		 * Reporter for the availability outcomes (log + checkout debug bar).
		 *
		 * @var SS_Shipping_Availability_Reporter
		 */
		protected SS_Shipping_Availability_Reporter $availability_reporter;

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

			$this->catalog               = new SS_Shipping_Method_Catalog();
			$this->settings_builder      = new SS_Shipping_Method_Settings( $this, $this->catalog );
			$this->form_renderer         = new SS_Shipping_Method_Form_Renderer( $this );
			$this->availability_reporter = new SS_Shipping_Availability_Reporter();

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

			// Built from the constant (never $this->id) so the hook name can't drift
			// out of sync with it if SS_SHIPPING_METHOD_ID's value ever changes - see #106.
			add_action( 'woocommerce_update_options_shipping_' . SS_SHIPPING_METHOD_ID, array( $this, 'process_admin_options' ) );
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

			// Set tax status based on selection otherwise always taxed
			$this->tax_status = $this->get_option( 'tax_status' );

			// The weight table defines which cart weights the method is available for at
			// all - an empty table means every weight is valid. Free shipping is only
			// ever applied on top of an otherwise-available rate: it never overrules the
			// weight table (see issue #16 - this reorders the v8 behaviour, which let the
			// flat-rate threshold skip the weight table entirely).
			$cart_weight     = WC()->cart->get_cart_contents_weight();
			$weight_table    = new SS_Shipping_Weight_Table( $this->get_option( 'cost_weight', array() ) );
			$weight_unit     = get_option( 'woocommerce_weight_unit' );
			$weight_in_table = $weight_table->contains( $cart_weight );

			// How the offered cost was derived, for the one-line debug bar
			// summary emitted at the end: null (no rate), a flat fee, or the
			// last applied weight table row.
			$cost_derivation = null;

			if ( ! $weight_in_table ) {
				SS_Shipping_Logger::debug(
					sprintf( 'Smart Send "%1$s": no rate added - cart weight %2$s %3$s did not match any weight table row.', $rate['label'], $cart_weight, $weight_unit ),
					array(
						'rate_id'     => $rate['id'],
						'cart_weight' => $cart_weight,
					)
				);
			} elseif ( $this->is_free_shipping( $package ) ) {

				$rate['cost'] = $this->get_option( 'flatfee_cost' );
				$this->add_rate( $rate );
				$cost_derivation = array( 'type' => 'flat_fee' );
				SS_Shipping_Logger::debug(
					sprintf( 'Smart Send "%1$s": free shipping applies - rate added with flat fee cost %2$s.', $rate['label'], $rate['cost'] ),
					array(
						'rate_id' => $rate['id'],
						'cost'    => $rate['cost'],
					)
				);

			} else {
				$rate_added   = false;
				$matched_rows = array();

				// Each matching row adds the rate under the same rate id, so
				// the LAST matching row wins on overlaps (v8 oddity, see
				// SS_Shipping_Weight_Table::rows_matching(); reported below).
				foreach ( $weight_table->rows_matching( $cart_weight ) as $weight_cost ) {

					// No cost formula configured for this row - nothing to add.
					if ( empty( $weight_cost['ss_cost_weight'] ) ) {
						continue;
					}

					$rate['cost'] = $this->evaluate_cost(
						$weight_cost['ss_cost_weight'],
						array(
							'qty'  => $this->get_package_item_qty( $package ),
							'cost' => $package['contents_cost'],
						)
					);

					$this->add_rate( $rate );
					$rate_added      = true;
					$matched_row     = sprintf( '%1$s-%2$s %3$s', $weight_cost['ss_min_weight'], $weight_cost['ss_max_weight'], $weight_unit );
					$matched_rows[]  = $matched_row;
					$cost_derivation = array(
						'type' => 'weight_row',
						'row'  => $matched_row,
					);
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

			// One-line debug bar summary of this method's evaluation: the
			// verdict plus how the offered cost was derived - the step-by-step
			// trace above stays in the log only (#92).
			$this->add_evaluation_summary_notice( $rate, $cost_derivation, $package['contents_cost'], $cart_weight, $weight_unit );

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
			// Built from the constant (never $this->id) so the hook name can't drift
			// out of sync with it if SS_SHIPPING_METHOD_ID's value ever changes - see #106.
			do_action( 'woocommerce_' . SS_SHIPPING_METHOD_ID . '_shipping_add_rate', $this, $rate );
		}

		/**
		 * Show the one-line evaluation summary of this method in the checkout
		 * shipping debug bar: whether the rate ended up available for the
		 * package (with the evaluated total and cart weight) and, when it did,
		 * how its cost was derived (flat fee or weight table row).
		 *
		 * @param array      $rate            The rate array built by calculate_shipping().
		 * @param array|null $cost_derivation How the cost was derived: null (no rate), ['type' => 'flat_fee'] or ['type' => 'weight_row', 'row' => 'min-max unit'].
		 * @param mixed      $contents_cost   The package contents cost the evaluation ran against.
		 * @param mixed      $cart_weight     The evaluated cart weight.
		 * @param string     $weight_unit     The store weight unit.
		 * @return void
		 */
		protected function add_evaluation_summary_notice( $rate, $cost_derivation, $contents_cost, $cart_weight, $weight_unit ) {
			if ( ! isset( $this->rates[ $rate['id'] ] ) || null === $cost_derivation ) {
				SS_Shipping_Checkout_Debug::add_notice(
					sprintf(
						/* translators: 1: shipping rate label, 2: shipping rate id, 3: package contents cost, 4: cart weight, 5: weight unit. */
						__( 'Smart Send: Evaluated method "%1$s" (%2$s) as not available (total=%3$s, weight=%4$s %5$s): the cart weight matched no weight table row.', 'smart-send-logistics' ),
						$rate['label'],
						$rate['id'],
						$contents_cost,
						$cart_weight,
						$weight_unit
					)
				);

				return;
			}

			$cost = $this->rates[ $rate['id'] ]->get_cost();

			if ( 'flat_fee' === $cost_derivation['type'] ) {
				SS_Shipping_Checkout_Debug::add_notice(
					sprintf(
						/* translators: 1: shipping rate label, 2: shipping rate id, 3: package contents cost, 4: cart weight, 5: weight unit, 6: flat fee cost. */
						__( 'Smart Send: Evaluated method "%1$s" (%2$s) as available (total=%3$s, weight=%4$s %5$s). Flat fee cost %6$s applied.', 'smart-send-logistics' ),
						$rate['label'],
						$rate['id'],
						$contents_cost,
						$cart_weight,
						$weight_unit,
						$cost
					)
				);

				return;
			}

			SS_Shipping_Checkout_Debug::add_notice(
				sprintf(
					/* translators: 1: shipping rate label, 2: shipping rate id, 3: package contents cost, 4: cart weight, 5: weight unit, 6: applied weight table row as "min-max unit", 7: rate cost. */
					__( 'Smart Send: Evaluated method "%1$s" (%2$s) as available (total=%3$s, weight=%4$s %5$s). Weight table row %6$s applied, cost %7$s.', 'smart-send-logistics' ),
					$rate['label'],
					$rate['id'],
					$contents_cost,
					$cart_weight,
					$weight_unit,
					$cost_derivation['row'],
					$cost
				)
			);
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
						case SS_Shipping_Method_Settings::SHIPPING_CLASS_OPT_ALL:
							$is_available = $all_in_array;
							break;
						case SS_Shipping_Method_Settings::SHIPPING_CLASS_OPT_ONE:
							$is_available = $one_in_array;
							break;
						case SS_Shipping_Method_Settings::SHIPPING_CLASS_OPT_NALL:
							$is_available = ! $one_in_array;
							break;
						case SS_Shipping_Method_Settings::SHIPPING_CLASS_OPT_NONE:
							$is_available = ! $all_in_array;
							break;
					}

					$this->availability_reporter->report_shipping_class_availability( $this->title, $display_shipping_class_opt, $is_available, $this->get_rate_id() );
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
									/* translators: 1: shipping method title, 2: shipping rate id, 3: customer role. */
									__( 'Smart Send: Evaluated method "%1$s" (%2$s) as not available: customer role "%3$s" is excluded.', 'smart-send-logistics' ),
									$this->title,
									$this->get_rate_id(),
									$customer_role
								)
							);
							break;
						}
					}
				}
			}

			// Built from the constant (never $this->id) so the hook name can't drift
			// out of sync with it if SS_SHIPPING_METHOD_ID's value ever changes - see #106.
			return apply_filters( 'woocommerce_shipping_' . SS_SHIPPING_METHOD_ID . '_is_available', $is_available, $package, $this );
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

			if ( in_array( $requires, array( SS_Shipping_Method_Settings::REQUIRES_COUPON, SS_Shipping_Method_Settings::REQUIRES_EITHER, SS_Shipping_Method_Settings::REQUIRES_BOTH ) ) ) { // phpcs:ignore WordPress.PHP.StrictInArray.MissingTrueStrict -- pre-existing loose in_array; tightening is a behaviour change out of scope for the #43 move.
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

			if ( in_array( $requires, array( SS_Shipping_Method_Settings::REQUIRES_MIN_AMOUNT, SS_Shipping_Method_Settings::REQUIRES_EITHER, SS_Shipping_Method_Settings::REQUIRES_BOTH ) ) ) { // phpcs:ignore WordPress.PHP.StrictInArray.MissingTrueStrict -- pre-existing loose in_array; tightening is a behaviour change out of scope for the #43 move.
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
				case SS_Shipping_Method_Settings::REQUIRES_MIN_AMOUNT:
					$is_available = $has_met_min_amount;
					break;
				case SS_Shipping_Method_Settings::REQUIRES_COUPON:
					$is_available = $has_coupon;
					break;
				case SS_Shipping_Method_Settings::REQUIRES_BOTH:
					$is_available = $has_met_min_amount && $has_coupon;
					break;
				case SS_Shipping_Method_Settings::REQUIRES_EITHER:
					$is_available = $has_met_min_amount || $has_coupon;
					break;
				case SS_Shipping_Method_Settings::REQUIRES_ENABLED:
					$is_available = true;
					break;
				case SS_Shipping_Method_Settings::REQUIRES_DISABLED:
				default:
					$is_available = false;
					break;
			}

			$this->availability_reporter->report_free_shipping_availability( $this->title, $requires, $is_available, isset( $total ) ? $total : null, $min_amount );

			// Built from the constant (never $this->id) so the hook name can't drift
			// out of sync with it if SS_SHIPPING_METHOD_ID's value ever changes - see #106.
			return apply_filters(
				'woocommerce_shipping_' . SS_SHIPPING_METHOD_ID . '_is_free_shipping',
				$is_available,
				$package,
				$this
			);
		}

		/**
		 * Get the human readable name of the Smart Send shipping method
		 * Example: 'PostNord: Closest pickup point (MyPack Collect)'
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
