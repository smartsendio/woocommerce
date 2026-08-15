<?php
/**
 * WooCommerce Smart Send resolved parcel value object.
 *
 * @package  SS_Shipping_Parcel
 * @category Shipping
 * @author   Smart Send
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// A second copy of the plugin may already have defined the class.
if ( ! class_exists( 'SS_Shipping_Parcel' ) ) :

	/**
	 * One resolved parcel of the internal shipment representation (#139):
	 * the OUTPUT side of the parcel domain, produced by
	 * SS_Shipping_Shipment_Builder when it resolves a
	 * SS_Shipping_Parcel_Plan (the input side, made of
	 * SS_Shipping_Parcel_Spec) against the order's item lines. Lives on
	 * SS_Shipping_Shipment, replacing the untyped parcel arrays it held
	 * before.
	 *
	 * A parcel resolved from an item-less spec carries no item rows and
	 * null amounts - declared amounts then live at shipment level only.
	 *
	 * Item rows stay plain arrays (see SS_Shipping_Order_Reader::
	 * get_items_data() for the row shape) for the same reason
	 * SS_Shipping_Shipment keeps its nested sections as arrays.
	 *
	 * Like SS_Shipping_Shipment, this class makes zero WordPress calls: it
	 * is a pure data holder read by
	 * \Smartsend\Resources\BookingResource::fromShipment().
	 */
	class SS_Shipping_Parcel {

		/**
		 * Order id.
		 *
		 * @var string|null
		 */
		protected ?string $internal_id = null;

		/**
		 * Order number.
		 *
		 * @var string|null
		 */
		protected ?string $internal_reference = null;

		/**
		 * Concrete parcel weight in kg.
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
		 * Freetext printed on the label.
		 *
		 * @var string|null
		 */
		protected ?string $freetext = null;

		/**
		 * Item rows contained in the parcel (possibly empty).
		 *
		 * @var array[]
		 */
		protected array $items = array();

		/**
		 * Parcel total excl. tax.
		 *
		 * @var float|null
		 */
		protected ?float $total_net_amount = null;

		/**
		 * Tax on the parcel total.
		 *
		 * @var float|null
		 */
		protected ?float $total_tax_amount = null;

		/**
		 * Get the order id.
		 *
		 * @return string|null
		 */
		public function get_internal_id() {
			return $this->internal_id;
		}

		/**
		 * Set the order id.
		 *
		 * @param mixed $internal_id Order id.
		 *
		 * @return self
		 */
		public function set_internal_id( $internal_id ): self {
			$this->internal_id = null === $internal_id ? null : (string) $internal_id;

			return $this;
		}

		/**
		 * Get the order number.
		 *
		 * @return string|null
		 */
		public function get_internal_reference() {
			return $this->internal_reference;
		}

		/**
		 * Set the order number.
		 *
		 * @param mixed $internal_reference Order number.
		 *
		 * @return self
		 */
		public function set_internal_reference( $internal_reference ): self {
			$this->internal_reference = null === $internal_reference ? null : (string) $internal_reference;

			return $this;
		}

		/**
		 * Get the parcel weight.
		 *
		 * @return float|null
		 */
		public function get_weight() {
			return $this->weight;
		}

		/**
		 * Set the parcel weight.
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
		 * Get the freetext.
		 *
		 * @return string|null
		 */
		public function get_freetext() {
			return $this->freetext;
		}

		/**
		 * Set the freetext.
		 *
		 * @param mixed $freetext The freetext, or null.
		 *
		 * @return self
		 */
		public function set_freetext( $freetext ): self {
			$this->freetext = null === $freetext ? null : (string) $freetext;

			return $this;
		}

		/**
		 * Get the item rows.
		 *
		 * @return array[]
		 */
		public function get_items(): array {
			return $this->items;
		}

		/**
		 * Set the item rows.
		 *
		 * @param array[] $items Item rows.
		 *
		 * @return self
		 */
		public function set_items( array $items ): self {
			$this->items = $items;

			return $this;
		}

		/**
		 * Get the parcel total excl. tax.
		 *
		 * @return float|null
		 */
		public function get_total_net_amount() {
			return $this->total_net_amount;
		}

		/**
		 * Set the parcel total excl. tax.
		 *
		 * @param mixed $total_net_amount Parcel total excl. tax.
		 *
		 * @return self
		 */
		public function set_total_net_amount( $total_net_amount ): self {
			$this->total_net_amount = null === $total_net_amount ? null : (float) $total_net_amount;

			return $this;
		}

		/**
		 * Get the tax on the parcel total.
		 *
		 * @return float|null
		 */
		public function get_total_tax_amount() {
			return $this->total_tax_amount;
		}

		/**
		 * Set the tax on the parcel total.
		 *
		 * @param mixed $total_tax_amount Tax on the parcel total.
		 *
		 * @return self
		 */
		public function set_total_tax_amount( $total_tax_amount ): self {
			$this->total_tax_amount = null === $total_tax_amount ? null : (float) $total_tax_amount;

			return $this;
		}
	}

endif;
