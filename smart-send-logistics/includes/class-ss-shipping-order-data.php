<?php
/**
 * WooCommerce Smart Send order data access.
 *
 * @package  SS_Shipping_Order_Data
 * @category Shipping
 * @author   Smart Send
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// A second copy of the plugin may already have defined the class.
if ( ! class_exists( 'SS_Shipping_Order_Data' ) ) :

	/**
	 * Answers every order-data question needed to build the booking request
	 * (shipment payload) from a WC_Order, using WooCommerce CRUD getters only.
	 *
	 * This is the single place that knows how to read receiver address data,
	 * item lines with weight/price/customs data and order totals. The class
	 * returns plain arrays; assembling the Smart Send API models from them is
	 * the job of SS_Shipping_Shipment.
	 */
	class SS_Shipping_Order_Data {

		/**
		 * The WooCommerce order.
		 *
		 * @var WC_Order
		 */
		protected WC_Order $order;

		/**
		 * Constructor.
		 *
		 * @param WC_Order $order The WooCommerce order.
		 */
		public function __construct( WC_Order $order ) {
			$this->order = $order;
		}

		/**
		 * Get the order id.
		 *
		 * @return int
		 */
		public function get_order_id() {
			return $this->order->get_id();
		}

		/**
		 * Get the order number (differs from the id when plugins customize it).
		 *
		 * @return string
		 */
		public function get_order_number() {
			return $this->order->get_order_number();
		}

		/**
		 * Get the receiver (shipping destination) data for the order.
		 *
		 * @return array {
		 *     Receiver data.
		 *
		 *     @type string      $company       Company name.
		 *     @type string      $name_line1    First name.
		 *     @type string      $name_line2    Last name.
		 *     @type string      $address_line1 Address line 1.
		 *     @type string      $address_line2 Address line 2.
		 *     @type string      $postal_code   Postal code.
		 *     @type string      $city          City.
		 *     @type string      $country       ISO country code.
		 *     @type string|null $phone         Phone number, if usable for SMS notification.
		 *     @type string|null $email         Email address.
		 * }
		 */
		public function get_receiver_data() {
			$billing_address = $this->order->get_address();

			/*
			 * Filter the receiver address used for the shipping label.
			 *
			 * @param array $shipping_address The order shipping address.
			 * @param int   $order_id         Order ID.
			 */
			$shipping_address = apply_filters(
				'smart_send_order_receiver',
				$this->order->get_address( 'shipping' ),
				$this->order->get_id()
			);

			// The shipping phone field was added in WooCommerce 5.6 but often added by hooks/plugins before that.
			// Note that the field is not shown on checkout per default but can be enabled by filters/plugins.
			// See: https://github.com/woocommerce/woocommerce/pull/30097#issuecomment-943114632
			if ( isset( $shipping_address['phone'] ) && $shipping_address['phone'] ) {
				$phone = $shipping_address['phone'];
			} elseif ( $shipping_address['country'] === $billing_address['country'] ) { // Require as only local phone numbers is accepted.
				$phone = $billing_address['phone'];
			} else {
				$phone = null;
			}

			$phone = $this->normalize_phone( $phone );

			/*
			 * Filter the receiver phone number used for the shipping label
			 * and the SMS notification service.
			 *
			 * @param string|null $phone The normalized receiver phone number.
			 * @param WC_Order    $order The WooCommerce order.
			 */
			$phone = apply_filters( 'smart_send_receiver_phone', $phone, $this->order );

			// The shipping email field does not exist in default WP installations but can be added by filters/hooks.
			if ( ! isset( $shipping_address['email'] ) && isset( $billing_address['email'] ) ) {
				$shipping_address['email'] = $billing_address['email'];
			}

			$receiver_data = array(
				'company'       => $shipping_address['company'],
				'name_line1'    => $shipping_address['first_name'],
				'name_line2'    => $shipping_address['last_name'],
				'address_line1' => $shipping_address['address_1'],
				'address_line2' => $shipping_address['address_2'],
				'postal_code'   => $shipping_address['postcode'],
				'city'          => $shipping_address['city'],
				'country'       => $shipping_address['country'],
				'phone'         => $phone,
				'email'         => isset( $shipping_address['email'] ) ? $shipping_address['email'] : null,
			);

			/*
			 * Filter the receiver section of the booking request.
			 *
			 * @param array    $receiver_data The receiver data (see the return doc above).
			 * @param WC_Order $order         The WooCommerce order.
			 */
			return apply_filters( 'smart_send_payload_receiver', $receiver_data, $this->order );
		}

		/**
		 * Get the item lines for the order: one row per order line with
		 * weight, price and customs data.
		 *
		 * Prices are based on the pre-discount line subtotal, matching how
		 * the plugin has always priced item lines (order-level discounts are
		 * reflected in the order totals, not the individual lines).
		 *
		 * @return array[] List of item rows.
		 */
		public function get_items_data() {
			$items = array();

			foreach ( $this->order->get_items() as $item ) {
				$product = wc_get_product( $item->get_product_id() );

				if ( $item->get_variation_id() ) {
					$product_variation = wc_get_product( $item->get_variation_id() );
					$product_id        = $item->get_variation_id();
					$product_sku       = $product_variation->get_sku() ? $product_variation->get_sku() : strval( $item->get_variation_id() );
				} else {
					$product_variation = $product;
					$product_id        = $item->get_product_id();
					// Ensure id is string and not int.
					$product_sku = $product->get_sku() ? $product->get_sku() : strval( $item->get_product_id() );
				}

				$quantity = $item->get_quantity();

				// Total w/o tax and individual w/o tax. Clamped at >= 0 so a
				// negative line never produces a negative item value (#54).
				$total_price_excluding_tax = max( 0.0, (float) $item->get_subtotal() );
				$unit_price_excluding_tax  = $total_price_excluding_tax / $quantity;

				// Total tax.
				$total_tax_amount = max( 0.0, (float) $item->get_subtotal_tax() );

				// Total w/ tax and individual w/ tax.
				$total_price_including_tax = $total_price_excluding_tax + $total_tax_amount;
				$unit_price_including_tax  = $total_price_including_tax / $quantity;

				$unit_weight = round( wc_get_weight( $product_variation->get_weight(), 'kg' ), 2 );

				$items[] = array(
					'id'                        => $product_id,
					'sku'                       => $product_sku,
					'name'                      => $product->get_title(),
					'description'               => $this->get_product_meta( $product, $product_variation, '_ss_customs_desc' ),
					'hs_code'                   => $this->get_product_meta( $product, $product_variation, '_ss_hs_code' ),
					'country_of_origin'         => $this->get_product_meta( $product, $product_variation, '_ss_country_of_origin' ),
					'quantity'                  => $quantity,
					'unit_weight'               => $unit_weight,
					'unit_price_excluding_tax'  => $unit_price_excluding_tax,
					'unit_price_including_tax'  => $unit_price_including_tax,
					'total_price_excluding_tax' => $total_price_excluding_tax,
					'total_price_including_tax' => $total_price_including_tax,
					'total_tax_amount'          => $total_tax_amount,
				);
			}

			/*
			 * Filter the item lines of the booking request.
			 *
			 * @param array[]  $items One row per order line (see the return doc above).
			 * @param WC_Order $order The WooCommerce order.
			 */
			return apply_filters( 'smart_send_payload_items', $items, $this->order );
		}

		/**
		 * Get the order totals used on the shipment and parcel level.
		 *
		 * The rule (see issue #72): totals are derived from what WooCommerce
		 * itself reports via CRUD getters and reconcile with
		 * WC_Order::get_total(): subtotal + shipping = total, on both the
		 * including-tax and excluding-tax basis. Every value is clamped at
		 * >= 0, so a discount or gift card larger than the item value (which
		 * can push the non-shipping subtotal negative) never produces a
		 * negative amount in the booking request (#54). When a clamp kicks
		 * in, the clamped value wins over strict reconciliation.
		 *
		 * @return array {
		 *     Order totals.
		 *
		 *     @type float  $subtotal_price_excluding_tax Order total excl. shipping, excl. tax.
		 *     @type float  $subtotal_price_including_tax Order total excl. shipping, incl. tax.
		 *     @type float  $subtotal_tax_amount          Tax on the order total excl. shipping.
		 *     @type float  $shipping_price_excluding_tax Shipping cost excl. tax.
		 *     @type float  $shipping_price_including_tax Shipping cost incl. tax.
		 *     @type float  $shipping_tax_amount          Tax on the shipping cost.
		 *     @type float  $total_price_excluding_tax    Order total excl. tax.
		 *     @type float  $total_price_including_tax    Order total incl. tax.
		 *     @type float  $total_tax_amount             Total tax on the order.
		 *     @type string $currency                     Order currency code.
		 * }
		 */
		public function get_totals() {
			// Order totals, as WooCommerce itself reports them.
			$total_including_tax = (float) $this->order->get_total();
			$total_tax           = (float) $this->order->get_total_tax();
			$total_excluding_tax = $total_including_tax - $total_tax;

			// Shipping totals. WC_Order::get_shipping_total() is excluding tax.
			$shipping_excluding_tax = (float) $this->order->get_shipping_total();
			$shipping_tax           = (float) $this->order->get_shipping_tax();
			$shipping_including_tax = $shipping_excluding_tax + $shipping_tax;

			// The non-shipping part of the order: items, fees and discounts.
			$subtotal_including_tax = $total_including_tax - $shipping_including_tax;
			$subtotal_tax           = $total_tax - $shipping_tax;
			$subtotal_excluding_tax = $subtotal_including_tax - $subtotal_tax;

			$totals = array(
				'subtotal_price_excluding_tax' => max( 0.0, $subtotal_excluding_tax ),
				'subtotal_price_including_tax' => max( 0.0, $subtotal_including_tax ),
				'subtotal_tax_amount'          => max( 0.0, $subtotal_tax ),
				'shipping_price_excluding_tax' => max( 0.0, $shipping_excluding_tax ),
				'shipping_price_including_tax' => max( 0.0, $shipping_including_tax ),
				'shipping_tax_amount'          => max( 0.0, $shipping_tax ),
				'total_price_excluding_tax'    => max( 0.0, $total_excluding_tax ),
				'total_price_including_tax'    => max( 0.0, $total_including_tax ),
				'total_tax_amount'             => max( 0.0, $total_tax ),
				'currency'                     => $this->order->get_currency(),
			);

			/*
			 * Filter the totals section of the booking request. The filter
			 * runs after the clamping rule, so returned values are used
			 * as-is.
			 *
			 * @param array    $totals The order totals (see the return doc above).
			 * @param WC_Order $order  The WooCommerce order.
			 */
			return apply_filters( 'smart_send_payload_totals', $totals, $this->order );
		}

		/**
		 * Get the order note to print as freetext on the shipping label, if
		 * the "include order comment" setting is enabled.
		 *
		 * @return string|null
		 */
		public function get_order_note() {
			$ss_settings = SS_SHIPPING_WC()->get_ss_shipping_settings();

			$order_note = null;
			if ( 'yes' === $ss_settings['include_order_comment'] ) {
				$order_note = $this->order->get_customer_note();
			}

			/*
			 * Filter the order note which can be printed as freetext on the shipping label.
			 *
			 * @param string   $order_note The customer note of the order.
			 * @param WC_Order $order      The WooCommerce order.
			 */
			return apply_filters( 'smart_send_order_note', $order_note, $this->order );
		}

		/**
		 * Normalize a receiver phone number.
		 *
		 * Deliberately minimal for now: strips surrounding whitespace and
		 * turns empty values into null. This is the seam where local phone
		 * numbers will be converted to an international format (#56); use
		 * the smart_send_receiver_phone filter to adjust the number until
		 * then.
		 *
		 * @param string|null $phone The raw phone number.
		 *
		 * @return string|null
		 */
		protected function normalize_phone( $phone ) {
			if ( ! $phone ) {
				return null;
			}

			$phone = trim( $phone );

			if ( '' === $phone ) {
				return null;
			}

			return $phone;
		}

		/**
		 * Read a Smart Send product meta value through the product CRUD
		 * layer, variation-aware: the variation's own value wins, falling
		 * back to the parent product when the variation has none.
		 *
		 * @param WC_Product $product           The (parent) product of the order line.
		 * @param WC_Product $product_variation The variation, or the product itself for simple products.
		 * @param string     $meta_key          Meta key to read.
		 *
		 * @return mixed The meta value, or an empty string when not set.
		 */
		protected function get_product_meta( $product, $product_variation, $meta_key ) {
			if ( $product_variation && $product_variation->get_id() !== $product->get_id() ) {
				$value = $product_variation->get_meta( $meta_key, true );

				if ( '' !== $value && null !== $value ) {
					return $value;
				}
			}

			return $product->get_meta( $meta_key, true );
		}
	}

endif;
