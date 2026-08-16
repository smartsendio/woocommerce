<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Smart Send shipping method settings form renderer.
 *
 * Renders the custom settings field types (button, selectopt, radio and
 * the cost-per-weight table) for SS_Shipping_WC_Method. WooCommerce's
 * settings framework dispatches generate_{type}_html on the shipping
 * method instance, which delegates here.
 *
 * @package  SS_Shipping_Method_Form_Renderer
 * @category Shipping
 * @author   Smart Send
 */

// A second copy of the plugin may already have defined the class.
if ( ! class_exists( 'SS_Shipping_Method_Form_Renderer' ) ) :

	class SS_Shipping_Method_Form_Renderer {

		/**
		 * The shipping method instance rendered for.
		 *
		 * @var SS_Shipping_WC_Method
		 */
		protected SS_Shipping_WC_Method $method;

		/**
		 * @param SS_Shipping_WC_Method $method The shipping method instance.
		 */
		public function __construct( SS_Shipping_WC_Method $method ) {
			$this->method = $method;
		}

		/**
		 * Generate Button HTML.
		 *
		 * @access public
		 * @param mixed $key
		 * @param mixed $data
		 * @since 8.0.0
		 * @return string
		 */
		public function generate_button_html( $key, $data ) {
			$field    = $this->method->plugin_id . $this->method->id . '_' . $key;
			$defaults = array(
				'class'             => 'button-secondary',
				'css'               => '',
				'custom_attributes' => array(),
				'desc_tip'          => false,
				'description'       => '',
				'title'             => '',
			);

			$data = wp_parse_args( $data, $defaults );

			// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-existing behaviour, matching WC_Settings_API core: the tooltip/description/attribute helpers return pre-escaped HTML; escaping again is a behaviour change out of scope for the #43 move.
			ob_start();
			?>
			<tr valign="top">
				<th scope="row" class="titledesc">
					<label for="<?php echo esc_attr( $field ); ?>"><?php echo wp_kses_post( $data['title'] ); ?></label>
					<?php echo $this->method->get_tooltip_html( $data ); ?>
				</th>
				<td class="forminp">
					<fieldset>
						<legend class="screen-reader-text"><span><?php echo wp_kses_post( $data['title'] ); ?></span>
						</legend>
						<button class="<?php echo esc_attr( $data['class'] ); ?>" type="button"
								name="<?php echo esc_attr( $field ); ?>" id="<?php echo esc_attr( $field ); ?>"
								style="<?php echo esc_attr( $data['css'] ); ?>" <?php echo $this->method->get_custom_attribute_html( $data ); ?>><?php echo wp_kses_post( $data['title'] ); ?></button>
						<?php echo $this->method->get_description_html( $data ); ?>
					</fieldset>
				</td>
			</tr>
			<?php
			return ob_get_clean();
			// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		public function generate_selectopt_html( $key, $data ) {
			$field_key = $this->method->get_field_key( $key );
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

			// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-existing behaviour, matching WC_Settings_API core: the tooltip/description/attribute helpers return pre-escaped HTML; escaping again is a behaviour change out of scope for the #43 move.
			ob_start();
			?>
			<tr valign="top">
				<th scope="row" class="titledesc">
					<?php echo $this->method->get_tooltip_html( $data ); ?>
					<label for="<?php echo esc_attr( $field_key ); ?>"><?php echo wp_kses_post( $data['title'] ); ?></label>
				</th>
				<td class="forminp">
					<fieldset>
						<legend class="screen-reader-text"><span><?php echo wp_kses_post( $data['title'] ); ?></span>
						</legend>
						<select class="select <?php echo esc_attr( $data['class'] ); ?>"
								name="<?php echo esc_attr( $field_key ); ?>" id="<?php echo esc_attr( $field_key ); ?>"
								style="<?php echo esc_attr( $data['css'] ); ?>" 
								<?php
								disabled(
									$data['disabled'],
									true
								);
								?>
							<?php echo $this->method->get_custom_attribute_html( $data ); ?>>

							<?php foreach ( (array) $data['options'] as $optgroup_key => $optgroup_value ) : ?>

								<?php if ( is_array( $optgroup_value ) ) : ?>

									<?php echo '<optgroup label="' . esc_attr( $optgroup_key ) . '">'; ?>

									<?php foreach ( (array) $optgroup_value as $option_key => $option_value ) : ?>

										<option value="<?php echo esc_attr( $option_key ); ?>" 
										<?php
										selected(
											$option_key,
											esc_attr( $this->method->get_option( $key ) )
										);
										?>
											><?php echo esc_attr( $option_value ); ?></option>

									<?php endforeach; ?>
								<?php else : ?>

									<option value="<?php echo esc_attr( $optgroup_key ); ?>" 
									<?php
									selected(
										$optgroup_key,
										esc_attr( $this->method->get_option( $key ) )
									);
									?>
										><?php echo esc_attr( $optgroup_value ); ?></option>

								<?php endif; ?>

								<?php echo '</optgroup>'; ?>

							<?php endforeach; ?>

						</select>
						<?php echo $this->method->get_description_html( $data ); ?>
					</fieldset>
				</td>
			</tr>
			<?php

			return ob_get_clean();
			// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
		}


		/**
		 * Generate cost weight html.
		 *
		 * @return string
		 */
		public function generate_cost_weight_html() {

			// phpcs:disable WordPress.Security.EscapeOutput -- pre-existing behaviour: this settings table is built from translated strings and internally generated markup exactly as before the #43 move; escaping it is a behaviour change out of scope here.
			ob_start();

			$cost_desc = __(
				'Enter a cost (excl. tax) or sum, e.g. 10.00 * [qty].',
				'smart-send-logistics'
			) . '<br/><br/>' . __(
				'Use [qty] for the number of items, <br/>[cost] for the total cost of items, and [fee percent=\'10\' min_fee=\'20\' max_fee=\'\'] for percentage based fees.',
				'smart-send-logistics'
			);

			?>
			<tr valign="top">
				<th scope="row" class="titledesc"><?php _e( 'Cost based on weight', 'smart-send-logistics' ); ?>:</th>
				<td class="forminp" id="ss_cost_weight">
					<table class="widefat wc_input_table sortable" cellspacing="0">
						<thead>
						<tr>
							<th class="sort">&nbsp;</th>
							<th><?php _e( 'Minimum', 'smart-send-logistics' ); ?>
								[<?php echo get_option( 'woocommerce_weight_unit' ); ?>]<a class="tips"
																						data-tip="
																						<?php
																							_e(
																								'Cart weight should be equal to or larger than this value for the shipping rate to be applicable',
																								'smart-send-logistics'
																							);
																						?>
																							">[?]</a>
							</th>
							<th><?php _e( 'Maximum', 'smart-send-logistics' ); ?>
								[<?php echo get_option( 'woocommerce_weight_unit' ); ?>]<a class="tips"
																						data-tip="
																						<?php
																							_e(
																								'Cart weight should be strictly less than this value for the shipping rate to be applicable',
																								'smart-send-logistics'
																							);
																						?>
																							">[?]</a>
							</th>
							<th><?php _e( 'Cost', 'smart-send-logistics' ); ?><a class="tips"
																				data-tip="<?php echo $cost_desc; ?>">[?]</a>
							</th>
						</tr>
						</thead>
						<tbody class="ss_weight_cost">
						<?php
						$i = -1;

						$weight_costs = $this->method->get_option(
							'cost_weight',
							array(
								array(
									'ss_min_weight'  => 0,
									'ss_max_weight'  => 20,
									'ss_cost_weight' => 15,
								),
							)
						);

						if ( $weight_costs ) {
							foreach ( $weight_costs as $weight_cost ) {
								++$i;

								echo '<tr class="ss_weight_cost">
                                    <td class="sort"></td>
                                    <td><input type="number" type="number" min="0" step="0.001" value="' . esc_attr( $weight_cost['ss_min_weight'] ) . '" name="ss_min_weight[' . $i . ']" class ="wc_input_decimal" /></td>
                                    <td><input type="number" type="number" min="0" step="0.001" value="' . esc_attr( $weight_cost['ss_max_weight'] ) . '" name="ss_max_weight[' . $i . ']" class ="wc_input_decimal" /></td>
                                    <td><input type="text" value="' . esc_attr( $weight_cost['ss_cost_weight'] ) . '" name="ss_cost_weight[' . $i . ']"  class ="" required/></td>
                                </tr>';
							}
						}
						?>
						</tbody>
						<tfoot>
						<tr>
							<th colspan="4"><a href="#" class="add button">
							<?php
							_e(
								'+ Add shipping rate',
								'smart-send-logistics'
							);
							?>
										</a> <a href="#"
																			class="remove_rows button">
																			<?php
																			_e(
																				'Remove selected rate(s)',
																				'smart-send-logistics'
																			);
																			?>
										</a></th>
						</tr>
						</tfoot>
					</table>
					<p class="description">
					<?php
					_e(
						'Enter the shipping cost excluding tax',
						'smart-send-logistics'
					);
					?>
							</p>
					<script type="text/javascript">
						jQuery(function () {
							jQuery('#ss_cost_weight').on('click', 'a.add', function () {

								var size = jQuery('#ss_cost_weight').find('tbody .ss_weight_cost').length;

								jQuery('<tr class="ss_weight_cost">\
									<td class="sort"></td>\
									<td><input type="number" min="0" step="0.001" class ="wc_input_decimal" name="ss_min_weight[' + size + ']" /></td>\
									<td><input type="number" min="0" step="0.001" class ="wc_input_decimal" name="ss_max_weight[' + size + ']" /></td>\
									<td><input type="text" class ="" name="ss_cost_weight[' + size + ']" required/></td>\
								</tr>').appendTo('#ss_cost_weight table tbody');

								return false;
							});
						});
					</script>
				</td>
			</tr>
			<?php
			return ob_get_clean();
			// phpcs:enable WordPress.Security.EscapeOutput
		}

		/**
		 * Generate Select HTML.
		 *
		 * @param  mixed $key
		 * @param  mixed $data
		 * @since  8.0.0
		 * @return string
		 */
		public function generate_radio_html( $key, $data ) {
			$field_key = $this->method->get_field_key( $key );
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

			// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-existing behaviour, matching WC_Settings_API core: the tooltip/description/attribute helpers return pre-escaped HTML; escaping again is a behaviour change out of scope for the #43 move.
			ob_start();
			?>
			<tr valign="top">
				<th scope="row" class="titledesc">
					<?php echo $this->method->get_tooltip_html( $data ); ?>
					<label for="<?php echo esc_attr( $field_key ); ?>"><?php echo esc_html( $data['title'] ); ?></label>
				</th>
				<td class="forminp forminp-<?php echo sanitize_title( $data['type'] ); ?>">
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
											<?php echo $this->method->get_custom_attribute_html( $data ); ?>
											<?php checked( $option_key, esc_attr( $this->method->get_option( $key ) ) ); ?>
										/> <?php echo esc_attr( $option_value ); ?></label>
								</li>
								<?php
							}
							?>
						</ul>
						<?php echo $this->method->get_description_html( $data ); ?>
					</fieldset>
				</td>
			</tr>

			<?php

			return ob_get_clean();
			// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}

endif;
