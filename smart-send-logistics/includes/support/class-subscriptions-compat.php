<?php

namespace Smart_Send\Support;

use InvalidArgumentException;
use Smart_Send\Delivery\Delivery_Details;
use Smart_Send\Delivery\Order_Meta;
use Smart_Send\Delivery\Parcel_Plan;
use WC_Order;
use WC_Order_Item_Product;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Renewal copying through the WooCommerce Subscriptions 4.9+ data APIs.
 *
 * Order metadata is copied before items receive their new IDs. A temporary
 * marker on cloned source items carries the exact identity through that
 * copy; the completed-order filter remaps parcel allocations and removes it.
 */
class Subscriptions_Compat {

	private const ITEM_SOURCE_META = '_smart_send_renewal_source_item';

	protected Order_Meta $order_meta;

	public function __construct( Order_Meta $order_meta ) {
		$this->order_meta = $order_meta;
	}

	/** Registering optional-plugin hooks is harmless when Subscriptions is absent. */
	public function register_hooks(): void {
		add_filter( 'wc_subscriptions_renewal_order_data', array( $this, 'filter_renewal_data' ) );
		add_filter( 'wcs_renewal_order_items', array( $this, 'mark_renewal_items' ), 10, 3 );
		add_filter( 'wcs_renewal_order_created', array( $this, 'restore_renewal_parcel_plan' ), 10, 2 );
	}

	/**
	 * Keep reusable delivery data, excluding completed shipping outcomes.
	 *
	 * @param array $data Metadata key => value; posts storage may still supply serialized values.
	 * @return array
	 */
	public function filter_renewal_data( array $data ): array {
		foreach ( Order_Meta::booking_outcome_meta_keys() as $key ) {
			unset( $data[ $key ] );
		}
		// Smart Send may have added these through the optional Shipment Tracking API.
		unset( $data['_wc_shipment_tracking_items'] );
		return $data;
	}

	/**
	 * Carry source identities through Subscriptions' item metadata copy.
	 *
	 * @param array    $items        Source product/shipping/fee/tax/coupon items.
	 * @param WC_Order $renewal      Destination order (items are not copied yet).
	 * @param WC_Order $subscription Source WC_Subscription, which extends WC_Order.
	 * @return array Clones only; the source subscription is never modified.
	 */
	public function mark_renewal_items( array $items, WC_Order $renewal, WC_Order $subscription ): array {
		if ( empty( $subscription->get_meta( Order_Meta::META_PARCELS, true ) ) ) {
			return $items;
		}

		foreach ( $items as $key => $item ) {
			if ( $item instanceof WC_Order_Item_Product ) {
				// WooCommerce also clones the item's metadata objects.
				$copy = clone $item;
				$copy->delete_meta_data( self::ITEM_SOURCE_META );
				$copy->add_meta_data(
					self::ITEM_SOURCE_META,
					array(
						'order_id' => $subscription->get_id(),
						'item_id'  => $item->get_id(),
					),
					true
				);
				$items[ $key ] = $copy;
			}
		}
		return $items;
	}

	/**
	 * Rebind allocations once the copied order has persisted item IDs.
	 *
	 * @param WC_Order $renewal      Completed renewal order.
	 * @param WC_Order $subscription Source subscription.
	 * @return WC_Order The same order, as required by the Subscriptions filter.
	 */
	public function restore_renewal_parcel_plan( WC_Order $renewal, WC_Order $subscription ): WC_Order {
		$source_items = $subscription->get_items();
		$mapping      = array();
		$valid        = true;
		foreach ( $renewal->get_items() as $item ) {
			$markers = $item->get_meta( self::ITEM_SOURCE_META, false );
			$marker  = $item->get_meta( self::ITEM_SOURCE_META, true );
			if ( $item->meta_exists( self::ITEM_SOURCE_META ) ) {
				$item->delete_meta_data( self::ITEM_SOURCE_META );
				$item->save_meta_data();
			}
			if ( 1 !== count( $markers ) || ! is_array( $marker ) || ( $marker['order_id'] ?? null ) !== $subscription->get_id()
				|| ! isset( $marker['item_id'] ) || ! is_int( $marker['item_id'] ) || $marker['item_id'] <= 0
				|| ! isset( $source_items[ $marker['item_id'] ] ) || isset( $mapping[ $marker['item_id'] ] ) ) {
				$valid = false;
				continue;
			}
			$mapping[ $marker['item_id'] ] = $item->get_id();
		}

		$stored = $renewal->get_meta( Order_Meta::META_PARCELS, true );
		if ( empty( $stored ) ) {
			return $renewal;
		}

		try {
			if ( ! is_array( $stored ) ) {
				throw new InvalidArgumentException( 'Unsupported subscription parcel plan.' );
			}
			$plan = Parcel_Plan::from_array( $stored );
			if ( $plan->validation_errors( $this->item_quantities( $subscription ) ) ) {
				throw new InvalidArgumentException( 'Subscription parcel allocations do not match its current items.' );
			}
			$data = $plan->to_array();
			foreach ( $data['specs'] as &$spec ) {
				foreach ( $spec['items'] as &$allocation ) {
					if ( ! $valid || ! isset( $mapping[ $allocation['order_item_id'] ] ) ) {
						throw new InvalidArgumentException( 'Renewal item correspondence is missing or ambiguous.' );
					}
					$allocation['order_item_id'] = $mapping[ $allocation['order_item_id'] ];
				}
				unset( $allocation );
			}
			unset( $spec );
			$plan = Parcel_Plan::from_array( $data );
			if ( $plan->validation_errors( $this->item_quantities( $renewal ) ) ) {
				throw new InvalidArgumentException( 'Renewal parcel allocations do not match its current items.' );
			}
			$details = new Delivery_Details();
			$details->set_parcel_plan( $plan );
			$this->order_meta->write( $renewal, $details );
		} catch ( InvalidArgumentException $error ) {
			// Preserve the original data for inspection, but force the existing
			// explicit-reset flow. Even a stale ID coinciding with a new item ID
			// must never make an unproven allocation look valid by accident.
			$renewal->update_meta_data( Order_Meta::META_PARCELS, array( 'unmapped_subscription_plan' => $stored ) );
			$renewal->save_meta_data();
			Logger::warning(
				'Subscription renewal parcel plan requires an explicit reset',
				array(
					'order_id'        => $renewal->get_id(),
					'subscription_id' => $subscription->get_id(),
					'reason'          => $error->getMessage(),
				)
			);
		}
		return $renewal;
	}

	/** @return array<int, int> Current item identities and quantities. */
	private function item_quantities( WC_Order $order ): array {
		$quantities = array();
		foreach ( $order->get_items() as $item ) {
			$quantities[ $item->get_id() ] = $item->get_quantity();
		}
		return $quantities;
	}
}
