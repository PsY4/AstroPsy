<?php

namespace App\Entity;

use App\Repository\DocRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: DocRepository::class)]
class Doc
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $title = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $doc = null;

    #[ORM\ManyToOne(inversedBy: 'docs')]
    private ?Target $target = null;

    #[ORM\ManyToOne(inversedBy: 'docs')]
    private ?Session $session = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTime $creationDate = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTime $updateDate = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $icon = null;

    #[ORM\Column(type: Types::ARRAY, nullable: true)]
    private ?array $tags = null;

    #[ORM\Column(length: 1024, nullable: true)]
    private ?string $path;

    #[Assert\File(
        maxSize: '10M',
        mimeTypes: ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document']
    )]
    private ?File $uploadedFile = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getDoc(): ?string
    {
        return $this->doc;
    }

    public function setDoc(?string $doc): static
    {
        $this->doc = $doc;

        return $this;
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

    public function getSession(): ?Session
    {
        return $this->session;
    }

    public function setSession(?Session $session): static
    {
        $this->session = $session;

        return $this;
    }

    public function getCreationDate(): ?\DateTime
    {
        return $this->creationDate;
    }

    public function setCreationDate(?\DateTime $creationDate): static
    {
        $this->creationDate = $creationDate;

        return $this;
    }

    public function getUpdateDate(): ?\DateTime
    {
        return $this->updateDate;
    }

    public function setUpdateDate(?\DateTime $updateDate): static
    {
        $this->updateDate = $updateDate;

        return $this;
    }

    public function getIcon(): ?string
    {
        return $this->icon;
    }

    public function setIcon(?string $icon): static
    {
        $this->icon = $icon;

        return $this;
    }

    public function getTags(): ?array
    {
        return $this->tags;
    }

    public function setTags(?array $tags): static
    {
        $this->tags = $tags;

        return $this;
    }

    public function setPath(string $path): Doc
    {
        $this->path = $path;
        return $this;
    }

    public function getPath(): ?string
    {
        return $this->path;
    }

    public function getUploadedFile(): ?File
    {
        return $this->uploadedFile;
    }

    public function setUploadedFile(?File $file): void
    {
        $this->uploadedFile = $file;
    }
}
