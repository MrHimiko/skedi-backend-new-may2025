<?php
// src/Plugins/Integrations/Service/GoogleCalendarService.php

namespace App\Plugins\Integrations\Service;

use Doctrine\ORM\EntityManagerInterface;
use App\Plugins\Integrations\Repository\IntegrationRepository;
use App\Plugins\Integrations\Entity\GoogleCalendarEventEntity;
use App\Plugins\Account\Service\UserAvailabilityService;
use App\Service\CrudManager;
use App\Exception\CrudException;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use App\Plugins\Integrations\Entity\IntegrationEntity;
use App\Plugins\Account\Entity\UserEntity;
use App\Plugins\Integrations\Exception\IntegrationException;
use DateTime;
use DateTimeInterface;
use Psr\Log\LoggerInterface;

// Google API imports
use Google\Client as GoogleClient;
use Google\Service\Calendar as GoogleCalendar;
use Google\Service\Oauth2;

class GoogleCalendarService extends IntegrationService
{
    private string $clientId;
    private string $clientSecret;
    private string $redirectUri;
    private LoggerInterface $logger;
    
    public function __construct(
        EntityManagerInterface $entityManager,
        IntegrationRepository $integrationRepository,
        UserAvailabilityService $userAvailabilityService,
        CrudManager $crudManager,
        ParameterBagInterface $parameterBag,
        LoggerInterface $logger
    ) {
        parent::__construct($entityManager, $integrationRepository, $userAvailabilityService, $crudManager);
        
        $this->logger = $logger;
        
        // Try to get the parameters and log them
        try {
            $this->clientId = $parameterBag->get('google.client_id');
        } catch (\Exception $e) {
            $this->logger->error("Error getting google.client_id: " . $e->getMessage());
            // Temporary fallback for testing
            $this->clientId = '263415563843-iisvu1oericu0v5mvc7bl2c1p3obq2mq.apps.googleusercontent.com';
        }
        
        try {
            $this->clientSecret = $parameterBag->get('google.client_secret');
        } catch (\Exception $e) {
            $this->logger->error("Error getting google.client_secret: " . $e->getMessage());
            // Temporary fallback
            $this->clientSecret = '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';
        }
        
        try {
            $this->redirectUri = $parameterBag->get('google.redirect_uri');
        } catch (\Exception $e) {
            $this->logger->error("Error getting google.redirect_uri: " . $e->getMessage());
            // Temporary fallback
            $this->redirectUri = 'https://app.skedi.com/oauth/google/callback';
        }
    }

    /**
     * Get Google Client instance
     */
    public function getGoogleClient(?IntegrationEntity $integration = null): GoogleClient
    {
        $client = new GoogleClient();
        
        // 1. Set exact credentials without any string manipulation
        $clientId = '263415563843-iisvu1oericu0v5mvc7bl2c1p3obq2mq.apps.googleusercontent.com';
        $clientSecret = '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';
        $redirectUri = 'https://app.skedi.com/oauth/google/callback';
        
        // 2. Log what we're setting
        $this->logger->info('Setting Google client credentials', [
            'client_id_length' => strlen($clientId),
            'client_secret_length' => strlen($clientSecret),
            'redirect_uri' => $redirectUri
        ]);
        
        // 3. Set client parameters
        $client->setClientId($clientId);
        $client->setClientSecret($clientSecret);
        $client->setRedirectUri($redirectUri);
        
        // 4. Set scopes exactly as they should be (no string interpolation)
        $client->setScopes([
            'https://www.googleapis.com/auth/calendar.readonly',
            'https://www.googleapis.com/auth/calendar.events'
        ]);
        
        // 5. Standard OAuth parameters
        $client->setAccessType('offline');
        $client->setPrompt('consent');
        $client->setIncludeGrantedScopes(true);
        
        // 6. Handle existing tokens if integration provided
        if ($integration && $integration->getAccessToken()) {
            // Simplified token handling
            $tokenData = ['access_token' => $integration->getAccessToken()];
            
            if ($integration->getRefreshToken()) {
                $tokenData['refresh_token'] = $integration->getRefreshToken();
            }
            
            $client->setAccessToken($tokenData);
        }
        
        return $client;
    }

    /**
     * Get OAuth URL
     */
    public function getAuthUrl(): string
    {
        $client = $this->getGoogleClient();
        
        return $client->createAuthUrl();
    }



