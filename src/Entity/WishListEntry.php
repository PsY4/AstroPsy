<?php

namespace App\Entity;

use App\Repository\WishListEntryRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: WishListEntryRepository::class)]
#[ORM\UniqueConstraint(columns: ['target_id'])]
class WishListEntry
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Target $target = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Setup $setup = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $raFraming = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $decFraming = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $rotationAngle = null;

    /** Array of filter positions (ints) selected for this target — null = all setup filters. */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $filtersSelected = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTarget(): ?Target
    {
        return $this->target;
    }

    public function setTarget(?Target $target): static
    {
        $this->target = $target;

        return $this;
    }

    public function getSetup(): ?Setup
    {
        return $this->setup;
    }

    public function setSetup(?Setup $setup): static
    {
        $this->setup = $setup;

        return $this;
    }

    public function getRaFraming(): ?float
    {
        return $this->raFraming;
    }

    public function setRaFraming(?float $raFraming): static
    {
        $this->raFraming = $raFraming;

        return $this;
    }

    public function getDecFraming(): ?float
    {
        return $this->decFraming;
    }

    public function setDecFraming(?float $decFraming): static
    {
        $this->decFraming = $decFraming;

        return $this;
    }

    public function getRotationAngle(): ?float
    {
        return $this->rotationAngle;
    }

    public function setRotationAngle(?float $rotationAngle): static
    {
        $this->rotationAngle = $rotationAngle;

        return $this;
    }

    public function getFiltersSelected(): ?array
    {
        return $this->filtersSelected;
    }

    public function setFiltersSelected(?array $filtersSelected): static
    {
        $this->filtersSelected = $filtersSelected;

        return $this;
    }
}
