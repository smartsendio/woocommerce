<?php
/**
 * WooCommerce Smart Send booked shipment document value object.
 *
 * @package  SS_Shipping_Shipment_Document
 * @category Shipping
 * @author   Smart Send
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// A second copy of the plugin may already have defined the class.
if ( ! class_exists( 'SS_Shipping_Shipment_Document' ) ) :

	/**
	 * One document a booking produced: something to print or download
	 * (#177). Documents live on the SS_Shipping_Booked_Shipment, never on
	 * a parcel - one shipment with three parcels may yield one combined
	 * PDF, and the document count is independent of the parcel count.
	 *
	 * The type/format/layout/url fields mirror the API v2 shipment
	 * document shape (smartsendio/dumbledore#2099); API v1 only ever
	 * produces one "label" document in "pdf" format. local_path/local_url
	 * are the WooCommerce-side copy saved in the uploads folder when the
	 * "save shipping labels in uploads" setting is enabled - they are set
	 * by the fulfillment workflow, not by the booking.
	 *
	 * Serializable value object with no WordPress dependency (Phase 7
	 * queues booked shipments). The one exception is inline_content, the
	 * API v1 bridge: v1 delivers the label PDF base64-encoded inline next
	 * to its URL, and the uploads copy is written from those bytes. The
	 * bytes are transient - excluded from to_array() and from PHP
	 * serialization - and gone once API v2 delivers a fetchable URL only.
	 */
	class SS_Shipping_Shipment_Document {

		const TYPE_LABEL               = 'label';
		const TYPE_CUSTOMS_DECLARATION = 'customs_declaration';
		const TYPE_COMMERCIAL_INVOICE  = 'commercial_invoice';
		const TYPE_PACKING_LIST        = 'packing_list';

		const FORMAT_PDF = 'pdf';
		const FORMAT_ZPL = 'zpl';
		const FORMAT_PNG = 'png';

		/**
		 * Document type - one of the TYPE_* constants.
		 *
		 * @var string
		 */
		protected string $type;

		/**
		 * File format - one of the FORMAT_* constants.
		 *
		 * @var string
		 */
		protected string $format;

		/**
		 * Paper layout (e.g. "A4", "100x150mm"), when the API reports one.
		 *
		 * @var string|null
		 */
		protected ?string $layout;

		/**
		 * Download URL at Smart Send.
		 *
		 * @var string
		 */
		protected string $url;

		/**
		 * Absolute filesystem path of the copy saved in the uploads
		 * folder, when one was stored.
		 *
		 * @var string|null
		 */
		protected ?string $local_path = null;

		/**
		 * Public URL of the copy saved in the uploads folder, when one was
		 * stored.
		 *
		 * @var string|null
		 */
		protected ?string $local_url = null;

		/**
		 * The document bytes, base64-encoded, when the API delivered them
		 * inline (API v1 bridge - see the class docblock). Never
		 * serialized.
		 *
		 * @var string|null
		 */
		protected ?string $inline_content = null;

		/**
		 * Constructor.
		 *
		 * @param string      $type   Document type (TYPE_* constant).
		 * @param string      $format File format (FORMAT_* constant).
		 * @param string      $url    Download URL at Smart Send.
		 * @param string|null $layout Paper layout, when known.
		 */
		public function __construct( string $type, string $format, string $url, ?string $layout = null ) {
			$this->type   = $type;
			$this->format = $format;
			$this->url    = $url;
			$this->layout = $layout;
		}

		/**
		 * Rebuild a document from its to_array() form.
		 *
		 * @param array $data The array produced by to_array().
		 *
		 * @return self
		 */
		public static function from_array( array $data ): self {
			$document = new self(
				(string) ( $data['type'] ?? self::TYPE_LABEL ),
				(string) ( $data['format'] ?? self::FORMAT_PDF ),
				(string) ( $data['url'] ?? '' ),
				isset( $data['layout'] ) ? (string) $data['layout'] : null
			);

			if ( isset( $data['local_path'] ) || isset( $data['local_url'] ) ) {
				$document->set_local_copy(
					isset( $data['local_path'] ) ? (string) $data['local_path'] : null,
					isset( $data['local_url'] ) ? (string) $data['local_url'] : null
				);
			}

			return $document;
		}

		/**
		 * Keep the inline document bytes out of PHP serialization: they
		 * are the transient v1 bridge, never persisted or queued.
		 *
		 * @return string[]
		 */
		public function __sleep() {
			return array( 'type', 'format', 'layout', 'url', 'local_path', 'local_url' );
		}

		/**
		 * The serializable array form (inline_content excluded).
		 *
		 * @return array
		 */
		public function to_array(): array {
			return array(
				'type'       => $this->type,
				'format'     => $this->format,
				'layout'     => $this->layout,
				'url'        => $this->url,
				'local_path' => $this->local_path,
				'local_url'  => $this->local_url,
			);
		}

		/**
		 * Document type (TYPE_* constant).
		 *
		 * @return string
		 */
		public function get_type(): string {
			return $this->type;
		}

		/**
		 * File format (FORMAT_* constant).
		 *
		 * @return string
		 */
		public function get_format(): string {
			return $this->format;
		}

		/**
		 * Paper layout, when known.
		 *
		 * @return string|null
		 */
		public function get_layout(): ?string {
			return $this->layout;
		}

		/**
		 * Download URL at Smart Send.
		 *
		 * @return string
		 */
		public function get_url(): string {
			return $this->url;
		}

		/**
		 * Record the copy saved in the uploads folder.
		 *
		 * @param string|null $local_path Absolute filesystem path of the copy.
		 * @param string|null $local_url  Public URL of the copy.
		 *
		 * @return self
		 */
		public function set_local_copy( ?string $local_path, ?string $local_url ): self {
			$this->local_path = $local_path;
			$this->local_url  = $local_url;

			return $this;
		}

		/**
		 * Absolute filesystem path of the uploads copy, when one was stored.
		 *
		 * @return string|null
		 */
		public function get_local_path(): ?string {
			return $this->local_path;
		}

		/**
		 * Public URL of the uploads copy, when one was stored.
		 *
		 * @return string|null
		 */
		public function get_local_url(): ?string {
			return $this->local_url;
		}

		/**
		 * Record the document bytes the API delivered inline (base64),
		 * the API v1 bridge the uploads copy is written from.
		 *
		 * @param string|null $inline_content The base64-encoded bytes, or null.
		 *
		 * @return self
		 */
		public function set_inline_content( ?string $inline_content ): self {
			$this->inline_content = $inline_content;

			return $this;
		}

		/**
		 * The base64-encoded document bytes, when the API delivered them
		 * inline (API v1 only). Null after serialization.
		 *
		 * @return string|null
		 */
		public function get_inline_content(): ?string {
			return $this->inline_content;
		}

		/**
		 * Whether a copy was saved in the uploads folder.
		 *
		 * @return boolean
		 */
		public function has_local_copy(): bool {
			return null !== $this->local_url;
		}

		/**
		 * The URL to hand a merchant for downloading: the uploads copy
		 * when one was stored, the Smart Send URL otherwise.
		 *
		 * @return string
		 */
		public function download_url(): string {
			return null === $this->local_url ? $this->url : $this->local_url;
		}
	}

endif;
