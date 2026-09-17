<?php
/**
 * WooCommerce Smart Send fulfillment outcome.
 *
 * @package Smart_Send
 * @category Shipping
 * @author   Smart Send
 */

namespace Smart_Send\Fulfillment;

use Smart_Send\Booking\Booked_Shipment;
use Smart_Send\Booking\Exceptions\Booking_Exception;
use WC_Order;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * The outcome of a Fulfillment_Service::fulfill_outbound()/
 * fulfill_return() run (#177): per attempted shipment (outbound first,
 * then the auto-generated return label) either the booked
 * Booked_Shipment with the WooCommerce-side outcomes the
 * workflow produced for it - the order note HTML (get_order_note())
 * and its comment id (get_order_note_id()), which side-effect steps
 * ran (get_steps()) and
 * any warnings (get_warnings(), e.g. the uploads copy could not be
 * saved - the shipment is booked and fulfilled regardless) - or the
 * failure as structured data: the plain message, the per-field
 * validation errors and the API Response-ID from the
 * Booking_Exception, plus the merchant-facing HTML
 * rendering (get_error_details(), get_outbound_error()). No exception
 * object is kept. The uploads copy of a label lives on the shipment's
 * label document (local_path/local_url).
 *
 * This is the second argument of smart_send_order_fulfilled, so a
 * listener sees the whole run - both labels when a return label was
 * auto-generated, and a failed return leg next to a booked outbound.
 *
 * Deliberately holds NO live WC_Order reference so the result stays
 * serializable for the Phase 7 async processing (#116).
 *
 * Each run entry is a plain array, one per attempted shipment in run
 * order: array( 'is_return' => bool, 'message' => string, 'error' =>
 * string (HTML), 'errors' => array, 'response_id' => string|null ) for
 * a failed one, or array( 'is_return' => bool, 'shipment' =>
 * Booked_Shipment, 'order_note' => string, 'note_id' =>
 * int|null, 'steps' => array, 'warnings' => string[] ) for a booked
 * one. to_array() is the canonical JSON form (#182, the fulfillment
 * REST response's shipments[]).
 */
class Fulfillment_Result {

	/**
	 * The run entries, in the order the fulfillments ran (outbound
	 * first, then the auto-generated return label).
	 *
	 * @var array[]
	 */
	protected array $entries;

	/**
	 * Constructor.
	 *
	 * @param array[] $entries The run entries (see the class docblock).
	 */
	public function __construct( array $entries ) {
		$this->entries = $entries;
	}

	/**
	 * A failed run entry: the failure as data, never an exception
	 * object (the result stays serializable).
	 *
	 * @param boolean     $is_return   Whether the failed shipment was a return shipment.
	 * @param string      $message     The plain failure message.
	 * @param string      $html        The merchant-facing rendering (HTML: message, field errors, Response ID); defaults to the message.
	 * @param array       $errors      Per-field validation errors from the API, field => list of messages.
	 * @param string|null $response_id The Smart Send API Response-ID of the failed request, for support reference.
	 *
	 * @return array
	 */
	public static function failed_entry( bool $is_return, string $message, string $html = '', array $errors = array(), ?string $response_id = null ): array {
		return array(
			'is_return'   => $is_return,
			'message'     => $message,
			'error'       => '' === $html ? $message : $html,
			'errors'      => $errors,
			'response_id' => $response_id,
		);
	}

	/**
	 * A fulfilled run entry.
	 *
	 * @param Booked_Shipment $shipment     The booked shipment.
	 * @param string                      $order_note The order note HTML (after the smart_send_fulfillment_order_note filter).
	 * @param array                       $steps      Which side-effect steps ran (see get_steps()).
	 * @param integer|null                $note_id    The comment id of the order note that was added, or null when none was.
	 * @param string[]                    $warnings   Warnings of side-effect steps that failed without un-fulfilling the shipment.
	 *
	 * @return array
	 */
	public static function fulfilled_entry( Booked_Shipment $shipment, string $order_note, array $steps, ?int $note_id = null, array $warnings = array() ): array {
		return array(
			'is_return'  => $shipment->is_return(),
			'shipment'   => $shipment,
			'order_note' => $order_note,
			'note_id'    => $note_id,
			'steps'      => $steps,
			'warnings'   => $warnings,
		);
	}

	/**
	 * Whether every attempted leg was booked and fulfilled. Warnings
	 * (see get_warning_messages()) do not make a run unsuccessful.
	 *
	 * @return boolean
	 */
	public function is_successful(): bool {
		foreach ( $this->entries as $entry ) {
			if ( isset( $entry['error'] ) ) {
				return false;
			}
		}

		return array() !== $this->entries;
	}

	/**
	 * Get every error message (merchant-facing HTML) collected during
	 * the run.
	 *
	 * @return string[]
	 */
	public function get_error_messages(): array {
		$messages = array();

		foreach ( $this->entries as $entry ) {
			if ( isset( $entry['error'] ) ) {
				$messages[] = $entry['error'];
			}
		}

		return $messages;
	}

	/**
	 * Get the first error message, or null when the run succeeded.
	 *
	 * @return string|null
	 */
	public function get_first_error_message(): ?string {
		$messages = $this->get_error_messages();

		return array() === $messages ? null : $messages[0];
	}

	/**
	 * Every warning collected during the run: side-effect steps that
	 * failed on a shipment that is nonetheless booked and fulfilled
	 * (e.g. the uploads copy could not be saved).
	 *
	 * @return string[]
	 */
	public function get_warning_messages(): array {
		$messages = array();

		foreach ( $this->entries as $entry ) {
			if ( isset( $entry['warnings'] ) ) {
				foreach ( $entry['warnings'] as $warning ) {
					$messages[] = $warning;
				}
			}
		}

		return $messages;
	}

	/**
	 * The error of the outbound leg (merchant-facing HTML), or null
	 * when it was not attempted or was fulfilled.
	 *
	 * @return string|null
	 */
	public function get_outbound_error(): ?string {
		return $this->find_error( false );
	}

	/**
	 * The error of the return leg (merchant-facing HTML), or null when
	 * it was not attempted or was fulfilled.
	 *
	 * @return string|null
	 */
	public function get_return_error(): ?string {
		return $this->find_error( true );
	}

	/**
	 * The per-field validation errors the API reported for a leg
	 * (field => list of messages), empty when the leg was not
	 * attempted, was fulfilled, or failed for another reason.
	 *
	 * @param boolean $is_return Which leg.
	 *
	 * @return array<string, string[]>
	 */
	public function get_validation_errors( bool $is_return ): array {
		$details = $this->get_error_details( $is_return );

		return null === $details ? array() : $details['fields'];
	}

	/**
	 * The structured failure of a leg: 'message' (plain text),
	 * 'response_id' (the API Response-ID, or null), 'fields' (the
	 * per-field validation errors, field => list of messages) and
	 * 'html' (the merchant-facing rendering). Null when the leg was
	 * not attempted or was fulfilled.
	 *
	 * @param boolean $is_return Which leg.
	 *
	 * @return array|null
	 */
	public function get_error_details( bool $is_return ): ?array {
		foreach ( $this->entries as $entry ) {
			if ( isset( $entry['error'] ) && $entry['is_return'] === $is_return ) {
				return self::error_details_of( $entry );
			}
		}

		return null;
	}

	/**
	 * Every shipment the run booked and fulfilled, in run order
	 * (outbound first, then the auto-generated return label). A
	 * side-effect step that failed after the booking (e.g. the uploads
	 * copy could not be saved) does not remove the shipment from this
	 * list - it is reported on get_warnings()/get_steps() instead.
	 *
	 * @return Booked_Shipment[]
	 */
	public function shipments(): array {
		$shipments = array();

		foreach ( $this->entries as $entry ) {
			if ( isset( $entry['shipment'] ) ) {
				$shipments[] = $entry['shipment'];
			}
		}

		return $shipments;
	}

	/**
	 * The fulfilled outbound shipment, if any.
	 *
	 * @return Booked_Shipment|null
	 */
	public function get_outbound_shipment(): ?Booked_Shipment {
		foreach ( $this->shipments() as $shipment ) {
			if ( ! $shipment->is_return() ) {
				return $shipment;
			}
		}

		return null;
	}

	/**
	 * The fulfilled return shipment, if any.
	 *
	 * @return Booked_Shipment|null
	 */
	public function get_return_shipment(): ?Booked_Shipment {
		foreach ( $this->shipments() as $shipment ) {
			if ( $shipment->is_return() ) {
				return $shipment;
			}
		}

		return null;
	}

	/**
	 * The HTML order note the workflow produced for a fulfilled
	 * shipment (label and document links, codes, tracking numbers),
	 * after the smart_send_fulfillment_order_note filter - an empty
	 * string when the filter suppressed the note. Null when the
	 * shipment is not part of this run.
	 *
	 * @param Booked_Shipment $shipment One of shipments().
	 *
	 * @return string|null
	 */
	public function get_order_note( Booked_Shipment $shipment ): ?string {
		$entry = $this->find_entry( $shipment );

		return null === $entry ? null : $entry['order_note'];
	}

	/**
	 * The comment id of the order note added for a fulfilled shipment,
	 * or null when no note was added (the caller asked for none, or
	 * the smart_send_fulfillment_order_note filter suppressed it) or
	 * the shipment is not part of this run.
	 *
	 * @param Booked_Shipment $shipment One of shipments().
	 *
	 * @return integer|null
	 */
	public function get_order_note_id( Booked_Shipment $shipment ): ?int {
		$entry = $this->find_entry( $shipment );

		return null === $entry ? null : $entry['note_id'];
	}

	/**
	 * Which side-effect steps ran for a fulfilled shipment:
	 * 'save_documents' (true - a local copy of the documents was
	 * stored, false - not attempted, 'failed' - attempted but the copy
	 * could not be saved; see get_warnings()), 'order_note' (bool -
	 * the note was added to the order), 'tracking' (bool - tracking
	 * was pushed to Shipment Tracking) and 'order_status' (the status
	 * the order was set to, or false). Null when the shipment is not
	 * part of this run.
	 *
	 * @param Booked_Shipment $shipment One of shipments().
	 *
	 * @return array|null
	 */
	public function get_steps( Booked_Shipment $shipment ): ?array {
		$entry = $this->find_entry( $shipment );

		return null === $entry ? null : $entry['steps'];
	}

	/**
	 * The warnings of a fulfilled shipment: side-effect steps that
	 * failed without un-fulfilling it. Empty when everything ran, null
	 * when the shipment is not part of this run.
	 *
	 * @param Booked_Shipment $shipment One of shipments().
	 *
	 * @return string[]|null
	 */
	public function get_warnings( Booked_Shipment $shipment ): ?array {
		$entry = $this->find_entry( $shipment );

		return null === $entry ? null : $entry['warnings'];
	}

	/**
	 * The canonical array form of the run (#182): one row per
	 * attempted leg, in run order.
	 *
	 *   array( 'direction' => 'outbound'|'return', 'status' => 'fulfilled',
	 *          'shipment' => Booked_Shipment::to_array(),
	 *          'steps' => array( 'save_documents' => bool|'failed', 'order_note' => bool, 'tracking' => bool, 'order_status' => string|false ),
	 *          'order_note' => array( 'id' => int|null, 'html' => null ),
	 *          'warnings' => string[] )
	 *   array( 'direction' => 'outbound'|'return', 'status' => 'failed',
	 *          'error' => array( 'message' => string, 'response_id' => string|null, 'fields' => array, 'html' => string ) )
	 *
	 * order_note.html (the note rendered as WooCommerce's order-note
	 * list item) is filled by the REST controller (#182 PR 2); it is
	 * null here.
	 *
	 * @return array[]
	 */
	public function to_array(): array {
		$rows = array();

		foreach ( $this->entries as $entry ) {
			$direction = $entry['is_return'] ? 'return' : 'outbound';

			if ( isset( $entry['error'] ) ) {
				$rows[] = array(
					'direction' => $direction,
					'status'    => 'failed',
					'error'     => self::error_details_of( $entry ),
				);
				continue;
			}

			$rows[] = array(
				'direction'  => $direction,
				'status'     => 'fulfilled',
				'shipment'   => $entry['shipment']->to_array(),
				'steps'      => $entry['steps'],
				'order_note' => array(
					'id'   => $entry['note_id'],
					'html' => null,
				),
				'warnings'   => $entry['warnings'],
			);
		}

		return $rows;
	}

	/**
	 * The error message (HTML) of a leg, or null.
	 *
	 * @param boolean $is_return Which leg.
	 *
	 * @return string|null
	 */
	protected function find_error( bool $is_return ): ?string {
		$details = $this->get_error_details( $is_return );

		return null === $details ? null : $details['html'];
	}

	/**
	 * The structured error of a failed entry (see get_error_details()).
	 *
	 * @param array $entry A failed run entry.
	 *
	 * @return array
	 */
	protected static function error_details_of( array $entry ): array {
		return array(
			'message'     => isset( $entry['message'] ) ? $entry['message'] : $entry['error'],
			'response_id' => isset( $entry['response_id'] ) ? $entry['response_id'] : null,
			'fields'      => isset( $entry['errors'] ) ? $entry['errors'] : array(),
			'html'        => $entry['error'],
		);
	}

	/**
	 * The run entry of a shipment: matched by identity first (the
	 * instance the action fires with), then by shipment id and
	 * direction (e.g. after a serialization round trip).
	 *
	 * @param Booked_Shipment $shipment The shipment to look up.
	 *
	 * @return array|null
	 */
	protected function find_entry( Booked_Shipment $shipment ): ?array {
		foreach ( $this->entries as $entry ) {
			if ( isset( $entry['shipment'] ) && $entry['shipment'] === $shipment ) {
				return $entry;
			}
		}

		foreach ( $this->entries as $entry ) {
			if ( isset( $entry['shipment'] )
				&& $entry['shipment']->get_shipment_id() === $shipment->get_shipment_id()
				&& $entry['shipment']->is_return() === $shipment->is_return() ) {
				return $entry;
			}
		}

		return null;
	}
}
