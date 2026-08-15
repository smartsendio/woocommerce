<?php
/**
 * WooCommerce Smart Send parcel plan value object.
 *
 * @package  SS_Shipping_Parcel_Plan
 * @category Shipping
 * @author   Smart Send
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// A second copy of the plugin may already have defined the class.
if ( ! class_exists( 'SS_Shipping_Parcel_Plan' ) ) :

	/**
	 * An ordered list of SS_Shipping_Parcel_Spec (#139): how an order is
	 * planned to be packed. An empty plan means one parcel containing
	 * everything.
	 *
	 * The plan converts to and from the frozen "Split into parcels" meta
	 * format (rows of id/name/value, one row per unit, value being the box
	 * number 1-9) via from_box_rows()/to_box_rows(); the repository
	 * (SS_Shipping_Order_Meta) and the label-creation controller both use
	 * that frozen row shape at their boundaries.
	 *
	 * Serializable, with no live WC_Order or WordPress dependency
	 * (Phase 7 queues delivery details).
	 */
	class SS_Shipping_Parcel_Plan {

		/**
		 * The planned parcels, in order.
		 *
		 * @var SS_Shipping_Parcel_Spec[]
		 */
		protected array $specs = array();

		/**
		 * Build a plan from parcel-split rows in the frozen meta shape:
		 * one row per unit, each with 'id' (product/variation id), 'name'
		 * (label) and 'value' (box number). Boxes become specs in
		 * first-occurrence order; units of the same id in the same box
		 * merge into one allocation with a quantity.
		 *
		 * @param array $rows The parcel-split rows.
		 *
		 * @return self
		 */
		public static function from_box_rows( array $rows ): self {
			$plan = new self();

			// Group rows per box number, preserving first-occurrence order
			// (mirrors the historic build_split_parcels() grouping).
			$boxes = array();
			foreach ( $rows as $row ) {
				$boxes[ $row['value'] ][] = $row;
			}

			foreach ( $boxes as $box_no => $box_rows ) {
				$spec = new SS_Shipping_Parcel_Spec();
				$spec->set_reference( (string) $box_no );

				foreach ( $box_rows as $row ) {
					$spec->add_item( $row['id'], 1, isset( $row['name'] ) ? $row['name'] : null );
				}

				$plan->add_spec( $spec );
			}

			return $plan;
		}

		/**
		 * Convert the plan back to the frozen meta row shape: one row per
		 * unit with id, name and value (the spec's reference, falling back
		 * to its 1-based position).
		 *
		 * @return array[]
		 */
		public function to_box_rows(): array {
			$rows = array();

			foreach ( array_values( $this->specs ) as $index => $spec ) {
				$reference = $spec->get_reference();
				$box_no    = null === $reference ? (string) ( $index + 1 ) : $reference;

				foreach ( $spec->get_items() as $item ) {
					for ( $unit = 0; $unit < $item['quantity']; $unit++ ) {
						$rows[] = array(
							'id'    => $item['id'],
							'name'  => $item['name'],
							'value' => $box_no,
						);
					}
				}
			}

			return $rows;
		}

		/**
		 * Append a planned parcel.
		 *
		 * @param SS_Shipping_Parcel_Spec $spec The planned parcel.
		 *
		 * @return self
		 */
		public function add_spec( SS_Shipping_Parcel_Spec $spec ): self {
			$this->specs[] = $spec;

			return $this;
		}

		/**
		 * Get the planned parcels, in order.
		 *
		 * @return SS_Shipping_Parcel_Spec[]
		 */
		public function get_specs(): array {
			return $this->specs;
		}

		/**
		 * Whether the plan is empty (= one parcel containing everything).
		 *
		 * @return boolean
		 */
		public function is_empty(): bool {
			return array() === $this->specs;
		}
	}

endif;
