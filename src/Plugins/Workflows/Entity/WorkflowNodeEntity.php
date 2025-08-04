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

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $name = null;

    #[ORM\Column(type: 'json')]
    private array $config = [];

    #[ORM\Column(type: 'integer')]
    private int $positionX = 0;

    #[ORM\Column(type: 'integer')]
    private int $positionY = 0;

    #[ORM\Column(name: 'created_at', type: 'datetime')]
    private \DateTime $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime')]
    private \DateTime $updatedAt;

    #[ORM\Column(type: 'boolean')]
    private bool $deleted = false;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
        $this->updatedAt = new \DateTime();
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

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): self
    {
        $this->name = $name;
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

    public function getCreatedAt(): \DateTime
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTime $createdAt): self
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getUpdatedAt(): \DateTime
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTime $updatedAt): self
    {
        $this->updatedAt = $updatedAt;
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
            'name' => $this->name,
            'config' => $this->config,
            'position_x' => $this->positionX,
            'position_y' => $this->positionY,
            'created_at' => $this->createdAt->format('Y-m-d H:i:s'),
            'updated_at' => $this->updatedAt->format('Y-m-d H:i:s'),
        ];
    }
}