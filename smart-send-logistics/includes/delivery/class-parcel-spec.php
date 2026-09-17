<?php
/**
 * WooCommerce Smart Send parcel spec value object.
 *
 * @package Smart_Send
 * @category Shipping
 * @author   Smart Send
 */

namespace Smart_Send\Delivery;

use Smart_Send\Booking\Parcel;
use Smart_Send\Booking\Shipment_Builder;
use InvalidArgumentException;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * One planned parcel, with optional measurements and order-line allocations.
 *
 * Allocations identify a WooCommerce order item, never a catalog product:
 * array( 'order_item_id' => positive int, 'quantity' => positive int,
 * 'name' => display label|null ). The name never determines identity.
 * An explicit weight overrides the allocated items' combined weight.
 * The canonical JSON form is produced by to_array().
 */
class Parcel_Spec {

	/**
	 * Explicit parcel weight in kg; wins over the item-sum when set.
	 *
	 * @var float|null
	 */
	protected ?float $weight = null;

	/**
	 * Parcel height in cm.
	 *
	 * @var float|null
	 */
	protected ?float $height = null;

	/**
	 * Parcel width in cm.
	 *
	 * @var float|null
	 */
	protected ?float $width = null;

	/**
	 * Parcel length in cm.
	 *
	 * @var float|null
	 */
	protected ?float $length = null;

	/**
	 * Reference label of this parcel inside its plan.
	 *
	 * @var string|null
	 */
	protected ?string $reference = null;

	/**
	 * Item allocations: order_item_id, quantity, name (label) per row.
	 *
	 * @var array[]
	 */
	protected array $items = array();

	/**
	 * Parse the canonical shape without accepting legacy product-id rows.
	 *
	 * @param array $data Canonical parcel data.
	 * @return self
	 * @throws InvalidArgumentException When the shape or an allocation is invalid.
	 */
	public static function from_array( array $data ): self {
		$allowed = array( 'reference', 'weight', 'length', 'width', 'height', 'items' );
		if ( array_diff( array_keys( $data ), $allowed ) ) {
			self::invalid( 'Parcel data contains unsupported fields.' );
		}
		if ( array_key_exists( 'items', $data ) && ! is_array( $data['items'] ) ) {
			self::invalid( 'Parcel items must be an array of order-item allocations.' );
		}

		$spec = new self();
		$spec->set_reference( isset( $data['reference'] ) ? $data['reference'] : null )
			->set_weight( self::float_or_null( $data, 'weight' ) )
			->set_length( self::float_or_null( $data, 'length' ) )
			->set_width( self::float_or_null( $data, 'width' ) )
			->set_height( self::float_or_null( $data, 'height' ) );

		foreach ( isset( $data['items'] ) ? $data['items'] : array() as $item ) {
			if ( ! is_array( $item ) || ! isset( $item['order_item_id'], $item['quantity'] )
				|| array_diff( array_keys( $item ), array( 'order_item_id', 'quantity', 'name' ) ) ) {
				self::invalid( 'Each parcel item requires an order_item_id and quantity; legacy product-id rows are not supported.' );
			}
			$spec->add_item( $item['order_item_id'], $item['quantity'], isset( $item['name'] ) ? $item['name'] : null );
		}

		return $spec;
	}

	/**
	 * The canonical array form of the spec (see the class docblock).
	 *
	 * @return array
	 */
	public function to_array(): array {
		return array(
			'reference' => $this->reference,
			'weight'    => $this->weight,
			'length'    => $this->length,
			'width'     => $this->width,
			'height'    => $this->height,
			'items'     => $this->items,
		);
	}

	/**
	 * Read an optional numeric key: absent, null or '' is null.
	 *
	 * @param array  $data The array.
	 * @param string $key  The key.
	 *
	 * @return float|null
	 */
	protected static function float_or_null( array $data, string $key ) {
		if ( ! isset( $data[ $key ] ) || '' === $data[ $key ] ) {
			return null;
		}

		return self::measurement( $data[ $key ] );
	}

	/**
	 * Get the explicit parcel weight, or null when the resolved weight
	 * should be the sum of the allocated items' weights.
	 *
	 * @return float|null
	 */
	public function get_weight() {
		return $this->weight;
	}

	/**
	 * Set the explicit parcel weight.
	 *
	 * @param mixed $weight Weight in kg, or null.
	 *
	 * @return self
	 */
	public function set_weight( $weight ): self {
		$this->weight = self::measurement( $weight );

		return $this;
	}

