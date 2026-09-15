<?php
/**
 * WooCommerce Smart Send booked shipment code value object.
 *
 * @package  SS_Shipping_Shipment_Code
 * @category Shipping
 * @author   Smart Send
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// A second copy of the plugin may already have defined the class.
if ( ! class_exists( 'SS_Shipping_Shipment_Code' ) ) :

	/**
	 * One code a booking produced: something to show or type at the
	 * parcel shop, e.g. a QR code for paperless handover or a label code
	 * the shop prints the label from (#177). Codes live on the
	 * SS_Shipping_Booked_Shipment, never on a parcel.
	 *
	 * Mirrors the API v2 shipment code shape (smartsendio/dumbledore#2099).
	 * API v1 never produces codes; the list exists so listeners written
	 * against 9.0.0 keep working unchanged when API v2 starts delivering
	 * them.
	 *
	 * Serializable value object with no WordPress dependency.
	 */
	class SS_Shipping_Shipment_Code {

		const TYPE_QR_CODE    = 'qr_code';
		const TYPE_LABEL_CODE = 'label_code';

		/**
		 * Code type - one of the TYPE_* constants.
		 *
		 * @var string
		 */
		protected string $type;

		/**
		 * The code value (what is shown or typed).
		 *
		 * @var string
		 */
		protected string $value;

		/**
		 * URL of a rendered image of the code (e.g. the QR image), when
		 * the API provides one.
		 *
		 * @var string|null
		 */
		protected ?string $image_url;

		/**
		 * When the code stops working, ISO 8601, when the API reports it.
		 *
		 * @var string|null
		 */
		protected ?string $expires_at;

		/**
		 * Customer-facing instructions ("Show this code at the parcel
		 * shop"), when the API provides them.
		 *
		 * @var string|null
		 */
		protected ?string $instructions;

		/**
		 * Constructor.
		 *
		 * @param string      $type         Code type (TYPE_* constant).
		 * @param string      $value        The code value.
		 * @param string|null $image_url    URL of a rendered image of the code.
		 * @param string|null $expires_at   Expiry, ISO 8601.
		 * @param string|null $instructions Customer-facing instructions.
		 */
		public function __construct( string $type, string $value, ?string $image_url = null, ?string $expires_at = null, ?string $instructions = null ) {
			$this->type         = $type;
			$this->value        = $value;
			$this->image_url    = $image_url;
			$this->expires_at   = $expires_at;
			$this->instructions = $instructions;
		}

		/**
		 * Rebuild a code from its to_array() form.
		 *
		 * @param array $data The array produced by to_array().
		 *
		 * @return self
		 */
		public static function from_array( array $data ): self {
			return new self(
				(string) ( $data['type'] ?? self::TYPE_QR_CODE ),
				(string) ( $data['value'] ?? '' ),
				isset( $data['image_url'] ) ? (string) $data['image_url'] : null,
				isset( $data['expires_at'] ) ? (string) $data['expires_at'] : null,
				isset( $data['instructions'] ) ? (string) $data['instructions'] : null
			);
		}

		/**
		 * The serializable array form.
		 *
		 * @return array
		 */
		public function to_array(): array {
			return array(
				'type'         => $this->type,
				'value'        => $this->value,
				'image_url'    => $this->image_url,
				'expires_at'   => $this->expires_at,
				'instructions' => $this->instructions,
			);
		}

		/**
		 * Code type (TYPE_* constant).
		 *
		 * @return string
		 */
		public function get_type(): string {
			return $this->type;
		}

		/**
		 * The code value.
		 *
		 * @return string
		 */
		public function get_value(): string {
			return $this->value;
		}

		/**
		 * URL of a rendered image of the code, when provided.
		 *
		 * @return string|null
		 */
		public function get_image_url(): ?string {
			return $this->image_url;
		}

		/**
		 * Expiry, ISO 8601, when reported.
		 *
		 * @return string|null
		 */
		public function get_expires_at(): ?string {
			return $this->expires_at;
		}

		/**
		 * Customer-facing instructions, when provided.
		 *
		 * @return string|null
		 */
		public function get_instructions(): ?string {
			return $this->instructions;
		}
	}

endif;
