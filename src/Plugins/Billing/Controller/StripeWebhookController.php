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
        $logFile = '/tmp/stripe_webhook_debug.log';
        $payload = $request->getContent();
        $signature = $request->headers->get('Stripe-Signature');
        
        // Debug logging
        $debugInfo = [
            'timestamp' => date('Y-m-d H:i:s'),
            'method' => $request->getMethod(),
            'uri' => $request->getRequestUri(),
            'has_signature' => !empty($signature),
            'payload_length' => strlen($payload),
            'payload_preview' => substr($payload, 0, 200) . '...'
        ];
        
        file_put_contents($logFile, "=== WEBHOOK START ===\n", FILE_APPEND);
        file_put_contents($logFile, json_encode($debugInfo, JSON_PRETTY_PRINT) . "\n", FILE_APPEND);
        
        try {
            file_put_contents($logFile, "Calling webhookService->handleWebhook\n", FILE_APPEND);
            $this->webhookService->handleWebhook($payload, $signature);
            
            file_put_contents($logFile, "SUCCESS: Webhook processed\n", FILE_APPEND);
            file_put_contents($logFile, "=== WEBHOOK END ===\n\n", FILE_APPEND);
            
            return new Response('Webhook received', 200);
        } catch (\Exception $e) {
            $errorInfo = [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => array_slice($e->getTrace(), 0, 5) // First 5 trace entries
            ];
            
            file_put_contents($logFile, "ERROR: " . json_encode($errorInfo, JSON_PRETTY_PRINT) . "\n", FILE_APPEND);
            file_put_contents($logFile, "=== WEBHOOK END ===\n\n", FILE_APPEND);
            
            // Return 200 to prevent Stripe retries, but log the error
            return new Response('Webhook received (with errors)', 200);
        }
    }

    #[Route('/test/webhook-service', name: 'test_webhook_service', methods: ['GET'])]
    public function testWebhookService(): JsonResponse
    {
        try {
            // Test if service is initialized properly
            $reflection = new \ReflectionClass($this->webhookService);
            $logFileProperty = $reflection->getProperty('logFile');
            $logFileProperty->setAccessible(true);
            $logFile = $logFileProperty->getValue($this->webhookService);
            
            return new JsonResponse([
                'success' => true,
                'log_file' => $logFile,
                'service_class' => get_class($this->webhookService)
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ]);
        }
    }

}