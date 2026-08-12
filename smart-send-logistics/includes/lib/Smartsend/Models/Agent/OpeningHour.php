<?php

namespace Smartsend\Models\Agent;

class OpeningHour implements \JsonSerializable
{
    private ?string $day = null;
    private ?string $opens = null;
    private ?string $closes = null;

    /**
     * @return string|null
     */
    public function getDay(): ?string
    {
        return $this->day;
    }

    /**
     * @param string|null $day
     * @return self
     */
    public function setDay(?string $day): self
    {
        $this->day = $day;
        return $this;
    }

    /**
     * @return string|null
     */
    public function getOpens(): ?string
    {
        return $this->opens;
    }

    /**
     * @param string|null $opens
     * @return self
     */
    public function setOpens(?string $opens): self
    {
        $this->opens = $opens;
        return $this;
    }

    /**
     * @return string|null
     */
    public function getCloses(): ?string
    {
        return $this->closes;
    }

    /**
     * @param string|null $closes
     * @return self
     */
    public function setCloses(?string $closes): self
    {
        $this->closes = $closes;
        return $this;
    }

    public function jsonSerialize()
    {
        $vars = get_object_vars($this);
        return $vars;
    }
}