<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class Competition
{
    public const TYPE_LEAGUE = 'league';
    public const TYPE_CUP = 'cup';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        /** Short code used everywhere: PL, PD, SA, BL1, FL1, CL. */
        #[ORM\Column(length: 10, unique: true)]
        private string $code,
        #[ORM\Column(length: 100)]
        private string $name,
        #[ORM\Column(length: 10)]
        private string $type,
        /** ISO 3166-1 alpha-3 country of a domestic league; null for international competitions. */
        #[ORM\Column(length: 3, nullable: true)]
        private ?string $country = null,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getCountry(): ?string
    {
        return $this->country;
    }
}
