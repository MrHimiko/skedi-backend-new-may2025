<?php

namespace App\Plugins\Integrations\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use App\Service\ResponseService;
use App\Plugins\Integrations\Service\OutlookCalendarService;
use App\Plugins\Integrations\Exception\IntegrationException;
use DateTime;
use Psr\Log\LoggerInterface;

#[Route('/api')]
class OutlookCalendarController extends AbstractController
{
    private ResponseService $responseService;
    private OutlookCalendarService $outlookCalendarService;
    private LoggerInterface $logger;
    
    public function __construct(
        ResponseService $responseService,
        OutlookCalendarService $outlookCalendarService,
        LoggerInterface $logger
    ) {
        $this->responseService = $responseService;
        $this->outlookCalendarService = $outlookCalendarService;
        $this->logger = $logger;
    }
    
    /**
     * Get Outlook OAuth URL
     */
    #[Route('/user/integrations/outlook/auth', name: 'outlook_auth_url#', methods: ['GET'])]
    public function getOutlookAuthUrl(Request $request): JsonResponse
    {
        try {
            $authUrl = $this->outlookCalendarService->getAuthUrl();
            
            return $this->responseService->json(true, 'retrieve', [
                'auth_url' => $authUrl
            ]);
        } catch (\Exception $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 500);
        }
    }
    
    /**
     * Handle Outlook OAuth callback
     */
    #[Route('/user/integrations/outlook/callback', name: 'outlook_auth_callback#', methods: ['POST'])]
    public function handleOutlookCallback(Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');
        $data = $request->attributes->get('data');
        
        if (!isset($data['code'])) {
            return $this->responseService->json(false, 'Code parameter is required', null, 400);
        }
        
        try {
            $integration = $this->outlookCalendarService->handleAuthCallback($user, $data['code']);
            
            return $this->responseService->json(true, 'Outlook Calendar connected successfully', $integration->toArray());
        } catch (IntegrationException $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 400);
        } catch (\Exception $e) {
            return $this->responseService->json(false, 'An error occurred: ' . $e->getMessage(), null, 500);
        }
    }
    
    /**
     * Sync Outlook Calendar events
     */
    #[Route('/user/integrations/{id}/sync-outlook', name: 'outlook_calendar_sync#', methods: ['POST'])]
    public function syncCalendar(int $id, Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');
        $data = $request->attributes->get('data');
        
        // Default to syncing the next 30 days
        $startDate = new DateTime($data['start_date'] ?? 'today');
        $endDate = new DateTime($data['end_date'] ?? '+30 days');
        
        try {
            $integration = $this->outlookCalendarService->getUserIntegration($user, $id);
            
            if (!$integration) {
                return $this->responseService->json(false, 'Outlook Calendar integration not found', null, 404);
            }
            
            $events = $this->outlookCalendarService->syncEvents($integration, $startDate, $endDate);
            
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
     * Get events for a date range (from local database)
     */
    #[Route('/user/integrations/outlook/events', name: 'outlook_calendar_events_get#', methods: ['GET'])]
    public function getEvents(Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');
        $startDate = $request->query->get('start_date', 'today');
        $endDate = $request->query->get('end_date', '+7 days');
        $autoSync = $request->query->get('sync', 'auto');
        
        try {
            $startDateTime = new DateTime($startDate);
            $endDateTime = new DateTime($endDate);
            
            // Get the user's integration
            $integration = $this->outlookCalendarService->getUserIntegration($user);
            
            if (!$integration) {
                return $this->responseService->json(false, 'Outlook Calendar integration not found. Please connect your calendar first.', null, 404);
            }
            
            // Add auto-sync logic
            $shouldSync = false;
            if ($autoSync === 'force') {
                $shouldSync = true;
            } else if ($autoSync === 'auto') {
                $shouldSync = !$integration->getLastSynced() || 
                             $integration->getLastSynced() < new DateTime('-30 minutes');
            }
            
            if ($shouldSync) {
                $this->outlookCalendarService->syncEvents($integration, $startDateTime, $endDateTime);
            }
            
            $events = $this->outlookCalendarService->getEventsForDateRange($user, $startDateTime, $endDateTime);
            
            $result = array_map(function($event) {
                return $event->toArray();
            }, $events);
            
            return $this->responseService->json(true, 'retrieve', [
                'events' => $result,
                'metadata' => [
                    'total' => count($result),
                    'start_date' => $startDateTime->format('Y-m-d H:i:s'),
                    'end_date' => $endDateTime->format('Y-m-d H:i:s'),
                    'last_synced' => $integration->getLastSynced() ? 
                        $integration->getLastSynced()->format('Y-m-d H:i:s') : null,
                    'synced_now' => $shouldSync
                ]
            ]);
        } catch (\Exception $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 500);
        }
    }
    
    /**
     * Get calendars from Outlook account
     */
    #[Route('/user/integrations/outlook/calendars', name: 'outlook_calendars_get#', methods: ['GET'])]
    public function getCalendars(Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');
        $integrationId = $request->query->get('integration_id');
        
        try {
            $integration = $this->outlookCalendarService->getUserIntegration($user, $integrationId);
            
            if (!$integration) {
                return $this->responseService->json(false, 'Outlook Calendar integration not found. Please connect your calendar first.', null, 404);
            }
            
            $calendars = $this->outlookCalendarService->getCalendars($integration);
            
            return $this->responseService->json(true, 'retrieve', $calendars);
        } catch (\Exception $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 500);
        }
    }

    /**
     * Create a new event in Outlook Calendar
     */
    #[Route('/user/integrations/{id}/outlook-events', name: 'outlook_calendar_event_create#', methods: ['POST'])]
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
            $integration = $this->outlookCalendarService->getUserIntegration($user, $id);
            
            if (!$integration) {
                return $this->responseService->json(false, 'Outlook Calendar integration not found', null, 404);
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
                'calendar_id' => $data['calendar_id'] ?? null,
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
            $event = $this->outlookCalendarService->createCalendarEvent(
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
            $this->logger->error('Error creating event: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            return $this->responseService->json(false, 'An error occurred: ' . $e->getMessage(), null, 500);
        }
    }
    
    /**
     * Delete an event in Outlook Calendar
     */
    #[Route('/user/integrations/{id}/outlook-events/{eventId}', name: 'outlook_calendar_event_delete#', methods: ['DELETE'])]
    public function deleteEvent(int $id, string $eventId, Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');
        
        try {
            $integration = $this->outlookCalendarService->getUserIntegration($user, $id);
            
            if (!$integration) {
                return $this->responseService->json(false, 'Outlook Calendar integration not found', null, 404);
            }
            
            $success = $this->outlookCalendarService->deleteEvent($integration, $eventId);
            
            if ($success) {
                return $this->responseService->json(true, 'Event deleted successfully');
            } else {
                return $this->responseService->json(false, 'Failed to delete event', null, 500);
            }
        } catch (\Exception $e) {
            return $this->responseService->json(false, 'An error occurred: ' . $e->getMessage(), null, 500);
        }
    }
}