<?php
/**
 * WooCommerce Smart Send booked shipment value object.
 *
 * @package  SS_Shipping_Booked_Shipment
 * @category Shipping
 * @author   Smart Send
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// A second copy of the plugin may already have defined the class.
if ( ! class_exists( 'SS_Shipping_Booked_Shipment' ) ) :

	/**
	 * What a booking produced (#177): the one typed representation of a
	 * booked shipment the plugin hands to its own workflow steps and to
	 * smart_send_shipment_booked listeners.
	 *
	 * Shaped for the concepts API v2 returns (smartsendio/dumbledore#2099)
	 * and populated from API v1 today by SS_Shipping_Booking_Service, so
	 * the public hook contract lands in 9.0.0 in its final form and the
	 * API v2 switch only replaces the mapper. Nothing API-version shaped
	 * is exposed here.
	 *
	 * Booking outputs live on the SHIPMENT, never on a parcel: one
	 * shipment with three parcels may yield one QR code or one combined
	 * PDF. documents() are things to print or download
	 * (SS_Shipping_Shipment_Document); codes() are things to show or
	 * type (SS_Shipping_Shipment_Code); parcels() carry per-parcel
	 * tracking only (SS_Shipping_Booked_Parcel). API v1 always produces
	 * exactly one "label" document in "pdf" format and no codes.
	 *
	 * Serializable value object with no WordPress dependency (Phase 7
	 * queues these): to_array()/from_array() round-trip every field.
	 */
	class SS_Shipping_Booked_Shipment {

		const STATE_BOOKED = 'booked';
		const STATE_QUEUED = 'queued';
		const STATE_FAILED = 'failed';

		/**
		 * The Smart Send shipment id.
		 *
		 * @var string
		 */
		protected string $shipment_id;

		/**
		 * The Smart Send carrier code (e.g. "postnord").
		 *
		 * @var string|null
		 */
		protected ?string $carrier = null;

		/**
		 * The Smart Send shipping method code the shipment was booked with
		 * (e.g. "agent", "returndropoff").
		 *
		 * @var string|null
		 */
		protected ?string $service_code = null;

		/**
		 * Whether the shipment is a return shipment.
		 *
		 * @var bool
		 */
		protected bool $is_return = false;

		/**
		 * The shipment state - one of the STATE_* constants. "booked"
		 * today; "queued" and "failed" are reserved for the Phase 7 async
		 * processing and API v2 operations.
		 *
		 * @var string
		 */
		protected string $state = self::STATE_BOOKED;

		/**
		 * When the shipment was booked, ISO 8601 (UTC).
		 *
		 * @var string|null
		 */
		protected ?string $booked_at = null;

		/**
		 * Shipment-level tracking code, when the carrier tracks the whole
		 * shipment under one code.
		 *
		 * @var string|null
		 */
		protected ?string $tracking_code = null;

		/**
		 * Shipment-level tracking URL.
		 *
		 * @var string|null
		 */
		protected ?string $tracking_url = null;

		/**
		 * The booked parcels, in order.
		 *
		 * @var SS_Shipping_Booked_Parcel[]
		 */
		protected array $parcels = array();

		/**
		 * The documents the booking produced, in order.
		 *
		 * @var SS_Shipping_Shipment_Document[]
		 */
		protected array $documents = array();

		/**
		 * The codes the booking produced, in order.
		 *
		 * @var SS_Shipping_Shipment_Code[]
		 */
		protected array $codes = array();

		/**
		 * Constructor.
		 *
		 * @param string $shipment_id The Smart Send shipment id.
		 */
		public function __construct( string $shipment_id ) {
			$this->shipment_id = $shipment_id;
		}

		/**
		 * Rebuild a booked shipment from its to_array() form.
		 *
		 * @param array $data The array produced by to_array().
		 *
		 * @return self
		 */
		public static function from_array( array $data ): self {
			$shipment = new self( (string) ( $data['shipment_id'] ?? '' ) );

			$shipment
				->set_carrier( isset( $data['carrier'] ) ? (string) $data['carrier'] : null )
				->set_service_code( isset( $data['service_code'] ) ? (string) $data['service_code'] : null )
				->set_is_return( ! empty( $data['is_return'] ) )
				->set_state( (string) ( $data['state'] ?? self::STATE_BOOKED ) )
				->set_booked_at( isset( $data['booked_at'] ) ? (string) $data['booked_at'] : null )
				->set_tracking(
					isset( $data['tracking_code'] ) ? (string) $data['tracking_code'] : null,
					isset( $data['tracking_url'] ) ? (string) $data['tracking_url'] : null
				);

			foreach ( (array) ( $data['parcels'] ?? array() ) as $parcel ) {
				$shipment->add_parcel( SS_Shipping_Booked_Parcel::from_array( (array) $parcel ) );
			}

			foreach ( (array) ( $data['documents'] ?? array() ) as $document ) {
				$shipment->add_document( SS_Shipping_Shipment_Document::from_array( (array) $document ) );
			}

			foreach ( (array) ( $data['codes'] ?? array() ) as $code ) {
				$shipment->add_code( SS_Shipping_Shipment_Code::from_array( (array) $code ) );
			}

			return $shipment;
		}

		/**
		 * The serializable array form.
		 *
		 * @return array
		 */
		public function to_array(): array {
			return array(
				'shipment_id'   => $this->shipment_id,
				'carrier'       => $this->carrier,
				'service_code'  => $this->service_code,
				'is_return'     => $this->is_return,
				'state'         => $this->state,
				'booked_at'     => $this->booked_at,
				'tracking_code' => $this->tracking_code,
				'tracking_url'  => $this->tracking_url,
				'parcels'       => array_map(
					static function ( SS_Shipping_Booked_Parcel $parcel ) {
						return $parcel->to_array();
					},
					$this->parcels
				),
				'documents'     => array_map(
					static function ( SS_Shipping_Shipment_Document $document ) {
						return $document->to_array();
					},
					$this->documents
				),
				'codes'         => array_map(
					static function ( SS_Shipping_Shipment_Code $code ) {
						return $code->to_array();
					},
					$this->codes
				),
			);
		}

		/**
		 * The Smart Send shipment id.
		 *
		 * @return string
		 */
		public function get_shipment_id(): string {
			return $this->shipment_id;
		}

		/**
		 * The Smart Send carrier code.
		 *
		 * @return string|null
		 */
		public function get_carrier(): ?string {
			return $this->carrier;
		}

		/**
		 * Set the Smart Send carrier code.
		 *
		 * @param string|null $carrier The carrier code.
		 *
		 * @return self
		 */
		public function set_carrier( ?string $carrier ): self {
			$this->carrier = $carrier;

			return $this;
		}

		/**
		 * The Smart Send shipping method code the shipment was booked with.
		 *
		 * @return string|null
		 */
		public function get_service_code(): ?string {
			return $this->service_code;
		}

		/**
		 * Set the shipping method code.
		 *
		 * @param string|null $service_code The shipping method code.
		 *
		 * @return self
		 */
		public function set_service_code( ?string $service_code ): self {
			$this->service_code = $service_code;

			return $this;
		}

		/**
		 * Whether the shipment is a return shipment.
		 *
		 * @return boolean
		 */
		public function is_return(): bool {
			return $this->is_return;
		}

		/**
		 * Flag the shipment as a return shipment.
		 *
		 * @param boolean $is_return Whether the shipment is a return shipment.
		 *
		 * @return self
		 */
		public function set_is_return( bool $is_return ): self {
			$this->is_return = $is_return;

			return $this;
		}

		/**
		 * The shipment state (STATE_* constant).
		 *
		 * @return string
		 */
		public function get_state(): string {
			return $this->state;
		}

		/**
		 * Set the shipment state.
		 *
		 * @param string $state A STATE_* constant.
		 *
		 * @return self
		 */
		public function set_state( string $state ): self {
			$this->state = $state;

			return $this;
		}

		/**
		 * When the shipment was booked, ISO 8601.
		 *
		 * @return string|null
		 */
		public function get_booked_at(): ?string {
			return $this->booked_at;
		}

		/**
		 * Set when the shipment was booked.
		 *
		 * @param string|null $booked_at ISO 8601 timestamp.
		 *
		 * @return self
		 */
		public function set_booked_at( ?string $booked_at ): self {
			$this->booked_at = $booked_at;

			return $this;
		}

		/**
		 * Shipment-level tracking code, when there is one.
		 *
		 * @return string|null
		 */
		public function get_tracking_code(): ?string {
			return $this->tracking_code;
		}

		/**
		 * Shipment-level tracking URL, when there is one.
		 *
		 * @return string|null
		 */
		public function get_tracking_url(): ?string {
			return $this->tracking_url;
		}

		/**
		 * Set the shipment-level tracking.
		 *
		 * @param string|null $tracking_code The tracking code.
		 * @param string|null $tracking_url  The tracking URL.
		 *
		 * @return self
		 */
		public function set_tracking( ?string $tracking_code, ?string $tracking_url ): self {
			$this->tracking_code = $tracking_code;
			$this->tracking_url  = $tracking_url;

			return $this;
		}

		/**
		 * The booked parcels, in order.
		 *
		 * @return SS_Shipping_Booked_Parcel[]
		 */
		public function parcels(): array {
			return $this->parcels;
		}

		/**
		 * Append a parcel.
		 *
		 * @param SS_Shipping_Booked_Parcel $parcel The parcel.
		 *
		 * @return self
		 */
		public function add_parcel( SS_Shipping_Booked_Parcel $parcel ): self {
			$this->parcels[] = $parcel;

			return $this;
		}

		/**
		 * The documents the booking produced, in order.
		 *
		 * @return SS_Shipping_Shipment_Document[]
		 */
		public function documents(): array {
			return $this->documents;
		}

		/**
		 * The documents of one type, in order.
		 *
		 * @param string $type A SS_Shipping_Shipment_Document::TYPE_* constant.
		 *
		 * @return SS_Shipping_Shipment_Document[]
		 */
		public function documents_of_type( string $type ): array {
			return array_values(
				array_filter(
					$this->documents,
					static function ( SS_Shipping_Shipment_Document $document ) use ( $type ) {
						return $document->get_type() === $type;
					}
				)
			);
		}

		/**
		 * The first "label" document, or null when the booking produced
		 * none (e.g. a paperless handover with a QR code only).
		 *
		 * @return SS_Shipping_Shipment_Document|null
		 */
		public function label_document(): ?SS_Shipping_Shipment_Document {
			$labels = $this->documents_of_type( SS_Shipping_Shipment_Document::TYPE_LABEL );

			return array() === $labels ? null : $labels[0];
		}

		/**
		 * Append a document.
		 *
		 * @param SS_Shipping_Shipment_Document $document The document.
		 *
		 * @return self
		 */
		public function add_document( SS_Shipping_Shipment_Document $document ): self {
			$this->documents[] = $document;

			return $this;
		}

		/**
		 * The codes the booking produced, in order.
		 *
		 * @return SS_Shipping_Shipment_Code[]
		 */
		public function codes(): array {
			return $this->codes;
		}

		/**
		 * Append a code.
		 *
		 * @param SS_Shipping_Shipment_Code $code The code.
		 *
		 * @return self
		 */
		public function add_code( SS_Shipping_Shipment_Code $code ): self {
			$this->codes[] = $code;

			return $this;
		}
	}

endif;
