<?php
// src/Plugins/Billing/Controller/StripeWebhookController.php

namespace App\Plugins\Billing\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use App\Plugins\Billing\Service\StripeWebhookService;

#[Route('/api/stripe')]
class StripeWebhookController extends AbstractController
{
    public function __construct(
        private StripeWebhookService $webhookService
    ) {}

    #[Route('/webhook', name: 'stripe_webhook', methods: ['POST'])]
    public function handleWebhook(Request $request): Response
    {
        $payload = $request->getContent();
        $signature = $request->headers->get('Stripe-Signature');
        
        try {
            $this->webhookService->handleWebhook($payload, $signature);
            return new Response('Webhook received', 200);
        } catch (\Exception $e) {
            // Log the error for debugging
            error_log('Stripe webhook error: ' . $e->getMessage());
            return new Response('Webhook error: ' . $e->getMessage(), 400);
        }
    }
}