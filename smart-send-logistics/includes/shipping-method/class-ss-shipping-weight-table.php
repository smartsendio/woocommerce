<?php
/**
 * Smart Send cost-per-weight table.
 *
 * @package  SS_Shipping_Weight_Table
 * @category Shipping
 * @author   Smart Send
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// A second copy of the plugin may already have defined the class.
if ( ! class_exists( 'SS_Shipping_Weight_Table' ) ) :

	/**
	 * The configured cost-per-weight table of a Smart Send shipping method
	 * instance as a value object (#140), so availability
	 * (SS_Shipping_WC_Method::calculate_shipping()'s weight gate) and the
	 * cost row loop share one row-matching predicate instead of two copies.
	 *
	 * Rows keep the persisted shape: ss_min_weight, ss_max_weight,
	 * ss_cost_weight. The v8 bound semantics are preserved exactly: an
	 * empty/zero min or max bound does not constrain, the min bound is
	 * inclusive and the max bound exclusive.
	 */
	class SS_Shipping_Weight_Table {

		/**
		 * The configured rows.
		 *
		 * @var array
		 */
		protected array $rows;

		/**
		 * @param mixed $rows The persisted cost_weight option value (rows array, or anything falsy for no table).
		 */
		public function __construct( $rows ) {
			$this->rows = is_array( $rows ) ? $rows : array();
		}

		/**
		 * Whether the cart weight falls inside a configured row. The weight
		 * table defines which weights the method is available for at all -
		 * an empty table means every weight is valid (see issue #16).
		 *
		 * @param float $cart_weight The cart's total weight.
		 * @return boolean
		 */
		public function contains( $cart_weight ): bool {
			if ( empty( $this->rows ) ) {
				return true;
			}

			foreach ( $this->rows as $row ) {
				if ( $this->row_matches( $row, $cart_weight ) ) {
					return true;
				}
			}

			return false;
		}

		/**
		 * The rows whose weight bounds match the cart weight, in configured
		 * order. Note that a rate built from several matching rows is
		 * added once per row under the same rate id, so the LAST matching
		 * row wins (v8 oddity, made explicit here - the caller reports it).
		 *
		 * @param float $cart_weight The cart's total weight.
		 * @return array
		 */
		public function rows_matching( $cart_weight ): array {
			$matching = array();

			foreach ( $this->rows as $row ) {
				if ( $this->row_matches( $row, $cart_weight ) ) {
					$matching[] = $row;
				}
			}

			return $matching;
		}

		/**
		 * The single row predicate shared by contains() and
		 * rows_matching().
		 *
		 * @param array $row         A configured row (ss_min_weight, ss_max_weight, ss_cost_weight).
		 * @param float $cart_weight The cart's total weight.
		 * @return boolean
		 */
		protected function row_matches( $row, $cart_weight ): bool {
			// If min weight is set and the cart weight is below it, this row does not apply.
			if ( ! empty( $row['ss_min_weight'] ) && ( $cart_weight < $row['ss_min_weight'] ) ) {
				return false;
			}

			// If max weight is set and the cart weight is at or above it, this row does not apply.
			if ( ! empty( $row['ss_max_weight'] ) && ( $cart_weight >= $row['ss_max_weight'] ) ) {
				return false;
			}

			return true;
		}
	}

endif;
