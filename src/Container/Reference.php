<?php

declare(strict_types=1);

namespace Radix\Container;

final class Reference
{
    private string $id;

    public function __construct(string $id)
    {
        $this->setId($id); // Använd setter för validering
    }

    /**
     * Sätter referensens ID.
     *
     * @param string $id
     * @throws \InvalidArgumentException Om ID inte är giltigt.
     */
    public function setId(string $id): void
    {
        if (empty($id)) {
            throw new \InvalidArgumentException('Reference ID must be a non-empty string.');
        }

        $this->id = $id;
    }

    /**
     * Hämtar referensens ID.
     *
     * @return string
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * Representerar objektreferensen som en sträng.
     *
     * @return string
     */
    public function __toString(): string
    {
        return $this->id;
    }
}