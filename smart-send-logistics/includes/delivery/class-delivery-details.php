<?php
/**
 * WooCommerce Smart Send delivery details value object.
 *
 * @package Smart_Send
 * @category Shipping
 * @author   Smart Send
 */

namespace Smart_Send\Delivery;

use Smart_Send\Fulfillment\Shipment_IDs;
use WC_Order;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Everything Smart Send knows about HOW an order ships (#139): the
 * resolved Smart Send shipping method, selected addons (empty today -
 * the slot exists for future addon selection), the selected pickup
 * point and the parcel plan. Future capabilities (e.g. a delivery
 * window) get fields here.
 *
 * The order's Smart-Send-owned meta reads into (and writes from) this
 * type through the repository (Order_Meta::read()/write()).
 * The type doubles as the request payload of the label flow (#116): a
 * caller can submit a PARTIAL details object - every field is nullable,
 * null meaning "not specified, keep the stored/derived value" - which
 * the flow merges with the stored/derived one. The merged details pass
 * through the smart_send_delivery_details filter (in the fulfillment
 * service) before booking is called.
 *
 * Booked shipment ids are deliberately NOT part of this type: they are
 * the OUTCOME of fulfillment, not delivery configuration (see
 * Shipment_IDs).
 *
 * to_array()/from_array() are the canonical JSON form (#182), the
 * shape a fulfillment request submits and the order meta box reads:
 *
 *   array(
 *     'shipping_method' => string|null,
 *     'pickup_point'    => Pickup_Point::to_array()  // a full point, or just array( 'agent_no' => '1234' )
 *                          | array( 'clear' => true )            // clear the stored pickup point
 *                          | null,                               // keep the stored/derived one
 *     'parcel_plan'     => Parcel_Plan::to_array() | null,
 *     'addons'          => array,
 *   )
 *
 * Absent (or null) means "not specified, keep the stored/derived
 * value" for every field. Because JSON null and absent are both
 * "unspecified", clearing the pickup point needs the explicit
 * array( 'clear' => true ) sentinel (clear_pickup_point() /
 * is_pickup_point_cleared()). A pickup point that carries only an
 * agent number is resolved into the full point by the fulfillment
 * service before booking (is_agent_no_only() on the point).
 *
 * Serializable, with no live WC_Order or WordPress dependency
 * (Phase 7 queues delivery details).
 */
class Delivery_Details {

	/**
	 * The array( 'clear' => true ) sentinel's key in to_array()/from_array().
	 */
	const PICKUP_POINT_CLEAR = 'clear';

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
	 * @var Pickup_Point|null
	 */
	protected ?Pickup_Point $pickup_point = null;

	/**
	 * Whether a partial details object explicitly clears the stored
	 * pickup point (as opposed to a null pickup point, which means
	 * "not specified").
	 *
	 * @var bool
	 */
	protected bool $pickup_point_cleared = false;

	/**
	 * The parcel plan, or null when not specified (which resolves to
	 * one parcel containing everything, same as an empty plan).
	 *
	 * @var Parcel_Plan|null
	 */
	protected ?Parcel_Plan $parcel_plan = null;

	/**
	 * Build delivery details from their to_array() form (the canonical
	 * JSON shape, see the class docblock). Every key is optional.
	 *
	 * @param array $data The array produced by to_array() (or the decoded JSON of a request).
	 *
	 * @return self
	 */
	public static function from_array( array $data ): self {
		$details = new self();

		if ( isset( $data['shipping_method'] ) && '' !== $data['shipping_method'] ) {
			$details->set_shipping_method( $data['shipping_method'] );
		}

		if ( isset( $data['addons'] ) && is_array( $data['addons'] ) ) {
			$details->set_addons( $data['addons'] );
		}

		if ( isset( $data['pickup_point'] ) && ( is_array( $data['pickup_point'] ) || is_object( $data['pickup_point'] ) ) ) {
			$pickup_point = (array) $data['pickup_point'];

			if ( ! empty( $pickup_point[ self::PICKUP_POINT_CLEAR ] ) ) {
				$details->clear_pickup_point();
			} elseif ( array() !== $pickup_point ) {
				$details->set_pickup_point( Pickup_Point::from_object( $pickup_point ) );
			}
		}

		if ( isset( $data['parcel_plan'] ) && is_array( $data['parcel_plan'] ) ) {
			$details->set_parcel_plan( Parcel_Plan::from_array( $data['parcel_plan'] ) );
		}

		return $details;
	}

	/**
	 * The canonical array form (see the class docblock).
	 *
	 * @return array
	 */
	public function to_array(): array {
		if ( $this->pickup_point_cleared ) {
			$pickup_point = array( self::PICKUP_POINT_CLEAR => true );
		} else {
			$pickup_point = null === $this->pickup_point ? null : $this->pickup_point->to_array();
		}

		return array(
			'shipping_method' => $this->shipping_method,
			'pickup_point'    => $pickup_point,
			'parcel_plan'     => null === $this->parcel_plan ? null : $this->parcel_plan->to_array(),
			'addons'          => $this->addons,
		);
	}

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
	 * @return Pickup_Point|null
	 */
	public function get_pickup_point() {
		return $this->pickup_point;
	}

	/**
	 * Set (or clear) the selected pickup point.
	 *
	 * @param Pickup_Point|null $pickup_point The pickup point, or null.
	 *
	 * @return self
	 */
	public function set_pickup_point( ?Pickup_Point $pickup_point ): self {
		$this->pickup_point         = $pickup_point;
		$this->pickup_point_cleared = false;

		return $this;
	}

	/**
	 * Explicitly clear the pickup point: on a partial details object
	 * this means "remove the stored pickup point" (persisted after a
	 * successful booking), where set_pickup_point( null ) would mean
	 * "not specified, keep it".
	 *
	 * @return self
	 */
	public function clear_pickup_point(): self {
		$this->pickup_point         = null;
		$this->pickup_point_cleared = true;

		return $this;
	}

	/**
	 * Whether the pickup point was explicitly cleared (the
	 * array( 'clear' => true ) sentinel), as opposed to not specified.
	 *
	 * @return boolean
	 */
	public function is_pickup_point_cleared(): bool {
		return $this->pickup_point_cleared;
	}

	/**
	 * Get the parcel plan, or null when not specified.
	 *
	 * @return Parcel_Plan|null
	 */
	public function get_parcel_plan() {
		return $this->parcel_plan;
	}

	/**
	 * Set (or clear) the parcel plan.
	 *
	 * @param Parcel_Plan|null $parcel_plan The parcel plan, or null.
	 *
	 * @return self
	 */
	public function set_parcel_plan( ?Parcel_Plan $parcel_plan ): self {
		$this->parcel_plan = $parcel_plan;

		return $this;
	}
}
