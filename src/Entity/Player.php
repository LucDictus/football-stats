<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * A player's stable identity. Never deleted or recreated by imports: sources are mapped
 * onto it through PlayerIdentifier, so the id stays the same across re-imports.
 */
#[ORM\Entity]
class Player
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        #[ORM\Column(length: 150)]
        private string $name,
        #[ORM\Column(nullable: true)]
        private ?int $birthYear = null,
        /** As given by the source, e.g. "ESP", "BRA". */
        #[ORM\Column(length: 10, nullable: true)]
        private ?string $nationality = null,
    ) {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getBirthYear(): ?int
    {
        return $this->birthYear;
    }

    public function getNationality(): ?string
    {
        return $this->nationality;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** Fill in details a richer source knows (a CL-only player later found in league data). */
    public function enrich(string $name, ?int $birthYear, ?string $nationality): void
    {
        $this->name = $name;
        $this->birthYear ??= $birthYear;
        $this->nationality ??= $nationality;
    }
}
