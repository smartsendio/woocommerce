<?php
/**
 * WooCommerce Smart Send booked parcel value object.
 *
 * @package  SS_Shipping_Booked_Parcel
 * @category Shipping
 * @author   Smart Send
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// A second copy of the plugin may already have defined the class.
if ( ! class_exists( 'SS_Shipping_Booked_Parcel' ) ) :

	/**
	 * One parcel of a booked shipment (#177): its Smart Send parcel id
	 * and per-parcel tracking. Parcels carry tracking only - documents
	 * and codes are outputs of the whole shipment and live on
	 * SS_Shipping_Booked_Shipment.
	 *
	 * Serializable value object with no WordPress dependency.
	 */
	class SS_Shipping_Booked_Parcel {

		/**
		 * The Smart Send parcel id, when the API reports one.
		 *
		 * @var string|null
		 */
		protected ?string $parcel_id;

		/**
		 * The carrier tracking code of the parcel.
		 *
		 * @var string|null
		 */
		protected ?string $tracking_code;

		/**
		 * The carrier tracking URL of the parcel.
		 *
		 * @var string|null
		 */
		protected ?string $tracking_url;

		/**
		 * Constructor.
		 *
		 * @param string|null $parcel_id     The Smart Send parcel id.
		 * @param string|null $tracking_code The tracking code.
		 * @param string|null $tracking_url  The tracking URL.
		 */
		public function __construct( ?string $parcel_id, ?string $tracking_code, ?string $tracking_url ) {
			$this->parcel_id     = $parcel_id;
			$this->tracking_code = $tracking_code;
			$this->tracking_url  = $tracking_url;
		}

		/**
		 * Rebuild a parcel from its to_array() form.
		 *
		 * @param array $data The array produced by to_array().
		 *
		 * @return self
		 */
		public static function from_array( array $data ): self {
			return new self(
				isset( $data['parcel_id'] ) ? (string) $data['parcel_id'] : null,
				isset( $data['tracking_code'] ) ? (string) $data['tracking_code'] : null,
				isset( $data['tracking_url'] ) ? (string) $data['tracking_url'] : null
			);
		}

		/**
		 * The serializable array form.
		 *
		 * @return array
		 */
		public function to_array(): array {
			return array(
				'parcel_id'     => $this->parcel_id,
				'tracking_code' => $this->tracking_code,
				'tracking_url'  => $this->tracking_url,
			);
		}

		/**
		 * The Smart Send parcel id, when reported.
		 *
		 * @return string|null
		 */
		public function get_parcel_id(): ?string {
			return $this->parcel_id;
		}

		/**
		 * The tracking code.
		 *
		 * @return string|null
		 */
		public function get_tracking_code(): ?string {
			return $this->tracking_code;
		}

		/**
		 * The tracking URL.
		 *
		 * @return string|null
		 */
		public function get_tracking_url(): ?string {
			return $this->tracking_url;
		}
	}

endif;
