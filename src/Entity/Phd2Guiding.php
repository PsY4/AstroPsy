<?php
namespace App\Entity;

use App\Entity\Session;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class Phd2Guiding
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Session::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Session $session = null;

    #[ORM\Column(type: 'string', length: 1024)]
    private string $sourcePath;

    #[ORM\Column(type: 'string', length: 40, nullable: true)]
    private ?string $sourceSha1 = null;

    #[ORM\Column(type: 'integer')]
    private int $sectionIndex;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $endedAt = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $headers = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $mount = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $pixelScaleArcsecPerPx = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $exposureMs = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $lockPosition = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $hfdPx = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $frameCount = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $dropCount = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $rmsRaArcsec = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $rmsDecArcsec = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $totalRmsArcsec = null;

    // Compact storage of rows: ['points' => [...mount rows...], 'drops' => [...drop rows...]]
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $samples = null;

    #[ORM\Column(nullable: true)]
    private ?bool $hidden = null;

    // --- Getters/Setters ---

    public function getId(): ?int { return $this->id; }

    public function getSession(): ?Session { return $this->session; }
    public function setSession(Session $session): self { $this->session = $session; return $this; }

    public function getSourcePath(): string { return $this->sourcePath; }
    public function setSourcePath(string $p): self { $this->sourcePath = $p; return $this; }

    public function getSourceSha1(): ?string { return $this->sourceSha1; }
    public function setSourceSha1(?string $s): self { $this->sourceSha1 = $s; return $this; }

    public function getSectionIndex(): int { return $this->sectionIndex; }
    public function setSectionIndex(int $i): self { $this->sectionIndex = $i; return $this; }

    public function getStartedAt(): ?\DateTimeImmutable { return $this->startedAt; }
    public function setStartedAt(?\DateTimeImmutable $dt): self { $this->startedAt = $dt; return $this; }

    public function getEndedAt(): ?\DateTimeImmutable { return $this->endedAt; }
    public function setEndedAt(?\DateTimeImmutable $dt): self { $this->endedAt = $dt; return $this; }

    public function getHeaders(): ?array { return $this->headers; }
    public function setHeaders(?array $h): self { $this->headers = $h; return $this; }

    public function getMount(): ?string { return $this->mount; }
    public function setMount(?string $m): self { $this->mount = $m; return $this; }

    public function getPixelScaleArcsecPerPx(): ?float { return $this->pixelScaleArcsecPerPx; }
    public function setPixelScaleArcsecPerPx(?float $f): self { $this->pixelScaleArcsecPerPx = $f; return $this; }

    public function getExposureMs(): ?int { return $this->exposureMs; }
    public function setExposureMs(?int $ms): self { $this->exposureMs = $ms; return $this; }

    public function getLockPosition(): ?array { return $this->lockPosition; }
    public function setLockPosition(?array $p): self { $this->lockPosition = $p; return $this; }

    public function getHfdPx(): ?float { return $this->hfdPx; }
    public function setHfdPx(?float $h): self { $this->hfdPx = $h; return $this; }

    public function getFrameCount(): ?int { return $this->frameCount; }
    public function setFrameCount(?int $n): self { $this->frameCount = $n; return $this; }

    public function getDropCount(): ?int { return $this->dropCount; }
    public function setDropCount(?int $n): self { $this->dropCount = $n; return $this; }

    public function getRmsRaArcsec(): ?float { return $this->rmsRaArcsec; }
    public function setRmsRaArcsec(?float $v): self { $this->rmsRaArcsec = $v; return $this; }

    public function getRmsDecArcsec(): ?float { return $this->rmsDecArcsec; }
    public function setRmsDecArcsec(?float $v): self { $this->rmsDecArcsec = $v; return $this; }

    public function getTotalRmsArcsec(): ?float { return $this->totalRmsArcsec; }
    public function setTotalRmsArcsec(?float $v): self { $this->totalRmsArcsec = $v; return $this; }

    public function getSamples(): ?array { return $this->samples; }
    public function setSamples(?array $s): self { $this->samples = $s; return $this; }

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
