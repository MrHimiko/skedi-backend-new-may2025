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
        
        // Set exact credentials without any string manipulation
        $clientId = '263415563843-iisvu1oericu0v5mvc7bl2c1p3obq2mq.apps.googleusercontent.com';
        $clientSecret = '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';
        $redirectUri = 'https://app.skedi.com/oauth/google/callback';
        
        // Log what we're setting
        $this->logger->info('Setting Google client credentials', [
            'client_id_length' => strlen($clientId),
            'client_secret_length' => strlen($clientSecret),
            'redirect_uri' => $redirectUri
        ]);
        
        // Set client parameters
        $client->setClientId($clientId);
        $client->setClientSecret($clientSecret);
        $client->setRedirectUri($redirectUri);
        
        // Set scopes exactly as they should be (no string interpolation)
        $client->setScopes([
            'https://www.googleapis.com/auth/calendar'
        ]);
        
        // Standard OAuth parameters
        $client->setAccessType('offline');
        $client->setPrompt('consent');
        $client->setIncludeGrantedScopes(true);
        
        // Handle existing tokens if integration provided
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
        $client = new GoogleClient();
        
        $clientId = '263415563843-iisvu1oericu0v5mvc7bl2c1p3obq2mq.apps.googleusercontent.com';
        $clientSecret = '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';
        $redirectUri = 'https://app.skedi.com/oauth/google/callback';
        
        $client->setClientId($clientId);
        $client->setClientSecret($clientSecret);
        $client->setRedirectUri($redirectUri);
        $client->setAccessType('offline');
        $client->setPrompt('consent');
        $client->setIncludeGrantedScopes(true);
        
        // Include userinfo.email scope
        $client->setScopes([
            'https://www.googleapis.com/auth/calendar',
            'https://www.googleapis.com/auth/userinfo.email'
        ]);
        
        return $client->createAuthUrl();
    }




    /**
     * Handle OAuth callback and exchange code for tokens
     */
    public function handleAuthCallback(UserEntity $user, string $code): IntegrationEntity
    {
        try {
            // Create a new Google client for this authentication flow
            $client = new GoogleClient();
            
            // Set direct credentials
            $clientId = '263415563843-iisvu1oericu0v5mvc7bl2c1p3obq2mq.apps.googleusercontent.com';
            $clientSecret = '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';
            $redirectUri = 'https://app.skedi.com/oauth/google/callback';
            
            // Set up client configuration
            $client->setClientId($clientId);
            $client->setClientSecret($clientSecret);
            $client->setRedirectUri($redirectUri);
            $client->setAccessType('offline');
            $client->setPrompt('consent');
            
            // Set scopes
            $client->setScopes([
                'https://www.googleapis.com/auth/calendar',
                'https://www.googleapis.com/auth/userinfo.email'
            ]);
            
            // Exchange the authorization code for an access token
            try {
                $accessToken = $client->fetchAccessTokenWithAuthCode($code);
                
                if (isset($accessToken['error'])) {
                    throw new IntegrationException('Failed to get access token: ' . 
                        ($accessToken['error_description'] ?? $accessToken['error']));
                }
            } catch (\Exception $e) {
                throw new IntegrationException('Token exchange failed: ' . $e->getMessage());
            }
            
            // Create expiration date
            $expiresIn = isset($accessToken['expires_in']) ? $accessToken['expires_in'] : 3600;
            $expiresAt = new DateTime();
            $expiresAt->modify("+{$expiresIn} seconds");
            
            // Try to get Google account email
            $googleEmail = null;
            $googleUserId = null;
            
            try {
                // Create a new client just for this operation
                $userClient = new GoogleClient();
                $userClient->setClientId($clientId);
                $userClient->setClientSecret($clientSecret);
                
                // Set the access token we received
                $userClient->setAccessToken($accessToken);
                
                // Call the userinfo API
                $oauth2 = new \Google\Service\Oauth2($userClient);
                $userInfo = $oauth2->userinfo->get();
                
                // Store the email and user ID
                $googleEmail = $userInfo->getEmail();
                $googleUserId = $userInfo->getId();
            } catch (\Exception $e) {
                // Log error but continue
                $this->logger->warning('Could not fetch Google account info, continuing anyway', [
                    'error' => $e->getMessage()
                ]);
            }
            
            // Use Google email if available, otherwise fall back to user's system email
            $integrationName = 'Google Calendar';
            if ($googleEmail) {
                $integrationName .= ' (' . $googleEmail . ')';
            } else {
                $integrationName .= ' (' . $user->getEmail() . ')';
            }
            
            // Use Google user ID if available, otherwise generate one
            $externalId = $googleUserId ?? 'google_' . uniqid();
            
            // Check if this user already has a Google Calendar integration
            $existingIntegration = $this->integrationRepository->findOneBy([
                'user' => $user,
                'provider' => 'google_calendar',
                'status' => 'active'
            ]);
            
            $integration = null;
            
            if ($existingIntegration) {
                // Update existing integration
                $existingIntegration->setAccessToken($accessToken['access_token']);
                $existingIntegration->setTokenExpires($expiresAt);
                
                // Update name and external ID if we got new info
                if ($googleEmail) {
                    $existingIntegration->setName($integrationName);
                }
                
                if ($googleUserId) {
                    $existingIntegration->setExternalId($externalId);
                }
                
                // Only update refresh token if a new one was provided
                if (isset($accessToken['refresh_token'])) {
                    $existingIntegration->setRefreshToken($accessToken['refresh_token']);
                }
                
                // Update config with Google email
                $config = $existingIntegration->getConfig() ?? [];
                if ($googleEmail) {
                    $config['google_email'] = $googleEmail;
                }
                $existingIntegration->setConfig($config);
                
                $this->entityManager->persist($existingIntegration);
                $this->entityManager->flush();
                
                $integration = $existingIntegration;
            } else {
                // Create new integration
                $integration = new IntegrationEntity();
                $integration->setUser($user);
                $integration->setProvider('google_calendar');
                $integration->setName($integrationName);
                $integration->setExternalId($externalId);
                $integration->setAccessToken($accessToken['access_token']);
                
                if (isset($accessToken['refresh_token'])) {
                    $integration->setRefreshToken($accessToken['refresh_token']);
                }
                
                $integration->setTokenExpires($expiresAt);
                $integration->setScopes(implode(',', $client->getScopes()));
                
                // Store Google email in the config
                $config = [
                    'calendars' => []
                ];
                
                if ($googleEmail) {
                    $config['google_email'] = $googleEmail;
                }
                
                $integration->setConfig($config);
                $integration->setStatus('active');
                
                $this->entityManager->persist($integration);
                $this->entityManager->flush();
            }
            
            // Perform initial sync as a background process to avoid blocking the auth flow
            try {
                // Sync events for the next 30 days to start
                $startDate = new DateTime('today');
                $endDate = new DateTime('+30 days');
                
                // Also sync events from the past 7 days
                $pastStartDate = new DateTime('-7 days');
                
                // First sync past events 
                $this->syncEvents($integration, $pastStartDate, $startDate);
                
                // Then sync future events
                $this->syncEvents($integration, $startDate, $endDate);
                
                $this->logger->info('Initial calendar sync completed successfully', [
                    'integration_id' => $integration->getId(),
                    'user_id' => $user->getId()
                ]);
            } catch (\Exception $e) {
                // Log but don't fail the auth process
                $this->logger->warning('Initial calendar sync failed, but continuing', [
                    'error' => $e->getMessage(),
                    'integration_id' => $integration->getId(),
                    'user_id' => $user->getId()
                ]);
            }
            
            return $integration;
        } catch (IntegrationException $e) {
            throw $e;
        } catch (\Exception $e) {
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
                $startTime = new DateTime($start->date, new \DateTimeZone('UTC'));
                $endTime = new DateTime($end->date, new \DateTimeZone('UTC'));
            } else {
                // Timed event - IMPORTANT: Explicitly convert to UTC
                $startDateTime = $start->dateTime;
                $endDateTime = $end->dateTime;
                
                // Get timezone from event or use UTC as fallback
                $timezone = $start->timeZone ?: 'UTC';
                
                // Parse with original timezone
                $startTime = new DateTime($startDateTime, new \DateTimeZone($timezone));
                $endTime = new DateTime($endDateTime, new \DateTimeZone($timezone));
                
                // Convert to UTC
                $startTime->setTimezone(new \DateTimeZone('UTC'));
                $endTime->setTimezone(new \DateTimeZone('UTC'));
            }
    
            // Create or update the event
            if ($existingEvent) {
                // Update existing event with new data
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
            
            // Check if token needs refresh
            if ($integration->getTokenExpires() && $integration->getTokenExpires() < new DateTime()) {
                $this->refreshToken($integration, $client);
            }
            
            $service = new GoogleCalendar($client);
            
            // Get calendar list
            $calendarList = $service->calendarList->listCalendarList();
            $savedEvents = [];
            $allEventIds = [];
            
            // Format dates for Google API query
            $timeMin = $startDate->format('c');
            $timeMax = $endDate->format('c');
            
            // Start a database transaction
            $this->entityManager->beginTransaction();
            $batchSize = 0;
            $maxBatchSize = 100; // Process this many events before flushing
            
            $this->logger->info('Starting Google Calendar sync', [
                'integration_id' => $integration->getId(),
                'user_id' => $user->getId(),
                'start_date' => $startDate->format('Y-m-d'),
                'end_date' => $endDate->format('Y-m-d')
            ]);
            
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
                
                // Get events from this calendar with pagination
                $pageToken = null;
                
                do {
                    $optParams = [
                        'timeMin' => $timeMin,
                        'timeMax' => $timeMax,
                        'showDeleted' => true,
                        'singleEvents' => true,
                        'orderBy' => 'startTime',
                        'maxResults' => 250 // Reasonable batch size for API
                    ];
                    
                    if ($pageToken) {
                        $optParams['pageToken'] = $pageToken;
                    }
                    
                    $eventsResult = $service->events->listEvents($calendarId, $optParams);
                    $events = $eventsResult->getItems();
                    
                    $this->logger->info('Retrieved batch of events', [
                        'calendar_id' => $calendarId,
                        'calendar_name' => $calendarName,
                        'batch_size' => count($events)
                    ]);
                    
                    // Process this batch of events
                    foreach ($events as $event) {
                        // Skip declined events where the user is not attending
                        $attendees = $event->getAttendees();
                        if ($attendees) {
                            $userEmail = $integration->getConfig()['google_email'] ?? null;
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
                        
                        // IMPORTANT: Skip events created by our application
                        if ($this->isSkediEvent($event)) {
                            $this->logger->info('Skipping Skedi event during sync', [
                                'event_id' => $event->getId(),
                                'title' => $event->getSummary()
                            ]);
                            
                            // Still add this ID to our list of seen events to prevent deletion
                            $calendarEventIds[] = $event->getId();
                            continue;
                        }
                        
                        // Save the event to our database (only external events)
                        $savedEvent = $this->saveEvent($integration, $user, $event, $calendarId, $calendarName);
                        if ($savedEvent) {
                            $savedEvents[] = $savedEvent;
                            $calendarEventIds[] = $event->getId();
                            $batchSize++;
                        }
                    }
                    
                    // If we've processed enough events, flush to database
                    if ($batchSize >= $maxBatchSize) {
                        $this->entityManager->flush();
                        $batchSize = 0;
                        $this->logger->info('Flushed batch of events to database');
                    }
                    
                    // Get the next page token
                    $pageToken = $eventsResult->getNextPageToken();
                    
                } while ($pageToken); // Continue until no more pages
                
                // Clean up deleted events for this calendar
                $this->cleanupDeletedEvents($user, $calendarEventIds, $calendarId, $startDate, $endDate);
                
                // Collect all event IDs for final check
                $allEventIds = array_merge($allEventIds, $calendarEventIds);
            }
            
            // Flush any remaining events
            if ($batchSize > 0) {
                $this->entityManager->flush();
            }
            
            // Update last synced timestamp
            $integration->setLastSynced(new DateTime());
            $this->entityManager->persist($integration);
            $this->entityManager->flush();
            
            // Commit the transaction
            $this->entityManager->commit();
            
            // Also update the user availability records
            $this->syncUserAvailability($user, $savedEvents);
            
            $this->logger->info('Successfully synced Google Calendar events', [
                'integration_id' => $integration->getId(),
                'user_id' => $user->getId(),
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
     * Clean up events that no longer exist in Google Calendar
     */

    private function cleanupDeletedEvents(UserEntity $user, array $keepEventIds, string $calendarId, DateTime $startDate, DateTime $endDate): void
    {
        try {
            $filters = [
                [
                    'field' => 'startTime',
                    'operator' => 'greater_than_or_equal',
                    'value' => $startDate
                ],
                [
                    'field' => 'endTime',
                    'operator' => 'less_than_or_equal',
                    'value' => $endDate
                ],
                [
                    'field' => 'calendarId',
                    'operator' => 'equals',
                    'value' => $calendarId
                ]
            ];
            
            $events = $this->crudManager->findMany(
                GoogleCalendarEventEntity::class,
                $filters,
                1,
                1000,
                ['user' => $user]
            );
            
            foreach ($events as $event) {
                if (!in_array($event->getGoogleEventId(), $keepEventIds)) {
                    // Mark as cancelled
                    $event->setStatus('cancelled');
                    $this->entityManager->persist($event);
                }
            }
        } catch (\Exception $e) {
            $this->logger->error('Error cleaning up deleted events: ' . $e->getMessage(), [
                'user_id' => $user->getId(),
                'calendar_id' => $calendarId
            ]);
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



    /**
     * Create an event in Google Calendar
     *
     * @param IntegrationEntity $integration The user's Google Calendar integration
     * @param string $title Event title
     * @param DateTimeInterface $startTime Event start time (in UTC)
     * @param DateTimeInterface $endTime Event end time (in UTC)
     * @param array $options Additional event options (description, location, etc.)
     * @return array Event data including Google Calendar event ID
     * @throws IntegrationException
     */
    public function createCalendarEvent(
        IntegrationEntity $integration,
        string $title,
        DateTimeInterface $startTime,
        DateTimeInterface $endTime,
        array $options = []
    ): array {
        try {
            $user = $integration->getUser();
            $client = $this->getGoogleClient($integration);
            
            // Check if token needs refresh
            if ($integration->getTokenExpires() && $integration->getTokenExpires() < new DateTime()) {
                $this->refreshToken($integration, $client);
            }
            
            $service = new GoogleCalendar($client);
            
            // Default to primary calendar if not specified
            $calendarId = $options['calendar_id'] ?? 'primary';
            
            // Create event
            $event = new \Google\Service\Calendar\Event();
            $event->setSummary($title);
            
            // Add extended properties to mark this as our own event
            $extendedProperties = new \Google\Service\Calendar\EventExtendedProperties();
            $private = ['skedi_event' => 'true'];
            $extendedProperties->setPrivate($private);
            $event->setExtendedProperties($extendedProperties);
            
            // Set description if provided
            if (!empty($options['description'])) {
                $event->setDescription($options['description']);
            }
            
            // Set location if provided
            if (!empty($options['location'])) {
                $event->setLocation($options['location']);
            }
            
            // Set visibility/transparency
            $transparency = $options['transparency'] ?? 'opaque'; // opaque = busy, transparent = free
            $event->setTransparency($transparency);
            
            // Set start and end times
            $startDateTime = clone $startTime;
            $endDateTime = clone $endTime;
            
            // Convert to RFC3339 format required by Google
            $start = new \Google\Service\Calendar\EventDateTime();
            $start->setDateTime($startDateTime->format('c'));
            $event->setStart($start);
            
            $end = new \Google\Service\Calendar\EventDateTime();
            $end->setDateTime($endDateTime->format('c'));
            $event->setEnd($end);
            
            // Set color if provided
            if (!empty($options['color_id'])) {
                $event->setColorId($options['color_id']);
            }
            
            // Add attendees if provided
            if (!empty($options['attendees']) && is_array($options['attendees'])) {
                $attendees = [];
                foreach ($options['attendees'] as $attendee) {
                    $eventAttendee = new \Google\Service\Calendar\EventAttendee();
                    $eventAttendee->setEmail($attendee['email']);
                    
                    if (isset($attendee['name'])) {
                        $eventAttendee->setDisplayName($attendee['name']);
                    }
                    
                    if (isset($attendee['optional']) && $attendee['optional'] === true) {
                        $eventAttendee->setOptional(true);
                    }
                    
                    $attendees[] = $eventAttendee;
                }
                $event->setAttendees($attendees);
            }
            
            // Add reminders if provided
            if (!empty($options['reminders']) && is_array($options['reminders'])) {
                $reminders = new \Google\Service\Calendar\EventReminders();
                $reminderItems = [];
                
                foreach ($options['reminders'] as $reminder) {
                    $eventReminder = new \Google\Service\Calendar\EventReminder();
                    $eventReminder->setMethod($reminder['method'] ?? 'popup');
                    $eventReminder->setMinutes($reminder['minutes'] ?? 30);
                    $reminderItems[] = $eventReminder;
                }
                
                $reminders->setUseDefault(false);
                $reminders->setOverrides($reminderItems);
                $event->setReminders($reminders);
            }
            
            // Create the event
            $createdEvent = $service->events->insert($calendarId, $event);
            
            // Update integration's last synced time
            $integration->setLastSynced(new DateTime());
            $this->entityManager->persist($integration);
            $this->entityManager->flush();
            
            // IMPORTANT: Don't save this event to our own database since it's our own event
            // We only want to store external events in our database
            
            // Log success
            $this->logger->info('Created Google Calendar event', [
                'integration_id' => $integration->getId(),
                'user_id' => $user->getId(),
                'event_id' => $createdEvent->getId()
            ]);
            
            // Return event data
            return [
                'google_event_id' => $createdEvent->getId(),
                'calendar_id' => $calendarId,
                'html_link' => $createdEvent->getHtmlLink(),
                'title' => $title,
                'start_time' => $startTime->format('Y-m-d H:i:s'),
                'end_time' => $endTime->format('Y-m-d H:i:s')
            ];
        } catch (\Exception $e) {
            $this->logger->error('Error creating Google Calendar event: ' . $e->getMessage(), [
                'integration_id' => $integration->getId(),
                'user_id' => $integration->getUser()->getId(),
                'trace' => $e->getTraceAsString()
            ]);
            
            throw new IntegrationException('Failed to create Google Calendar event: ' . $e->getMessage());
        }
    }



    /**
     * Delete events in Google Calendar for a cancelled booking
     */

/**
 * Delete events in Google Calendar for a cancelled booking
 */
public function deleteEventForCancelledBooking(IntegrationEntity $integration, \App\Plugins\Events\Entity\EventBookingEntity $booking): bool
{
    // Start with an array to collect all debug messages
    $debug = [];
    $debug[] = "DEBUG: Starting Google Calendar event deletion";
    $debug[] = "Booking ID: " . $booking->getId();
    $debug[] = "Event Name: " . $booking->getEvent()->getName();
    $debug[] = "Start Time: " . $booking->getStartTime()->format('Y-m-d H:i:s e');
    $debug[] = "End Time: " . $booking->getEndTime()->format('Y-m-d H:i:s e');
    
    try {
        $user = $integration->getUser();
        $client = $this->getGoogleClient($integration);
        
        // Check if token needs refresh
        if ($integration->getTokenExpires() && $integration->getTokenExpires() < new DateTime()) {
            $debug[] = "Refreshing token before deletion";
            $this->refreshToken($integration, $client);
        }
        
        $service = new GoogleCalendar($client);
        $event = $booking->getEvent();
        $title = $event->getName();
        
        // Get all calendars for this user
        $calendarList = $service->calendarList->listCalendarList();
        $deletedCount = 0;
        
        // Get the event title for searching
        $eventTitle = $event->getName();
        
        foreach ($calendarList->getItems() as $calendarListEntry) {
            $calendarId = $calendarListEntry->getId();
            $calendarName = $calendarListEntry->getSummary() ?? 'Unknown';
            
            // Only process primary calendar and selected calendars
            $isPrimary = $calendarListEntry->getPrimary() ?? false;
            $isSelected = $calendarListEntry->getSelected() ?? false;
            
            $debug[] = "Calendar: $calendarName (ID: $calendarId) - Primary: " . ($isPrimary ? 'Yes' : 'No') . 
                     ", Selected: " . ($isSelected ? 'Yes' : 'No');
            
            if (!$isPrimary && !$isSelected) {
                $debug[] = "Skipping calendar - not primary or selected";
                continue;
            }
            
            // Search for events that match this booking's time and title
            $startTime = clone $booking->getStartTime();
            $endTime = clone $booking->getEndTime();
            
            // Add a buffer to handle slight time differences
            $startSearchBuffer = clone $startTime;
            $startSearchBuffer->modify('-5 minutes');
            $endSearchBuffer = clone $endTime;
            $endSearchBuffer->modify('+5 minutes');
            
            $debug[] = "Searching for events between " . $startSearchBuffer->format('Y-m-d H:i:s e') . 
                     " and " . $endSearchBuffer->format('Y-m-d H:i:s e') . " with title '$eventTitle'";
            
            $optParams = [
                'timeMin' => $startSearchBuffer->format('c'),
                'timeMax' => $endSearchBuffer->format('c'),
                'q' => $eventTitle,
                'singleEvents' => true
            ];
            
            try {
                $eventsResult = $service->events->listEvents($calendarId, $optParams);
                $events = $eventsResult->getItems();
                
                $debug[] = "Found " . count($events) . " matching events";
                
                foreach ($events as $googleEvent) {
                    $debug[] = "Google Event: ID=" . $googleEvent->getId() . 
                         ", Title='" . $googleEvent->getSummary() . "'";
                    
                    if (!$googleEvent->getStart() || !$googleEvent->getEnd()) {
                        $debug[] = "Skipping event without start/end time";
                        continue;
                    }
                    
                    // Delete the events that match
                    if ($googleEvent->getStart()->dateTime) { // Only timed events
                        try {
                            $debug[] = "Attempting to delete event " . $googleEvent->getId() . 
                                 " from calendar " . $calendarId;
                            
                            $service->events->delete($calendarId, $googleEvent->getId());
                            $deletedCount++;
                            
                            $debug[] = "Successfully deleted event";
                        } catch (\Exception $e) {
                            $debug[] = "Failed to delete event: " . $e->getMessage();
                        }
                    } else {
                        $debug[] = "Skipping all-day event";
                    }
                }
                
            } catch (\Exception $e) {
                $debug[] = "Error searching calendar: " . $e->getMessage();
            }
        }
        
        $debug[] = "Deletion complete. Deleted $deletedCount events.";
        
        // Output all debug messages and exit
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'debug_info' => $debug,
            'deleted_count' => $deletedCount
        ], JSON_PRETTY_PRINT);
        exit();
        
        // This won't be reached due to exit()
        return $deletedCount > 0;
    } catch (\Exception $e) {
        $debug[] = "ERROR: " . $e->getMessage();
        $debug[] = "Stack trace: " . $e->getTraceAsString();
        
        // Output error debug messages and exit
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'debug_info' => $debug,
            'error' => $e->getMessage()
        ], JSON_PRETTY_PRINT);
        exit();
        
        // This won't be reached due to exit()
        return false;
    }
}


    /**
     * Sync a Skedi event booking to Google Calendar
     */
    public function syncEventBooking(
        \App\Plugins\Events\Entity\EventBookingEntity $booking, 
        ?\App\Plugins\Account\Entity\UserEntity $specificUser = null
    ): array {
        $results = [
            'success' => 0,
            'failure' => 0,
            'skipped' => 0
        ];
        
        try {
            $event = $booking->getEvent();
            $title = $event->getName();
            
            // Format description with booking details
            $description = "Booking for: {$title}\n";
            
            // Add booking form data if available
            $formData = $booking->getFormDataAsArray();
            if ($formData) {
                $description .= "\nBooking details:\n";
                foreach ($formData as $key => $value) {
                    if (is_scalar($value)) {
                        $description .= "- " . ucfirst(str_replace('_', ' ', $key)) . ": " . 
                            (is_string($value) ? $value : json_encode($value)) . "\n";
                    }
                }
            }
            
            // Get all assignees for this event
            $criteria = ['event' => $event];
            if ($specificUser) {
                $criteria['user'] = $specificUser;
            }
            
            $assignees = $this->crudManager->findMany(
                'App\Plugins\Events\Entity\EventAssigneeEntity',
                [],
                1,
                1000,
                $criteria
            );
            
            // Prepare attendees from booking guests
            $attendees = [];
            $guests = $this->crudManager->findMany(
                'App\Plugins\Events\Entity\EventGuestEntity',
                [],
                1,
                100,
                ['booking' => $booking]
            );
            
            foreach ($guests as $guest) {
                $attendees[] = [
                    'email' => $guest->getEmail(),
                    'name' => $guest->getName()
                ];
            }
            
            // Process each assignee
            foreach ($assignees as $assignee) {
                $user = $assignee->getUser();
                
                // Get active Google Calendar integrations for this user
                $integrations = $this->crudManager->findMany(
                    'App\Plugins\Integrations\Entity\IntegrationEntity',
                    [],
                    1,
                    10,
                    [
                        'user' => $user,
                        'provider' => 'google_calendar',
                        'status' => 'active'
                    ]
                );
                
                if (empty($integrations)) {
                    $results['skipped']++;
                    continue; // Skip users without integrations
                }
                
                // Use the first active integration
                $integration = $integrations[0];
                
                try {
                    // Create the event in Google Calendar and get back the result
                    $createdEvent = $this->createCalendarEvent(
                        $integration,
                        $title,
                        $booking->getStartTime(),
                        $booking->getEndTime(),
                        [
                            'description' => $description,
                            'attendees' => $attendees,
                            'source_id' => 'booking_' . $booking->getId()
                        ]
                    );
                    
                    // Make sure the entity is persisted and flushed before using its ID
                    $this->entityManager->flush();
                    
                    $results['success']++;
                } catch (\Exception $e) {
                    // Log but don't fail the whole process
                    $this->logger->error('Failed to create Google Calendar event for user: ' . $e->getMessage(), [
                        'user_id' => $user->getId(),
                        'integration_id' => $integration->getId(),
                        'booking_id' => $booking->getId()
                    ]);
                    
                    $results['failure']++;
                }
            }
            
            return $results;
        } catch (\Exception $e) {
            $this->logger->error('Error handling event booking for Google Calendar sync: ' . $e->getMessage(), [
                'booking_id' => $booking->getId(),
                'event_id' => $booking->getEvent()->getId()
            ]);
            
            return $results;
        }
    }   



    /**
     * Delete all Google Calendar events for a cancelled booking
     */
    public function deleteGoogleEventsForBooking(EventBookingEntity $booking): void
    {
        try {
            // Get all assignees for this event
            $event = $booking->getEvent();
            $assignees = $this->entityManager->getRepository('App\Plugins\Events\Entity\EventAssigneeEntity')
                ->findBy(['event' => $event]);
            
            foreach ($assignees as $assignee) {
                $user = $assignee->getUser();
                
                // Find Google Calendar integrations for this user
                $integrations = $this->integrationRepository->findBy([
                    'user' => $user,
                    'provider' => 'google_calendar',
                    'status' => 'active'
                ]);
                
                foreach ($integrations as $integration) {
                    try {
                        // Delete the event from Google Calendar
                        $this->deleteEventForCancelledBooking($integration, $booking);
                    } catch (\Exception $e) {
                        $this->logger->error('Failed to delete Google Calendar event: ' . $e->getMessage(), [
                            'booking_id' => $booking->getId(),
                            'user_id' => $user->getId()
                        ]);
                    }
                }
            }
        } catch (\Exception $e) {
            $this->logger->error('Error deleting Google Calendar events: ' . $e->getMessage(), [
                'booking_id' => $booking->getId()
            ]);
        }
    }

    /**
     * Delete an event from Google Calendar for a cancelled booking
     */
    public function deleteBookingEvent(IntegrationEntity $integration, \App\Plugins\Events\Entity\EventBookingEntity $booking): bool
    {
        try {
            $user = $integration->getUser();
            $client = $this->getGoogleClient($integration);
            
            // Check if token needs refresh
            if ($integration->getTokenExpires() && $integration->getTokenExpires() < new DateTime()) {
                $this->refreshToken($integration, $client);
            }
            
            $service = new GoogleCalendar($client);
            $event = $booking->getEvent();
            $title = $event->getName();
            
            // Get calendars
            $calendarList = $service->calendarList->listCalendarList();
            $deletedCount = 0;
            
            foreach ($calendarList->getItems() as $calendarListEntry) {
                $calendarId = $calendarListEntry->getId();
                
                // Skip calendars that aren't primary or selected
                $isPrimary = $calendarListEntry->getPrimary() ?? false;
                $isSelected = $calendarListEntry->getSelected() ?? false;
                
                if (!$isPrimary && !$isSelected) {
                    continue;
                }
                
                // Search for events that match this booking's time and title
                $startTime = clone $booking->getStartTime();
                $endTime = clone $booking->getEndTime();
                
                // Add a small buffer to handle time discrepancies
                $startSearch = clone $startTime;
                $startSearch->modify('-2 minutes');
                $endSearch = clone $endTime;
                $endSearch->modify('+2 minutes');
                
                $optParams = [
                    'timeMin' => $startSearch->format('c'),
                    'timeMax' => $endSearch->format('c'),
                    'q' => $title, // Search by title
                    'singleEvents' => true
                ];
                
                try {
                    $eventsResult = $service->events->listEvents($calendarId, $optParams);
                    $events = $eventsResult->getItems();
                    
                    foreach ($events as $event) {
                        // Get event start/end times
                        $eventStart = null;
                        $eventEnd = null;
                        
                        if ($event->getStart()->dateTime) {
                            $eventStart = new DateTime($event->getStart()->dateTime);
                            $eventEnd = new DateTime($event->getEnd()->dateTime);
                        } else if ($event->getStart()->date) {
                            // Skip all-day events
                            continue;
                        }
                        
                        if (!$eventStart || !$eventEnd) {
                            continue;
                        }
                        
                        // Check if times match within 3 minutes
                        $startDiff = abs($eventStart->getTimestamp() - $startTime->getTimestamp());
                        $endDiff = abs($eventEnd->getTimestamp() - $endTime->getTimestamp());
                        
                        if ($startDiff <= 180 && $endDiff <= 180) {
                            try {
                                // Delete the event
                                $service->events->delete($calendarId, $event->getId());
                                $deletedCount++;
                                
                                $this->logger->info('Deleted Google Calendar event', [
                                    'event_id' => $event->getId(),
                                    'booking_id' => $booking->getId()
                                ]);
                            } catch (\Exception $e) {
                                $this->logger->warning('Failed to delete event: ' . $e->getMessage());
                            }
                        }
                    }
                } catch (\Exception $e) {
                    $this->logger->warning('Failed to search calendar: ' . $e->getMessage());
                }
            }
            
            return $deletedCount > 0;
        } catch (\Exception $e) {
            $this->logger->error('Error deleting Google Calendar events: ' . $e->getMessage(), [
                'integration_id' => $integration->getId(),
                'user_id' => $integration->getUser()->getId(),
                'booking_id' => $booking->getId()
            ]);
            
            return false;
        }
    }



    /**
     * Delete all existing events in a date range for a user
     */
    private function deleteExistingEventsInDateRange(UserEntity $user, DateTime $startDate, DateTime $endDate): void
    {
        try {
            $qb = $this->entityManager->createQueryBuilder();
            $qb->delete(GoogleCalendarEventEntity::class, 'e')
                ->where('e.user = :user')
                ->andWhere('e.startTime >= :startDate')
                ->andWhere('e.endTime <= :endDate')
                ->setParameter('user', $user)
                ->setParameter('startDate', $startDate)
                ->setParameter('endDate', $endDate);
            
            $deleted = $qb->getQuery()->execute();
            
            $this->logger->info('Deleted existing events in date range', [
                'user_id' => $user->getId(),
                'count' => $deleted,
                'start_date' => $startDate->format('Y-m-d'),
                'end_date' => $endDate->format('Y-m-d')
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Error deleting existing events: ' . $e->getMessage(), [
                'user_id' => $user->getId()
            ]);
        }
    }   



    /**
     * Determine if an event is created by Skedi
     */
    private function isSkediEvent(\Google\Service\Calendar\Event $event): bool
    {
        // Check extended properties
        if ($event->getExtendedProperties() && $event->getExtendedProperties()->getPrivate()) {
            $private = $event->getExtendedProperties()->getPrivate();
            if (isset($private['skedi_event']) && $private['skedi_event'] === 'true') {
                return true;
            }
        }
        
        // Check for Skedi-specific markers in the description
        $description = $event->getDescription() ?? '';
        if (strpos($description, 'Booking for:') !== false || 
            strpos($description, 'Booking details:') !== false) {
            return true;
        }
        
        // Check for Skedi-specific formats in the title
        $title = $event->getSummary() ?? '';
        if (strpos($title, ' - Booking') !== false) {
            return true;
        }
        
        return false;
    }


}