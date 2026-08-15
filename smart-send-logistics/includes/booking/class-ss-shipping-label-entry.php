<?php
/**
 * WooCommerce Smart Send created-label entry.
 *
 * @package  SS_Shipping_Label_Entry
 * @category Shipping
 * @author   Smart Send
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// A second copy of the plugin may already have defined the class.
if ( ! class_exists( 'SS_Shipping_Label_Entry' ) ) :

	/**
	 * One created shipping label, as handed to
	 * smart_send_shipping_label_created listeners (#139): the WooCommerce
	 * presentation data (label URL, order note HTML, return flag) that
	 * used to be mutated INTO the raw API response object's ->woocommerce
	 * property now travels here, typed, alongside the pristine response.
	 */
	class SS_Shipping_Label_Entry {

		/**
		 * The WooCommerce order id.
		 *
		 * @var int
		 */
		protected int $order_id;

		/**
		 * Whether the label is a return label.
		 *
		 * @var bool
		 */
		protected bool $is_return;

		/**
		 * The label download URL: the uploads-folder copy when the
		 * save-labels-in-uploads setting is enabled, the Smart Send API
		 * link otherwise.
		 *
		 * @var string
		 */
		protected string $label_url;

		/**
		 * The HTML order note with the label link and tracking numbers.
		 *
		 * @var string
		 */
		protected string $order_note;

		/**
		 * The raw (unmutated) API booking response.
		 *
		 * @var object
		 */
		protected $response;

		/**
		 * Constructor.
		 *
		 * @param int     $order_id   The WooCommerce order id.
		 * @param boolean $is_return  Whether the label is a return label.
		 * @param string  $label_url  The label download URL.
		 * @param string  $order_note The HTML order note with label link and tracking.
		 * @param object  $response   The raw API booking response.
		 */
		public function __construct( $order_id, $is_return, $label_url, $order_note, $response ) {
			$this->order_id   = (int) $order_id;
			$this->is_return  = (bool) $is_return;
			$this->label_url  = (string) $label_url;
			$this->order_note = (string) $order_note;
			$this->response   = $response;
		}

		/**
		 * Get the WooCommerce order id.
		 *
		 * @return int
		 */
		public function get_order_id(): int {
			return $this->order_id;
		}

		/**
		 * Whether the label is a return label.
		 *
		 * @return boolean
		 */
		public function is_return(): bool {
			return $this->is_return;
		}

		/**
		 * Get the label download URL.
		 *
		 * @return string
		 */
		public function get_label_url(): string {
			return $this->label_url;
		}

		/**
		 * Get the HTML order note with label link and tracking numbers.
		 *
		 * @return string
		 */
		public function get_order_note(): string {
			return $this->order_note;
		}

		/**
		 * Get the raw (unmutated) API booking response.
		 *
		 * @return object
		 */
		public function get_response() {
			return $this->response;
		}
	}

endif;
