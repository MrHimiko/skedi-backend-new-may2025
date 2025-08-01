<?php
// src/Plugins/Billing/Service/BillingService.php

namespace App\Plugins\Billing\Service;

use App\Plugins\Organizations\Entity\OrganizationEntity;
use App\Plugins\Billing\Entity\OrganizationSubscriptionEntity;
use App\Plugins\Billing\Repository\BillingPlanRepository;
use App\Plugins\Billing\Repository\OrganizationSubscriptionRepository;
use App\Service\CrudManager;
use App\Plugins\Invitations\Repository\InvitationRepository;
use Doctrine\ORM\EntityManagerInterface;

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
        private OrganizationSubscriptionRepository $subscriptionRepository,
        private InvitationRepository $invitationRepository,
        private EntityManagerInterface $entityManager
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

    /**
     * Check if organization can add a new member (including pending invitations)
     */
    public function canAddMember(OrganizationEntity $organization): bool
    {
        $seatInfo = $this->getOrganizationSeatInfo($organization);
        return $seatInfo['available'] > 0;
    }

    /**
     * Get detailed seat information for an organization
     */
    public function getOrganizationSeatInfo(OrganizationEntity $organization): array
    {
        $subscription = $this->getOrganizationSubscription($organization);
        
        // Base: 1 seat for creator/admin (always included)
        $totalSeats = 1;
        
        if ($subscription && $subscription->isActive()) {
            $totalSeats += $subscription->getAdditionalSeats();
        }
        
        // Count current members
        $currentMembers = $this->countOrganizationMembers($organization);
        
        // Count pending invitations (both org and team invitations)
        $pendingInvitations = $this->countPendingInvitations($organization);
        
        $used = $currentMembers + $pendingInvitations;
        $available = max(0, $totalSeats - $used);
        
        return [
            'total' => $totalSeats,
            'additional_seats' => $subscription ? $subscription->getAdditionalSeats() : 0,
            'used' => $used,
            'current_members' => $currentMembers,
            'pending_invitations' => $pendingInvitations,
            'available' => $available,
            'needs_seats' => $available <= 0,
            'has_subscription' => $subscription && $subscription->isActive()
        ];
    }

    /**
     * Count all members in the organization
     */
    public function countOrganizationMembers(OrganizationEntity $organization): int
    {
        // Get all members using CrudManager
        $members = $this->crudManager->findMany(
            'App\Plugins\Organizations\Entity\UserOrganizationEntity',
            [],
            1,
            9999,  // High limit to get all
            ['organization' => $organization]
        );
        
        return count($members);
    }

    /**
     * Count pending invitations for the organization (including team invitations)
     */
    public function countPendingInvitations(OrganizationEntity $organization): int
    {
        // Get all pending invitations using CrudManager
        $invitations = $this->crudManager->findMany(
            'App\Plugins\Invitations\Entity\InvitationEntity',
            [],
            1,
            9999,  // High limit to get all
            [
                'organization' => $organization,
                'status' => 'pending',
                'deleted' => false
            ]
        );
        
        return count($invitations);
    }

    /**
     * Check if organization is compliant with seat limits
     * Returns array with compliance status and details
     */
    public function checkOrganizationCompliance(OrganizationEntity $organization): array
    {
        $seatInfo = $this->getOrganizationSeatInfo($organization);
        
        $isCompliant = $seatInfo['used'] <= $seatInfo['total'];
        $overageCount = max(0, $seatInfo['used'] - $seatInfo['total']);
        
        return [
            'is_compliant' => $isCompliant,
            'seat_info' => $seatInfo,
            'overage_count' => $overageCount,
            'required_additional_seats' => $overageCount
        ];
    }

    /**
     * Get organizations that are non-compliant (more members than seats)
     */
    public function getNonCompliantOrganizations(): array
    {
        $organizations = $this->entityManager->getRepository(OrganizationEntity::class)
            ->findBy(['deleted' => false]);
            
        $nonCompliant = [];
        
        foreach ($organizations as $organization) {
            $compliance = $this->checkOrganizationCompliance($organization);
            if (!$compliance['is_compliant']) {
                $nonCompliant[] = [
                    'organization' => $organization,
                    'compliance' => $compliance
                ];
            }
        }
        
        return $nonCompliant;
    }

    public function getPlanBySlug(string $slug): ?\App\Plugins\Billing\Entity\BillingPlanEntity
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

    /**
     * Calculate how many additional seats are needed
     */
    public function calculateRequiredSeats(OrganizationEntity $organization, int $newInvitations = 1): int
    {
        $seatInfo = $this->getOrganizationSeatInfo($organization);
        $totalNeeded = $seatInfo['used'] + $newInvitations;
        $currentTotal = $seatInfo['total'];
        
        return max(0, $totalNeeded - $currentTotal);
    }
}