    /**
     * Handle OAuth callback and exchange code for tokens
     */
    public function handleAuthCallback(UserEntity $user, string $code): IntegrationEntity
    {
        try {
            // 1. Get a fresh client with proper credentials
            $client = $this->getGoogleClient();
            
            // 2. Exchange authorization code for access token
            $accessToken = $client->fetchAccessTokenWithAuthCode($code);
            
            if (isset($accessToken['error'])) {
                throw new IntegrationException('Failed to get access token: ' . $accessToken['error']);
            }
            
            // 3. Set the access token on the client (important for subsequent API calls)
            $client->setAccessToken($accessToken);
            
            // 4. Get user info with the authenticated client
            $oauth2 = new \Google\Service\Oauth2($client);
            $userInfo = $oauth2->userinfo->get();
            $name = $userInfo->getEmail() ?: 'Google Calendar';
            
            // 5. Create expiration date from token info
            $expiresIn = isset($accessToken['expires_in']) ? $accessToken['expires_in'] : 3600;
            $expiresAt = new DateTime();
            $expiresAt->modify("+{$expiresIn} seconds");
            
            // 6. Check if integration already exists
            $existingIntegration = $this->integrationRepository->findOneBy([
                'user' => $user,
                'provider' => 'google_calendar',
                'externalId' => $userInfo->getId()
            ]);
            
            if ($existingIntegration) {
                // 7. Update existing integration
                $existingIntegration->setAccessToken($accessToken['access_token']);
                $existingIntegration->setTokenExpires($expiresAt);
                
                // Only update refresh token if a new one was provided
                if (isset($accessToken['refresh_token'])) {
                    $existingIntegration->setRefreshToken($accessToken['refresh_token']);
                }
                
                $existingIntegration->setStatus('active');
                
                $this->entityManager->persist($existingIntegration);
                $this->entityManager->flush();
                
                // 8. Perform initial sync (without blocking the auth flow)
                try {
                    $this->syncEvents($existingIntegration, new DateTime('today'), new DateTime('+30 days'));
                } catch (\Exception $e) {
                    // Log the error but don't fail the auth process
                    if (method_exists($this, 'logger') && $this->logger) {
                        $this->logger->warning('Initial sync failed: ' . $e->getMessage());
                    }
                }
                
                return $existingIntegration;
            }
            
            // 9. Create new integration
            $integration = new IntegrationEntity();
            $integration->setUser($user);
            $integration->setProvider('google_calendar');
            $integration->setName($name);
            $integration->setExternalId($userInfo->getId());
            $integration->setAccessToken($accessToken['access_token']);
            
            if (isset($accessToken['refresh_token'])) {
                $integration->setRefreshToken($accessToken['refresh_token']);
            }
            
            $integration->setTokenExpires($expiresAt);
            $integration->setScopes(implode(',', $client->getScopes()));
            $integration->setConfig([
                'email' => $userInfo->getEmail(),
                'picture' => $userInfo->getPicture(),
                'calendars' => [] // Will be populated on first sync
            ]);
            $integration->setStatus('active');
            
            $this->entityManager->persist($integration);
            $this->entityManager->flush();
            
            // 10. Perform initial sync (without blocking the auth flow)
            try {
                $this->syncEvents($integration, new DateTime('today'), new DateTime('+30 days'));
            } catch (\Exception $e) {
                // Log the error but don't fail the auth process
                if (method_exists($this, 'logger') && $this->logger) {
                    $this->logger->warning('Initial sync failed: ' . $e->getMessage());
                }
            }
            
            return $integration;
        } catch (IntegrationException $e) {
            // Rethrow IntegrationExceptions directly
            throw $e;
        } catch (\Exception $e) {
            // Wrap other exceptions
            throw new IntegrationException('Failed to authenticate with Google: ' . $e->getMessage());
        }
    }


    /**
     * Refresh token
     */
    private function refreshToken(IntegrationEntity $integration, GoogleClient $client = null): void
    {
        if (!$client) {
            $client = $this->getGoogleClient($integration);
        }
        
        if (!$integration->getRefreshToken()) {
            throw new IntegrationException('No refresh token available');
        }
        
        try {
            $accessToken = $client->fetchAccessTokenWithRefreshToken($integration->getRefreshToken());
            
            if (isset($accessToken['error'])) {
                $this->logger->error('Failed to refresh token', [
                    'error' => $accessToken['error'],
                    'integration_id' => $integration->getId(),
                    'user_id' => $integration->getUser()->getId()
                ]);
                throw new IntegrationException('Failed to refresh token: ' . $accessToken['error']);
            }
            
            // Update token in database
            $expiresIn = isset($accessToken['expires_in']) ? $accessToken['expires_in'] : 3600;
            $expiresAt = new DateTime();
            $expiresAt->modify("+{$expiresIn} seconds");
            
            $integration->setAccessToken($accessToken['access_token']);
            $integration->setTokenExpires($expiresAt);
            
            // Only update refresh token if a new one was provided
            if (isset($accessToken['refresh_token'])) {
                $integration->setRefreshToken($accessToken['refresh_token']);
            }
            
            $this->entityManager->persist($integration);
            $this->entityManager->flush();
        } catch (\Exception $e) {
            $this->logger->error('Error refreshing token: ' . $e->getMessage(), [
                'integration_id' => $integration->getId(),
                'user_id' => $integration->getUser()->getId()
            ]);
            throw new IntegrationException('Failed to refresh token: ' . $e->getMessage());
        }
    }

