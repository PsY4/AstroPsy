<?php
namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'target_goal')]
#[ORM\UniqueConstraint(columns: ['target_id', 'filter_name'])]
class TargetGoal
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'goals')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Target $target;

    #[ORM\Column(length: 32)]
    private string $filterName;

    #[ORM\Column(type: 'integer')]
    private int $goalSeconds = 0;

    public function getId(): ?int { return $this->id; }

    public function getTarget(): Target { return $this->target; }
    public function setTarget(Target $target): self { $this->target = $target; return $this; }

    public function getFilterName(): string { return $this->filterName; }
    public function setFilterName(string $filterName): self { $this->filterName = $filterName; return $this; }

    public function getGoalSeconds(): int { return $this->goalSeconds; }
    public function setGoalSeconds(int $goalSeconds): self { $this->goalSeconds = $goalSeconds; return $this; }
}
