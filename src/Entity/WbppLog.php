<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'wbpp_log')]
class WbppLog
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Session::class, inversedBy: 'wbppLogs')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Session $session = null;

    #[ORM\Column(type: 'string', length: 1024)]
    private string $sourcePath;

    #[ORM\Column(type: 'string', length: 40, nullable: true)]
    private ?string $sourceSha1 = null;

    #[ORM\Column(type: 'string', length: 64, nullable: true)]
    private ?string $piVersion = null;

    #[ORM\Column(type: 'string', length: 64, nullable: true)]
    private ?string $wbppVersion = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $durationSeconds = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $calibrationSummary = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $filterGroups = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $frames = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $integrationResults = null;

    #[ORM\Column(nullable: true)]
    private ?bool $hidden = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    // --- Getters / Setters ---

    public function getId(): ?int { return $this->id; }

    public function getSession(): ?Session { return $this->session; }
    public function setSession(Session $session): self { $this->session = $session; return $this; }

    public function getSourcePath(): string { return $this->sourcePath; }
    public function setSourcePath(string $p): self { $this->sourcePath = $p; return $this; }

    public function getSourceSha1(): ?string { return $this->sourceSha1; }
    public function setSourceSha1(?string $s): self { $this->sourceSha1 = $s; return $this; }

    public function getPiVersion(): ?string { return $this->piVersion; }
    public function setPiVersion(?string $v): self { $this->piVersion = $v; return $this; }

    public function getWbppVersion(): ?string { return $this->wbppVersion; }
    public function setWbppVersion(?string $v): self { $this->wbppVersion = $v; return $this; }

    public function getStartedAt(): ?\DateTimeImmutable { return $this->startedAt; }
    public function setStartedAt(?\DateTimeImmutable $dt): self { $this->startedAt = $dt; return $this; }

    public function getDurationSeconds(): ?int { return $this->durationSeconds; }
    public function setDurationSeconds(?int $d): self { $this->durationSeconds = $d; return $this; }

    public function getCalibrationSummary(): ?array { return $this->calibrationSummary; }
    public function setCalibrationSummary(?array $c): self { $this->calibrationSummary = $c; return $this; }

    public function getFilterGroups(): ?array { return $this->filterGroups; }
    public function setFilterGroups(?array $f): self { $this->filterGroups = $f; return $this; }

    public function getFrames(): ?array { return $this->frames; }
    public function setFrames(?array $f): self { $this->frames = $f; return $this; }

    public function getIntegrationResults(): ?array { return $this->integrationResults; }
    public function setIntegrationResults(?array $r): self { $this->integrationResults = $r; return $this; }

    public function isHidden(): ?bool { return $this->hidden; }
    public function setHidden(?bool $h): self { $this->hidden = $h; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function setCreatedAt(\DateTimeImmutable $dt): self { $this->createdAt = $dt; return $this; }

    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function setUpdatedAt(\DateTimeImmutable $dt): self { $this->updatedAt = $dt; return $this; }

    // --- Computed helpers ---

    public function getFrameCount(): int
    {
        return count($this->frames ?? []);
    }

    public function getAcceptedCount(): int
    {
        return count(array_filter($this->frames ?? [], fn(array $f) => ($f['accepted'] ?? false) === true));
    }

    public function getRejectedCount(): int
    {
        return $this->getFrameCount() - $this->getAcceptedCount();
    }

    public function getFilterCount(): int
    {
        return count($this->filterGroups ?? []);
    }
}
