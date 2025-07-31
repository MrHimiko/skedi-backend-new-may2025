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
        $this->log("handleCheckoutCompleted started", [
            'session_id' => $session['id'],
            'mode' => $session['mode']
        ]);
        
        // For setup mode sessions (adding seats), process separately
        if ($session['mode'] === 'setup' && isset($session['metadata']['action']) && $session['metadata']['action'] === 'add_seats') {
            $this->handleSeatsCheckoutCompleted($session);
            return;
        }
        
        // Regular subscription checkout handling continues...
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
        $this->log("handleSubscriptionUpdate started", [
            'subscription_id' => $subscription['id'],
            'status' => $subscription['status']
        ]);
        
        // Find the organization subscription
        $orgSubscription = $this->entityManager->getRepository(OrganizationSubscriptionEntity::class)
            ->findOneBy(['stripeSubscriptionId' => $subscription['id']]);
            
        if (!$orgSubscription) {
            $this->log("Organization subscription not found for Stripe ID: " . $subscription['id']);
            return;
        }
        
        $this->log("Found organization subscription", ['id' => $orgSubscription->getId()]);
        
        // Update subscription status
        $orgSubscription->setStatus($subscription['status']);
        
        // Update period dates
        if (isset($subscription['current_period_start'])) {
            $orgSubscription->setCurrentPeriodStart(new \DateTime('@' . $subscription['current_period_start']));
        }
        if (isset($subscription['current_period_end'])) {
            $orgSubscription->setCurrentPeriodEnd(new \DateTime('@' . $subscription['current_period_end']));
        }
        
        // Update seat count from subscription items
        $this->updateSeatCountFromSubscription($orgSubscription, $subscription);
        
        $this->entityManager->persist($orgSubscription);
        $this->entityManager->flush();
        
        $this->log("Subscription updated successfully");
    }   

    private function updateSeatCountFromSubscription(OrganizationSubscriptionEntity $orgSubscription, array $stripeSubscription): void
    {
        // Look through subscription items for seats
        $seatCount = 0;
        $seatItemId = null;
        
        if (isset($stripeSubscription['items']['data'])) {
            foreach ($stripeSubscription['items']['data'] as $item) {
                // Check if this is the seats price (you'll need to inject the seats price ID)
                if ($item['price']['id'] === $this->additionalSeatsPriceId) {
                    $seatCount = $item['quantity'];
                    $seatItemId = $item['id'];
                    break;
                }
            }
        }
        
        $this->log("Updating seat count", [
            'previous' => $orgSubscription->getAdditionalSeats(),
            'new' => $seatCount,
            'item_id' => $seatItemId
        ]);
        
        $orgSubscription->setAdditionalSeats($seatCount);
        if ($seatItemId) {
            $orgSubscription->setSeatsSubscriptionItemId($seatItemId);
        }
    }


    private function handleSeatsCheckoutCompleted($session): void
    {
        $this->log("Processing seats checkout completion");
        
        $organizationId = $session['metadata']['organization_id'] ?? null;
        $seatsToAdd = (int)($session['metadata']['seats_to_add'] ?? 0);
        
        if (!$organizationId || $seatsToAdd <= 0) {
            $this->log("Invalid seats checkout metadata");
            return;
        }
        
        try {
            // Find organization subscription
            $subscription = $this->entityManager->getRepository(OrganizationSubscriptionEntity::class)
                ->findOneBy(['organization' => $organizationId]);
                
            if (!$subscription) {
                $this->log("Organization subscription not found");
                return;
            }
            
            // The actual seat update will happen via subscription.updated webhook
            // Just log that checkout was completed
            $this->log("Seats checkout completed successfully", [
                'organization_id' => $organizationId,
                'seats_requested' => $seatsToAdd
            ]);
            
        } catch (\Exception $e) {
            $this->log("Error processing seats checkout", [
                'error' => $e->getMessage()
            ]);
        }
    }



}