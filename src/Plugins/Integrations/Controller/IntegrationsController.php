<?php

namespace App\Plugins\Integrations\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use App\Service\ResponseService;
use App\Plugins\Integrations\Service\IntegrationService;
use App\Plugins\Integrations\Exception\IntegrationException;

#[Route('/api')]
class IntegrationsController extends AbstractController
{
    private ResponseService $responseService;
    private IntegrationService $integrationService;
    
    public function __construct(
        ResponseService $responseService,
        IntegrationService $integrationService
    ) {
        $this->responseService = $responseService;
        $this->integrationService = $integrationService;
    }
    
    /**
     * Get available integration providers
     */
    #[Route('/user/integrations/providers', name: 'integrations_providers#', methods: ['GET'])]
    public function getProviders(Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');
        
        try {
            $providers = $this->integrationService->getAvailableProviders();
            
            return $this->responseService->json(true, 'retrieve', $providers);
        } catch (\Exception $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 500);
        }
    }
    
    /**
     * Get user integrations
     */
    #[Route('/user/integrations', name: 'user_integrations_get#', methods: ['GET'])]
    public function getUserIntegrations(Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');
        $provider = $request->query->get('provider');
        
        try {
            $integrations = $this->integrationService->getUserIntegrations($user, $provider);
            
            $result = array_map(function($integration) {
                return $integration->toArray();
            }, $integrations);
            
            return $this->responseService->json(true, 'retrieve', $result);
        } catch (\Exception $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 500);
        }
    }
    
    /**
     * Delete integration
     */
    #[Route('/user/integrations/{id}', name: 'integration_delete#', methods: ['DELETE'])]
    public function deleteIntegration(int $id, Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');
        
        try {
            $integration = $this->integrationService->getIntegration($id, $user);
            
            if (!$integration) {
                return $this->responseService->json(false, 'Integration not found', null, 404);
            }
            
            $this->integrationService->deleteIntegration($integration);
            
            return $this->responseService->json(true, 'Integration deleted successfully');
        } catch (\Exception $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 500);
        }
    }
}