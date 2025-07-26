<?php
// src/Plugins/Billing/Entity/OrganizationSubscriptionEntity.php

namespace App\Plugins\Billing\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Plugins\Organizations\Entity\OrganizationEntity;

#[ORM\Entity(repositoryClass: "App\Plugins\Billing\Repository\OrganizationSubscriptionRepository")]
#[ORM\Table(name: "organization_subscriptions")]
class OrganizationSubscriptionEntity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: "bigint")]
    private int $id;

    #[ORM\OneToOne(targetEntity: OrganizationEntity::class)]
    #[ORM\JoinColumn(name: "organization_id", referencedColumnName: "id", nullable: false)]
    private OrganizationEntity $organization;

    #[ORM\ManyToOne(targetEntity: BillingPlanEntity::class)]
    #[ORM\JoinColumn(name: "plan_id", referencedColumnName: "id", nullable: false)]
    private BillingPlanEntity $plan;

    #[ORM\Column(name: "stripe_subscription_id", type: "string", length: 255, nullable: true)]
    private ?string $stripeSubscriptionId = null;

    #[ORM\Column(name: "stripe_customer_id", type: "string", length: 255, nullable: true)]
    private ?string $stripeCustomerId = null;

    #[ORM\Column(type: "string", length: 50)]
    private string $status = 'active';

    #[ORM\Column(name: "additional_seats", type: "integer")]
    private int $additionalSeats = 0;

    #[ORM\Column(name: "current_period_start", type: "datetime", nullable: true)]
    private ?\DateTime $currentPeriodStart = null;

    #[ORM\Column(name: "current_period_end", type: "datetime", nullable: true)]
    private ?\DateTime $currentPeriodEnd = null;

    #[ORM\Column(name: "created_at", type: "datetime")]
    private \DateTime $createdAt;

    #[ORM\Column(name: "updated_at", type: "datetime")]
    private \DateTime $updatedAt;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
        $this->updatedAt = new \DateTime();
    }

    public function getId(): int
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

    public function getPlan(): BillingPlanEntity
    {
        return $this->plan;
    }

    public function setPlan(BillingPlanEntity $plan): self
    {
        $this->plan = $plan;
        return $this;
    }

    public function getStripeSubscriptionId(): ?string
    {
        return $this->stripeSubscriptionId;
    }

    public function setStripeSubscriptionId(?string $stripeSubscriptionId): self
    {
        $this->stripeSubscriptionId = $stripeSubscriptionId;
        return $this;
    }

    public function getStripeCustomerId(): ?string
    {
        return $this->stripeCustomerId;
    }

    public function setStripeCustomerId(?string $stripeCustomerId): self
    {
        $this->stripeCustomerId = $stripeCustomerId;
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

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function getAdditionalSeats(): int
    {
        return $this->additionalSeats;
    }

    public function setAdditionalSeats(int $additionalSeats): self
    {
        $this->additionalSeats = $additionalSeats;
        return $this;
    }

    public function getCurrentPeriodStart(): ?\DateTime
    {
        return $this->currentPeriodStart;
    }

    public function setCurrentPeriodStart(?\DateTime $currentPeriodStart): self
    {
        $this->currentPeriodStart = $currentPeriodStart;
        return $this;
    }

    public function getCurrentPeriodEnd(): ?\DateTime
    {
        return $this->currentPeriodEnd;
    }

    public function setCurrentPeriodEnd(?\DateTime $currentPeriodEnd): self
    {
        $this->currentPeriodEnd = $currentPeriodEnd;
        return $this;
    }

    public function getTotalSeats(): int
    {
        return $this->plan->getIncludedSeats() + $this->additionalSeats;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->organization->getId(),
            'plan' => $this->plan->toArray(),
            'status' => $this->status,
            'additional_seats' => $this->additionalSeats,
            'total_seats' => $this->getTotalSeats(),
            'current_period_start' => $this->currentPeriodStart?->format('Y-m-d H:i:s'),
            'current_period_end' => $this->currentPeriodEnd?->format('Y-m-d H:i:s'),
            'created_at' => $this->createdAt->format('Y-m-d H:i:s'),
        ];
    }
}