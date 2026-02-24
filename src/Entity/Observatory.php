<?php

namespace App\Entity;

use App\Repository\ObservatoryRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ObservatoryRepository::class)]
class Observatory
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $name = null;

    #[ORM\Column(nullable: true)]
    private ?float $lat = null;

    #[ORM\Column(nullable: true)]
    private ?float $lon = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $comments = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $city = null;
    #[ORM\Column(length: 512, nullable: true)]
    private ?string $live = null;

    /**
     * @var Collection<int, Session>
     */
    #[ORM\OneToMany(targetEntity: Session::class, mappedBy: 'observatory')]
    private Collection $sessions;

    #[ORM\Column(nullable: true)]
    private ?bool $favorite = null;

    /**
     * @var Collection<int, Author>
     */
    #[ORM\ManyToMany(targetEntity: Author::class, inversedBy: 'observatories')]
    private Collection $authors;

    /**
     * @var Collection<int, Setup>
     */
    #[ORM\OneToMany(targetEntity: Setup::class, mappedBy: 'observatory')]
    private Collection $setups;

    public function __construct()
    {
        $this->sessions = new ArrayCollection();
        $this->authors = new ArrayCollection();
        $this->setups = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getLat(): ?float
    {
        return $this->lat;
    }

    public function setLat(?float $lat): static
    {
        $this->lat = $lat;

        return $this;
    }

    public function getLon(): ?float
    {
        return $this->lon;
    }

    public function setLon(?float $lon): static
    {
        $this->lon = $lon;

        return $this;
    }

    public function getComments(): ?string
    {
        return $this->comments;
    }

    public function setComments(?string $comments): static
    {
        $this->comments = $comments;

        return $this;
    }

    public function getCity(): ?string
    {
        return $this->city;
    }

    public function setCity(?string $city): static
    {
        $this->city = $city;

        return $this;
    }

    /**
     * @return Collection<int, Session>
     */
    public function getSessions(): Collection
    {
        return $this->sessions;
    }

    public function addSession(Session $session): static
    {
        if (!$this->sessions->contains($session)) {
            $this->sessions->add($session);
            $session->setObservatory($this);
        }

        return $this;
    }

    public function removeSession(Session $session): static
    {
        if ($this->sessions->removeElement($session)) {
            // set the owning side to null (unless already changed)
            if ($session->getObservatory() === $this) {
                $session->setObservatory(null);
            }
        }

        return $this;
    }

    public function isFavorite(): ?bool
    {
        return $this->favorite;
    }

    public function setFavorite(?bool $favorite): static
    {
        $this->favorite = $favorite;

        return $this;
    }

    /**
     * @return Collection<int, Author>
     */
    public function getAuthors(): Collection
    {
        return $this->authors;
    }

    public function addAuthor(Author $author): static
    {
        if (!$this->authors->contains($author)) {
            $this->authors->add($author);
        }

        return $this;
    }

    public function removeAuthor(Author $author): static
    {
        $this->authors->removeElement($author);

        return $this;
    }

    /**
     * @return Collection<int, Setup>
     */
    public function getSetups(): Collection
    {
        return $this->setups;
    }

    public function addSetup(Setup $setup): static
    {
        if (!$this->setups->contains($setup)) {
            $this->setups->add($setup);
            $setup->setObservatory($this);
        }

        return $this;
    }

    public function removeSetup(Setup $setup): static
    {
        if ($this->setups->removeElement($setup)) {
            // set the owning side to null (unless already changed)
            if ($setup->getObservatory() === $this) {
                $setup->setObservatory(null);
            }
        }

        return $this;
    }

    public function setLive(?string $live): Observatory
    {
        $this->live = $live;
        return $this;
    }

    public function getLive(): ?string
    {
        return $this->live;
    }

    #[ORM\Column(nullable: true)]
    private ?float $altitudeHorizon = null;

    public function getAltitudeHorizon(): float { return $this->altitudeHorizon ?? 30.0; }
    public function setAltitudeHorizon(?float $v): static { $this->altitudeHorizon = $v; return $this; }
}
