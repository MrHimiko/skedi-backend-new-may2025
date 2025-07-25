<?php
// src/Plugins/Billing/Service/StripeService.php

namespace App\Plugins\Billing\Service;

use Stripe\StripeClient;
use App\Plugins\Organizations\Entity\OrganizationEntity;
use App\Plugins\Billing\Entity\OrganizationSubscriptionEntity;
use App\Plugins\Billing\Entity\BillingPlanEntity;
use Doctrine\ORM\EntityManagerInterface;

class StripeService
{
    private StripeClient $stripe;
    private string $additionalSeatsPriceId; // Set this from your Stripe dashboard

    public function __construct(
        string $stripeSecretKey,
        private EntityManagerInterface $entityManager,
        string $additionalSeatsPriceId
    ) {
        $this->stripe = new StripeClient($stripeSecretKey);
        $this->additionalSeatsPriceId = $additionalSeatsPriceId;
    }

    public function createCheckoutSession(
        OrganizationEntity $organization,
        BillingPlanEntity $plan,
        int $additionalSeats = 0
    ): string {
        $lineItems = [
            [
                'price' => $plan->getStripePriceId(),
                'quantity' => 1,
            ]
        ];
        
        if ($additionalSeats > 0) {
            $lineItems[] = [
                'price' => $this->additionalSeatsPriceId,
                'quantity' => $additionalSeats,
            ];
        }
        
        $session = $this->stripe->checkout->sessions->create([
            'mode' => 'subscription',
            'line_items' => $lineItems,
            'success_url' => $_ENV['APP_URL'] . '/billing/success?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $_ENV['APP_URL'] . '/billing/cancel',
            'metadata' => [
                'organization_id' => $organization->getId(),
                'plan_id' => $plan->getId(),
            ]
        ]);
        
        return $session->url;
    }

    public function addSeats(OrganizationSubscriptionEntity $subscription, int $seatsToAdd): void
    {
        $stripeSubscription = $this->stripe->subscriptions->retrieve(
            $subscription->getStripeSubscriptionId()
        );
        
        $seatItem = null;
        foreach ($stripeSubscription->items->data as $item) {
            if ($item->price->id === $this->additionalSeatsPriceId) {
                $seatItem = $item;
                break;
            }
        }
        
        if ($seatItem) {
            $this->stripe->subscriptionItems->update($seatItem->id, [
                'quantity' => $seatItem->quantity + $seatsToAdd,
            ]);
        } else {
            $this->stripe->subscriptionItems->create([
                'subscription' => $subscription->getStripeSubscriptionId(),
                'price' => $this->additionalSeatsPriceId,
                'quantity' => $seatsToAdd,
            ]);
        }
        
        $subscription->setAdditionalSeats($subscription->getAdditionalSeats() + $seatsToAdd);
        $this->entityManager->flush();
    }

    public function createCustomerPortalSession(string $customerId): string
    {
        $session = $this->stripe->billingPortal->sessions->create([
            'customer' => $customerId,
            'return_url' => $_ENV['APP_URL'] . '/billing',
        ]);
        
        return $session->url;
    }
}