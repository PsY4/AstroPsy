<?php
namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class Master
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column] private ?int $id = null;
    #[ORM\ManyToOne(inversedBy: 'masters')] private ?Session $session = null;
    #[ORM\Column(length: 32)] private string $type; // MasterLight|Dark|Flat|Bias
    #[ORM\Column(length: 16, nullable: true)] private ?string $filterName = null;
    #[ORM\Column(length: 1024)] private string $path;
    #[ORM\Column(length: 64)] private string $hash;
    #[ORM\Column(type: 'json', nullable: true)] private ?array $header = null;

    public function setId(?int $id): Master
    {
        $this->id = $id;
        return $this;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setSession(?Session $session): Master
    {
        $this->session = $session;
        return $this;
    }

    public function getSession(): ?Session
    {
        return $this->session;
    }

    public function setType(string $type): Master
    {
        $this->type = $type;
        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setFilterName(?string $filterName): Master
    {
        $this->filterName = $filterName;
        return $this;
    }

    public function getFilterName(): ?string
    {
        return $this->filterName;
    }

    public function setPath(string $path): Master
    {
        $this->path = $path;
        return $this;
    }

    public function getPath(): ?string
    {
        return $this->path;
    }

    public function setHash(string $hash): Master
    {
        $this->hash = $hash;
        return $this;
    }

    public function getHash(): string
    {
        return $this->hash;
    }

    public function setHeader(?array $header): Master
    {
        $this->header = $header;
        return $this;
    }

    public function getHeader(): ?array
    {
        return $this->header;
    }
}
