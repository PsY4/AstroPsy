<?php

namespace App\Entity;

use App\Repository\Phd2CalibrationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: Phd2CalibrationRepository::class)]
class Phd2Calibration
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    // Link to your existing Session entity
    #[ORM\ManyToOne(targetEntity: Session::class, inversedBy: 'phd2Calibrations')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Session $session = null;

    // Source file info (optional but very useful to avoid duplicates / debugging)
    #[ORM\Column(type: Types::STRING, length: 1024, nullable: true)]
    private ?string $sourcePath = null;

    #[ORM\Column(type: Types::STRING, length: 40, nullable: true)]
    private ?string $sourceSha1 = null;

    // Timing
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $sectionIndex = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $endedAt = null;

    // Quick headers (parsed K/V lines from the log header near "Calibration Begins ...")
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $headers = null;

    // Mount/device name gleaned from header lines if available
    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $mount = null;

    // Pixel scale is handy for client px→arcsec conversion when plotting overlays
    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $pixelScaleArcsecPerPx = null;

    // Lock position (for overlay plotting)
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $lockPosition = null; // e.g. ["x" => 453.861, "y" => 1047.970]

    // RA (West/East) calibration summary
    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $westAngleDeg = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $westRatePxPerSec = null;

    #[ORM\Column(type: Types::STRING, length: 32, nullable: true)]
    private ?string $westParity = null; // e.g. "Even"/"Odd"

    // DEC (North/South) calibration summary
    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $northAngleDeg = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $northRatePxPerSec = null;

    #[ORM\Column(type: Types::STRING, length: 32, nullable: true)]
    private ?string $northParity = null;

    // Orthogonality (deg) if present/derived
    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $orthogonalityDeg = null;

    // Ready-to-plot points for each sweep direction
    // Structure example: [{"step":0,"dx":0.12,"dy":-0.03,"x":451.2,"y":1048.0,"dist":0.13}, ...]
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $pointsWest = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $pointsEast = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $pointsNorth = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $pointsSouth = null;

    // Timestamps
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(nullable: true)]
    private ?bool $hidden = null;

    public function __construct()
    {
        $now = new \DateTimeImmutable('now');
        $this->createdAt = $now;
        $this->updatedAt = $now;
        $this->startedAt = $now;
    }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable('now');
    }
    public function getId(): ?int { return $this->id; }

    public function getSession(): ?Session { return $this->session; }
    public function setSession(?Session $session): self { $this->session = $session; return $this; }

    public function getSourcePath(): ?string { return $this->sourcePath; }
    public function setSourcePath(?string $sourcePath): self { $this->sourcePath = $sourcePath; return $this; }

    public function getSourceSha1(): ?string { return $this->sourceSha1; }
    public function setSourceSha1(?string $sourceSha1): self { $this->sourceSha1 = $sourceSha1; return $this; }

    public function getStartedAt(): \DateTimeImmutable { return $this->startedAt; }
    public function setStartedAt(\DateTimeImmutable $startedAt): self { $this->startedAt = $startedAt; return $this; }

    public function getEndedAt(): ?\DateTimeImmutable { return $this->endedAt; }
    public function setEndedAt(?\DateTimeImmutable $endedAt): self { $this->endedAt = $endedAt; return $this; }

    public function getHeaders(): ?array { return $this->headers; }
    public function setHeaders(?array $headers): self { $this->headers = $headers; return $this; }

    public function getMount(): ?string { return $this->mount; }
    public function setMount(?string $mount): self { $this->mount = $mount; return $this; }

    public function getPixelScaleArcsecPerPx(): ?float { return $this->pixelScaleArcsecPerPx; }
    public function setPixelScaleArcsecPerPx(?float $scale): self { $this->pixelScaleArcsecPerPx = $scale; return $this; }

    public function getLockPosition(): ?array { return $this->lockPosition; }
    public function setLockPosition(?array $lockPosition): self { $this->lockPosition = $lockPosition; return $this; }

    public function getWestAngleDeg(): ?float { return $this->westAngleDeg; }
    public function setWestAngleDeg(?float $westAngleDeg): self { $this->westAngleDeg = $westAngleDeg; return $this; }

    public function getWestRatePxPerSec(): ?float { return $this->westRatePxPerSec; }
    public function setWestRatePxPerSec(?float $westRatePxPerSec): self { $this->westRatePxPerSec = $westRatePxPerSec; return $this; }

    public function getWestParity(): ?string { return $this->westParity; }
    public function setWestParity(?string $westParity): self { $this->westParity = $westParity; return $this; }

    public function getNorthAngleDeg(): ?float { return $this->northAngleDeg; }
    public function setNorthAngleDeg(?float $northAngleDeg): self { $this->northAngleDeg = $northAngleDeg; return $this; }

    public function getNorthRatePxPerSec(): ?float { return $this->northRatePxPerSec; }
    public function setNorthRatePxPerSec(?float $northRatePxPerSec): self { $this->northRatePxPerSec = $northRatePxPerSec; return $this; }

    public function getNorthParity(): ?string { return $this->northParity; }
    public function setNorthParity(?string $northParity): self { $this->northParity = $northParity; return $this; }

    public function getOrthogonalityDeg(): ?float { return $this->orthogonalityDeg; }
    public function setOrthogonalityDeg(?float $orthogonalityDeg): self { $this->orthogonalityDeg = $orthogonalityDeg; return $this; }

    public function getPointsWest(): ?array { return $this->pointsWest; }
    public function setPointsWest(?array $pointsWest): self { $this->pointsWest = $pointsWest; return $this; }

    public function getPointsEast(): ?array { return $this->pointsEast; }
    public function setPointsEast(?array $pointsEast): self { $this->pointsEast = $pointsEast; return $this; }

    public function getPointsNorth(): ?array { return $this->pointsNorth; }
    public function setPointsNorth(?array $pointsNorth): self { $this->pointsNorth = $pointsNorth; return $this; }

    public function getPointsSouth(): ?array { return $this->pointsSouth; }
    public function setPointsSouth(?array $pointsSouth): self { $this->pointsSouth = $pointsSouth; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function setCreatedAt(\DateTimeImmutable $createdAt): self { $this->createdAt = $createdAt; return $this; }

    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function setUpdatedAt(\DateTimeImmutable $updatedAt): self { $this->updatedAt = $updatedAt; return $this; }

    public function setSectionIndex(?int $sectionIndex): Phd2Calibration
    {
        $this->sectionIndex = $sectionIndex;
        return $this;
    }

    public function getSectionIndex(): ?int
    {
        return $this->sectionIndex;
    }

    public function isHidden(): ?bool
    {
        return $this->hidden;
    }

    public function setHidden(?bool $hidden): static
    {
        $this->hidden = $hidden;

        return $this;
    }

}
