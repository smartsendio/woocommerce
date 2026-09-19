<?php

namespace Smart_Send\API\Models\Shipment;

class Services implements \JsonSerializable {

	private ?string $email_notification = null;
	private ?string $sms_notification   = null;
	// Untyped: never assigned anywhere in the plugin (always null in the
	// payload golden tests) or by any test, so the real API contract for
	// this field (bool flag vs. a delivery-window string) can't be
	// confirmed from usage. Guessing a scalar type risks silently coercing
	// a shape the API doesn't expect.
	private $flex_delivery;

	public function __construct( array $services = array() ) {
		if ( isset( $services['email_notification'] ) ) {
			$this->set_email_notification( $services['email_notification'] );
		}

		if ( isset( $services['sms_notification'] ) ) {
			$this->set_sms_notification( $services['sms_notification'] );
		}

		if ( isset( $services['flex_delivery'] ) ) {
			$this->set_flex_delivery( $services['flex_delivery'] );
		}
	}

	/**
	 * @return string|null
	 */
	public function get_email_notification(): ?string {
		return $this->email_notification;
	}

	/**
	 * @param string|null $email_notification
	 * @return self
	 */
	public function set_email_notification( ?string $email_notification ): self {
		$this->email_notification = $email_notification;
		return $this;
	}

	/**
	 * @return string|null
	 */
	public function get_sms_notification(): ?string {
		return $this->sms_notification;
	}

	/**
	 * @param string|null $sms_notification
	 * @return self
	 */
	public function set_sms_notification( ?string $sms_notification ): self {
		$this->sms_notification = $sms_notification;
		return $this;
	}

	/**
	 * @return mixed
	 */
	public function get_flex_delivery() {
		return $this->flex_delivery;
	}

	/**
	 * @param mixed $flex_delivery
	 * @return self
	 */
	public function set_flex_delivery( $flex_delivery ): self {
		$this->flex_delivery = $flex_delivery;
		return $this;
	}

	public function jsonSerialize(): array {
		$vars = get_object_vars( $this );
		return $vars;
	}
}
