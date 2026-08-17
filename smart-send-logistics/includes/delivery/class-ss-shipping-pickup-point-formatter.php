<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Smart Send pickup point formatter.
 *
 * The single place a pickup point is turned into display text (#139),
 * consolidating the three diverging historic copies: the checkout
 * drop-down / order-details formatting from SS_Shipping_Frontend, the
 * settings-screen format option labels from the plugin singleton, and
 * the order meta box's address block.
 *
 * @package  SS_Shipping_Pickup_Point_Formatter
 * @category Shipping
 * @author   Smart Send
 */

// A second copy of the plugin may already have defined the class.
if ( ! class_exists( 'SS_Shipping_Pickup_Point_Formatter' ) ) :

	class SS_Shipping_Pickup_Point_Formatter {

		/**
		 * Memoized translated format option labels.
		 *
		 * @var array
		 */
		protected array $format_options = array();

		/**
		 * Typed plugin settings reader.
		 *
		 * @var SS_Shipping_Settings
		 */
		protected SS_Shipping_Settings $settings;

		/**
		 * The settings reader is stateless, so a fresh default is safe for
		 * ad-hoc construction (tests construct the formatter directly).
		 *
		 * @param SS_Shipping_Settings|null $settings Typed plugin settings reader.
		 */
		public function __construct( ?SS_Shipping_Settings $settings = null ) {
			$this->settings = null === $settings ? new SS_Shipping_Settings() : $settings;
		}

		/**
		 * The translated "Dropdown display format" option labels, keyed by
		 * format id.
		 *
		 * Built lazily on first call (not in a constructor): the plugin
		 * bootstraps before the init action, and since WordPress 6.7 any
		 * translation call for our text domain that runs before init
		 * triggers a _load_textdomain_just_in_time "called incorrectly"
		 * notice.
		 *
		 * @return array
		 */
		public function get_format_options() {
			if ( empty( $this->format_options ) ) {
				$this->format_options = array(
					'1' => __( '#Company, #Street', 'smart-send-logistics' ),
					'2' => __( '#Company, #Street, #Zipcode', 'smart-send-logistics' ),
					'3' => __( '#Company, #Street, #City', 'smart-send-logistics' ),
					'4' => __( '#Company, #Street, #Zipcode #City', 'smart-send-logistics' ),
					'5' => __( '#Company, #Zipcode', 'smart-send-logistics' ),
					'6' => __( '#Company, #Zipcode, #City', 'smart-send-logistics' ),
					'7' => __( '#Company, #City', 'smart-send-logistics' ),
				);
			}

			return $this->format_options;
		}

		/**
		 * Format a pickup point for display: the checkout drop-down labels
		 * (format id 0 reads the "Dropdown display format" setting) and the
		 * order-details/email block (format id -1, the multi-line default
		 * template).
		 *
		 * @param SS_Shipping_Pickup_Point|object $pickup_point The pickup point (value object or plain agent object).
		 * @param int                             $format_id    The format id; 0 resolves the setting, negative forces the default block template.
		 *
		 * @return string
		 */
		// The parameter stays docblock-typed only: PHP 7.4 has no union types
		// and both the value object and the plain API agent object are
		// legitimate callers (normalize() accepts either).
		public function format( $pickup_point, $format_id = 0 ): string {
			$pickup_point = $this->normalize( $pickup_point );

			if ( 0 == $format_id ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- pre-existing loose comparison; tightening is a behaviour change out of scope for the #139 move.
				// Find the setting
				$format_id = $this->settings->dropdown_display_format();
			}

			switch ( $format_id ) {
				case 1:
					$address_format = '#Company, #Street';
					break;
				case 2:
					$address_format = '#Company, #Street, #Zipcode';
					break;
				case 3:
					$address_format = '#Company, #Street, #City';
					break;
				case 4:
					$address_format = '#Company, #Street, #Zipcode #City';
					break;
				case 5:
					$address_format = '#Company, #Zipcode';
					break;
				case 6:
					$address_format = '#Company, #Zipcode, #City';
					break;
				case 7:
					$address_format = '#Company, #City';
					break;
				default:
					$address_format = '#Company<br>#Street<br>#Country #Zipcode #City';
					break;
			}

			$place_holders = array(
				'#AgentNo',
				'#Company',
				'#Street',
				'#Zipcode',
				'#City',
				'#Country',
			);

			$place_holders_vals = array(
				$pickup_point->get_agent_no(),
				$pickup_point->get_company(),
				$pickup_point->get_address_line1(),
				$pickup_point->get_postal_code(),
				$pickup_point->get_city(),
				$pickup_point->get_country(),
			);

			$formatted_address = str_replace( $place_holders, $place_holders_vals, $address_format );

			if ( ! empty( $pickup_point->get_distance() ) && $format_id > 0 ) {
				if ( $pickup_point->get_distance() < 1 ) {
					/* translators: %s: distance in meters. */
					$formatted_distance = sprintf( __( '%sm', 'smart-send-logistics' ), number_format( $pickup_point->get_distance() * 1000, 0, '.', '' ) );
				} else {
					/* translators: %s: distance in kilometers. */
					$formatted_distance = sprintf( __( '%skm', 'smart-send-logistics' ), number_format( $pickup_point->get_distance(), 2, '.', '' ) );
				}
				$formatted_address = sprintf( '%1$s: %2$s', $formatted_distance, $formatted_address );
			}

			return $formatted_address;
		}

		/**
		 * The checkout drop-down label of a pickup point: the configured
		 * "Dropdown display format" with the smart_send_pickup_point_option_label
		 * filter applied - the single pipeline shared by the classic checkout
		 * drop-down and the Checkout Block cart extension (#74).
		 *
		 * @param SS_Shipping_Pickup_Point|object $pickup_point The pickup point (value object or plain agent object).
		 *
		 * @return string
		 */
		// The parameter stays docblock-typed only: PHP 7.4 has no union types
		// and both the value object and the plain API agent object are
		// legitimate callers (format() normalizes either).
		public function dropdown_label( $pickup_point ): string {
			$formatted_address = $this->format( $pickup_point );

			/*
			 * Filter the label shown for a pickup point in the checkout
			 * drop-down (classic checkout and Checkout Block alike).
			 *
			 * @since 9.0.0
			 *
			 * @param string $formatted_address The label formatted per the "Dropdown display format" setting.
			 * @param object $pickup_point      The pickup point (agent_no, company, address_line1, postal_code, city, country, distance, ...).
			 *
			 * @return string The option label to render.
			 */
			return apply_filters( 'smart_send_pickup_point_option_label', $formatted_address, $pickup_point );
		}

		/**
		 * The order meta box's address block markup for the selected pickup
		 * point.
		 *
		 * @param SS_Shipping_Pickup_Point|object|null $pickup_point The pickup point, or an empty value.
		 *
		 * @return string
		 */
		public function format_admin_block( $pickup_point ) {
			if ( empty( $pickup_point ) ) {
				return '';
			}

			$pickup_point = $this->normalize( $pickup_point );

			return '<p class="ss_agent_address">' . $pickup_point->get_company() . '</br>' . $pickup_point->get_address_line1() . '</br>' . $pickup_point->get_postal_code() . ' ' . $pickup_point->get_city() . '</p>';
		}

		/**
		 * Accept both the value object and the plain agent objects the API
		 * lookup returns.
		 *
		 * @param SS_Shipping_Pickup_Point|object $pickup_point The pickup point.
		 *
		 * @return SS_Shipping_Pickup_Point
		 */
		protected function normalize( $pickup_point ): SS_Shipping_Pickup_Point {
			if ( $pickup_point instanceof SS_Shipping_Pickup_Point ) {
				return $pickup_point;
			}

			return SS_Shipping_Pickup_Point::from_object( (object) $pickup_point );
		}
	}

endif;
