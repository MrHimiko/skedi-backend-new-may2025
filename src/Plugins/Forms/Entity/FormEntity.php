<?php

namespace App\Plugins\Forms\Entity;

use Doctrine\ORM\Mapping as ORM;
use DateTime;
use DateTimeInterface;
use App\Plugins\Organizations\Entity\OrganizationEntity;
use App\Plugins\Account\Entity\UserEntity;

#[ORM\Entity(repositoryClass: "App\Plugins\Forms\Repository\FormRepository")]
#[ORM\Table(name: "forms")]
#[ORM\HasLifecycleCallbacks]
class FormEntity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: "id", type: "bigint")]
    private int $id;

    #[ORM\Column(name: "name", type: "string", length: 255, nullable: false)]
    private string $name;

    #[ORM\Column(name: "slug", type: "string", length: 255, nullable: false, unique: true)]
    private string $slug;

    #[ORM\Column(name: "description", type: "text", nullable: true)]
    private ?string $description = null;

    #[ORM\ManyToOne(targetEntity: OrganizationEntity::class)]
    #[ORM\JoinColumn(name: "organization_id", referencedColumnName: "id", nullable: false)]
    private OrganizationEntity $organization;

    #[ORM\ManyToOne(targetEntity: UserEntity::class)]
    #[ORM\JoinColumn(name: "created_by", referencedColumnName: "id", nullable: false)]
    private UserEntity $createdBy;

    #[ORM\Column(name: "fields_json", type: "json", nullable: false)]
    private array $fieldsJson = [];

    #[ORM\Column(name: "settings_json", type: "json", nullable: true)]
    private ?array $settingsJson = [];

    #[ORM\Column(name: "is_active", type: "boolean", options: ["default" => true])]
    private bool $isActive = true;

    #[ORM\Column(name: "allow_multiple_submissions", type: "boolean", options: ["default" => true])]
    private bool $allowMultipleSubmissions = true;

    #[ORM\Column(name: "requires_authentication", type: "boolean", options: ["default" => false])]
    private bool $requiresAuthentication = false;

    #[ORM\Column(name: "deleted", type: "boolean", options: ["default" => false])]
    private bool $deleted = false;

    #[ORM\Column(name: "created", type: "datetime", nullable: false, options: ["default" => "CURRENT_TIMESTAMP"])]
    private DateTimeInterface $created;

    #[ORM\Column(name: "updated", type: "datetime", nullable: false, options: ["default" => "CURRENT_TIMESTAMP"])]
    private DateTimeInterface $updated;

    public function __construct()
    {
        $this->created = new DateTime();
        $this->updated = new DateTime();
    }

    public function getId(): int
    {
        return $this->id;
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

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): self
    {
        $this->slug = $slug;
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

    public function getOrganization(): OrganizationEntity
    {
        return $this->organization;
    }

    public function setOrganization(OrganizationEntity $organization): self
    {
        $this->organization = $organization;
        return $this;
    }

    public function getCreatedBy(): UserEntity
    {
        return $this->createdBy;
    }

    public function setCreatedBy(UserEntity $createdBy): self
    {
        $this->createdBy = $createdBy;
        return $this;
    }


    public function getDefaultFields(): array
    {
        return [
            [
                'type' => 'text',
                'name' => self::SYSTEM_FIELD_NAME,
                'label' => 'Your Name',
                'placeholder' => 'Enter your name',
                'required' => true,
                'deletable' => false,
                'system_field' => true,
                'order' => 1
            ],
            [
                'type' => 'email',
                'name' => self::SYSTEM_FIELD_EMAIL,
                'label' => 'Email Address',
                'placeholder' => 'your@email.com',
                'required' => true,
                'deletable' => false,
                'system_field' => true,
                'order' => 2
            ],
            [
                'type' => 'guest_repeater',
                'name' => self::GUEST_REPEATER_FIELD,
                'label' => 'Add Guests',
                'placeholder' => '',
                'required' => false,
                'deletable' => true,
                'system_field' => false,
                'max_guests' => 10,
                'order' => 3
            ]
        ];
    }
    
    const SYSTEM_FIELD_NAME = 'name';
    const SYSTEM_FIELD_EMAIL = 'email';
    const GUEST_REPEATER_FIELD = 'additional_guests';

    /**
     * Ensure system fields are always present in fields JSON
     */
    public function setFieldsJson(array $fieldsJson): self
    {
        // Get system fields
        $systemFields = array_filter($this->getDefaultFields(), function($field) {
            return isset($field['system_field']) && $field['system_field'] === true;
        });
        
        // Check which system fields are missing
        $existingSystemFieldNames = [];
        foreach ($fieldsJson as $field) {
            if (isset($field['system_field']) && $field['system_field'] === true) {
                $existingSystemFieldNames[] = $field['name'];
            }
        }
        
        // Add missing system fields at the beginning
        foreach ($systemFields as $systemField) {
            if (!in_array($systemField['name'], $existingSystemFieldNames)) {
                array_unshift($fieldsJson, $systemField);
            }
        }
        
        // Ensure system fields can't be deleted
        foreach ($fieldsJson as &$field) {
            if (isset($field['name']) && in_array($field['name'], [self::SYSTEM_FIELD_NAME, self::SYSTEM_FIELD_EMAIL])) {
                $field['deletable'] = false;
                $field['system_field'] = true;
                $field['required'] = true;
            }
        }
        
        $this->fieldsJson = $fieldsJson;
        return $this;
    }

    public function getFieldsJson(): array
    {
        return $this->fieldsJson;
    }


    public function getSettingsJson(): ?array
    {
        return $this->settingsJson;
    }

    public function setSettingsJson(?array $settingsJson): self
    {
        $this->settingsJson = $settingsJson;
        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): self
    {
        $this->isActive = $isActive;
        return $this;
    }

    public function isAllowMultipleSubmissions(): bool
    {
        return $this->allowMultipleSubmissions;
    }

    public function setAllowMultipleSubmissions(bool $allowMultipleSubmissions): self
    {
        $this->allowMultipleSubmissions = $allowMultipleSubmissions;
        return $this;
    }

    public function isRequiresAuthentication(): bool
    {
        return $this->requiresAuthentication;
    }

    public function setRequiresAuthentication(bool $requiresAuthentication): self
    {
        $this->requiresAuthentication = $requiresAuthentication;
        return $this;
    }

    public function isDeleted(): bool
    {
        return $this->deleted;
    }

    public function setDeleted(bool $deleted): self
    {
        $this->deleted = $deleted;
        return $this;
    }

    public function getCreated(): DateTimeInterface
    {
        return $this->created;
    }

    public function getUpdated(): DateTimeInterface
    {
        return $this->updated;
    }

    #[ORM\PreUpdate]
    public function updateTimestamp(): void
    {
        $this->updated = new DateTime();
    }

    public function toArray(): array
    {
        return [
            'id' => $this->getId(),
            'name' => $this->getName(),
            'slug' => $this->getSlug(),
            'description' => $this->getDescription(),
            'organization_id' => $this->getOrganization()->getId(),
            'created_by' => $this->getCreatedBy()->getId(),
            'fields' => $this->getFieldsJson(),
            'settings' => $this->getSettingsJson(),
            'is_active' => $this->isActive(),
            'allow_multiple_submissions' => $this->isAllowMultipleSubmissions(),
            'requires_authentication' => $this->isRequiresAuthentication(),
            'deleted' => $this->isDeleted(),
            'created' => $this->getCreated()->format('Y-m-d H:i:s'),
            'updated' => $this->getUpdated()->format('Y-m-d H:i:s'),
        ];
    }
}