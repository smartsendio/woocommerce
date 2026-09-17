<?php
/**
 * WooCommerce Smart Send booked shipment id accessor.
 *
 * @package Smart_Send
 * @category Shipping
 * @author   Smart Send
 */

namespace Smart_Send\Fulfillment;

use Smart_Send\Booking\Booked_Shipment;
use Smart_Send\Delivery\Delivery_Details;
use Smart_Send\Delivery\Order_Meta;
use WC_Order;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Reads and writes the booked shipment ids of an order (#139).
 *
 * Shipment ids are the OUTCOME of fulfillment, not delivery
 * configuration, so they deliberately live outside
 * Delivery_Details and its repository - a small distinct
 * accessor in the same file family. The meta keys (and their
 * booking-outcome classification, which keeps them off subscription
 * renewal orders) stay defined on Order_Meta.
 */
class Shipment_IDs {

	/**
	 * How many rows the booked-labels list keeps - the newest ones. A
	 * bound so a pathological order (a merchant re-booking over and
	 * over) cannot grow its meta without limit.
	 */
	const MAX_LABELS = 50;

	/**
	 * Save the id of a booked shipment on the order, under the outbound
	 * or return meta key according to the shipment's own direction
	 * (#177), and append it to the order's booked-labels list.
	 *
	 * @param integer|WC_Order            $order    Order (or order id).
	 * @param Booked_Shipment $shipment The booked shipment.
	 *
	 * @return void
	 */
	public function save_booked( $order, Booked_Shipment $shipment ) {
		$this->save( $order, $shipment->get_shipment_id(), $shipment->is_return(), $shipment->get_booked_at() );
	}

	/**
	 * Save a booked shipment id on the order: the frozen per-direction
	 * key (the LATEST id of that direction - a documented public
	 * contract) and a new row on the append-only booked-labels list the
	 * order screen's timeline renders.
	 *
	 * @param integer|WC_Order $order       Order (or order id).
	 * @param string           $shipment_id The Smart Send shipment id.
	 * @param boolean          $is_return   Whether the label is a return label.
	 * @param string|null      $booked_at   When it was booked (ISO 8601); now when omitted.
	 *
	 * @return void
	 */
	public function save( $order, $shipment_id, $is_return, $booked_at = null ) {
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order );
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$order->update_meta_data(
			$is_return ? Order_Meta::META_RETURN_LABEL_ID : Order_Meta::META_LABEL_ID,
			$shipment_id
		);

		$labels   = $this->labels( $order );
		$labels[] = array(
			'direction'   => $is_return ? 'return' : 'outbound',
			'shipment_id' => (string) $shipment_id,
			'booked_at'   => $this->normalize_booked_at( $booked_at ),
		);

		if ( count( $labels ) > self::MAX_LABELS ) {
			$labels = array_slice( $labels, -self::MAX_LABELS );
		}

		$order->update_meta_data( Order_Meta::META_LABELS, $labels );
		$order->save();
	}

	/**
	 * The labels booked for the order, oldest first - one row per
	 * booked shipment ( direction, shipment_id, booked_at ).
	 *
	 * Orders booked before this list existed carry only the two frozen
	 * id keys and no timestamps; this method does NOT invent rows for
	 * them (the presenter falls back to the frozen ids for the
	 * timeline, without a timestamp).
	 *
	 * @param integer|WC_Order $order Order (or order id).
	 *
	 * @return array[]
	 */
	public function labels( $order ) {
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order );
		if ( ! $order instanceof WC_Order ) {
			return array();
		}

		$stored = $order->get_meta( Order_Meta::META_LABELS, true );

		if ( ! is_array( $stored ) ) {
			return array();
		}

		$labels = array();
		foreach ( $stored as $row ) {
			if ( ! is_array( $row ) || empty( $row['shipment_id'] ) ) {
				continue;
			}

			$labels[] = array(
				'direction'   => isset( $row['direction'] ) && 'return' === $row['direction'] ? 'return' : 'outbound',
				'shipment_id' => (string) $row['shipment_id'],
				'booked_at'   => empty( $row['booked_at'] ) ? null : (string) $row['booked_at'],
			);
		}

		return $labels;
	}

	/**
	 * A booking timestamp as the list stores it: ISO 8601 in UTC.
	 *
	 * @param string|null $booked_at The shipment's own timestamp, if any.
	 *
	 * @return string
	 */
	protected function normalize_booked_at( $booked_at ) {
		if ( ! empty( $booked_at ) ) {
			$time = strtotime( (string) $booked_at );

			if ( false !== $time ) {
				return gmdate( 'c', $time );
			}
		}

		return gmdate( 'c' );
	}

	/**
	 * Get the booked shipment id of the order, if any.
	 *
	 * @param integer|WC_Order $order     Order (or order id).
	 * @param boolean          $is_return Whether to read the return label's shipment id.
	 *
	 * @return string The shipment id, or '' when none is stored (or the order cannot be loaded).
	 */
	public function get( $order, $is_return ) {
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order );
		if ( ! $order instanceof WC_Order ) {
			return '';
		}

		return (string) $order->get_meta(
			$is_return ? Order_Meta::META_RETURN_LABEL_ID : Order_Meta::META_LABEL_ID,
			true
		);
	}
}
