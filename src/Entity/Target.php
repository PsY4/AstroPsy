<?php
namespace App\Entity;

use App\Repository\TargetRepository;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;

#[ORM\Entity(repositoryClass: TargetRepository::class)]
class Target
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column] private ?int $id = null;
    #[ORM\Column(length: 255)] private string $name;
    #[ORM\Column(type: 'float', nullable: true)] private ?float $ra = null;
    #[ORM\Column(type: 'float', nullable: true)] private ?float $dec = null;
    #[ORM\Column(type: 'json', nullable: true)] private ?array $catalogIds = null;
    #[ORM\OneToMany(mappedBy: 'target', targetEntity: Session::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['startedAt' => 'DESC'])]
    private Collection $sessions;
    #[ORM\Column(type: 'text', nullable: true)] private ?string $notes = null;

    #[ORM\Column(length: 512, nullable: true)]
    private ?string $thumbnailUrl = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $constellation = null;

    #[ORM\Column(nullable: true)]
    private ?float $visualMag = null;

    #[ORM\Column(length: 512, nullable: true)]
    private ?string $telescopiusUrl = null;
    #[ORM\Column(length: 128, nullable: true)]
    private ?string $type = null;
    #[ORM\Column(length: 512, nullable: true)] private ?string $path = null;
    #[ORM\Column(nullable: true)] private ?bool $wishlist = null;

    /**
     * @var Collection<int, Doc>
     */
    #[ORM\OneToMany(targetEntity: Doc::class, mappedBy: 'target')]
    private Collection $docs;

    /**
     * @var Collection<int, TargetGoal>
     */
    #[ORM\OneToMany(mappedBy: 'target', targetEntity: TargetGoal::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $goals;

    public function __construct(){
        $this->sessions = new ArrayCollection();
        $this->docs = new ArrayCollection();
        $this->goals = new ArrayCollection();
    }
    public function getId(): ?int { return $this->id; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = $name; return $this; }
    public function getRa(): ?float { return $this->ra; }
    public function setRa(?float $ra): self { $this->ra = $ra; return $this; }
    public function getDec(): ?float { return $this->dec; }
    public function setDec(?float $dec): self { $this->dec = $dec; return $this; }
    public function getCatalogIds(): ?array { return $this->catalogIds; }
    public function setCatalogIds(?array $ids): self { $this->catalogIds = $ids; return $this; }
    public function getNotes(): ?string { return $this->notes; }
    public function setNotes(?string $n): self { $this->notes = $n; return $this; }
    /** @return Collection<int, Session> */
    public function getSessions(): Collection { return $this->sessions; }

    public function getThumbnailUrl(): ?string
    {
        return $this->thumbnailUrl;
    }

    public function setThumbnailUrl(?string $thumbnailUrl): static
    {
        $this->thumbnailUrl = $thumbnailUrl;

        return $this;
    }

    public function getConstellation(): ?string
    {
        return $this->constellation;
    }

    public function setConstellation(?string $constellation): static
    {
        $this->constellation = $constellation;

        return $this;
    }

    public function getVisualMag(): ?float
    {
        return $this->visualMag;
    }

    public function setVisualMag(?float $visualMag): static
    {
        $this->visualMag = $visualMag;

        return $this;
    }

    public function getTelescopiusUrl(): ?string
    {
        return $this->telescopiusUrl;
    }

    public function setTelescopiusUrl(?string $telescopiusUrl): static
    {
        $this->telescopiusUrl = $telescopiusUrl;

        return $this;
    }

    public function setPath(string $path): Target
    {
        $this->path = $path;
        return $this;
    }

    public function getPath(): ?string
    {
        return $this->path;
    }

    public function setType(?string $type): Target
    {
        $this->type = $type;
        return $this;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    /**
     * @return Collection<int, Doc>
     */
    public function getDocs(): Collection
    {
        return $this->docs;
    }

    public function addDoc(Doc $doc): static
    {
        if (!$this->docs->contains($doc)) {
            $this->docs->add($doc);
            $doc->setTarget($this);
        }

        return $this;
    }

    public function removeDoc(Doc $doc): static
    {
        if ($this->docs->removeElement($doc)) {
            if ($doc->getTarget() === $this) {
                $doc->setTarget(null);
            }
        }

        return $this;
    }

    /** @return Collection<int, TargetGoal> */
    public function getGoals(): Collection { return $this->goals; }

    public function isNarrowbandType(): bool
    {
        return (bool) preg_match('/NEB|HII|SNR|PN/i', $this->type ?? '');
    }

    public function isWishlist(): bool { return $this->wishlist ?? false; }
    public function setWishlist(?bool $wishlist): self { $this->wishlist = $wishlist; return $this; }
}
