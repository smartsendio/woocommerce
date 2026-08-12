<?php

namespace Smartsend\Models\Agent;

class Coordinates implements \JsonSerializable
{

    private ?float $latitude = null;
    private ?float $longitude = null;

    /**
     * @return float|null
     */
    public function getLatitude(): ?float
    {
        return $this->latitude;
    }

    /**
     * @param mixed $latitude
     * @return self
     */
    public function setLatitude($latitude): self
    {
        $this->latitude = is_null($latitude) ? null : ((float) $latitude);
        return $this;
    }

    /**
     * @return float|null
     */
    public function getLongitude(): ?float
    {
        return $this->longitude;
    }

    /**
     * @param mixed $longitude
     * @return self
     */
    public function setLongitude($longitude): self
    {
        $this->longitude = is_null($longitude) ? null : ((float) $longitude);
        return $this;
    }

    public function jsonSerialize()
    {
        $vars = get_object_vars($this);
        return $vars;
    }
}