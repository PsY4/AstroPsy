<?php
namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class Exposure
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column] private ?int $id = null;
    #[ORM\ManyToOne(inversedBy: 'exposures')] private ?Session $session = null;
    #[ORM\Column(length: 1024)] private string $path;
    #[ORM\Column(length: 64, unique: true)] private string $hash;
    #[ORM\Column(length: 32, nullable: true)] private ?string $format = null; // FITS|XISF|RAW
    #[ORM\Column(length: 32, nullable: true)] private ?string $type = null; // LIGHT/DARK/BIAS/FLAT
    #[ORM\Column(length: 32, nullable: true)] private ?string $filterName = null;
    #[ORM\Column(type: 'float', nullable: true)] private ?float $exposure_s = null;
    #[ORM\Column(type: 'float', nullable: true)] private ?float $sensorTemp = null;
    #[ORM\Column(type: 'datetime', nullable: true)] private ?\DateTimeInterface $dateTaken = null;
    #[ORM\Column(type: 'json', nullable: true)] private ?array $rawHeader = null;


    public function getId(): ?int { return $this->id; }
    public function getSession(): ?Session { return $this->session; }
    public function setSession(?Session $s): self { $this->session = $s; return $this; }
    public function getPath(): ?string { return $this->path; }
    public function setPath(string $p): self { $this->path = $p; return $this; }
    public function getHash(): string { return $this->hash; }
    public function setHash(string $h): self { $this->hash = $h; return $this; }
    public function getFormat(): string { return $this->format; }
    public function setFormat(string $f): self { $this->format = $f; return $this; }
    public function getFilterName(): ?string { return $this->filterName; }
    public function setFilterName(?string $n): self { $this->filterName = $n; return $this; }
    public function getExposureS(): ?float { return $this->exposure_s; }
    public function setExposureS(?float $v): self { $this->exposure_s = $v; return $this; }
    public function getSensorTemp(): ?float { return $this->sensorTemp; }
    public function setSensorTemp(?float $v): self { $this->sensorTemp = $v; return $this; }
    public function getDateTaken(): ?\DateTimeInterface { return $this->dateTaken; }
    public function setDateTaken(?\DateTimeInterface $d): self { $this->dateTaken = $d; return $this; }
    public function getRawHeader(): ?array { return $this->rawHeader; }
    public function setRawHeader(?array $h): self { $this->rawHeader = $h; return $this; }

    public function setType(string $type): Exposure
    {
        $this->type = $type;
        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }

}
