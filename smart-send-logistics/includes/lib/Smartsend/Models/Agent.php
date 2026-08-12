<?php

namespace Smartsend\Models;

use Smartsend\Models\Agent\Coordinates;
use Smartsend\Models\Agent\OpeningHour;

require_once 'Agent/OpeningHour.php';
require_once 'Agent/Coordinates.php';

class Agent implements \JsonSerializable
{
    //private $id; Only returned by API
    //private $type='agent'; Only returned by API
    // Typed with "= null" so get_object_vars() serialization is unchanged.
    private ?string $agent_no = null;
    private ?string $carrier = null;
    private ?string $company = null;
    private ?string $name_line1 = null;
    private ?string $name_line2 = null;
    private ?string $address_line1 = null;
    private ?string $address_line2 = null;
    private ?string $postal_code = null;
    private ?string $city = null;
    private ?string $country = null;
    private ?Coordinates $coordinates = null;
    private ?array $opening_hours = null;

    /**
     * @return string|null
     */
    public function getAgentNo(): ?string
    {
        return $this->agent_no;
    }

    /**
     * @param string|null $agent_no
     * @return self
     */
    public function setAgentNo(?string $agent_no): self
    {
        $this->agent_no = $agent_no;
        return $this;
    }

    /**
     * @return string|null
     */
    public function getCarrier(): ?string
    {
        return $this->carrier;
    }

    /**
     * @param string|null $carrier
     * @return self
     */
    public function setCarrier(?string $carrier): self
    {
        $this->carrier = $carrier;
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
    public function getNameLine1(): ?string
    {
        return $this->name_line1;
    }

    /**
     * @param string|null $name_line1
     * @return self
     */
    public function setNameLine1(?string $name_line1): self
    {
        $this->name_line1 = $name_line1;
        return $this;
    }

    /**
     * @return string|null
     */
    public function getNameLine2(): ?string
    {
        return $this->name_line2;
    }

    /**
     * @param string|null $name_line2
     * @return self
     */
    public function setNameLine2(?string $name_line2): self
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
     * @return Coordinates|null
     */
    public function getCoordinates(): ?Coordinates
    {
        return $this->coordinates;
    }

    /**
     * @param Coordinates $coordinates
     * @return self
     */
    public function setCoordinates(Coordinates $coordinates): self
    {
        $this->coordinates = $coordinates;
        return $this;
    }

    /**
     * @return OpeningHour[]|null
     */
    public function getOpeningHours(): ?array
    {
        return $this->opening_hours;
    }

    /**
     * @param OpeningHour[]|null $opening_hours
     * @return self
     */
    public function setOpeningHours(?array $opening_hours): self
    {
        $this->opening_hours = $opening_hours;
        return $this;
    }

    /**
     * @param OpeningHour $opening_hour
     * @return self
     */
    public function addOpeningHours(OpeningHour $opening_hour): self
    {
        if (is_array($this->opening_hours)) {
            $this->opening_hours[] = $opening_hour;
        } else {
            $this->setOpeningHours(array($opening_hour));
        }

        return $this;
    }

    public function jsonSerialize()
    {
        $vars = get_object_vars($this);
        return $vars;
    }

}