	/**
	 * Get the parcel height.
	 *
	 * @return float|null
	 */
	public function get_height() {
		return $this->height;
	}

	/**
	 * Set the parcel height.
	 *
	 * @param mixed $height Height in cm, or null.
	 *
	 * @return self
	 */
	public function set_height( $height ): self {
		$this->height = self::measurement( $height );

		return $this;
	}

	/**
	 * Get the parcel width.
	 *
	 * @return float|null
	 */
	public function get_width() {
		return $this->width;
	}

	/**
	 * Set the parcel width.
	 *
	 * @param mixed $width Width in cm, or null.
	 *
	 * @return self
	 */
	public function set_width( $width ): self {
		$this->width = self::measurement( $width );

		return $this;
	}

	/**
	 * Get the parcel length.
	 *
	 * @return float|null
	 */
	public function get_length() {
		return $this->length;
	}

	/**
	 * Set the parcel length.
	 *
	 * @param mixed $length Length in cm, or null.
	 *
	 * @return self
	 */
	public function set_length( $length ): self {
		$this->length = self::measurement( $length );

		return $this;
	}

	/**
	 * Get the reference label of the spec.
	 *
	 * @return string|null
	 */
	public function get_reference() {
		return $this->reference;
	}

	/**
	 * Set the reference label of the spec.
	 *
	 * @param mixed $reference The reference label, or null.
	 *
	 * @return self
	 */
	public function set_reference( $reference ): self {
		if ( null !== $reference && ! is_scalar( $reference ) ) {
			self::invalid( 'A parcel reference must be a scalar label.' );
		}
		$this->reference = null === $reference ? null : (string) $reference;

		return $this;
	}

	/**
	 * Allocate a positive whole number of units of one order item.
	 *
	 * @param int|string  $order_item_id WooCommerce order-item identifier.
	 * @param int|string  $quantity      Allocated units of that order line.
	 * @param string|null $name          Optional display label.
	 * @return self
	 * @throws InvalidArgumentException When identity or quantity is invalid or the order item is already allocated here.
	 */
	public function add_item( $order_item_id, $quantity = 1, $name = null ): self {
		if ( ! self::is_positive_integer( $order_item_id ) || ! self::is_positive_integer( $quantity ) ) {
			self::invalid( 'Parcel order_item_id and quantity must be positive integers.' );
		}
		if ( null !== $name && ! is_string( $name ) ) {
			self::invalid( 'A parcel item name must be a string or null.' );
		}
		foreach ( $this->items as $item ) {
			if ( $item['order_item_id'] === (int) $order_item_id ) {
				self::invalid( 'Each order item may appear only once in a parcel; use its quantity to allocate multiple units.' );
			}
		}

		$this->items[] = array(
			'order_item_id' => (int) $order_item_id,
			'quantity'      => (int) $quantity,
			'name'          => $name,
		);

		return $this;
	}

	/**
	 * Recognize integer input without truncating fractions or overflowing.
	 *
	 * @param mixed $value Candidate identity or quantity.
	 * @return bool
	 */
	public static function is_positive_integer( $value ): bool {
		return ( is_int( $value ) || is_string( $value ) )
			&& false !== filter_var( $value, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) );
	}

	/**
	 * Normalize an optional non-negative finite measurement.
	 *
	 * @param mixed $value Measurement in kg or cm.
	 * @return float|null
	 */
	protected static function measurement( $value ) {
		if ( null === $value ) {
			return null;
		}
		if ( ! is_numeric( $value ) || ! is_finite( (float) $value ) || (float) $value < 0 ) {
			self::invalid( 'Parcel measurements must be non-negative finite numbers or null.' );
		}
		return (float) $value;
	}

	/**
	 * Reject invalid data at a boundary, without silently losing allocations.
	 *
	 * @param string $message Plain-text validation failure.
	 * @throws InvalidArgumentException Always.
	 */
	protected static function invalid( string $message ): void {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Boundary catches this plain-text data exception before rendering.
		throw new InvalidArgumentException( $message );
	}

	/**
	 * Get the item allocations.
	 *
	 * @return array[] Rows of order_item_id, quantity, name.
	 */
	public function get_items(): array {
		return $this->items;
	}

	/**
	 * Whether the spec allocates any items.
	 *
	 * @return boolean
	 */
	public function has_items(): bool {
		return array() !== $this->items;
	}
}
