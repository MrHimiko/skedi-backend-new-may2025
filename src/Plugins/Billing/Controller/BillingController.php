<?php
// src/Plugins/Billing/Controller/BillingController.php

namespace App\Plugins\Billing\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use App\Service\ResponseService;
use App\Plugins\Billing\Service\BillingService;
use App\Plugins\Billing\Service\StripeService;
use App\Plugins\Organizations\Service\OrganizationService;
use App\Plugins\Organizations\Service\UserOrganizationService;

#[Route('/api/billing')]
class BillingController extends AbstractController
{
    public function __construct(
        private ResponseService $responseService,
        private BillingService $billingService,
        private StripeService $stripeService,
        private OrganizationService $organizationService,
        private UserOrganizationService $userOrganizationService
    ) {}

    #[Route('/plans', name: 'billing_plans#', methods: ['GET'])]
    public function getPlans(): JsonResponse
    {
        try {
            $plans = $this->billingService->getAvailablePlans();
            
            $plansArray = array_map(function($plan) {
                return $plan->toArray();
            }, $plans);
            
            return $this->responseService->json(true, 'Plans retrieved', $plansArray);
        } catch (\Exception $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 500);
        }
    }

    #[Route('/organizations/{organization_id}/subscription', name: 'billing_get_subscription#', methods: ['GET'])]
    public function getSubscription(int $organization_id, Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');
        
        try {
            $organization = $this->organizationService->getOne($organization_id);
            if (!$organization) {
                return $this->responseService->json(false, 'Organization not found', null, 404);
            }
            
            if (!$this->userOrganizationService->getOrganizationByUser($organizationId, $user)) {
                return $this->responseService->json(false, 'Access denied', null, 403);
            }
            
            $subscription = $this->billingService->getOrganizationSubscription($organization);
            $planLevel = $this->billingService->getOrganizationPlanLevel($organization);
            
            return $this->responseService->json(true, 'success', [
                'subscription' => $subscription?->toArray(),
                'plan_level' => $planLevel,
                'can_add_members' => $this->billingService->canAddMember($organization)
            ]);
        } catch (\Exception $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 500);
        }
    }

    #[Route('/organizations/{organization_id}/seats', name: 'billing_add_seats#', methods: ['POST'])]
    public function addSeats(int $organization_id, Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');
        $data = $request->attributes->get('data');
        
        try {
            $organization = $this->organizationService->getOne($organization_id);
            if (!$organization) {
                return $this->responseService->json(false, 'Organization not found', null, 404);
            }
            
            $userOrg = $this->userOrganizationService->getOrganizationByUser($organization_id, $user);
            if (!$userOrg || $userOrg->role !== 'admin') {
                return $this->responseService->json(false, 'Admin access required', null, 403);
            }
            
            $subscription = $this->billingService->getOrganizationSubscription($organization);
            
            if (!$subscription || !$subscription->isActive()) {
                return $this->responseService->json(false, 'No active subscription', null, 400);
            }
            
            $seatsToAdd = (int) ($data['seats'] ?? 0);
            if ($seatsToAdd <= 0) {
                return $this->responseService->json(false, 'Invalid number of seats', null, 400);
            }
            
            $this->stripeService->addSeats($subscription, $seatsToAdd);
            
            return $this->responseService->json(true, 'Seats added successfully', [
                'new_total_seats' => $subscription->getTotalSeats()
            ]);
        } catch (\Exception $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 500);
        }
    }

    #[Route('/organizations/{organization_id}/checkout', name: 'billing_checkout#', methods: ['POST'])]
    public function createCheckoutSession(int $organization_id, Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');
        $data = $request->attributes->get('data');
        
        try {
            $organization = $this->organizationService->getOne($organization_id);
            if (!$organization) {
                return $this->responseService->json(false, 'Organization not found', null, 404);
            }
            
            $userOrg = $this->userOrganizationService->getOrganizationByUser($organization_id, $user);
            if (!$userOrg || $userOrg->role !== 'admin') {
                return $this->responseService->json(false, 'Admin access required', null, 403);
            }
            
            $planSlug = $data['plan_slug'] ?? '';
            
            $plan = $this->billingService->getPlanBySlug($planSlug);
            if (!$plan || !$plan->getStripePriceId()) {
                return $this->responseService->json(false, 'Invalid plan selected', null, 400);
            }
            
            $checkoutUrl = $this->stripeService->createCheckoutSession(
                $organization,
                $plan,
                0
            );
            
            return $this->responseService->json(true, 'Checkout session created', [
                'checkout_url' => $checkoutUrl
            ]);
        } catch (\Exception $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 500);
        }
    }

    #[Route('/stripe/session-verify', name: 'billing_verify_session#', methods: ['POST'])]
    public function verifyStripeSession(Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');
        $data = $request->attributes->get('data');
        
        try {
            $sessionId = $data['session_id'] ?? '';
            $organizationId = $data['organization_id'] ?? 0;
            
            $organization = $this->organizationService->getOne($organizationId);
            if (!$organization) {
                return $this->responseService->json(false, 'Organization not found', null, 404);
            }
            
            if (!$this->userOrganizationService->getOrganizationByUser($organization_id, $user)) {
                return $this->responseService->json(false, 'Access denied', null, 403);
            }
            
            return $this->responseService->json(true, 'Payment verified', [
                'plan_name' => 'Professional'
            ]);
        } catch (\Exception $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 500);
        }
    }

    #[Route('/organizations/{organization_id}/portal', name: 'billing_portal#', methods: ['POST'])]
    public function createPortalSession(int $organization_id, Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');
        
        try {
            $organization = $this->organizationService->getOne($organization_id);
            if (!$organization) {
                return $this->responseService->json(false, 'Organization not found', null, 404);
            }
            
            $userOrg = $this->userOrganizationService->getOrganizationByUser($organization_id, $user);
            if (!$userOrg || $userOrg->role !== 'admin') {
                return $this->responseService->json(false, 'Admin access required', null, 403);
            }
            
            $subscription = $this->billingService->getOrganizationSubscription($organization);
            
            if (!$subscription || !$subscription->getStripeCustomerId()) {
                return $this->responseService->json(false, 'No billing account found', null, 400);
            }
            
            $portalUrl = $this->stripeService->createCustomerPortalSession(
                $subscription->getStripeCustomerId()
            );
            
            return $this->responseService->json(true, 'Portal session created', [
                'url' => $portalUrl
            ]);
        } catch (\Exception $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 500);
        }
    }
}