    /**
     * Get user's Google Calendar integration
     */
    public function getUserIntegration(UserEntity $user, ?int $integrationId = null): ?IntegrationEntity
    {
        if ($integrationId) {
            $integration = $this->integrationRepository->find($integrationId);
            if ($integration && $integration->getUser()->getId() === $user->getId() && 
                $integration->getProvider() === 'google_calendar' && 
                $integration->getStatus() === 'active') {
                return $integration;
            }
            return null;
        }
        
        // Get the most recently created active integration
        return $this->integrationRepository->findOneBy(
            [
                'user' => $user,
                'provider' => 'google_calendar',
                'status' => 'active'
            ],
            ['created' => 'DESC']
        );
    }

    /**
     * Get Google calendars list
     */
    public function getCalendars(IntegrationEntity $integration): array
    {
        try {
            $client = $this->getGoogleClient($integration);
            $service = new GoogleCalendar($client);
            
            // Get calendar list
            $calendarList = $service->calendarList->listCalendarList();
            $calendars = [];
            
            foreach ($calendarList->getItems() as $calendarListEntry) {
                $calendars[] = [
                    'id' => $calendarListEntry->getId(),
                    'summary' => $calendarListEntry->getSummary(),
                    'description' => $calendarListEntry->getDescription(),
                    'primary' => $calendarListEntry->getPrimary(),
                    'access_role' => $calendarListEntry->getAccessRole(),
                    'background_color' => $calendarListEntry->getBackgroundColor(),
                    'foreground_color' => $calendarListEntry->getForegroundColor(),
                    'selected' => $calendarListEntry->getSelected(),
                    'time_zone' => $calendarListEntry->getTimeZone()
                ];
            }
            
            // Update the integration with the calendar list
            $config = $integration->getConfig() ?: [];
            $config['calendars'] = $calendars;
            $integration->setConfig($config);
            
            $this->entityManager->persist($integration);
            $this->entityManager->flush();
            
            return $calendars;
        } catch (\Exception $e) {
            $this->logger->error('Error fetching calendars: ' . $e->getMessage(), [
                'integration_id' => $integration->getId(),
                'user_id' => $integration->getUser()->getId()
            ]);
            throw new IntegrationException('Failed to fetch calendars: ' . $e->getMessage());
        }
    }

