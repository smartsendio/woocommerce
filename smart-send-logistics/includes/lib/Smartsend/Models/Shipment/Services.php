<?php

namespace Smartsend\Models\Shipment;

class Services  implements \JsonSerializable
{
    private ?string $email_notification = null;
    private ?string $sms_notification = null;
    // Untyped: never assigned anywhere in the plugin (always null in the
    // payload golden tests) or by any test, so the real API contract for
    // this field (bool flag vs. a delivery-window string) can't be
    // confirmed from usage. Guessing a scalar type risks silently coercing
    // a shape the API doesn't expect.
    private $flex_delivery;

    public function __construct(Array $services=array())
    {
        if (isset($services['email_notification'])) {
            $this->setEmailNotification($services['email_notification']);
        }

        if (isset($services['sms_notification'])) {
            $this->setSmsNotification($services['sms_notification']);
        }

        if (isset($services['flex_delivery'])) {
            $this->setFlexDelivery($services['flex_delivery']);
        }
    }

    /**
     * @return string|null
     */
    public function getEmailNotification(): ?string
    {
        return $this->email_notification;
    }

    /**
     * @param string|null $email_notification
     * @return self
     */
    public function setEmailNotification(?string $email_notification): self
    {
        $this->email_notification = $email_notification;
        return $this;
    }

    /**
     * @return string|null
     */
    public function getSmsNotification(): ?string
    {
        return $this->sms_notification;
    }

    /**
     * @param string|null $sms_notification
     * @return self
     */
    public function setSmsNotification(?string $sms_notification): self
    {
        $this->sms_notification = $sms_notification;
        return $this;
    }

    /**
     * @return mixed
     */
    public function getFlexDelivery()
    {
        return $this->flex_delivery;
    }

    /**
     * @param mixed $flex_delivery
     * @return self
     */
    public function setFlexDelivery($flex_delivery): self
    {
        $this->flex_delivery = $flex_delivery;
        return $this;
    }

    public function jsonSerialize()
    {
        $vars = get_object_vars($this);
        return $vars;
    }

}