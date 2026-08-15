<?php
/**
 * Smart Send shipping method code.
 *
 * @package  SS_Shipping_Method_Code
 * @category Shipping
 * @author   Smart Send
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// A second copy of the plugin may already have defined the class.
if ( ! class_exists( 'SS_Shipping_Method_Code' ) ) :

	/**
	 * A Smart Send shipping method code (e.g. 'postnord_agent') as a value
	 * object (#140): the carrier/type parsing that lived as explode('_')
	 * helpers on the plugin singleton, plus the catalog-backed human
	 * readable name lookup that used to walk the live WooCommerce shipping
	 * method instances just to reach the (WooCommerce-free) catalog.
	 */
	class SS_Shipping_Method_Code {

		/**
		 * The raw method code, assumed to be in the 'carrier_type' format.
		 *
		 * @var string
		 */
		protected string $code;

		/**
		 * @param string|null $code The method code (e.g. 'postnord_agent'); null is treated as ''.
		 */
		public function __construct( ?string $code ) {
			$this->code = (string) $code;
		}

		/**
		 * The carrier part of the code (e.g. 'postnord'), or the raw code
		 * when it has no 'carrier_type' structure (preserved v8 behaviour).
		 *
		 * @return string
		 */
		public function carrier(): string {
			$parts = $this->parts();

			return isset( $parts[0] ) ? $parts[0] : $this->code;
		}

		/**
		 * The type part of the code (e.g. 'agent'), or the raw code when it
		 * has no 'carrier_type' structure (preserved v8 behaviour).
		 *
		 * @return string
		 */
		public function type(): string {
			$parts = $this->parts();

			return isset( $parts[1] ) ? $parts[1] : $this->code;
		}

		/**
		 * The human readable name of the method from the catalogue, e.g.
		 * 'PostNord: Closest pickup point (MyPack Collect)', or '' for an
		 * unknown code.
		 *
		 * @param SS_Shipping_Method_Catalog|null $catalog The catalogue to look the name up in; a fresh one is built when omitted.
		 *
		 * @return string
		 */
		public function name( ?SS_Shipping_Method_Catalog $catalog = null ): string {
			$catalog = null === $catalog ? new SS_Shipping_Method_Catalog() : $catalog;

			return $catalog->get_shipping_method_name( $this->code );
		}

		/**
		 * Split the code on the 'carrier_type' format. An empty code has no
		 * parts.
		 *
		 * @return array
		 */
		protected function parts(): array {
			if ( '' === $this->code ) {
				return array();
			}

			// Assumes format 'carrier_type'.
			return explode( '_', $this->code );
		}
	}

endif;
