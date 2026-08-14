<?php
/**
 * WooCommerce Smart Send internal shipment representation.
 *
 * @package  SS_Shipping_Shipment
 * @category Shipping
 * @author   Smart Send
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// A second copy of the plugin may already have defined the class.
if ( ! class_exists( 'SS_Shipping_Shipment' ) ) :

	/**
	 * The internal shipment representation (#113) assembled by
	 * SS_Shipping_Shipment_Builder from SS_Shipping_Order_Reader's output
	 * plus the agent/pick-up-point selection and parcel split.
	 *
	 * A typed, WP-side value object shaped close to the Smart Send v2 API
	 * schema: a single net amount + tax amount per level (shipment/parcel/
	 * item), no excl/incl redundancy, and a pickup-point section named
	 * after v2's PickupParty.service_point_code rather than v1's
	 * agent_no/agent.
	 *
	 * Nested sections (receiver, pickup_point, parcels and each parcel's
	 * items) are kept as plain arrays rather than their own value-object
	 * classes - promoting every nesting level would add ceremony without
	 * adding safety here, since the only other reader is
	 * \Smartsend\Resources\BookingResource::fromShipment(), which already
	 * needs array access to build its own sub-models.
	 *
	 * This class does not talk to the API and does not know about the v1
	 * wire format - translating it into the v1 request body is
	 * \Smartsend\Resources\BookingResource::fromShipment()'s job. It also
	 * makes zero WordPress function calls, despite living in the WP-side
	 * global namespace: it is a pure data holder, referenced by the
	 * "WordPress-light" PSR API client (includes/lib/Smartsend/) purely by
	 * its fully-qualified global name (\SS_Shipping_Shipment).
	 */
	class SS_Shipping_Shipment {

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
		 * Smart Send carrier code.
		 *
		 * @var string|null
		 */
		protected ?string $shipping_carrier = null;

		/**
		 * Smart Send shipping method code.
		 *
		 * @var string|null
		 */
		protected ?string $shipping_method = null;

		/**
		 * Shipping date (Y-m-d).
		 *
		 * @var string|null
		 */
		protected ?string $shipping_date = null;

		/**
		 * Receiver data, see SS_Shipping_Order_Reader::get_receiver_data().
		 *
		 * @var array|null
		 */
		protected ?array $receiver = null;

		/**
		 * Pickup point data (service_point_code, address, ...), or null.
		 *
		 * @var array|null
		 */
		protected ?array $pickup_point = null;

		/**
		 * One row per parcel: internal_id, internal_reference, weight,
		 * height, width, length, freetext, items (item rows),
		 * total_net_amount, total_tax_amount.
		 *
		 * @var array[]
		 */
		protected array $parcels = array();

		/**
		 * Order total excl. shipping, excl. tax.
		 *
		 * @var float|null
		 */
		protected ?float $subtotal_net_amount = null;

		/**
		 * Tax on the order total excl. shipping.
		 *
		 * @var float|null
		 */
		protected ?float $subtotal_tax_amount = null;

		/**
		 * Shipping cost excl. tax.
		 *
		 * @var float|null
		 */
		protected ?float $shipping_net_amount = null;

		/**
		 * Tax on the shipping cost.
		 *
		 * @var float|null
		 */
		protected ?float $shipping_tax_amount = null;

		/**
		 * Order total excl. tax.
		 *
		 * @var float|null
		 */
		protected ?float $total_net_amount = null;

		/**
		 * Total tax on the order.
		 *
		 * @var float|null
		 */
		protected ?float $total_tax_amount = null;

		/**
		 * Order currency code.
		 *
		 * @var string|null
		 */
		protected ?string $currency = null;

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
		 * Get the Smart Send carrier code.
		 *
		 * @return string|null
		 */
		public function get_shipping_carrier() {
			return $this->shipping_carrier;
		}

		/**
		 * Set the Smart Send carrier code.
		 *
		 * @param mixed $shipping_carrier Smart Send carrier code.
		 *
		 * @return self
		 */
		public function set_shipping_carrier( $shipping_carrier ): self {
			$this->shipping_carrier = null === $shipping_carrier ? null : (string) $shipping_carrier;

			return $this;
		}

		/**
		 * Get the Smart Send shipping method code.
		 *
		 * @return string|null
		 */
		public function get_shipping_method() {
			return $this->shipping_method;
		}

		/**
		 * Set the Smart Send shipping method code.
		 *
		 * @param mixed $shipping_method Smart Send shipping method code.
		 *
		 * @return self
		 */
		public function set_shipping_method( $shipping_method ): self {
			$this->shipping_method = null === $shipping_method ? null : (string) $shipping_method;

			return $this;
		}

		/**
		 * Get the shipping date (Y-m-d).
		 *
		 * @return string|null
		 */
		public function get_shipping_date() {
			return $this->shipping_date;
		}

		/**
		 * Set the shipping date (Y-m-d).
		 *
		 * @param string|null $shipping_date Shipping date (Y-m-d).
		 *
		 * @return self
		 */
		public function set_shipping_date( $shipping_date ): self {
			$this->shipping_date = $shipping_date;

			return $this;
		}

		/**
		 * Get the receiver data.
		 *
		 * @return array|null
		 */
		public function get_receiver() {
			return $this->receiver;
		}

		/**
		 * Set the receiver data.
		 *
		 * @param array|null $receiver Receiver data.
		 *
		 * @return self
		 */
		public function set_receiver( $receiver ): self {
			$this->receiver = $receiver;

			return $this;
		}

		/**
		 * Get the pickup point data, or null.
		 *
		 * @return array|null
		 */
		public function get_pickup_point() {
			return $this->pickup_point;
		}

		/**
		 * Set the pickup point data.
		 *
		 * @param array|null $pickup_point Pickup point data, or null.
		 *
		 * @return self
		 */
		public function set_pickup_point( $pickup_point ): self {
			$this->pickup_point = $pickup_point;

			return $this;
		}

		/**
		 * Get the parcel rows.
		 *
		 * @return array[]
		 */
		public function get_parcels(): array {
			return $this->parcels;
		}

		/**
		 * Set the parcel rows.
		 *
		 * @param array[] $parcels Parcel rows.
		 *
		 * @return self
		 */
		public function set_parcels( array $parcels ): self {
			$this->parcels = $parcels;

			return $this;
		}

		/**
		 * Get the order total excl. shipping, excl. tax.
		 *
		 * @return float|null
		 */
		public function get_subtotal_net_amount() {
			return $this->subtotal_net_amount;
		}

		/**
		 * Set the order total excl. shipping, excl. tax.
		 *
		 * @param mixed $subtotal_net_amount Order total excl. shipping, excl. tax.
		 *
		 * @return self
		 */
		public function set_subtotal_net_amount( $subtotal_net_amount ): self {
			$this->subtotal_net_amount = null === $subtotal_net_amount ? null : (float) $subtotal_net_amount;

			return $this;
		}

		/**
		 * Get the tax on the order total excl. shipping.
		 *
		 * @return float|null
		 */
		public function get_subtotal_tax_amount() {
			return $this->subtotal_tax_amount;
		}

		/**
		 * Set the tax on the order total excl. shipping.
		 *
		 * @param mixed $subtotal_tax_amount Tax on the order total excl. shipping.
		 *
		 * @return self
		 */
		public function set_subtotal_tax_amount( $subtotal_tax_amount ): self {
			$this->subtotal_tax_amount = null === $subtotal_tax_amount ? null : (float) $subtotal_tax_amount;

			return $this;
		}

		/**
		 * Get the shipping cost excl. tax.
		 *
		 * @return float|null
		 */
		public function get_shipping_net_amount() {
			return $this->shipping_net_amount;
		}

		/**
		 * Set the shipping cost excl. tax.
		 *
		 * @param mixed $shipping_net_amount Shipping cost excl. tax.
		 *
		 * @return self
		 */
		public function set_shipping_net_amount( $shipping_net_amount ): self {
			$this->shipping_net_amount = null === $shipping_net_amount ? null : (float) $shipping_net_amount;

			return $this;
		}

		/**
		 * Get the tax on the shipping cost.
		 *
		 * @return float|null
		 */
		public function get_shipping_tax_amount() {
			return $this->shipping_tax_amount;
		}

		/**
		 * Set the tax on the shipping cost.
		 *
		 * @param mixed $shipping_tax_amount Tax on the shipping cost.
		 *
		 * @return self
		 */
		public function set_shipping_tax_amount( $shipping_tax_amount ): self {
			$this->shipping_tax_amount = null === $shipping_tax_amount ? null : (float) $shipping_tax_amount;

			return $this;
		}

		/**
		 * Get the order total excl. tax.
		 *
		 * @return float|null
		 */
		public function get_total_net_amount() {
			return $this->total_net_amount;
		}

		/**
		 * Set the order total excl. tax.
		 *
		 * @param mixed $total_net_amount Order total excl. tax.
		 *
		 * @return self
		 */
		public function set_total_net_amount( $total_net_amount ): self {
			$this->total_net_amount = null === $total_net_amount ? null : (float) $total_net_amount;

			return $this;
		}

		/**
		 * Get the total tax on the order.
		 *
		 * @return float|null
		 */
		public function get_total_tax_amount() {
			return $this->total_tax_amount;
		}

		/**
		 * Set the total tax on the order.
		 *
		 * @param mixed $total_tax_amount Total tax on the order.
		 *
		 * @return self
		 */
		public function set_total_tax_amount( $total_tax_amount ): self {
			$this->total_tax_amount = null === $total_tax_amount ? null : (float) $total_tax_amount;

			return $this;
		}

		/**
		 * Get the order currency code.
		 *
		 * @return string|null
		 */
		public function get_currency() {
			return $this->currency;
		}

		/**
		 * Set the order currency code.
		 *
		 * @param string|null $currency Order currency code.
		 *
		 * @return self
		 */
		public function set_currency( $currency ): self {
			$this->currency = $currency;

			return $this;
		}
	}

endif;
