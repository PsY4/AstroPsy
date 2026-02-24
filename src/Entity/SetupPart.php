<?php

namespace App\Entity;

use App\Repository\SetupPartRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SetupPartRepository::class)]
class SetupPart
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $make = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $model = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $type = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(length: 1024, nullable: true)]
    private ?string $url = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $images = null;

    #[ORM\ManyToOne(inversedBy: 'setupParts')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Setup $setup = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMake(): ?string
    {
        return $this->make;
    }

    public function setMake(?string $make): static
    {
        $this->make = $make;

        return $this;
    }

    public function getModel(): ?string
    {
        return $this->model;
    }

    public function setModel(?string $model): static
    {
        $this->model = $model;

        return $this;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(?string $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): static
    {
        $this->notes = $notes;

        return $this;
    }

    public function getUrl(): ?string
    {
        return $this->url;
    }

    public function setUrl(?string $url): static
    {
        $this->url = $url;

        return $this;
    }

    public function getImages(): ?array
    {
        return $this->images;
    }

    public function setImages(?array $images): static
    {
        $this->images = $images;

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
}
