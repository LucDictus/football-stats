<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class Season
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        /** e.g. "2025/26" */
        #[ORM\Column(length: 9, unique: true)]
        private string $label,
        /** Assessment period: performances between these dates count. */
        #[ORM\Column(type: Types::DATE_IMMUTABLE)]
        private \DateTimeImmutable $startDate,
        #[ORM\Column(type: Types::DATE_IMMUTABLE)]
        private \DateTimeImmutable $endDate,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getStartDate(): \DateTimeImmutable
    {
        return $this->startDate;
    }

    public function getEndDate(): \DateTimeImmutable
    {
        return $this->endDate;
    }

    public function setPeriod(\DateTimeImmutable $startDate, \DateTimeImmutable $endDate): void
    {
        $this->startDate = $startDate;
        $this->endDate = $endDate;
    }
}
