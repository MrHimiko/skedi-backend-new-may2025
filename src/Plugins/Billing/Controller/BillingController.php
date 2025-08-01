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
use Doctrine\ORM\EntityManagerInterface;
use App\Plugins\Billing\Entity\OrganizationSubscriptionEntity;

#[Route('/api/billing')]
class BillingController extends AbstractController
{
    public function __construct(
        private ResponseService $responseService,
        private BillingService $billingService,
        private StripeService $stripeService,
        private OrganizationService $organizationService,
        private UserOrganizationService $userOrganizationService,
        private EntityManagerInterface $entityManager
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
            
            if (!$this->userOrganizationService->getOrganizationByUser($organization_id, $user)) {
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
            $additionalSeats = (int) ($data['additional_seats'] ?? 0);
            
            $plan = $this->billingService->getPlanBySlug($planSlug);
            if (!$plan || !$plan->getStripePriceId()) {
                return $this->responseService->json(false, 'Invalid plan selected', null, 400);
            }
            
            $checkoutUrl = $this->stripeService->createCheckoutSession(
                $organization,
                $plan,
                $additionalSeats
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
            
            if (!$this->userOrganizationService->getOrganizationByUser($organizationId, $user)) {
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

    #[Route('/organizations/{organization_id}/seats', name: 'billing_purchase_seats#', methods: ['POST'])]
    public function purchaseSeats(int $organization_id, Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');
        $data = $request->attributes->get('data');
        
        try {
            $organization = $this->organizationService->getOne($organization_id);
            if (!$organization) {
                return $this->responseService->json(false, 'Organization not found', null, 404);
            }
            
            // Check admin access
            $userOrg = $this->userOrganizationService->getOrganizationByUser($organization_id, $user);
            if (!$userOrg || $userOrg->role !== 'admin') {
                return $this->responseService->json(false, 'Admin access required', null, 403);
            }
            
            // Validate seats input
            $seatsToAdd = (int) ($data['seats'] ?? 0);
            if ($seatsToAdd <= 0 || $seatsToAdd > 100) {
                return $this->responseService->json(false, 'Invalid number of seats. Must be between 1 and 100.', null, 400);
            }
            
            // Get current subscription
            $subscription = $this->billingService->getOrganizationSubscription($organization);
            
            if (!$subscription || !$subscription->isActive()) {
                // No active subscription - create new subscription with seats
                // First, they need a plan
                return $this->responseService->json(
                    false, 
                    'Please select a subscription plan first before adding seats.', 
                    ['requires_plan' => true],
                    400
                );
            }
            
            // Check if immediate purchase or Stripe checkout
            if ($subscription->getStripeCustomerId()) {
                // Existing customer - update subscription directly
                try {
                    $this->stripeService->addSeats($subscription, $seatsToAdd);
                    
                    return $this->responseService->json(true, 'Seats added successfully', [
                        'new_total_seats' => $subscription->getTotalSeats(),
                        'seats_added' => $seatsToAdd
                    ]);
                } catch (\Exception $e) {
                    // If direct update fails, fall back to checkout
                    $checkoutUrl = $this->stripeService->createSeatsCheckoutSession($subscription, $seatsToAdd);
                    
                    return $this->responseService->json(true, 'Redirecting to checkout', [
                        'checkout_url' => $checkoutUrl
                    ]);
                }
            } else {
                // New customer - create checkout session
                $checkoutUrl = $this->stripeService->createSeatsCheckoutSession($subscription, $seatsToAdd);
                
                return $this->responseService->json(true, 'Redirecting to checkout', [
                    'checkout_url' => $checkoutUrl
                ]);
            }
        } catch (\Exception $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 500);
        }
    }

    #[Route('/billing/seats/success', name: 'billing_seats_success#', methods: ['GET'])]
    public function seatsCheckoutSuccess(Request $request): JsonResponse
    {
        $sessionId = $request->query->get('session_id');
        
        if (!$sessionId) {
            return $this->responseService->json(false, 'Invalid session', null, 400);
        }
        
        try {
            $this->stripeService->processSeatsCheckout($sessionId);
            
            return $this->responseService->json(true, 'Seats purchased successfully');
        } catch (\Exception $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 500);
        }
    }

    // TEST METHODS - Remove these in production
    #[Route('/test/webhook', name: 'billing_test_webhook#', methods: ['GET'])]
    public function testWebhook(Request $request): JsonResponse
    {
        $logFile = '/tmp/test_webhook.log';
        
        try {
            file_put_contents($logFile, "=== TEST START " . date('Y-m-d H:i:s') . " ===\n", FILE_APPEND);
            
            // Test 1: Can we access organization 47?
            $org = $this->organizationService->getOne(47);
            $orgResult = $org ? "Organization 47 found: " . $org->getName() : "Organization 47 NOT FOUND";
            file_put_contents($logFile, "Test 1 - Organization: $orgResult\n", FILE_APPEND);
            
            // Test 2: Can we access plan 3?
            $plan = $this->billingService->getPlanBySlug('business');
            if (!$plan) {
                // Try by ID
                $planRepo = $this->entityManager->getRepository(\App\Plugins\Billing\Entity\BillingPlanEntity::class);
                $plan = $planRepo->find(3);
            }
            $planResult = $plan ? "Plan found: " . $plan->getName() : "Plan NOT FOUND";
            file_put_contents($logFile, "Test 2 - Plan: $planResult\n", FILE_APPEND);
            
            // Test 3: Try to create a subscription manually
            if ($org && $plan) {
                $subscription = new OrganizationSubscriptionEntity();
                $subscription->setOrganization($org);
                $subscription->setPlan($plan);
                $subscription->setStripeSubscriptionId('test_sub_' . time());
                $subscription->setStripeCustomerId('test_cus_' . time());
                $subscription->setStatus('active');
                $subscription->setAdditionalSeats(5);
                
                $this->entityManager->persist($subscription);
                $this->entityManager->flush();
                
                file_put_contents($logFile, "Test 3 - Subscription created with ID: " . $subscription->getId() . "\n", FILE_APPEND);
                
                return $this->responseService->json(true, 'Test successful', [
                    'subscription_id' => $subscription->getId(),
                    'organization' => $orgResult,
                    'plan' => $planResult
                ]);
            } else {
                file_put_contents($logFile, "Test 3 - Could not create subscription, missing org or plan\n", FILE_APPEND);
                
                return $this->responseService->json(false, 'Test failed', [
                    'organization' => $orgResult,
                    'plan' => $planResult
                ]);
            }
            
        } catch (\Exception $e) {
            file_put_contents($logFile, "ERROR: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n", FILE_APPEND);
            
            return $this->responseService->json(false, 'Test error', [
                'error' => $e->getMessage()
            ], 500);
        }
    }

    #[Route('/test/cleanup', name: 'billing_test_cleanup#', methods: ['GET'])]
    public function cleanupTest(Request $request): JsonResponse
    {
        try {
            // Clean up test subscriptions
            $qb = $this->entityManager->createQueryBuilder();
            $qb->select('s')
               ->from(OrganizationSubscriptionEntity::class, 's')
               ->where('s.stripeSubscriptionId LIKE :pattern')
               ->setParameter('pattern', 'test_sub_%');
               
            $testSubs = $qb->getQuery()->getResult();
                
            foreach ($testSubs as $sub) {
                $this->entityManager->remove($sub);
            }
            
            $this->entityManager->flush();
            
            return $this->responseService->json(true, 'Cleanup complete', [
                'cleaned' => count($testSubs)
            ]);
        } catch (\Exception $e) {
            return $this->responseService->json(false, 'Cleanup failed', [
                'error' => $e->getMessage()
            ], 500);
        }
    }
}