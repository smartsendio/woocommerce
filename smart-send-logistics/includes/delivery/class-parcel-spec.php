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
use WC_Order;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * One planned parcel (#139): the INPUT side of the parcel domain.
 *
 * Every field is optional by design - a shop must be able to declare
 * (via the smart_send_delivery_details filter) "2 parcels of size
 * X/Y/Z and weight W" with no item information at all. An explicit
 * weight always wins over any item-sum when the plan is resolved
 * (packaging weight is real). The resolved OUTPUT side is
 * Parcel, produced by Shipment_Builder.
 *
 * Item allocations are rows of the shape
 * array( 'id' => product/variation id, 'quantity' => units, 'name' => label|null ).
 *
 * to_array()/from_array() are the canonical JSON form of a spec (#182):
 * array( 'reference' => string|null, 'weight' => float|null, 'length'
 * => float|null, 'width' => float|null, 'height' => float|null, 'items'
 * => array( array( 'id', 'quantity', 'name' ), ... ) ). Every scalar
 * is optional on the way in - an absent or null weight means "compute
 * it from the items" (see the smart_send_parcel_default_weight filter
 * in Shipment_Builder), absent dimensions mean "none".
 *
 * Serializable, with no live WC_Order or WordPress dependency
 * (Phase 7 queues delivery details).
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
	 * Reference label of the spec inside its plan - for a plan built
	 * from the stored "Split into parcels" meta this is the box number
	 * ('1'-'9'), preserved so a read-modify-write round-trips the
	 * frozen meta format.
	 *
	 * @var string|null
	 */
	protected ?string $reference = null;

	/**
	 * Item allocations: id, quantity, name (label) per row.
	 *
	 * @var array[]
	 */
	protected array $items = array();

	/**
	 * Build a spec from its to_array() form (the canonical JSON shape).
	 *
	 * Every key is optional: a missing or null 'weight', 'length',
	 * 'width', 'height' or 'reference' stays null; 'items' defaults to
	 * no allocations. An item row needs an 'id'; 'quantity' defaults to
	 * 1 and 'name' to null (rows without an id are dropped).
	 *
	 * @param array $data The array produced by to_array() (or the decoded JSON of a request).
	 *
	 * @return self
	 */
	public static function from_array( array $data ): self {
		$spec = new self();

		$spec->set_reference( isset( $data['reference'] ) ? $data['reference'] : null )
			->set_weight( self::float_or_null( $data, 'weight' ) )
			->set_length( self::float_or_null( $data, 'length' ) )
			->set_width( self::float_or_null( $data, 'width' ) )
			->set_height( self::float_or_null( $data, 'height' ) );

		foreach ( isset( $data['items'] ) && is_array( $data['items'] ) ? $data['items'] : array() as $item ) {
			if ( ! is_array( $item ) || ! isset( $item['id'] ) ) {
				continue;
			}

			$spec->add_item(
				$item['id'],
				isset( $item['quantity'] ) ? (int) $item['quantity'] : 1,
				isset( $item['name'] ) ? (string) $item['name'] : null
			);
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

		return (float) $data[ $key ];
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
		$this->weight = null === $weight ? null : (float) $weight;

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
		$this->height = null === $height ? null : (float) $height;

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
		$this->width = null === $width ? null : (float) $width;

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
		$this->length = null === $length ? null : (float) $length;

		return $this;
	}

	/**
	 * Get the reference label of the spec (the box number for plans
	 * built from the stored parcel-split meta).
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
		$this->reference = null === $reference ? null : (string) $reference;

		return $this;
	}

	/**
	 * Allocate items to the parcel.
	 *
	 * @param int|string  $id       Product or variation id of the order line.
	 * @param int         $quantity Number of units of that line in this parcel.
	 * @param string|null $name     Display label of the allocation, kept so the frozen meta rows round-trip.
	 *
	 * @return self
	 */
	public function add_item( $id, $quantity = 1, $name = null ): self {
		$this->items[] = array(
			'id'       => $id,
			'quantity' => (int) $quantity,
			'name'     => $name,
		);

		return $this;
	}

	/**
	 * Get the item allocations.
	 *
	 * @return array[] Rows of id, quantity, name.
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
