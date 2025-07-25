<?php
// src/Plugins/Billing/Service/BillingService.php

namespace App\Plugins\Billing\Service;

use App\Plugins\Organizations\Entity\OrganizationEntity;
use App\Plugins\Billing\Entity\OrganizationSubscriptionEntity;
use App\Plugins\Billing\Repository\BillingPlanRepository;
use App\Plugins\Billing\Repository\OrganizationSubscriptionRepository;
use App\Service\CrudManager;

class BillingService
{
    const PLAN_FREE = 1;
    const PLAN_PROFESSIONAL = 2;
    const PLAN_BUSINESS = 3;
    const PLAN_ENTERPRISE = 4;

    private array $planLevels = [
        'free' => self::PLAN_FREE,
        'professional' => self::PLAN_PROFESSIONAL,
        'business' => self::PLAN_BUSINESS,
        'enterprise' => self::PLAN_ENTERPRISE
    ];

    public function __construct(
        private CrudManager $crudManager,
        private BillingPlanRepository $planRepository,
        private OrganizationSubscriptionRepository $subscriptionRepository
    ) {}

    public function getOrganizationPlanLevel(OrganizationEntity $organization): int
    {
        $subscription = $this->subscriptionRepository->findOneBy([
            'organization' => $organization
        ]);
        
        if (!$subscription || !$subscription->isActive()) {
            return self::PLAN_FREE;
        }
        
        $planSlug = $subscription->getPlan()->getSlug();
        return $this->planLevels[$planSlug] ?? self::PLAN_FREE;
    }

    public function getOrganizationSubscription(OrganizationEntity $organization): ?OrganizationSubscriptionEntity
    {
        return $this->subscriptionRepository->findOneBy([
            'organization' => $organization
        ]);
    }

    public function canAddMember(OrganizationEntity $organization): bool
    {
        $subscription = $this->getOrganizationSubscription($organization);
        
        if (!$subscription) {
            $currentMembers = $this->countOrganizationMembers($organization);
            return $currentMembers < 1;
        }
        
        $totalSeats = $subscription->getTotalSeats();
        $currentMembers = $this->countOrganizationMembers($organization);
        return $currentMembers < $totalSeats;
    }

    public function countOrganizationMembers(OrganizationEntity $organization): int
    {
        $result = $this->crudManager->findMany(
            'App\Plugins\Organizations\Entity\UserOrganizationEntity',
            [],
            1,
            1,
            ['organization' => $organization],
            null,
            true
        );
        
        return $result['total'] ?? 0;
    }

    public function getPlanBySlug(string $slug): ?BillingPlanEntity
    {
        return $this->planRepository->findOneBy(['slug' => $slug]);
    }

    public function getAvailablePlans(): array
    {
        return $this->planRepository->findBy(
            ['slug' => ['professional', 'business']], 
            ['priceMonthly' => 'ASC']
        );
    }
}