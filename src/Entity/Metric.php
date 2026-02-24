<?php
namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class Metric
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column] private ?int $id = null;
    #[ORM\ManyToOne] private ?Session $session = null;
    #[ORM\Column(length: 32)] private string $kind; // GUIDE_RA, GUIDE_DEC, HFD, TEMP, etc.
    #[ORM\Column(type: 'datetime')] private \DateTimeInterface $t;
    #[ORM\Column(type: 'float')] private float $value;
    #[ORM\Column(type: 'json', nullable: true)] private ?array $extras = null;
}
