<?php

namespace App\Entity;

use App\Repository\AuthorRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AuthorRepository::class)]
class Author
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $name = null;

    #[ORM\Column(length: 512, nullable: true)]
    private ?string $logo = null;

    #[ORM\Column(length: 512, nullable: true)]
    private ?string $linkAstrobin = null;
    #[ORM\Column(length: 128, nullable: true)]
    private ?string $astrobinId = null;
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $astrobinProfile = null;
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $astrobinStats = null;

    /**
     * @var Collection<int, Session>
     */
    #[ORM\ManyToMany(targetEntity: Session::class, mappedBy: 'authors')]
    private Collection $sessions;

    /**
     * @var Collection<int, Observatory>
     */
    #[ORM\ManyToMany(targetEntity: Observatory::class, mappedBy: 'authors')]
    private Collection $observatories;

    /**
     * @var Collection<int, Setup>
     */
    #[ORM\OneToMany(targetEntity: Setup::class, mappedBy: 'author')]
    private Collection $setups;

    public function __construct()
    {
        $this->sessions = new ArrayCollection();
        $this->observatories = new ArrayCollection();
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

    public function getLogo(): ?string
    {
        return $this->logo;
    }

    public function setLogo(?string $logo): static
    {
        $this->logo = $logo;

        return $this;
    }

    public function getLinkAstrobin(): ?string
    {
        return $this->linkAstrobin;
    }

    public function setLinkAstrobin(?string $linkAstrobin): static
    {
        $this->linkAstrobin = $linkAstrobin;

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
            $session->addAuthor($this);
        }

        return $this;
    }

    public function removeSession(Session $session): static
    {
        if ($this->sessions->removeElement($session)) {
            $session->removeAuthor($this);
        }

        return $this;
    }

    /**
     * @return Collection<int, Observatory>
     */
    public function getObservatories(): Collection
    {
        return $this->observatories;
    }

    public function addObservatory(Observatory $observatory): static
    {
        if (!$this->observatories->contains($observatory)) {
            $this->observatories->add($observatory);
            $observatory->addAuthor($this);
        }

        return $this;
    }

    public function removeObservatory(Observatory $observatory): static
    {
        if ($this->observatories->removeElement($observatory)) {
            $observatory->removeAuthor($this);
        }

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
            $setup->setAuthor($this);
        }

        return $this;
    }

    public function removeSetup(Setup $setup): static
    {
        if ($this->setups->removeElement($setup)) {
            // set the owning side to null (unless already changed)
            if ($setup->getAuthor() === $this) {
                $setup->setAuthor(null);
            }
        }

        return $this;
    }

    public function setAstrobinId(?string $astrobinId): Author
    {
        $this->astrobinId = $astrobinId;
        return $this;
    }

    public function getAstrobinId(): ?string
    {
        return $this->astrobinId;
    }

    public function setAstrobinStats(?array $astrobinStats): Author
    {
        $this->astrobinStats = $astrobinStats;
        return $this;
    }

    public function getAstrobinStats(): ?array
    {
        return $this->astrobinStats;
    }
    public function getAstrobinLikes(): ?int
    {
        foreach ($this->getAstrobinStats()["stats"] ?? [] as $astrobinStat) {
            if($astrobinStat[0]=="Likes received") return $astrobinStat[1];
        }
        return 0;
    }
    public function getAstrobinViews(): ?int
    {
        foreach ($this->getAstrobinStats()["stats"] ?? [] as $astrobinStat) {
            if($astrobinStat[0]=="Views received") return $astrobinStat[1];
        }
        return 0;
    }

    public function setAstrobinProfile(?array $astrobinProfile): Author
    {
        $this->astrobinProfile = $astrobinProfile;
        return $this;
    }

    public function getAstrobinProfile(): ?array
    {
        return $this->astrobinProfile;
    }
    public function getAstrobinGalleryHeaderImage(): ?string
    {
        return $this->getAstrobinProfile()["gallery_header_image"] ?? null;
    }
    public function getAstrobinImageCount(): ?int
    {
        return $this->getAstrobinProfile()["image_count"] ?? null;
    }
    public function getAstrobinImageIndex(): ?float
    {
        return $this->getAstrobinProfile()["image_index"] ?? null;
    }
    public function getAstrobinFollowersCount(): ?float
    {
        return $this->getAstrobinProfile()["followers_count"] ?? null;
    }
    public function getAstrobinFollowingCount(): ?float
    {
        return $this->getAstrobinProfile()["following_count"] ?? null;
    }

}
