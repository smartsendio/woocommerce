<?php
/**
 * WooCommerce Smart Send pickup point not found exception.
 *
 * @package Smart_Send
 * @category Shipping
 * @author   Smart Send
 */

namespace Smart_Send\Delivery_Options\Exceptions;

use Smart_Send\Delivery_Options\Pickup_Point_Lookup;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * An agent number could not be resolved into a pickup point (#182).
 *
 * Thrown by Pickup_Point_Lookup::find_by_agent_no() when
 * the Smart Send API knows no pickup point with that number for the
 * carrier and country (the API client exception is the previous
 * exception, when there is one). Callers decide what the miss means:
 * the Custom Fields validator rejects the edit, the fulfillment
 * service fails the leg with a structured error, and the order meta
 * box lookup route (#182 PR 2) answers 422.
 */
class Pickup_Point_Not_Found_Exception extends \Exception {

	/**
	 * The carrier code the lookup ran for.
	 *
	 * @var string
	 */
	protected string $carrier;

	/**
	 * The agent number that was not found.
	 *
	 * @var string
	 */
	protected string $agent_no;

	/**
	 * Constructor.
	 *
	 * @param string          $carrier  The carrier code the lookup ran for.
	 * @param string          $agent_no The agent number that was not found.
	 * @param \Throwable|null $previous The API client exception, when there is one.
	 */
	public function __construct( string $carrier, string $agent_no, ?\Throwable $previous = null ) {
		parent::__construct(
			sprintf(
				/* translators: %s: the pickup point agent number that was entered. */
				__( 'The agent number entered, %s, was not found.', 'smart-send-logistics' ),
				$agent_no
			),
			0,
			$previous
		);

		$this->carrier  = $carrier;
		$this->agent_no = $agent_no;
	}

	/**
	 * The carrier code the lookup ran for.
	 *
	 * @return string
	 */
	public function carrier(): string {
		return $this->carrier;
	}

	/**
	 * The agent number that was not found.
	 *
	 * @return string
	 */
	public function agent_no(): string {
		return $this->agent_no;
	}
}
