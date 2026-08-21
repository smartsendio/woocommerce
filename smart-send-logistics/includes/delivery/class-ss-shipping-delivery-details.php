<?php
/**
 * WooCommerce Smart Send delivery details value object.
 *
 * @package  SS_Shipping_Delivery_Details
 * @category Shipping
 * @author   Smart Send
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// A second copy of the plugin may already have defined the class.
if ( ! class_exists( 'SS_Shipping_Delivery_Details' ) ) :

	/**
	 * Everything Smart Send knows about HOW an order ships (#139): the
	 * resolved Smart Send shipping method, selected addons (empty today -
	 * the slot exists for future addon selection), the selected pickup
	 * point and the parcel plan. Future capabilities (e.g. a delivery
	 * window) get fields here.
	 *
	 * The order's Smart-Send-owned meta reads into (and writes from) this
	 * type through the repository (SS_Shipping_Order_Meta::read()/write()).
	 * The type doubles as the request payload of the label flow (#116): a
	 * caller can submit a PARTIAL details object - every field is nullable,
	 * null meaning "not specified, keep the stored/derived value" - which
	 * the flow merges with the stored/derived one. The merged details pass
	 * through the smart_send_delivery_details filter before booking.
	 *
	 * Booked shipment ids are deliberately NOT part of this type: they are
	 * the OUTCOME of fulfillment, not delivery configuration (see
	 * SS_Shipping_Shipment_Ids).
	 *
	 * Serializable, with no live WC_Order or WordPress dependency
	 * (Phase 7 queues delivery details).
	 */
	class SS_Shipping_Delivery_Details {

		/**
		 * The Smart Send shipping method id (e.g. 'postnord_agent'), or
		 * null when not (yet) resolved.
		 *
		 * @var string|null
		 */
		protected ?string $shipping_method = null;

		/**
		 * Selected addons. Empty today; the slot exists for future addon
		 * selection.
		 *
		 * @var array
		 */
		protected array $addons = array();

		/**
		 * The selected pickup point, or null.
		 *
		 * @var SS_Shipping_Pickup_Point|null
		 */
		protected ?SS_Shipping_Pickup_Point $pickup_point = null;

		/**
		 * The parcel plan, or null when not specified (which resolves to
		 * one parcel containing everything, same as an empty plan).
		 *
		 * @var SS_Shipping_Parcel_Plan|null
		 */
		protected ?SS_Shipping_Parcel_Plan $parcel_plan = null;

		/**
		 * Get the Smart Send shipping method id.
		 *
		 * @return string|null
		 */
		public function get_shipping_method() {
			return $this->shipping_method;
		}

		/**
		 * Set the Smart Send shipping method id.
		 *
		 * @param mixed $shipping_method The method id, or null.
		 *
		 * @return self
		 */
		public function set_shipping_method( $shipping_method ): self {
			$this->shipping_method = null === $shipping_method ? null : (string) $shipping_method;

			return $this;
		}

		/**
		 * Get the selected addons.
		 *
		 * @return array
		 */
		public function get_addons(): array {
			return $this->addons;
		}

		/**
		 * Set the selected addons.
		 *
		 * @param array $addons The addons.
		 *
		 * @return self
		 */
		public function set_addons( array $addons ): self {
			$this->addons = $addons;

			return $this;
		}

		/**
		 * Get the selected pickup point, or null.
		 *
		 * @return SS_Shipping_Pickup_Point|null
		 */
		public function get_pickup_point() {
			return $this->pickup_point;
		}

		/**
		 * Set (or clear) the selected pickup point.
		 *
		 * @param SS_Shipping_Pickup_Point|null $pickup_point The pickup point, or null.
		 *
		 * @return self
		 */
		public function set_pickup_point( ?SS_Shipping_Pickup_Point $pickup_point ): self {
			$this->pickup_point = $pickup_point;

			return $this;
		}

		/**
		 * Get the parcel plan, or null when not specified.
		 *
		 * @return SS_Shipping_Parcel_Plan|null
		 */
		public function get_parcel_plan() {
			return $this->parcel_plan;
		}

		/**
		 * Set (or clear) the parcel plan.
		 *
		 * @param SS_Shipping_Parcel_Plan|null $parcel_plan The parcel plan, or null.
		 *
		 * @return self
		 */
		public function set_parcel_plan( ?SS_Shipping_Parcel_Plan $parcel_plan ): self {
			$this->parcel_plan = $parcel_plan;

			return $this;
		}
	}

endif;
