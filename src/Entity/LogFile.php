<?php
namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class LogFile
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column] private ?int $id = null;
    #[ORM\ManyToOne(inversedBy: 'logs')] private ?Session $session = null;
    #[ORM\Column(length: 32)] private string $source; // NINA|PHD2|PI
    #[ORM\Column(length: 1024)] private string $path;
    #[ORM\Column(length: 64)] private string $hash;
    #[ORM\Column(type: 'boolean')] private bool $parsed = false;
    #[ORM\Column(type: 'json', nullable: true)] private ?array $parseErrors = null;
}
