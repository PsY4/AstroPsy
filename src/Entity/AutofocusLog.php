<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'autofocus_log')]
class AutofocusLog
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Session::class, inversedBy: 'autofocusLogs')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Session $session = null;

    #[ORM\Column(type: 'string', length: 1024)]
    private string $sourcePath;

    #[ORM\Column(type: 'string', length: 255)]
    private string $runFolder;

    #[ORM\Column(type: 'integer')]
    private int $attemptNumber;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $timestamp = null;

    #[ORM\Column(type: 'string', length: 32, nullable: true)]
    private ?string $filter = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $temperature = null;

    #[ORM\Column(type: 'string', length: 32, nullable: true)]
    private ?string $method = null;

    #[ORM\Column(type: 'string', length: 32, nullable: true)]
    private ?string $fitting = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $durationSeconds = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $initialPosition = null;

    #[ORM\Column(type: 'float', nullable: true, name: 'initial_hfr')]
    private ?float $initialHfr = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $calculatedPosition = null;

    #[ORM\Column(type: 'float', nullable: true, name: 'calculated_hfr')]
    private ?float $calculatedHfr = null;

    #[ORM\Column(type: 'float', nullable: true, name: 'final_hfr')]
    private ?float $finalHfr = null;

    #[ORM\Column(type: 'float', nullable: true, name: 'r_squared')]
    private ?float $rSquared = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $measurePoints = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $fittings = null;

    #[ORM\Column(type: 'string', length: 64, nullable: true)]
    private ?string $focuserName = null;

    #[ORM\Column(type: 'string', length: 64, nullable: true)]
    private ?string $starDetectorName = null;

    #[ORM\Column(type: 'string', length: 32, nullable: true)]
    private ?string $backlashModel = null;

    #[ORM\Column(type: 'integer', nullable: true, name: 'backlash_in')]
    private ?int $backlashIn = null;

    #[ORM\Column(type: 'integer', nullable: true, name: 'backlash_out')]
    private ?int $backlashOut = null;

    #[ORM\Column(type: 'boolean')]
    private bool $success = false;

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

    public function getRunFolder(): string { return $this->runFolder; }
    public function setRunFolder(string $f): self { $this->runFolder = $f; return $this; }

    public function getAttemptNumber(): int { return $this->attemptNumber; }
    public function setAttemptNumber(int $n): self { $this->attemptNumber = $n; return $this; }

    public function getTimestamp(): ?\DateTimeImmutable { return $this->timestamp; }
    public function setTimestamp(?\DateTimeImmutable $dt): self { $this->timestamp = $dt; return $this; }

    public function getFilter(): ?string { return $this->filter; }
    public function setFilter(?string $f): self { $this->filter = $f; return $this; }

    public function getTemperature(): ?float { return $this->temperature; }
    public function setTemperature(?float $t): self { $this->temperature = $t; return $this; }

    public function getMethod(): ?string { return $this->method; }
    public function setMethod(?string $m): self { $this->method = $m; return $this; }

    public function getFitting(): ?string { return $this->fitting; }
    public function setFitting(?string $f): self { $this->fitting = $f; return $this; }

    public function getDurationSeconds(): ?int { return $this->durationSeconds; }
    public function setDurationSeconds(?int $d): self { $this->durationSeconds = $d; return $this; }

    public function getInitialPosition(): ?float { return $this->initialPosition; }
    public function setInitialPosition(?float $v): self { $this->initialPosition = $v; return $this; }

    public function getInitialHfr(): ?float { return $this->initialHfr; }
    public function setInitialHfr(?float $v): self { $this->initialHfr = $v; return $this; }

    public function getCalculatedPosition(): ?float { return $this->calculatedPosition; }
    public function setCalculatedPosition(?float $v): self { $this->calculatedPosition = $v; return $this; }

    public function getCalculatedHfr(): ?float { return $this->calculatedHfr; }
    public function setCalculatedHfr(?float $v): self { $this->calculatedHfr = $v; return $this; }

    public function getFinalHfr(): ?float { return $this->finalHfr; }
    public function setFinalHfr(?float $v): self { $this->finalHfr = $v; return $this; }

    public function getRSquared(): ?float { return $this->rSquared; }
    public function setRSquared(?float $v): self { $this->rSquared = $v; return $this; }

    public function getMeasurePoints(): ?array { return $this->measurePoints; }
    public function setMeasurePoints(?array $v): self { $this->measurePoints = $v; return $this; }

    public function getFittings(): ?array { return $this->fittings; }
    public function setFittings(?array $v): self { $this->fittings = $v; return $this; }

    public function getFocuserName(): ?string { return $this->focuserName; }
    public function setFocuserName(?string $v): self { $this->focuserName = $v; return $this; }

    public function getStarDetectorName(): ?string { return $this->starDetectorName; }
    public function setStarDetectorName(?string $v): self { $this->starDetectorName = $v; return $this; }

    public function getBacklashModel(): ?string { return $this->backlashModel; }
    public function setBacklashModel(?string $v): self { $this->backlashModel = $v; return $this; }

    public function getBacklashIn(): ?int { return $this->backlashIn; }
    public function setBacklashIn(?int $v): self { $this->backlashIn = $v; return $this; }

    public function getBacklashOut(): ?int { return $this->backlashOut; }
    public function setBacklashOut(?int $v): self { $this->backlashOut = $v; return $this; }

    public function isSuccess(): bool { return $this->success; }
    public function setSuccess(bool $v): self { $this->success = $v; return $this; }

    public function isHidden(): ?bool { return $this->hidden; }
    public function setHidden(?bool $h): self { $this->hidden = $h; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function setCreatedAt(\DateTimeImmutable $dt): self { $this->createdAt = $dt; return $this; }

    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function setUpdatedAt(\DateTimeImmutable $dt): self { $this->updatedAt = $dt; return $this; }

    // --- Computed helpers ---

    public function getStepCount(): int
    {
        return count($this->measurePoints ?? []);
    }
}
