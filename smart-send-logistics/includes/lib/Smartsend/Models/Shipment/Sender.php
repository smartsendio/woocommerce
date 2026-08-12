<?php

namespace Smartsend\Models\Shipment;


class Sender implements \JsonSerializable
{
    // Typed properties keep "= null" so get_object_vars() serialization is
    // unchanged.
    private ?string $internal_id = null;
    private ?string $internal_reference = null;
    private ?string $company = null;
    private ?string $name_line1 = null;
    private ?string $name_line2 = null;
    private ?string $address_line1 = null;
    private ?string $address_line2 = null;
    private ?string $postal_code = null;
    private ?string $city = null;
    private ?string $country = null;
    private ?string $sms = null;
    private ?string $email = null;

    public function __construct($receiver=array())
    {
        if (isset($receiver['internal_id'])) {
            $this->setInternalId($receiver['internal_id']);
        }

        if (isset($receiver['internal_reference'])) {
            $this->setInternalReference($receiver['internal_reference']);
        }

        if (isset($receiver['company'])) {
            $this->setCompany($receiver['company']);
        }

        if (isset($receiver['name_line1'])) {
            $this->setName1($receiver['name_line1']);
        }

        if (isset($receiver['name_line2'])) {
            $this->setName2($receiver['name_line2']);
        }

        if (isset($receiver['address_line1'])) {
            $this->setAddressLine1($receiver['address_line1']);
        }

        if (isset($receiver['address_line2'])) {
            $this->setAddressLine2($receiver['address_line2']);
        }

        if (isset($receiver['postal_code'])) {
            $this->setPostalCode($receiver['postal_code']);
        }

        if (isset($receiver['city'])) {
            $this->setCity($receiver['city']);
        }

        if (isset($receiver['country'])) {
            $this->setCountry($receiver['country']);
        }

        if (isset($receiver['sms'])) {
            $this->setSms($receiver['sms']);
        }

        if (isset($receiver['email'])) {
            $this->setEmail($receiver['email']);
        }
    }

    /**
     * @return string|null
     */
    public function getInternalId(): ?string
    {
        return $this->internal_id;
    }

    /**
     * @param string $internal_id
     * @return self
     */
    public function setInternalId($internal_id): self
    {
        $this->internal_id = (string) $internal_id;
        return $this;
    }

    /**
     * @return string|null
     */
    public function getInternalReference(): ?string
    {
        return $this->internal_reference;
    }

    /**
     * @param string $internal_reference
     * @return self
     */
    public function setInternalReference($internal_reference): self
    {
        $this->internal_reference = (string) $internal_reference;
        return $this;
    }

    /**
     * @return string|null
     */
    public function getCompany(): ?string
    {
        return $this->company;
    }

    /**
     * @param string|null $company
     * @return self
     */
    public function setCompany(?string $company): self
    {
        $this->company = $company;
        return $this;
    }

    /**
     * @return string|null
     */
    public function getName1(): ?string
    {
        return $this->name_line1;
    }

    /**
     * @param string|null $name_line1
     * @return self
     */
    public function setName1(?string $name_line1): self
    {
        $this->name_line1 = $name_line1;
        return $this;
    }

    /**
     * @return string|null
     */
    public function getName2(): ?string
    {
        return $this->name_line2;
    }

    /**
     * @param string|null $name_line2
     * @return self
     */
    public function setName2(?string $name_line2): self
    {
        $this->name_line2 = $name_line2;
        return $this;
    }

    /**
     * @return string|null
     */
    public function getAddressLine1(): ?string
    {
        return $this->address_line1;
    }

    /**
     * @param string|null $address_line1
     * @return self
     */
    public function setAddressLine1(?string $address_line1): self
    {
        $this->address_line1 = $address_line1;
        return $this;
    }

    /**
     * @return string|null
     */
    public function getAddressLine2(): ?string
    {
        return $this->address_line2;
    }

    /**
     * @param string|null $address_line2
     * @return self
     */
    public function setAddressLine2(?string $address_line2): self
    {
        $this->address_line2 = $address_line2;
        return $this;
    }

    /**
     * @return string|null
     */
    public function getPostalCode(): ?string
    {
        return $this->postal_code;
    }

    /**
     * @param string|null $postal_code
     * @return self
     */
    public function setPostalCode(?string $postal_code): self
    {
        $this->postal_code = $postal_code;
        return $this;
    }

    /**
     * @return string|null
     */
    public function getCity(): ?string
    {
        return $this->city;
    }

    /**
     * @param string|null $city
     * @return self
     */
    public function setCity(?string $city): self
    {
        $this->city = $city;
        return $this;
    }

    /**
     * @return string|null
     */
    public function getCountry(): ?string
    {
        return $this->country;
    }

    /**
     * @param string|null $country
     * @return self
     */
    public function setCountry(?string $country): self
    {
        $this->country = $country;
        return $this;
    }

    /**
     * @return string|null
     */
    public function getSms(): ?string
    {
        return $this->sms;
    }

    /**
     * @param string|null $sms
     * @return self
     */
    public function setSms(?string $sms): self
    {
        $this->sms = $sms;
        return $this;
    }

    /**
     * @return string|null
     */
    public function getEmail(): ?string
    {
        return $this->email;
    }

    /**
     * @param string|null $email
     * @return self
     */
    public function setEmail(?string $email): self
    {
        $this->email = $email;
        return $this;
    }

    public function jsonSerialize()
    {
        $vars = get_object_vars($this);

        return $vars;
    }

}