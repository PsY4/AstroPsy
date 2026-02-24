<?php
namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;

#[ORM\Entity]
class Session
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column] private ?int $id = null;
    #[ORM\ManyToOne(inversedBy: 'sessions')] private ?Target $target = null;
    #[ORM\Column(type: 'datetime', nullable: true)] private ?\DateTimeInterface $startedAt = null;
    #[ORM\Column(type: 'datetime', nullable: true)] private ?\DateTimeInterface $endedAt = null;
    #[ORM\Column(length: 255, nullable: true)] private ?string $site = null;
    #[ORM\Column(type: 'json', nullable: true)] private ?array $gearProfile = null;
    #[ORM\Column(type: 'text', nullable: true)] private ?string $notes = null;
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $astrobinStats = null;


    #[ORM\OneToMany(mappedBy: 'session', targetEntity: Exposure::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['dateTaken' => 'ASC'])]
    private Collection $exposures;
    #[ORM\OneToMany(mappedBy: 'session', targetEntity: LogFile::class, cascade: ['persist'], orphanRemoval: true)]
    private Collection $logs;
    #[ORM\OneToMany(mappedBy: 'session', targetEntity: Export::class, cascade: ['persist'], orphanRemoval: true)]
    private Collection $exports;
    #[ORM\OneToMany(mappedBy: 'session', targetEntity: Master::class, cascade: ['persist'], orphanRemoval: true)]
    private Collection $masters;

    #[ORM\OneToMany(mappedBy: 'session', targetEntity: Phd2Calibration::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['startedAt' => 'ASC'])]
    private Collection $phd2Calibrations;
    #[ORM\OneToMany(mappedBy: 'session', targetEntity: Phd2Guiding::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['startedAt' => 'ASC'])]
    private Collection $phd2Guidings;

    #[ORM\Column(length: 512, nullable: true)] private ?string $path = null;

    /**
     * @var Collection<int, Author>
     */
    #[ORM\ManyToMany(targetEntity: Author::class, inversedBy: 'sessions')]
    private Collection $authors;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $astrobin = null;

    #[ORM\ManyToOne(inversedBy: 'sessions')]
    private ?Observatory $observatory = null;

    #[ORM\ManyToOne]
    private ?Setup $setup = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $excludeFromProgress = false;

    /**
     * @var Collection<int, Doc>
     */
    #[ORM\OneToMany(targetEntity: Doc::class, mappedBy: 'session')]
    private Collection $docs;

    public function __construct() {
        $this->exposures = new ArrayCollection();
        $this->logs = new ArrayCollection();
        $this->exports = new ArrayCollection();
        $this->masters = new ArrayCollection();
        $this->authors = new ArrayCollection();
        $this->docs = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }
    public function getTarget(): ?Target { return $this->target; }
    public function setTarget(?Target $t): self { $this->target = $t; return $this; }
    public function getStartedAt(): ?\DateTimeInterface { return $this->startedAt; }
    public function setStartedAt(?\DateTimeInterface $d): self { $this->startedAt = $d; return $this; }
    public function getEndedAt(): ?\DateTimeInterface { return $this->endedAt; }
    public function setEndedAt(?\DateTimeInterface $d): self { $this->endedAt = $d; return $this; }
    public function getSite(): ?string { return $this->site; }
    public function setSite(?string $s): self { $this->site = $s; return $this; }
    public function getGearProfile(): ?array { return $this->gearProfile; }
    public function setGearProfile(?array $g): self { $this->gearProfile = $g; return $this; }
    public function getNotes(): ?string { return $this->notes; }
    public function setNotes(?string $n): self { $this->notes = $n; return $this; }
    public function isExcludeFromProgress(): bool { return $this->excludeFromProgress; }
    public function setExcludeFromProgress(bool $v): self { $this->excludeFromProgress = $v; return $this; }

    public function setExposures(Collection $exposures): Session
    {
        $this->exposures = $exposures;
        return $this;
    }

    public function getExposures(): Collection
    {
        return $this->exposures;
    }

    public function setLogs(Collection $logs): Session
    {
        $this->logs = $logs;
        return $this;
    }

    public function getLogs(): Collection
    {
        return $this->logs;
    }

    public function setExports(Collection $exports): Session
    {
        $this->exports = $exports;
        return $this;
    }

    public function getExports(): Collection
    {
        return $this->exports;
    }

    public function setMasters(Collection $masters): Session
    {
        $this->masters = $masters;
        return $this;
    }

    public function getMasters(): Collection
    {
        return $this->masters;
    }

    public function setPath(string $path): Session
    {
        $this->path = $path;
        return $this;
    }

    public function getPath(): ?string
    {
        return $this->path;
    }

    public function setPhd2Calibrations(Collection $phd2Calibrations): Session
    {
        $this->phd2Calibrations = $phd2Calibrations;
        return $this;
    }

    public function getPhd2Calibrations(): Collection
    {
        return $this->phd2Calibrations;
    }

    public function setPhd2Guidings(Collection $phd2Guidings): Session
    {
        $this->phd2Guidings = $phd2Guidings;
        return $this;
    }

    public function getPhd2Guidings(): Collection
    {
        return $this->phd2Guidings;
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

    public function getAstrobin(): ?string
    {
        return $this->astrobin;
    }

    public function setAstrobin(?string $astrobin): static
    {
        $this->astrobin = $astrobin;

        return $this;
    }

    public function getObservatory(): ?Observatory
    {
        return $this->observatory;
    }

    public function setObservatory(?Observatory $observatory): static
    {
        $this->observatory = $observatory;

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
            $doc->setSession($this);
        }

        return $this;
    }

    public function removeDoc(Doc $doc): static
    {
        if ($this->docs->removeElement($doc)) {
            // set the owning side to null (unless already changed)
            if ($doc->getSession() === $this) {
                $doc->setSession(null);
            }
        }

        return $this;
    }

    public function setAstrobinStats(?array $astrobinStats): Session
    {
        $this->astrobinStats = $astrobinStats;
        return $this;
    }

    public function getAstrobinStats(): ?array
    {
        return $this->astrobinStats;
    }

    public function getAstrobinViews(): ?float
    {
        return $this->getAstrobinStats()['viewCount'] ?? null;
    }

    public function getAstrobinLikes(): ?float
    {
        return $this->getAstrobinStats()['likeCount'] ?? null;
    }

    public function getAstrobinBookmarks(): ?float
    {
        return $this->getAstrobinStats()['bookmarkCount'] ?? null;
    }

    public function getAstrobinComments(): ?float
    {
        return $this->getAstrobinStats()['commentCount'] ?? null;
    }
}