    /**
     * Save single Google Calendar event to database
     */
    private function saveEvent(
        IntegrationEntity $integration, 
        UserEntity $user, 
        \Google\Service\Calendar\Event $event, 
        string $calendarId, 
        string $calendarName
    ): ?GoogleCalendarEventEntity {
        try {
            // Skip cancelled events or events without start/end time
            if ($event->getStatus() === 'cancelled' || !$event->getStart() || !$event->getEnd()) {
                return null;
            }
            
            // Check if the event already exists in our database
            $existingEvent = $this->entityManager->getRepository(GoogleCalendarEventEntity::class)->findOneBy([
                'user' => $user,
                'googleEventId' => $event->getId(),
                'calendarId' => $calendarId
            ]);
            
            // Determine if this is an all-day event
            $isAllDay = false;
            $startTime = null;
            $endTime = null;
            
            $start = $event->getStart();
            $end = $event->getEnd();
            
            if ($start->date) {
                // All-day event
                $isAllDay = true;
                $startTime = new DateTime($start->date);
                $endTime = new DateTime($end->date);
            } else {
                // Timed event
                $startTime = new DateTime($start->dateTime);
                $endTime = new DateTime($end->dateTime);
            }
            
            // Create or update the event
            if ($existingEvent) {
                $existingEvent->setTitle($event->getSummary() ?: 'Untitled Event');
                $existingEvent->setDescription($event->getDescription());
                $existingEvent->setLocation($event->getLocation());
                $existingEvent->setStartTime($startTime);
                $existingEvent->setEndTime($endTime);
                $existingEvent->setIsAllDay($isAllDay);
                $existingEvent->setStatus($event->getStatus());
                $existingEvent->setTransparency($event->getTransparency());
                $existingEvent->setCalendarName($calendarName);
                $existingEvent->setEtag($event->getEtag());
                $existingEvent->setHtmlLink($event->getHtmlLink());
                
                // Handle organizer info
                if ($event->getOrganizer()) {
                    $existingEvent->setOrganizerEmail($event->getOrganizer()->getEmail());
                    $existingEvent->setIsOrganizer($event->getOrganizer()->getSelf() ?? false);
                }
                
                $existingEvent->setSyncedAt(new DateTime());
                
                $this->entityManager->persist($existingEvent);
                
                return $existingEvent;
            } else {
                // Create new event
                $newEvent = new GoogleCalendarEventEntity();
                $newEvent->setUser($user);
                $newEvent->setIntegration($integration);
                $newEvent->setGoogleEventId($event->getId());
                $newEvent->setCalendarId($calendarId);
                $newEvent->setCalendarName($calendarName);
                $newEvent->setTitle($event->getSummary() ?: 'Untitled Event');
                $newEvent->setDescription($event->getDescription());
                $newEvent->setLocation($event->getLocation());
                $newEvent->setStartTime($startTime);
                $newEvent->setEndTime($endTime);
                $newEvent->setIsAllDay($isAllDay);
                $newEvent->setStatus($event->getStatus());
                $newEvent->setTransparency($event->getTransparency());
                $newEvent->setEtag($event->getEtag());
                $newEvent->setHtmlLink($event->getHtmlLink());
                
                // Handle organizer info
                if ($event->getOrganizer()) {
                    $newEvent->setOrganizerEmail($event->getOrganizer()->getEmail());
                    $newEvent->setIsOrganizer($event->getOrganizer()->getSelf() ?? false);
                }
                
                $newEvent->setSyncedAt(new DateTime());
                
                $this->entityManager->persist($newEvent);
                
                return $newEvent;
            }
        } catch (\Exception $e) {
            $this->logger->error('Error saving event: ' . $e->getMessage(), [
                'event_id' => $event->getId(),
                'calendar_id' => $calendarId,
                'user_id' => $user->getId()
            ]);
            
            return null;
        }
    }

    /**
     * Get events for a user within a date range using CrudManager
     */
    public function getEventsForDateRange(UserEntity $user, DateTime $startDate, DateTime $endDate): array
    {
        try {
            $filters = [
                [
                    'field' => 'startTime',
                    'operator' => 'less_than',
                    'value' => $endDate
                ],
                [
                    'field' => 'endTime',
                    'operator' => 'greater_than',
                    'value' => $startDate
                ],
                [
                    'field' => 'status',
                    'operator' => 'not_equals',
                    'value' => 'cancelled'
                ]
            ];
            
            return $this->crudManager->findMany(
                GoogleCalendarEventEntity::class,
                $filters,
                1,  // page
                1000, // limit
                ['user' => $user],
                function($queryBuilder) {
                    $queryBuilder->orderBy('t1.startTime', 'ASC');
                }
            );
        } catch (CrudException $e) {
            $this->logger->error('Error getting events: ' . $e->getMessage(), [
                'user_id' => $user->getId()
            ]);
            return [];
        }
    }

    /**
     * Delete events not in a list of IDs
     * Since CrudManager doesn't directly support this operation,
     * we'll use EntityManager but keep it in the service
     */
    public function deleteEventsNotInList(UserEntity $user, array $keepEventIds, string $calendarId = null): int
    {
        try {
            $qb = $this->entityManager->createQueryBuilder();
            $qb->delete(GoogleCalendarEventEntity::class, 'e')
               ->where('e.user = :user');
            
            if (!empty($keepEventIds)) {
                $qb->andWhere('e.googleEventId NOT IN (:eventIds)')
                   ->setParameter('eventIds', $keepEventIds);
            }
            
            if ($calendarId) {
                $qb->andWhere('e.calendarId = :calendarId')
                   ->setParameter('calendarId', $calendarId);
            }
            
            $qb->setParameter('user', $user);
            
            return $qb->getQuery()->execute();
        } catch (\Exception $e) {
            $this->logger->error('Error deleting events: ' . $e->getMessage(), [
                'user_id' => $user->getId()
            ]);
            return 0;
        }
    }

