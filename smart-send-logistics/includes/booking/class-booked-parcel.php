<?php
/**
 * WooCommerce Smart Send booked parcel value object.
 *
 * @package Smart_Send
 * @category Shipping
 * @author   Smart Send
 */

namespace Smart_Send\Booking;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * One parcel of a booked shipment (#177): its Smart Send parcel id,
 * per-parcel tracking and the measures it was booked with (weight,
 * dimensions and the parcel reference - carried over from the request
 * parcel, Parcel, since the API echoes none of them back;
 * see Booking_Service::map_v1_response()). Parcels carry
 * tracking only - documents and codes are outputs of the whole
 * shipment and live on Booked_Shipment.
 *
 * Serializable value object with no WordPress dependency.
 */
class Booked_Parcel {

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
	 * The booked weight in kg, when known.
	 *
	 * @var float|null
	 */
	protected ?float $weight;

	/**
	 * The booked length in cm, when known.
	 *
	 * @var float|null
	 */
	protected ?float $length;

	/**
	 * The booked width in cm, when known.
	 *
	 * @var float|null
	 */
	protected ?float $width;

	/**
	 * The booked height in cm, when known.
	 *
	 * @var float|null
	 */
	protected ?float $height;

	/**
	 * The parcel reference (the request parcel's internal reference).
	 *
	 * @var string|null
	 */
	protected ?string $reference;

	/**
	 * Constructor.
	 *
	 * @param string|null $parcel_id     The Smart Send parcel id.
	 * @param string|null $tracking_code The tracking code.
	 * @param string|null $tracking_url  The tracking URL.
	 * @param float|null  $weight        The booked weight in kg.
	 * @param float|null  $length        The booked length in cm.
	 * @param float|null  $width         The booked width in cm.
	 * @param float|null  $height        The booked height in cm.
	 * @param string|null $reference     The parcel reference.
	 */
	public function __construct( ?string $parcel_id, ?string $tracking_code, ?string $tracking_url, ?float $weight = null, ?float $length = null, ?float $width = null, ?float $height = null, ?string $reference = null ) {
		$this->parcel_id     = $parcel_id;
		$this->tracking_code = $tracking_code;
		$this->tracking_url  = $tracking_url;
		$this->weight        = $weight;
		$this->length        = $length;
		$this->width         = $width;
		$this->height        = $height;
		$this->reference     = $reference;
	}

	/**
	 * Rebuild a parcel from its to_array() form.
	 *
	 * @param array $data The array produced by to_array().
	 *
	 * @return self
	 */
	public static function from_array( array $data ): self {
		$number = static function ( $value ): ?float {
			return null === $value || '' === $value ? null : (float) $value;
		};

		return new self(
			isset( $data['parcel_id'] ) ? (string) $data['parcel_id'] : null,
			isset( $data['tracking_code'] ) ? (string) $data['tracking_code'] : null,
			isset( $data['tracking_url'] ) ? (string) $data['tracking_url'] : null,
			$number( isset( $data['weight'] ) ? $data['weight'] : null ),
			$number( isset( $data['length'] ) ? $data['length'] : null ),
			$number( isset( $data['width'] ) ? $data['width'] : null ),
			$number( isset( $data['height'] ) ? $data['height'] : null ),
			isset( $data['reference'] ) && null !== $data['reference'] ? (string) $data['reference'] : null
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
			'weight'        => $this->weight,
			'length'        => $this->length,
			'width'         => $this->width,
			'height'        => $this->height,
			'reference'     => $this->reference,
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

	/**
	 * The booked weight in kg, when known.
	 *
	 * @return float|null
	 */
	public function get_weight(): ?float {
		return $this->weight;
	}

	/**
	 * The booked length in cm, when known.
	 *
	 * @return float|null
	 */
	public function get_length(): ?float {
		return $this->length;
	}

	/**
	 * The booked width in cm, when known.
	 *
	 * @return float|null
	 */
	public function get_width(): ?float {
		return $this->width;
	}

	/**
	 * The booked height in cm, when known.
	 *
	 * @return float|null
	 */
	public function get_height(): ?float {
		return $this->height;
	}

	/**
	 * Whether the parcel carries all three dimensions.
	 *
	 * @return boolean
	 */
	public function has_dimensions(): bool {
		return null !== $this->length && null !== $this->width && null !== $this->height;
	}

	/**
	 * The parcel reference, when set.
	 *
	 * @return string|null
	 */
	public function get_reference(): ?string {
		return $this->reference;
	}
}
