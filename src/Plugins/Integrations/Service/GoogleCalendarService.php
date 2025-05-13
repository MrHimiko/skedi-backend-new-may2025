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
            'https://www.googleapis.com/auth/calendar.readonly',
            'https://www.googleapis.com/auth/calendar.events'
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
            'https://www.googleapis.com/auth/calendar.readonly',
            'https://www.googleapis.com/auth/calendar.events',
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
                'https://www.googleapis.com/auth/calendar.readonly',
                'https://www.googleapis.com/auth/calendar.events',
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
            // Skip events without start/end time
            if (!$event->getStart() || !$event->getEnd()) {
                return null;
            }
            
            // Determine if this is an all-day event
            $isAllDay = false;
            $startTime = null;
            $endTime = null;
            
            $start = $event->getStart();
            $end = $event->getEnd();
            
            // Handle all-day events
            if ($start->date) {
                $isAllDay = true;
                // For all-day events, use UTC midnight for consistency
                $startTime = new DateTime($start->date . 'T00:00:00Z', new \DateTimeZone('UTC'));
                $endTime = new DateTime($end->date . 'T23:59:59Z', new \DateTimeZone('UTC'));
            } 
            // Handle timed events
            else if ($start->dateTime) {
                // Get timezone from event or use UTC as fallback
                $timezone = $start->timeZone ?: 'UTC';
                
                try {
                    // Parse with original timezone
                    $startTime = new DateTime($start->dateTime);
                    $endTime = new DateTime($end->dateTime);
                    
                    // Debug log the original times
                    $this->logger->debug('Original event times', [
                        'event_id' => $event->getId(),
                        'start_time' => $start->dateTime,
                        'end_time' => $end->dateTime,
                        'timezone' => $timezone,
                        'parsed_start' => $startTime->format('Y-m-d H:i:s T'),
                        'parsed_end' => $endTime->format('Y-m-d H:i:s T')
                    ]);
                    
                    // Make sure they're in UTC for storage
                    $startTime->setTimezone(new \DateTimeZone('UTC'));
                    $endTime->setTimezone(new \DateTimeZone('UTC'));
                } catch (\Exception $e) {
                    $this->logger->error('Error parsing event times: ' . $e->getMessage(), [
                        'event_id' => $event->getId(),
                        'start_time' => $start->dateTime,
                        'end_time' => $end->dateTime
                    ]);
                    return null;
                }
            } else {
                // Neither date nor dateTime available
                $this->logger->warning('Event has no valid start/end time', [
                    'event_id' => $event->getId()
                ]);
                return null;
            }
            
            // Check if the event already exists in our database
            $existingEvent = $this->entityManager->getRepository(GoogleCalendarEventEntity::class)->findOneBy([
                'user' => $user,
                'googleEventId' => $event->getId(),
                'calendarId' => $calendarId
            ]);
            
            // Debug log the final times we're saving
            $this->logger->debug('Saving event with times', [
                'event_id' => $event->getId(),
                'title' => $event->getSummary(),
                'start_time' => $startTime->format('Y-m-d H:i:s e'),
                'end_time' => $endTime->format('Y-m-d H:i:s e'),
                'is_all_day' => $isAllDay
            ]);
            
            // Create or update the event
            if ($existingEvent) {
                $existingEvent->setTitle($event->getSummary() ?: 'Untitled Event');
                $existingEvent->setDescription($event->getDescription());
                $existingEvent->setLocation($event->getLocation());
                $existingEvent->setStartTime($startTime);
                $existingEvent->setEndTime($endTime);
                $existingEvent->setIsAllDay($isAllDay);
                $existingEvent->setStatus($event->getStatus() ?: 'confirmed');
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
                $this->entityManager->flush();
                
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
                $newEvent->setStatus($event->getStatus() ?: 'confirmed');
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
                $this->entityManager->flush();
                
                return $newEvent;
            }
        } catch (\Exception $e) {
            $this->logger->error('Error saving event: ' . $e->getMessage(), [
                'event_id' => $event->getId() ?? 'unknown',
                'calendar_id' => $calendarId,
                'user_id' => $user->getId(),
                'trace' => $e->getTraceAsString()
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
            
            // Format dates for Google API query
            $timeMin = $startDate->format('c');
            $timeMax = $endDate->format('c');
            
            $this->logger->info('Starting Google Calendar sync with full reset', [
                'integration_id' => $integration->getId(),
                'user_id' => $user->getId(),
                'start_date' => $startDate->format('Y-m-d'),
                'end_date' => $endDate->format('Y-m-d')
            ]);
            
            // STEP 1: DELETE ALL EXISTING EVENTS FOR THIS DATE RANGE
            $this->deleteAllEventsInRange($user, $startDate, $endDate);
            
            // STEP 2: FETCH AND SAVE ALL ACTIVE EVENTS FROM GOOGLE CALENDAR
            // Loop through each calendar
            foreach ($calendarList->getItems() as $calendarListEntry) {
                $calendarId = $calendarListEntry->getId();
                $calendarName = $calendarListEntry->getSummary();
                
                // Always include primary calendar and selected calendars
                $isPrimary = $calendarListEntry->getPrimary() ?? false;
                $isSelected = $calendarListEntry->getSelected() ?? false;
                
                if (!$isPrimary && !$isSelected) {
                    continue;
                }
                
                $this->logger->info('Processing calendar', [
                    'calendar_id' => $calendarId,
                    'calendar_name' => $calendarName
                ]);
                
                // Get events from this calendar with pagination
                $pageToken = null;
                
                do {
                    $optParams = [
                        'timeMin' => $timeMin,
                        'timeMax' => $timeMax,
                        'showDeleted' => false, // Only get active events
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
                        
                        // Save the active event to our database
                        $savedEvent = $this->saveEvent($integration, $user, $event, $calendarId, $calendarName);
                        if ($savedEvent) {
                            $savedEvents[] = $savedEvent;
                        }
                    }
                    
                    // Get the next page token
                    $pageToken = $eventsResult->getNextPageToken();
                    
                } while ($pageToken); // Continue until no more pages
            }
            
            // Update last synced timestamp
            $integration->setLastSynced(new DateTime());
            $this->entityManager->persist($integration);
            $this->entityManager->flush();
            
            // Also update the user availability records
            $this->syncUserAvailability($user, $savedEvents);
            
            $this->logger->info('Successfully synced Google Calendar events', [
                'integration_id' => $integration->getId(),
                'user_id' => $user->getId(),
                'events_count' => count($savedEvents)
            ]);
            
            return $savedEvents;
        } catch (\Exception $e) {
            $this->logger->error('Error syncing events: ' . $e->getMessage(), [
                'integration_id' => $integration->getId(),
                'user_id' => $integration->getUser()->getId(),
                'trace' => $e->getTraceAsString()
            ]);
            
            throw new IntegrationException('Failed to sync calendar events: ' . $e->getMessage());
        }
    }

    /**
     * Delete all events for a user within a specific date range
     */
    private function deleteAllEventsInRange(UserEntity $user, DateTime $startDate, DateTime $endDate): void
    {
        try {
            $this->logger->info('Deleting all existing events in date range', [
                'user_id' => $user->getId(),
                'start_date' => $startDate->format('Y-m-d'),
                'end_date' => $endDate->format('Y-m-d')
            ]);
            
            // Create query builder to find all events in the range
            $qb = $this->entityManager->createQueryBuilder();
            $qb->delete(GoogleCalendarEventEntity::class, 'e')
                ->where('e.user = :user')
                ->andWhere('e.startTime >= :start')
                ->andWhere('e.endTime <= :end')
                ->setParameter('user', $user)
                ->setParameter('start', $startDate)
                ->setParameter('end', $endDate);
            
            $result = $qb->getQuery()->execute();
            
            $this->logger->info('Deleted existing events', [
                'count' => $result
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Error deleting events: ' . $e->getMessage(), [
                'user_id' => $user->getId()
            ]);
            
            // Don't rethrow - we want to continue with the sync even if delete fails
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
            
            // Store the event in our database
            $savedEvent = $this->saveEvent(
                $integration,
                $user,
                $createdEvent,
                $calendarId,
                $options['calendar_name'] ?? 'Google Calendar'
            );
            
            if (!$savedEvent) {
                throw new IntegrationException('Failed to save the event to database');
            }
            
            // Log success
            $this->logger->info('Created Google Calendar event', [
                'integration_id' => $integration->getId(),
                'user_id' => $user->getId(),
                'event_id' => $createdEvent->getId()
            ]);
            
            // Return event data
            return [
                'id' => $savedEvent->getId(),
                'google_event_id' => $createdEvent->getId(),
                'calendar_id' => $calendarId,
                'html_link' => $createdEvent->getHtmlLink(),
                'title' => $savedEvent->getTitle(),
                'start_time' => $savedEvent->getStartTime()->format('Y-m-d H:i:s'),
                'end_time' => $savedEvent->getEndTime()->format('Y-m-d H:i:s')
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
     * Sync a Skedi event booking to Google Calendar
     *
     * @param EventBookingEntity $booking The event booking to sync
     * @param UserEntity|null $specificUser Optional: sync only for a specific user
     * @return array Status information about sync operations
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
                    if (is_string($value)) {
                        $description .= "- " . ucfirst(str_replace('_', ' ', $key)) . ": $value\n";
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
                    IntegrationEntity::class,
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
                    // Create the event
                    $this->createCalendarEvent(
                        $integration,
                        $title,
                        $booking->getStartTime(),
                        $booking->getEndTime(),
                        [
                            'description' => $description,
                            'attendees' => $attendees
                        ]
                    );
                    
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

}