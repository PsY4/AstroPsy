<?php

namespace App\Entity;

use App\Repository\SetupRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SetupRepository::class)]
class Setup
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $name = null;

    #[ORM\ManyToOne(inversedBy: 'setups')]
    private ?Author $author = null;

    #[ORM\ManyToOne(inversedBy: 'setups')]
    private ?Observatory $observatory = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(length: 512, nullable: true)]
    private ?string $logo = null;

    #[ORM\Column(name: 'sensor_w_px', nullable: true)]
    private ?int $sensorWPx = null;

    #[ORM\Column(name: 'sensor_h_px', nullable: true)]
    private ?int $sensorHPx = null;

    #[ORM\Column(name: 'pixel_size_um', type: 'float', nullable: true)]
    private ?float $pixelSizeUm = null;

    #[ORM\Column(name: 'focal_mm', type: 'float', nullable: true)]
    private ?float $focalMm = null;

    #[ORM\Column(nullable: true)]
    private ?int $slewTimeMin = null;

    #[ORM\Column(nullable: true)]
    private ?int $autofocusTimeMin = null;

    #[ORM\Column(nullable: true)]
    private ?int $autofocusIntervalMin = null;

    #[ORM\Column(nullable: true)]
    private ?int $meridianFlipTimeMin = null;

    #[ORM\Column(nullable: true)]
    private ?int $minShootTimeMin = null;

    #[ORM\Column(name: 'imaging_type', length: 4, options: ['default' => 'MONO'])]
    private string $imagingType = 'MONO';

    #[ORM\Column(name: 'camera_gain', nullable: true)]
    private ?int $cameraGain = null;

    #[ORM\Column(name: 'camera_offset', nullable: true)]
    private ?int $cameraOffset = null;

    #[ORM\Column(name: 'camera_cooling_temp', type: 'float', nullable: true)]
    private ?float $cameraCoolingTemp = null;

    #[ORM\Column(name: 'camera_binning', nullable: true)]
    private ?int $cameraBinning = null;

    #[ORM\Column(name: 'dither_every', nullable: true)]
    private ?int $ditherEvery = null;

    #[ORM\Column(name: 'filters_config', type: 'json', nullable: true)]
    private ?array $filtersConfig = null;

    /**
     * @var Collection<int, SetupPart>
     */
    #[ORM\OneToMany(targetEntity: SetupPart::class, mappedBy: 'setup', orphanRemoval: true, cascade: ['persist'])]
    private Collection $setupParts;

    public function __construct()
    {
        $this->setupParts = new ArrayCollection();
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

    public function getAuthor(): ?Author
    {
        return $this->author;
    }

    public function setAuthor(?Author $author): static
    {
        $this->author = $author;

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

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): static
    {
        $this->notes = $notes;

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

    /**
     * @return Collection<int, SetupPart>
     */
    public function getSetupParts(): Collection
    {
        return $this->setupParts;
    }

    public function addSetupPart(SetupPart $setupPart): static
    {
        if (!$this->setupParts->contains($setupPart)) {
            $this->setupParts->add($setupPart);
            $setupPart->setSetup($this);
        }

        return $this;
    }

    public function removeSetupPart(SetupPart $setupPart): static
    {
        if ($this->setupParts->removeElement($setupPart)) {
            // set the owning side to null (unless already changed)
            if ($setupPart->getSetup() === $this) {
                $setupPart->setSetup(null);
            }
        }

        return $this;
    }

    public function getSensorWPx(): ?int
    {
        return $this->sensorWPx;
    }

    public function setSensorWPx(?int $sensorWPx): static
    {
        $this->sensorWPx = $sensorWPx;

        return $this;
    }

    public function getSensorHPx(): ?int
    {
        return $this->sensorHPx;
    }

    public function setSensorHPx(?int $sensorHPx): static
    {
        $this->sensorHPx = $sensorHPx;

        return $this;
    }

    public function getPixelSizeUm(): ?float
    {
        return $this->pixelSizeUm;
    }

    public function setPixelSizeUm(?float $pixelSizeUm): static
    {
        $this->pixelSizeUm = $pixelSizeUm;

        return $this;
    }

    public function getFocalMm(): ?float
    {
        return $this->focalMm;
    }

    public function setFocalMm(?float $focalMm): static
    {
        $this->focalMm = $focalMm;

        return $this;
    }

    public function getSlewTimeMin(): ?int
    {
        return $this->slewTimeMin;
    }

    public function setSlewTimeMin(?int $slewTimeMin): static
    {
        $this->slewTimeMin = $slewTimeMin;

        return $this;
    }

    public function getAutofocusTimeMin(): ?int
    {
        return $this->autofocusTimeMin;
    }

    public function setAutofocusTimeMin(?int $autofocusTimeMin): static
    {
        $this->autofocusTimeMin = $autofocusTimeMin;

        return $this;
    }

    public function getAutofocusIntervalMin(): ?int
    {
        return $this->autofocusIntervalMin;
    }

    public function setAutofocusIntervalMin(?int $autofocusIntervalMin): static
    {
        $this->autofocusIntervalMin = $autofocusIntervalMin;

        return $this;
    }

    public function getMeridianFlipTimeMin(): ?int
    {
        return $this->meridianFlipTimeMin;
    }

    public function setMeridianFlipTimeMin(?int $meridianFlipTimeMin): static
    {
        $this->meridianFlipTimeMin = $meridianFlipTimeMin;

        return $this;
    }

    public function getMinShootTimeMin(): ?int
    {
        return $this->minShootTimeMin;
    }

    public function setMinShootTimeMin(?int $minShootTimeMin): static
    {
        $this->minShootTimeMin = $minShootTimeMin;

        return $this;
    }

    public function getImagingType(): string
    {
        return $this->imagingType;
    }

    public function setImagingType(string $imagingType): static
    {
        $this->imagingType = $imagingType;

        return $this;
    }

    public function getCameraGain(): ?int
    {
        return $this->cameraGain;
    }

    public function setCameraGain(?int $cameraGain): static
    {
        $this->cameraGain = $cameraGain;

        return $this;
    }

    public function getCameraOffset(): ?int
    {
        return $this->cameraOffset;
    }

    public function setCameraOffset(?int $cameraOffset): static
    {
        $this->cameraOffset = $cameraOffset;

        return $this;
    }

    public function getCameraCoolingTemp(): ?float
    {
        return $this->cameraCoolingTemp;
    }

    public function setCameraCoolingTemp(?float $cameraCoolingTemp): static
    {
        $this->cameraCoolingTemp = $cameraCoolingTemp;

        return $this;
    }

    public function getCameraBinning(): ?int
    {
        return $this->cameraBinning;
    }

    public function setCameraBinning(?int $cameraBinning): static
    {
        $this->cameraBinning = $cameraBinning;

        return $this;
    }

    public function getDitherEvery(): ?int
    {
        return $this->ditherEvery;
    }

    public function setDitherEvery(?int $ditherEvery): static
    {
        $this->ditherEvery = $ditherEvery;

        return $this;
    }

    public function getFiltersConfig(): ?array
    {
        return $this->filtersConfig;
    }

    public function setFiltersConfig(?array $filtersConfig): static
    {
        $this->filtersConfig = $filtersConfig;

        return $this;
    }
}
