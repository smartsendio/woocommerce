<?php
/**
 * WooCommerce Smart Send parcel plan value object.
 *
 * @package Smart_Send
 * @category Shipping
 * @author   Smart Send
 */

namespace Smart_Send\Delivery;

use InvalidArgumentException;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Ordered parcel specifications with explicit order-item allocations.
 *
 * The canonical stored and JSON form is array( 'specs' => array( ... ) ).
 * An empty plan, or one itemless spec, places all ordered items together.
 * Several parcels require explicit allocations covering every ordered unit.
 */
class Parcel_Plan {

	/**
	 * The planned parcels, in order.
	 *
	 * @var Parcel_Spec[]
	 */
	protected array $specs = array();

	/**
	 * Parse canonical parcel data; legacy product-id box rows are rejected.
	 *
	 * @param array $data The canonical plan shape.
	 * @return self
	 * @throws InvalidArgumentException When the plan shape is invalid.
	 */
	public static function from_array( array $data ): self {
		if ( array() !== $data && ( array( 'specs' ) !== array_keys( $data ) || ! is_array( $data['specs'] ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text data exception caught at the persistence or request boundary.
			throw new InvalidArgumentException( 'The parcel plan must contain a specs array; legacy parcel rows are not supported.' );
		}

		$plan = new self();
		foreach ( isset( $data['specs'] ) ? $data['specs'] : array() as $spec ) {
			if ( ! is_array( $spec ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text data exception caught at the persistence or request boundary.
				throw new InvalidArgumentException( 'Every parcel specification must be an object.' );
			}
			$plan->add_spec( Parcel_Spec::from_array( $spec ) );
		}

		return $plan;
	}

	/**
	 * Validate complete allocation against current order-item identities.
	 *
	 * @param array $order_items Reader rows or order_item_id => quantity pairs.
	 * @return array<string, string[]> Field names mapped to plain-text errors.
	 */
	public function validation_errors( array $order_items ): array {
		$errors   = array();
		$expected = array();
		foreach ( $order_items as $key => $row ) {
			$id       = is_array( $row ) ? ( $row['order_item_id'] ?? null ) : $key;
			$quantity = is_array( $row ) ? ( $row['quantity'] ?? null ) : $row;
			if ( ! Parcel_Spec::is_positive_integer( $id ) || ! Parcel_Spec::is_positive_integer( $quantity ) || isset( $expected[ $id ] ) ) {
				$errors['parcel_plan'][] = __( 'The order contains an invalid or duplicated order-item identity or quantity.', 'smart-send-logistics' );
				continue;
			}
			$expected[ (int) $id ] = (int) $quantity;
		}

		if ( $this->is_empty() || ( 1 === count( $this->specs ) && ! $this->specs[0]->has_items() ) ) {
			return $errors;
		}

		$allocated = array();
		foreach ( $this->specs as $index => $spec ) {
			if ( ! $spec->has_items() && array() !== $expected ) {
				$errors[ 'parcel_plan.specs.' . $index . '.items' ][] = __( 'Assign order items to every parcel when using more than one parcel.', 'smart-send-logistics' );
			}
			foreach ( $spec->get_items() as $allocation ) {
				$id = $allocation['order_item_id'];
				if ( ! isset( $expected[ $id ] ) ) {
					$errors[ 'parcel_plan.specs.' . $index . '.items' ][] = __( 'A parcel references an order item that is no longer in the order.', 'smart-send-logistics' );
					continue;
				}
				$allocated[ $id ] = ( $allocated[ $id ] ?? 0 ) + $allocation['quantity'];
			}
		}

		foreach ( $expected as $id => $quantity ) {
			if ( ( $allocated[ $id ] ?? 0 ) !== $quantity ) {
				$errors[ 'parcel_plan.items.' . $id . '.quantity' ][] = __( 'Parcel quantities must allocate every ordered unit exactly once.', 'smart-send-logistics' );
			}
		}

		return $errors;
	}

	/**
	 * The canonical array form of the plan (see the class docblock).
	 *
	 * @return array
	 */
	public function to_array(): array {
		return array(
			'specs' => array_map(
				static function ( Parcel_Spec $spec ) {
					return $spec->to_array();
				},
				array_values( $this->specs )
			),
		);
	}

	/**
	 * Append a planned parcel.
	 *
	 * @param Parcel_Spec $spec The planned parcel.
	 *
	 * @return self
	 */
	public function add_spec( Parcel_Spec $spec ): self {
		$this->specs[] = $spec;

		return $this;
	}

	/**
	 * Get the planned parcels, in order.
	 *
	 * @return Parcel_Spec[]
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
