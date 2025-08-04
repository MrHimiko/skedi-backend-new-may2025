<?php
// src/Plugins/Workflows/Entity/WorkflowEntity.php

namespace App\Plugins\Workflows\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Plugins\Workflows\Repository\WorkflowRepository;
use App\Plugins\Organizations\Entity\OrganizationEntity;
use App\Plugins\Account\Entity\UserEntity;

#[ORM\Entity(repositoryClass: WorkflowRepository::class)]
#[ORM\Table(name: 'workflows')]
class WorkflowEntity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: OrganizationEntity::class)]
    #[ORM\JoinColumn(nullable: false)]
    private OrganizationEntity $organization;

    #[ORM\Column(type: 'string', length: 255)]
    private string $name;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: 'string', length: 100)]
    private string $triggerType;

    #[ORM\Column(type: 'json')]
    private array $triggerConfig = [];

    #[ORM\Column(type: 'string', length: 50)]
    private string $status = 'inactive';

    #[ORM\Column(type: 'datetime')]
    private \DateTime $created;

    #[ORM\Column(type: 'datetime')]
    private \DateTime $updated;

    #[ORM\ManyToOne(targetEntity: UserEntity::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?UserEntity $createdBy = null;

    #[ORM\Column(type: 'boolean')]
    private bool $deleted = false;

    #[ORM\OneToMany(targetEntity: WorkflowNodeEntity::class, mappedBy: 'workflow', cascade: ['remove'])]
    private $nodes;

    public function __construct()
    {
        $this->created = new \DateTime();
        $this->updated = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOrganization(): OrganizationEntity
    {
        return $this->organization;
    }

    public function setOrganization(OrganizationEntity $organization): self
    {
        $this->organization = $organization;
        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;
        return $this;
    }

    public function getTriggerType(): string
    {
        return $this->triggerType;
    }

    public function setTriggerType(string $triggerType): self
    {
        $this->triggerType = $triggerType;
        return $this;
    }

    public function getTriggerConfig(): array
    {
        return $this->triggerConfig;
    }

    public function setTriggerConfig(array $triggerConfig): self
    {
        $this->triggerConfig = $triggerConfig;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;
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

    public function getCreatedBy(): ?UserEntity
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?UserEntity $createdBy): self
    {
        $this->createdBy = $createdBy;
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

    public function getNodes()
    {
        return $this->nodes;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->organization->getId(),
            'name' => $this->name,
            'description' => $this->description,
            'trigger_type' => $this->triggerType,
            'trigger_config' => $this->triggerConfig,
            'status' => $this->status,
            'created' => $this->created->format('Y-m-d H:i:s'),
            'updated' => $this->updated->format('Y-m-d H:i:s'),
            'created_by' => $this->createdBy ? $this->createdBy->getId() : null,
        ];
    }
}