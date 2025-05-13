<?php
// src/Plugins/Integrations/Controller/GoogleCalendarController.php

namespace App\Plugins\Integrations\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use App\Service\ResponseService;
use App\Plugins\Integrations\Service\GoogleCalendarService;
use App\Plugins\Integrations\Exception\IntegrationException; 
use DateTime;

#[Route('/api')]
class GoogleCalendarController extends AbstractController
{
    private ResponseService $responseService;
    private GoogleCalendarService $googleCalendarService;
    
    public function __construct(
        ResponseService $responseService,
        GoogleCalendarService $googleCalendarService
    ) {
        $this->responseService = $responseService;
        $this->googleCalendarService = $googleCalendarService;
    }
    
    /**
     * Get Google OAuth URL
     */
    #[Route('/user/integrations/google/auth', name: 'google_auth_url#', methods: ['GET'])]
    public function getGoogleAuthUrl(Request $request): JsonResponse
    {
        try {
            $authUrl = $this->googleCalendarService->getAuthUrl();
            
            return $this->responseService->json(true, 'retrieve', [
                'auth_url' => $authUrl
            ]);
        } catch (\Exception $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 500);
        }
    }
    
    /**
     * Handle Google OAuth callback
     */
    #[Route('/user/integrations/google/callback', name: 'google_auth_callback#', methods: ['POST'])]
    public function handleGoogleCallback(Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');
        $data = $request->attributes->get('data');
        
        if (!isset($data['code'])) {
            return $this->responseService->json(false, 'Code parameter is required', null, 400);
        }
        
        try {
            $integration = $this->googleCalendarService->handleAuthCallback($user, $data['code']);
            
            return $this->responseService->json(true, 'Google Calendar connected successfully', $integration->toArray());
        } catch (IntegrationException $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 400);
        } catch (\Exception $e) {
            return $this->responseService->json(false, 'An error occurred: ' . $e->getMessage(), null, 500);
        }
    }
    
    /**
     * Sync Google Calendar events
     */
    #[Route('/user/integrations/{id}/sync', name: 'google_calendar_sync#', methods: ['POST'])]
    public function syncCalendar(int $id, Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');
        $data = $request->attributes->get('data');
        
        // Default to syncing the next 30 days
        $startDate = new DateTime($data['start_date'] ?? 'today');
        $endDate = new DateTime($data['end_date'] ?? '+30 days');
        
        try {
            $integration = $this->googleCalendarService->getUserIntegration($user, $id);
            
            if (!$integration) {
                return $this->responseService->json(false, 'Google Calendar integration not found', null, 404);
            }
            
            $events = $this->googleCalendarService->syncEvents($integration, $startDate, $endDate);
            
            return $this->responseService->json(true, 'Events synced successfully', [
                'integration' => $integration->toArray(),
                'events_count' => count($events),
                'sync_range' => [
                    'start' => $startDate->format('Y-m-d H:i:s'),
                    'end' => $endDate->format('Y-m-d H:i:s')
                ]
            ]);
        } catch (IntegrationException $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 400);
        } catch (\Exception $e) {
            return $this->responseService->json(false, 'An error occurred: ' . $e->getMessage(), null, 500);
        }
    }
    
    /**
     * Get calendars from Google account
     */
    #[Route('/user/integrations/google/calendars', name: 'google_calendars_get#', methods: ['GET'])]
    public function getCalendars(Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');
        $integrationId = $request->query->get('integration_id');
        
        try {
            $integration = $this->googleCalendarService->getUserIntegration($user, $integrationId);
            
            if (!$integration) {
                return $this->responseService->json(false, 'Google Calendar integration not found. Please connect your calendar first.', null, 404);
            }
            
            $calendars = $this->googleCalendarService->getCalendars($integration);
            
            return $this->responseService->json(true, 'retrieve', $calendars);
        } catch (\Exception $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 500);
        }
    }

    /**
     * Create a new event in Google Calendar
     */
    #[Route('/user/integrations/{id}/events', name: 'google_calendar_event_create#', methods: ['POST'])]
    public function createEvent(int $id, Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');
        $data = $request->attributes->get('data');
        
        // Basic validation
        if (empty($data['title']) || empty($data['start_time']) || empty($data['end_time'])) {
            return $this->responseService->json(false, 'Title, start time, and end time are required', null, 400);
        }
        
        try {
            // Get the user's integration
            $integration = $this->googleCalendarService->getUserIntegration($user, $id);
            
            if (!$integration) {
                return $this->responseService->json(false, 'Google Calendar integration not found', null, 404);
            }
            
            // Parse dates
            $startTime = new DateTime($data['start_time']);
            $endTime = new DateTime($data['end_time']);
            
            if ($startTime >= $endTime) {
                return $this->responseService->json(false, 'End time must be after start time', null, 400);
            }
            
            // Prepare options
            $options = [
                'description' => $data['description'] ?? null,
                'location' => $data['location'] ?? null,
                'calendar_id' => $data['calendar_id'] ?? 'primary',
                'transparency' => $data['transparency'] ?? 'opaque'
            ];
            
            // Add attendees if provided
            if (!empty($data['attendees']) && is_array($data['attendees'])) {
                $options['attendees'] = $data['attendees'];
            }
            
            // Add reminders if provided
            if (!empty($data['reminders']) && is_array($data['reminders'])) {
                $options['reminders'] = $data['reminders'];
            }
            
            // Create the event
            $event = $this->googleCalendarService->createCalendarEvent(
                $integration,
                $data['title'],
                $startTime,
                $endTime,
                $options
            );
            
            return $this->responseService->json(true, 'Event created successfully', $event, 201);
        } catch (IntegrationException $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 400);
        } catch (\Exception $e) {
            return $this->responseService->json(false, 'An error occurred: ' . $e->getMessage(), null, 500);
        }
    }
}