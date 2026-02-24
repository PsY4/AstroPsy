<?php
namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class Export
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column] private ?int $id = null;
    #[ORM\ManyToOne(inversedBy: 'exports')] private ?Session $session = null;
    #[ORM\Column(length: 32)] private string $type; // JPEG|TIFF|PNG|PI
    #[ORM\Column(length: 1024)] private string $path;
    #[ORM\Column(length: 64)] private string $hash;
    #[ORM\Column(type: 'json', nullable: true)] private ?array $metadata = null;

    public function setId(?int $id): Export
    {
        $this->id = $id;
        return $this;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setSession(?Session $session): Export
    {
        $this->session = $session;
        return $this;
    }

    public function getSession(): ?Session
    {
        return $this->session;
    }

    public function setType(string $type): Export
    {
        $this->type = $type;
        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setPath(string $path): Export
    {
        $this->path = $path;
        return $this;
    }

    public function getPath(): ?string
    {
        return $this->path;
    }

    public function setHash(string $hash): Export
    {
        $this->hash = $hash;
        return $this;
    }

    public function getHash(): string
    {
        return $this->hash;
    }

    public function setMetadata(?array $metadata): Export
    {
        $this->metadata = $metadata;
        return $this;
    }

    public function getMetadata(): ?array
    {
        return $this->metadata;
    }
}
