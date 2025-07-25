<?php
// src/Plugins/Billing/Service/StripeWebhookService.php

namespace App\Plugins\Billing\Service;

use Stripe\StripeClient;
use Stripe\Webhook;
use App\Plugins\Organizations\Service\OrganizationService;
use App\Plugins\Billing\Entity\OrganizationSubscriptionEntity;
use App\Plugins\Billing\Repository\BillingPlanRepository;
use Doctrine\ORM\EntityManagerInterface;

class StripeWebhookService
{
    private StripeClient $stripe;
    
    public function __construct(
        private string $stripeSecretKey,
        private string $webhookSecret,
        private EntityManagerInterface $entityManager,
        private OrganizationService $organizationService,
        private BillingPlanRepository $planRepository
    ) {
        $this->stripe = new StripeClient($stripeSecretKey);
    }

    public function handleWebhook(string $payload, ?string $signature): void
    {
        echo "Webhook received\n";
        
        if ($signature) {
            $event = Webhook::constructEvent($payload, $signature, $this->webhookSecret);
        } else {
            $data = json_decode($payload, true);
            $event = $data;
        }
        
        echo "Event type: " . $event['type'] . "\n";
        
        switch ($event['type']) {
            case 'checkout.session.completed':
                echo "Processing checkout.session.completed\n";
                $this->handleCheckoutCompleted($event['data']['object']);
                break;
                
            case 'customer.subscription.created':
                echo "Processing customer.subscription.created\n";
                $this->handleSubscriptionCreated($event['data']['object']);
                break;
                
            case 'customer.subscription.updated':
            case 'customer.subscription.deleted':
                $this->handleSubscriptionUpdate($event['data']['object']);
                break;
        }
    }

    private function handleCheckoutCompleted($session): void
    {
        echo "\n\nHandling checkout.session.completed\n";
        echo "Session ID: " . $session['id'] . "\n";
        echo "Metadata: " . json_encode($session['metadata']) . "\n";
        echo "Customer: " . $session['customer'] . "\n";
        echo "Subscription: " . $session['subscription'] . "\n";
        
        $organizationId = $session['metadata']['organization_id'] ?? null;
        $planId = $session['metadata']['plan_id'] ?? null;
        
        echo "Organization ID from metadata: $organizationId\n";
        echo "Plan ID from metadata: $planId\n";
        
        if (!$organizationId || !$planId) {
            echo "ERROR: Missing organization or plan ID in metadata\n";
            exit;
        }
        
        echo "Looking up organization and plan...\n";
        
        $organization = $this->organizationService->getOne($organizationId);
        $plan = $this->planRepository->find($planId);
        
        echo "Organization found: " . ($organization ? 'YES' : 'NO') . "\n";
        echo "Plan found: " . ($plan ? 'YES' : 'NO') . "\n";
        
        exit;
    }

    private function handleSubscriptionCreated($subscription): void
    {
        echo "\n\nHandling customer.subscription.created\n";
        echo "Subscription ID: " . $subscription['id'] . "\n";
        echo "Metadata: " . json_encode($subscription['metadata']) . "\n";
        
        $organizationId = $subscription['metadata']['organization_id'] ?? null;
        $planId = $subscription['metadata']['plan_id'] ?? null;
        
        echo "Organization ID: $organizationId\n";
        echo "Plan ID: $planId\n";
        
        if (!$organizationId || !$planId) {
            echo "ERROR: Missing metadata\n";
            return;
        }
        
        $organization = $this->organizationService->getOne($organizationId);
        $plan = $this->planRepository->find($planId);
        
        if (!$organization || !$plan) {
            echo "ERROR: Organization or plan not found\n";
            return;
        }
        
        // Create or update subscription
        $sub = $this->entityManager->getRepository(OrganizationSubscriptionEntity::class)
            ->findOneBy(['organization' => $organization]);
            
        if (!$sub) {
            $sub = new OrganizationSubscriptionEntity();
            $sub->setOrganization($organization);
        }
        
        $sub->setPlan($plan);
        $sub->setStripeSubscriptionId($subscription['id']);
        $sub->setStripeCustomerId($subscription['customer']);
        $sub->setStatus($subscription['status']);
        $sub->setCurrentPeriodStart(new \DateTime('@' . $subscription['current_period_start']));
        $sub->setCurrentPeriodEnd(new \DateTime('@' . $subscription['current_period_end']));
        
        $this->entityManager->persist($sub);
        $this->entityManager->flush();
        
        echo "SUCCESS: Subscription saved to database\n";
    }

    private function handleSubscriptionUpdate($subscription): void
    {
        $sub = $this->entityManager->getRepository(OrganizationSubscriptionEntity::class)
            ->findOneBy(['stripeSubscriptionId' => $subscription['id']]);
            
        if (!$sub) {
            return;
        }
        
        $sub->setStatus($subscription['status']);
        $sub->setCurrentPeriodStart(new \DateTime('@' . $subscription['current_period_start']));
        $sub->setCurrentPeriodEnd(new \DateTime('@' . $subscription['current_period_end']));
        
        $this->entityManager->flush();
    }
}