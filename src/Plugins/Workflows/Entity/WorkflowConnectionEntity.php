<?php
// src/Plugins/Workflows/Entity/WorkflowConnectionEntity.php

namespace App\Plugins\Workflows\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Plugins\Workflows\Repository\WorkflowConnectionRepository;

#[ORM\Entity(repositoryClass: WorkflowConnectionRepository::class)]
#[ORM\Table(name: 'workflow_connections')]
class WorkflowConnectionEntity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: WorkflowEntity::class)]
    #[ORM\JoinColumn(nullable: false)]
    private WorkflowEntity $workflow;

    #[ORM\ManyToOne(targetEntity: WorkflowNodeEntity::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?WorkflowNodeEntity $fromNode = null;

    #[ORM\ManyToOne(targetEntity: WorkflowNodeEntity::class)]
    #[ORM\JoinColumn(nullable: false)]
    private WorkflowNodeEntity $toNode;

    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    private ?string $conditionType = null; // 'true', 'false', null

    #[ORM\Column(type: 'integer')]
    private int $priority = 0;

    #[ORM\Column(type: 'datetime')]
    private \DateTime $created;

    public function __construct()
    {
        $this->created = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getWorkflow(): WorkflowEntity
    {
        return $this->workflow;
    }

    public function setWorkflow(WorkflowEntity $workflow): self
    {
        $this->workflow = $workflow;
        return $this;
    }

    public function getFromNode(): ?WorkflowNodeEntity
    {
        return $this->fromNode;
    }

    public function setFromNode(?WorkflowNodeEntity $fromNode): self
    {
        $this->fromNode = $fromNode;
        return $this;
    }

    public function getToNode(): WorkflowNodeEntity
    {
        return $this->toNode;
    }

    public function setToNode(WorkflowNodeEntity $toNode): self
    {
        $this->toNode = $toNode;
        return $this;
    }

    public function getConditionType(): ?string
    {
        return $this->conditionType;
    }

    public function setConditionType(?string $conditionType): self
    {
        $this->conditionType = $conditionType;
        return $this;
    }

    public function getPriority(): int
    {
        return $this->priority;
    }

    public function setPriority(int $priority): self
    {
        $this->priority = $priority;
        return $this;
    }

    public function getCreated(): \DateTime
    {
        return $this->created;
    }

    public function setCreated(\DateTime $created): self
    {
        $this->created = $created;
        return $this;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'workflow_id' => $this->workflow->getId(),
            'from_node_id' => $this->fromNode ? $this->fromNode->getId() : null,
            'to_node_id' => $this->toNode->getId(),
            'condition_type' => $this->conditionType,
            'priority' => $this->priority,
            'created' => $this->created->format('Y-m-d H:i:s'),
        ];
    }
}