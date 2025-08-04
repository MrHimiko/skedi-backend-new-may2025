<?php
// src/Plugins/Workflows/Entity/WorkflowNodeEntity.php

namespace App\Plugins\Workflows\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Plugins\Workflows\Repository\WorkflowNodeRepository;

#[ORM\Entity(repositoryClass: WorkflowNodeRepository::class)]
#[ORM\Table(name: 'workflow_nodes')]
class WorkflowNodeEntity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: WorkflowEntity::class, inversedBy: 'nodes')]
    #[ORM\JoinColumn(nullable: false)]
    private WorkflowEntity $workflow;

    #[ORM\Column(type: 'string', length: 50)]
    private string $nodeType; // 'action', 'condition'

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $actionType = null; // 'email.send', 'webhook.send'

    #[ORM\Column(type: 'json')]
    private array $config = [];

    #[ORM\Column(type: 'integer')]
    private int $positionX = 0;

    #[ORM\Column(type: 'integer')]
    private int $positionY = 0;

    #[ORM\Column(type: 'datetime')]
    private \DateTime $created;

    #[ORM\Column(type: 'datetime')]
    private \DateTime $updated;

    #[ORM\Column(type: 'boolean')]
    private bool $deleted = false;

    public function __construct()
    {
        $this->created = new \DateTime();
        $this->updated = new \DateTime();
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

    public function getNodeType(): string
    {
        return $this->nodeType;
    }

    public function setNodeType(string $nodeType): self
    {
        $this->nodeType = $nodeType;
        return $this;
    }

    public function getActionType(): ?string
    {
        return $this->actionType;
    }

    public function setActionType(?string $actionType): self
    {
        $this->actionType = $actionType;
        return $this;
    }

    public function getConfig(): array
    {
        return $this->config;
    }

    public function setConfig(array $config): self
    {
        $this->config = $config;
        return $this;
    }

    public function getPositionX(): int
    {
        return $this->positionX;
    }

    public function setPositionX(int $positionX): self
    {
        $this->positionX = $positionX;
        return $this;
    }

    public function getPositionY(): int
    {
        return $this->positionY;
    }

    public function setPositionY(int $positionY): self
    {
        $this->positionY = $positionY;
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

    public function getUpdated(): \DateTime
    {
        return $this->updated;
    }

    public function setUpdated(\DateTime $updated): self
    {
        $this->updated = $updated;
        return $this;
    }

    public function getDeleted(): bool
    {
        return $this->deleted;
    }

    public function setDeleted(bool $deleted): self
    {
        $this->deleted = $deleted;
        return $this;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'workflow_id' => $this->workflow->getId(),
            'node_type' => $this->nodeType,
            'action_type' => $this->actionType,
            'config' => $this->config,
            'position_x' => $this->positionX,
            'position_y' => $this->positionY,
            'created' => $this->created->format('Y-m-d H:i:s'),
            'updated' => $this->updated->format('Y-m-d H:i:s'),
        ];
    }
}