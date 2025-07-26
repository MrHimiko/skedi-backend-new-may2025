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
    private string $logFile = '/tmp/stripe_webhook_debug.log';
    
    public function __construct(
        private string $stripeSecretKey,
        private string $webhookSecret,
        private EntityManagerInterface $entityManager,
        private OrganizationService $organizationService,
        private BillingPlanRepository $planRepository
    ) {
        $this->stripe = new StripeClient($stripeSecretKey);
    }

    private function log($message, $data = null): void
    {
        $entry = date('Y-m-d H:i:s') . " - " . $message;
        if ($data !== null) {
            $entry .= " - " . json_encode($data);
        }
        file_put_contents($this->logFile, $entry . "\n", FILE_APPEND);
    }

    public function handleWebhook(string $payload, ?string $signature): void
    {
        $this->log("handleWebhook started");
        
        try {
            if ($signature) {
                $event = Webhook::constructEvent($payload, $signature, $this->webhookSecret);
            } else {
                $data = json_decode($payload, true);
                $event = $data;
            }
            
            $this->log("Event type", ['type' => $event['type']]);
            
            switch ($event['type']) {
                case 'checkout.session.completed':
                    $this->handleCheckoutCompleted($event['data']['object']);
                    break;
                    
                case 'customer.subscription.created':
                    $this->handleSubscriptionCreated($event['data']['object']);
                    break;
                    
                case 'customer.subscription.updated':
                case 'customer.subscription.deleted':
                    $this->handleSubscriptionUpdate($event['data']['object']);
                    break;
            }
        } catch (\Exception $e) {
            $this->log("Exception in handleWebhook", [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            throw $e;
        }
    }

    private function handleCheckoutCompleted($session): void
    {
        $this->log("handleCheckoutCompleted - skipping");
    }

    private function handleSubscriptionCreated($subscription): void
    {
        $this->log("handleSubscriptionCreated started", [
            'subscription_id' => $subscription['id']
        ]);
        
        $organizationId = $subscription['metadata']['organization_id'] ?? null;
        $planId = $subscription['metadata']['plan_id'] ?? null;
        
        $this->log("Metadata extracted", [
            'organization_id' => $organizationId,
            'plan_id' => $planId
        ]);
        
        if (!$organizationId || !$planId) {
            $this->log("Missing metadata - aborting");
            return;
        }
        
        try {
            $organizationId = (int)$organizationId;
            $planId = (int)$planId;
            
            $this->log("Looking up organization", ['id' => $organizationId]);
            $organization = $this->organizationService->getOne($organizationId);
            
            if (!$organization) {
                $this->log("Organization not found!");
                return;
            }
            $this->log("Organization found", ['name' => $organization->getName()]);
            
            $this->log("Looking up plan", ['id' => $planId]);
            $plan = $this->planRepository->find($planId);
            
            if (!$plan) {
                $this->log("Plan not found!");
                return;
            }
            $this->log("Plan found", ['name' => $plan->getName()]);
            
            // Check if subscription already exists
            $sub = $this->entityManager->getRepository(OrganizationSubscriptionEntity::class)
                ->findOneBy(['organization' => $organization]);
                
            if (!$sub) {
                $this->log("Creating new subscription");
                $sub = new OrganizationSubscriptionEntity();
                $sub->setOrganization($organization);
            } else {
                $this->log("Updating existing subscription", ['id' => $sub->getId()]);
            }
            
            $sub->setPlan($plan);
            $sub->setStripeSubscriptionId($subscription['id']);
            $sub->setStripeCustomerId($subscription['customer']);
            $sub->setStatus($subscription['status']);
            
            // Add null checks for timestamps
            if (isset($subscription['current_period_start']) && $subscription['current_period_start']) {
                $sub->setCurrentPeriodStart(new \DateTime('@' . $subscription['current_period_start']));
            }
            
            if (isset($subscription['current_period_end']) && $subscription['current_period_end']) {
                $sub->setCurrentPeriodEnd(new \DateTime('@' . $subscription['current_period_end']));
            }
            
            $this->log("Persisting subscription");
            $this->entityManager->persist($sub);
            
            $this->log("Flushing to database");
            $this->entityManager->flush();
            
            $this->log("Subscription saved successfully!", ['subscription_id' => $sub->getId()]);
        } catch (\Exception $e) {
            $this->log("Exception in handleSubscriptionCreated", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    private function handleSubscriptionUpdate($subscription): void
    {
        $this->log("handleSubscriptionUpdate", ['id' => $subscription['id']]);
        
        try {
            $sub = $this->entityManager->getRepository(OrganizationSubscriptionEntity::class)
                ->findOneBy(['stripeSubscriptionId' => $subscription['id']]);
                
            if (!$sub) {
                $this->log("Subscription not found for update");
                return;
            }
            
            $sub->setStatus($subscription['status']);
            
            if (isset($subscription['current_period_start']) && $subscription['current_period_start']) {
                $sub->setCurrentPeriodStart(new \DateTime('@' . $subscription['current_period_start']));
            }
            
            if (isset($subscription['current_period_end']) && $subscription['current_period_end']) {
                $sub->setCurrentPeriodEnd(new \DateTime('@' . $subscription['current_period_end']));
            }
            
            $this->entityManager->persist($sub);
            $this->entityManager->flush();
            
            $this->log("Subscription updated successfully");
        } catch (\Exception $e) {
            $this->log("Exception in handleSubscriptionUpdate", [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
}