    /**
     * Sync calendar events for a specific date range
     */
    public function syncEvents(IntegrationEntity $integration, DateTime $startDate, DateTime $endDate): array
    {
        try {
            $user = $integration->getUser();
            $client = $this->getGoogleClient($integration);
            $service = new GoogleCalendar($client);
            
            // Get calendar list
            $calendarList = $service->calendarList->listCalendarList();
            $savedEvents = [];
            $allEventIds = [];
            
            // Start a database transaction for atomicity
            $this->entityManager->beginTransaction();
            
            // Format dates for Google API query
            $timeMin = $startDate->format('c');
            $timeMax = $endDate->format('c');
            
            // Loop through each calendar
            foreach ($calendarList->getItems() as $calendarListEntry) {
                $calendarId = $calendarListEntry->getId();
                $calendarName = $calendarListEntry->getSummary();
                $calendarEventIds = [];
                
                // Always include primary calendar and selected calendars
                $isPrimary = $calendarListEntry->getPrimary() ?? false;
                $isSelected = $calendarListEntry->getSelected() ?? false;
                
                if (!$isPrimary && !$isSelected) {
                    continue;
                }
                
                // Get events from this calendar
                $eventsResult = $service->events->listEvents($calendarId, [
                    'timeMin' => $timeMin,
                    'timeMax' => $timeMax,
                    'showDeleted' => true,
                    'singleEvents' => true,
                    'orderBy' => 'startTime',
                    'maxResults' => 2500 // Limit to avoid hitting API quotas
                ]);
                
                foreach ($eventsResult->getItems() as $event) {
                    // Skip declined events where the user is not attending
                    $attendees = $event->getAttendees();
                    if ($attendees) {
                        $userEmail = $integration->getConfig()['email'] ?? null;
                        $isDeclined = false;
                        
                        foreach ($attendees as $attendee) {
                            if ($attendee->getEmail() === $userEmail && $attendee->getResponseStatus() === 'declined') {
                                $isDeclined = true;
                                break;
                            }
                        }
                        
                        if ($isDeclined) {
                            continue;
                        }
                    }
                    
                    // Save the event to our database
                    $savedEvent = $this->saveEvent($integration, $user, $event, $calendarId, $calendarName);
                    if ($savedEvent) {
                        $savedEvents[] = $savedEvent;
                        $calendarEventIds[] = $event->getId();
                    }
                }
                
                // Clean up deleted events for this calendar
                $this->deleteEventsNotInList($user, $calendarEventIds, $calendarId);
                
                // Collect all event IDs for final check
                $allEventIds = array_merge($allEventIds, $calendarEventIds);
            }
            
            // Update last synced timestamp
            $integration->setLastSynced(new DateTime());
            $this->entityManager->persist($integration);
            
            // Commit the transaction
            $this->entityManager->flush();
            $this->entityManager->commit();
            
            // Also update the user availability records
            $this->syncUserAvailability($user, $savedEvents);
            
            $this->logger->info('Successfully synced Google Calendar events', [
                'integration_id' => $integration->getId(),
                'user_id' => $user->getId(),
                'start_date' => $startDate->format('Y-m-d'),
                'end_date' => $endDate->format('Y-m-d'),
                'events_count' => count($savedEvents)
            ]);
            
            return $savedEvents;
        } catch (\Exception $e) {
            // Rollback transaction on error
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }
            
            $this->logger->error('Error syncing events: ' . $e->getMessage(), [
                'integration_id' => $integration->getId(),
                'user_id' => $integration->getUser()->getId(),
                'trace' => $e->getTraceAsString()
            ]);
            
            throw new IntegrationException('Failed to sync calendar events: ' . $e->getMessage());
        }
    }

    /**
     * Sync user availability records from Google Calendar events
     */
    private function syncUserAvailability(UserEntity $user, array $events): void
    {
        try {
            foreach ($events as $event) {
                // Skip cancelled events or transparent events
                if ($event->getStatus() === 'cancelled' || $event->getTransparency() === 'transparent') {
                    continue;
                }
                
                // Create a source ID that uniquely identifies this event
                $sourceId = 'google_' . $event->getCalendarId() . '_' . $event->getGoogleEventId();
                
                // Use the availability service to create/update availability
                $this->userAvailabilityService->createExternalAvailability(
                    $user,
                    $event->getTitle() ?: 'Busy',
                    $event->getStartTime(),
                    $event->getEndTime(),
                    'google_calendar',
                    $sourceId,
                    $event->getDescription(),
                    $event->getStatus()
                );
            }
        } catch (\Exception $e) {
            // Log error but continue
            $this->logger->error('Error syncing user availability: ' . $e->getMessage(), [
                'user_id' => $user->getId()
            ]);
        }
    }
}