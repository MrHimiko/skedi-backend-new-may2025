<?php

namespace App\Plugins\Integrations\Service;

use Doctrine\ORM\EntityManagerInterface;
use App\Plugins\Integrations\Repository\IntegrationRepository;
use App\Plugins\Integrations\Entity\OutlookCalendarEventEntity;
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

// Microsoft Graph API libraries
use Microsoft\Graph\Graph;
use Microsoft\Graph\Model;
use GuzzleHttp\Client as HttpClient;

class OutlookCalendarService extends IntegrationService
{
    private string $clientId;
    private string $clientSecret;
    private string $redirectUri;
    private string $tenantId;
    private string $authority;
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
        
        try {
            $this->clientId = 'ca8b407b-677a-44c6-842a-c79e64ba0cd0';
            $this->clientSecret = '3Jm8Q~Pnah2u2xznxe8Cj-Nqgj2EmzN2h1tUdcLp';
            $this->tenantId = '7233e89a-7f4c-4086-9cd1-8b3112ce8360';
            $this->redirectUri = 'https://app.skedi.com/oauth/outlook/callback';
            // Default to common tenant if not specified
            $this->authority = 'https://login.microsoftonline.com/' . ($this->tenantId ?: 'common');
        } catch (\Exception $e) {
            $this->logger->error("Error getting Outlook parameters: " . $e->getMessage());
            // Temporary fallbacks for testing
            $this->clientId = 'ca8b407b-677a-44c6-842a-c79e64ba0cd0';
            $this->clientSecret = '3Jm8Q~Pnah2u2xznxe8Cj-Nqgj2EmzN2h1tUdcLp';
            $this->tenantId = '7233e89a-7f4c-4086-9cd1-8b3112ce8360';
            $this->redirectUri = 'https://app.skedi.com/oauth/outlook/callback';
            $this->authority = 'https://login.microsoftonline.com/common';
        }
    }

    /**
     * Get Microsoft Graph instance
     */
    private function getGraphClient(IntegrationEntity $integration): Graph
    {
        if (!$integration->getAccessToken()) {
            throw new IntegrationException('No access token available');
        }
        
        // Check if token needs refresh
        if ($integration->getTokenExpires() && $integration->getTokenExpires() < new DateTime()) {
            $this->refreshToken($integration);
        }
        
        $graph = new Graph();
        $graph->setAccessToken($integration->getAccessToken());
        
        return $graph;
    }

    /**
     * Get OAuth URL
     */
    public function getAuthUrl(): string
    {
        $tenant = $this->tenantId ?: 'common';
        $authUrl = "https://login.microsoftonline.com/$tenant/oauth2/v2.0/authorize";
        
        $params = [
            'client_id' => $this->clientId,
            'response_type' => 'code',
            'redirect_uri' => $this->redirectUri,
            'response_mode' => 'query',
            'scope' => 'offline_access User.Read Calendars.ReadWrite',
            'state' => uniqid('', true),
        ];
        
        return $authUrl . '?' . http_build_query($params);
    }

    /**
     * Handle OAuth callback and exchange code for tokens
     */
    public function handleAuthCallback(UserEntity $user, string $code): IntegrationEntity
    {
        try {
            $tenant = $this->tenantId ?: 'common';
            $tokenUrl = "https://login.microsoftonline.com/$tenant/oauth2/v2.0/token";
            
            $client = new HttpClient();
            $response = $client->post($tokenUrl, [
                'form_params' => [
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'code' => $code,
                    'redirect_uri' => $this->redirectUri,
                    'grant_type' => 'authorization_code'
                ]
            ]);
            
            $responseBody = json_decode((string) $response->getBody(), true);
            
            if (isset($responseBody['error'])) {
                throw new IntegrationException('Token exchange failed: ' . 
                    ($responseBody['error_description'] ?? $responseBody['error']));
            }
            
            // Create expiration date
            $expiresIn = isset($responseBody['expires_in']) ? $responseBody['expires_in'] : 3600;
            $expiresAt = new DateTime();
            $expiresAt->modify("+{$expiresIn} seconds");
            
            // Get user info from Microsoft Graph
            $graph = new Graph();
            $graph->setAccessToken($responseBody['access_token']);
            
            $outlookUser = null;
            try {
                $outlookUser = $graph->createRequest('GET', '/me')
                    ->setReturnType(Model\User::class)
                    ->execute();
            } catch (\Exception $e) {
                $this->logger->warning('Could not fetch Outlook user info: ' . $e->getMessage());
            }
            
            // Use Outlook email if available, otherwise fall back to user's system email
            $outlookEmail = $outlookUser ? $outlookUser->getMail() : null;
            $outlookUserId = $outlookUser ? $outlookUser->getId() : null;
            
            $integrationName = 'Outlook Calendar';
            if ($outlookEmail) {
                $integrationName .= ' (' . $outlookEmail . ')';
            } else {
                $integrationName .= ' (' . $user->getEmail() . ')';
            }
            
            // Use Outlook user ID if available, otherwise generate one
            $externalId = $outlookUserId ?? 'outlook_' . uniqid();
            
            // Check if this user already has an Outlook Calendar integration
            $existingIntegration = $this->integrationRepository->findOneBy([
                'user' => $user,
                'provider' => 'outlook_calendar',
                'status' => 'active'
            ]);
            
            if ($existingIntegration) {
                // Update existing integration
                $existingIntegration->setAccessToken($responseBody['access_token']);
                $existingIntegration->setTokenExpires($expiresAt);
                
                // Update name and external ID if we got new info
                if ($outlookEmail) {
                    $existingIntegration->setName($integrationName);
                }
                
                if ($outlookUserId) {
                    $existingIntegration->setExternalId($externalId);
                }
                
                // Only update refresh token if a new one was provided
                if (isset($responseBody['refresh_token'])) {
                    $existingIntegration->setRefreshToken($responseBody['refresh_token']);
                }
                
                // Update config with Outlook email
                $config = $existingIntegration->getConfig() ?? [];
                if ($outlookEmail) {
                    $config['outlook_email'] = $outlookEmail;
                }
                $existingIntegration->setConfig($config);
                
                $this->entityManager->persist($existingIntegration);
                $this->entityManager->flush();
                
                $integration = $existingIntegration;
            } else {
                // Create new integration
                $integration = new IntegrationEntity();
                $integration->setUser($user);
                $integration->setProvider('outlook_calendar');
                $integration->setName($integrationName);
                $integration->setExternalId($externalId);
                $integration->setAccessToken($responseBody['access_token']);
                
                if (isset($responseBody['refresh_token'])) {
                    $integration->setRefreshToken($responseBody['refresh_token']);
                }
                
                $integration->setTokenExpires($expiresAt);
                $integration->setScopes('offline_access User.Read Calendars.ReadWrite');
                
                // Store Outlook email in the config
                $config = [
                    'calendars' => []
                ];
                
                if ($outlookEmail) {
                    $config['outlook_email'] = $outlookEmail;
                }
                
                $integration->setConfig($config);
                $integration->setStatus('active');
                
                $this->entityManager->persist($integration);
                $this->entityManager->flush();
            }
            
            // Perform initial sync as a background process
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
            } catch (\Exception $e) {
                // Log but don't fail the auth process
                $this->logger->warning('Initial calendar sync failed: ' . $e->getMessage());
            }
            
            return $integration;
        } catch (IntegrationException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new IntegrationException('Failed to authenticate with Outlook: ' . $e->getMessage());
        }
    }

    /**
     * Refresh token
     */
    private function refreshToken(IntegrationEntity $integration): void
    {
        if (!$integration->getRefreshToken()) {
            throw new IntegrationException('No refresh token available');
        }
        
        try {
            $tenant = $this->tenantId ?: 'common';
            $tokenUrl = "https://login.microsoftonline.com/$tenant/oauth2/v2.0/token";
            
            $client = new HttpClient();
            $response = $client->post($tokenUrl, [
                'form_params' => [
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'refresh_token' => $integration->getRefreshToken(),
                    'redirect_uri' => $this->redirectUri,
                    'grant_type' => 'refresh_token',
                    'scope' => 'offline_access User.Read Calendars.ReadWrite'
                ]
            ]);
            
            $responseBody = json_decode((string) $response->getBody(), true);
            
            if (isset($responseBody['error'])) {
                throw new IntegrationException('Token refresh failed: ' . 
                    ($responseBody['error_description'] ?? $responseBody['error']));
            }
            
            // Update token in database
            $expiresIn = isset($responseBody['expires_in']) ? $responseBody['expires_in'] : 3600;
            $expiresAt = new DateTime();
            $expiresAt->modify("+{$expiresIn} seconds");
            
            $integration->setAccessToken($responseBody['access_token']);
            $integration->setTokenExpires($expiresAt);
            
            // Only update refresh token if a new one was provided
            if (isset($responseBody['refresh_token'])) {
                $integration->setRefreshToken($responseBody['refresh_token']);
            }
            
            $this->entityManager->persist($integration);
            $this->entityManager->flush();
        } catch (\Exception $e) {
            $this->logger->error('Error refreshing token: ' . $e->getMessage());
            throw new IntegrationException('Failed to refresh token: ' . $e->getMessage());
        }
    }

    /**
     * Get user's Outlook Calendar integration
     */
    public function getUserIntegration(UserEntity $user, ?int $integrationId = null): ?IntegrationEntity
    {
        if ($integrationId) {
            $integration = $this->integrationRepository->find($integrationId);
            if ($integration && $integration->getUser()->getId() === $user->getId() && 
                $integration->getProvider() === 'outlook_calendar' && 
                $integration->getStatus() === 'active') {
                return $integration;
            }
            return null;
        }
        
        // Get the most recently created active integration
        return $this->integrationRepository->findOneBy(
            [
                'user' => $user,
                'provider' => 'outlook_calendar',
                'status' => 'active'
            ],
            ['created' => 'DESC']
        );
    }

    /**
     * Get Outlook calendars list
     */
    public function getCalendars(IntegrationEntity $integration): array
    {
        try {
            $graph = $this->getGraphClient($integration);
            
            $calendars = $graph->createRequest('GET', '/me/calendars')
                ->setReturnType(Model\Calendar::class)
                ->execute();
            
            $result = [];
            
            foreach ($calendars as $calendar) {
                $result[] = [
                    'id' => $calendar->getId(),
                    'name' => $calendar->getName(),
                    'color' => $calendar->getColor(),
                    'is_default' => $calendar->getIsDefaultCalendar(),
                    'can_edit' => $calendar->getCanEdit(),
                    'can_share' => $calendar->getCanShare(),
                    'can_view_private_items' => $calendar->getCanViewPrivateItems(),
                    'owner' => [
                        'name' => $calendar->getOwner() ? $calendar->getOwner()->getName() : null,
                        'address' => $calendar->getOwner() ? $calendar->getOwner()->getAddress() : null
                    ]
                ];
            }
            
            // Update the integration with the calendar list
            $config = $integration->getConfig() ?: [];
            $config['calendars'] = $result;
            $integration->setConfig($config);
            
            $this->entityManager->persist($integration);
            $this->entityManager->flush();
            
            return $result;
        } catch (\Exception $e) {
            $this->logger->error('Error fetching calendars: ' . $e->getMessage());
            throw new IntegrationException('Failed to fetch calendars: ' . $e->getMessage());
        }
    }

    /**
     * Sync calendar events for a specific date range
     */
    public function syncEvents(IntegrationEntity $integration, DateTime $startDate, DateTime $endDate): array
    {
        try {
            $user = $integration->getUser();
            $graph = $this->getGraphClient($integration);
            
            // Start a database transaction
            $this->entityManager->beginTransaction();
            $savedEvents = [];
            $allEventIds = [];
            
            // Format dates for Microsoft Graph API (ISO 8601)
            $timeMin = $startDate->format('c');
            $timeMax = $endDate->format('c');
            
            // Get user's calendars
            $calendars = $graph->createRequest('GET', '/me/calendars')
                ->setReturnType(Model\Calendar::class)
                ->execute();
            
            foreach ($calendars as $calendar) {
                $calendarId = $calendar->getId();
                $calendarName = $calendar->getName();
                $calendarEventIds = [];
                
                // Skip some calendars based on criteria if needed
                if (false) { // Add your own criteria here
                    continue;
                }
                
                // Query for events in this calendar
                $eventsUrl = "/me/calendars/{$calendarId}/calendarView?startDateTime={$timeMin}&endDateTime={$timeMax}";
                $queryParams = "\$select=id,subject,bodyPreview,start,end,location,organizer,attendees,isAllDay,showAs,status";
                
                $events = $graph->createRequest('GET', $eventsUrl . '&' . $queryParams)
                    ->setReturnType(Model\Event::class)
                    ->execute();
                
                $this->logger->info('Retrieved Outlook events', [
                    'calendar_id' => $calendarId,
                    'calendar_name' => $calendarName,
                    'count' => count($events)
                ]);
                
                foreach ($events as $event) {
                    // Skip Skedi events (implement detection criteria)
                    if ($this->isSkediEvent($event)) {
                        $calendarEventIds[] = $event->getId();
                        continue;
                    }
                    
                    // Save the event to our database
                    $savedEvent = $this->saveEvent($integration, $user, $event, $calendarId, $calendarName);
                    if ($savedEvent) {
                        $savedEvents[] = $savedEvent;
                        $calendarEventIds[] = $event->getId();
                    }
                }
                
                // Clean up deleted events for this calendar
                $this->cleanupDeletedEvents($user, $calendarEventIds, $calendarId, $startDate, $endDate);
                
                // Collect all event IDs
                $allEventIds = array_merge($allEventIds, $calendarEventIds);
            }
            
            // Update last synced timestamp
            $integration->setLastSynced(new DateTime());
            $this->entityManager->persist($integration);
            $this->entityManager->flush();
            
            // Commit the transaction
            $this->entityManager->commit();
            
            // Update user availability records
            $this->syncUserAvailability($user, $savedEvents);
            
            return $savedEvents;
        } catch (\Exception $e) {
            // Rollback transaction on error
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }
            
            $this->logger->error('Error syncing events: ' . $e->getMessage());
            throw new IntegrationException('Failed to sync calendar events: ' . $e->getMessage());
        }
    }

    /**
     * Save single Outlook Calendar event to database
     */
    private function saveEvent(
        IntegrationEntity $integration, 
        UserEntity $user, 
        Model\Event $event, 
        string $calendarId, 
        string $calendarName
    ): ?OutlookCalendarEventEntity {
        try {
            // Check if the event already exists in our database
            $existingEvent = $this->entityManager->getRepository(OutlookCalendarEventEntity::class)->findOneBy([
                'user' => $user,
                'outlookEventId' => $event->getId(),
                'calendarId' => $calendarId
            ]);
            
            // Determine if this is an all-day event and set start/end times
            $isAllDay = $event->getIsAllDay() ?? false;
            $startTime = null;
            $endTime = null;
            
            $start = $event->getStart();
            $end = $event->getEnd();
            
            if ($isAllDay) {
                // All-day event
                $startTime = new DateTime($start->getDateTime(), new \DateTimeZone($start->getTimeZone() ?: 'UTC'));
                $endTime = new DateTime($end->getDateTime(), new \DateTimeZone($end->getTimeZone() ?: 'UTC'));
            } else {
                // Timed event - Convert to UTC
                $startDateTime = $start->getDateTime();
                $endDateTime = $end->getDateTime();
                $timezone = $start->getTimeZone() ?: 'UTC';
                
                $startTime = new DateTime($startDateTime, new \DateTimeZone($timezone));
                $endTime = new DateTime($endDateTime, new \DateTimeZone($timezone));
                
                $startTime->setTimezone(new \DateTimeZone('UTC'));
                $endTime->setTimezone(new \DateTimeZone('UTC'));
            }
            
            // Handle status mapping
            $status = 'confirmed';
            if ($event->getIsCancelled()) {
                $status = 'cancelled';
            }
            
            // Handle transparency mapping (free/busy)
            $transparency = $event->getShowAs();
            if ($transparency === 'free') {
                $transparency = 'transparent';
            } else {
                $transparency = 'opaque';
            }
            
            // Create or update the event
            if ($existingEvent) {
                // Update existing event
                $existingEvent->setTitle($event->getSubject() ?: 'Untitled Event');
                $existingEvent->setDescription($event->getBodyPreview());
                $existingEvent->setLocation($event->getLocation() ? $event->getLocation()->getDisplayName() : null);
                $existingEvent->setStartTime($startTime);
                $existingEvent->setEndTime($endTime);
                $existingEvent->setIsAllDay($isAllDay);
                $existingEvent->setStatus($status);
                $existingEvent->setTransparency($transparency);
                $existingEvent->setCalendarName($calendarName);
                
                // Handle organizer info
                if ($event->getOrganizer() && $event->getOrganizer()->getEmailAddress()) {
                    $existingEvent->setOrganizerEmail($event->getOrganizer()->getEmailAddress()->getAddress());
                    
                    // Determine if user is organizer
                    $userEmail = $integration->getConfig()['outlook_email'] ?? $user->getEmail();
                    $existingEvent->setIsOrganizer(
                        $event->getOrganizer()->getEmailAddress()->getAddress() === $userEmail
                    );
                }
                
                $existingEvent->setSyncedAt(new DateTime());
                
                $this->entityManager->persist($existingEvent);
                
                return $existingEvent;
            } else {
                // Create new event
                $newEvent = new OutlookCalendarEventEntity();
                $newEvent->setUser($user);
                $newEvent->setIntegration($integration);
                $newEvent->setOutlookEventId($event->getId());
                $newEvent->setCalendarId($calendarId);
                $newEvent->setCalendarName($calendarName);
                $newEvent->setTitle($event->getSubject() ?: 'Untitled Event');
                $newEvent->setDescription($event->getBodyPreview());
                $newEvent->setLocation($event->getLocation() ? $event->getLocation()->getDisplayName() : null);
                $newEvent->setStartTime($startTime);
                $newEvent->setEndTime($endTime);
                $newEvent->setIsAllDay($isAllDay);
                $newEvent->setStatus($status);
                $newEvent->setTransparency($transparency);
                
                // Handle organizer info
                if ($event->getOrganizer() && $event->getOrganizer()->getEmailAddress()) {
                    $newEvent->setOrganizerEmail($event->getOrganizer()->getEmailAddress()->getAddress());
                    
                    // Determine if user is organizer
                    $userEmail = $integration->getConfig()['outlook_email'] ?? $user->getEmail();
                    $newEvent->setIsOrganizer(
                        $event->getOrganizer()->getEmailAddress()->getAddress() === $userEmail
                    );
                }
                
                $newEvent->setSyncedAt(new DateTime());
                
                $this->entityManager->persist($newEvent);
                
                return $newEvent;
            }
        } catch (\Exception $e) {
            $this->logger->error('Error saving event: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Clean up events that no longer exist in Outlook Calendar
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
                OutlookCalendarEventEntity::class,
                $filters,
                1,
                1000,
                ['user' => $user]
            );
            
            foreach ($events as $event) {
                if (!in_array($event->getOutlookEventId(), $keepEventIds)) {
                    // Mark as cancelled
                    $event->setStatus('cancelled');
                    $this->entityManager->persist($event);
                }
            }
        } catch (\Exception $e) {
            $this->logger->error('Error cleaning up deleted events: ' . $e->getMessage());
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
                OutlookCalendarEventEntity::class,
                $filters,
                1,  // page
                1000, // limit
                ['user' => $user],
                function($queryBuilder) {
                    $queryBuilder->orderBy('t1.startTime', 'ASC');
                }
            );
        } catch (CrudException $e) {
            $this->logger->error('Error getting events: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Create an event in Outlook Calendar
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
            $graph = $this->getGraphClient($integration);
            
            // Default to primary calendar if not specified
            $calendarId = $options['calendar_id'] ?? null;
            
            // Prepare the event
            $event = [
                'subject' => $title,
                'body' => [
                    'contentType' => 'HTML',
                    'content' => $options['description'] ?? ''
                ],
                'start' => [
                    'dateTime' => $startTime->format('Y-m-d\TH:i:s'),
                    'timeZone' => 'UTC'
                ],
                'end' => [
                    'dateTime' => $endTime->format('Y-m-d\TH:i:s'),
                    'timeZone' => 'UTC'
                ],
                'isAllDay' => false
            ];
            
            // Set location if provided
            if (!empty($options['location'])) {
                $event['location'] = [
                    'displayName' => $options['location']
                ];
            }
            
            // Set free/busy status (transparency)
            $transparency = $options['transparency'] ?? 'opaque'; // opaque = busy, transparent = free
            $event['showAs'] = ($transparency === 'transparent') ? 'free' : 'busy';
            
            // Add attendees if provided
            if (!empty($options['attendees']) && is_array($options['attendees'])) {
                $attendees = [];
                foreach ($options['attendees'] as $attendee) {
                    $attendees[] = [
                        'emailAddress' => [
                            'address' => $attendee['email'],
                            'name' => $attendee['name'] ?? $attendee['email']
                        ],
                        'type' => 'required'
                    ];
                }
                $event['attendees'] = $attendees;
            }
            
            // Add reminder if provided
            if (!empty($options['reminders']) && is_array($options['reminders'])) {
                $reminder = $options['reminders'][0];
                $event['reminderMinutesBeforeStart'] = $reminder['minutes'] ?? 15;
                $event['isReminderOn'] = true;
            }
            
            // Create event in specific calendar or default calendar
            $endpointUrl = $calendarId 
                ? "/me/calendars/{$calendarId}/events" 
                : "/me/events";
            
            // Add skedi property to identify our events
            $event['singleValueExtendedProperties'] = [
                [
                    'id' => 'String {66f5a359-4659-4830-9070-00049ec6ac6e} Name skedi_event',
                    'value' => 'true'
                ]
            ];
            
            $response = $graph->createRequest('POST', $endpointUrl)
                ->attachBody($event)
                ->setReturnType(Model\Event::class)
                ->execute();
            
            // Update integration's last synced time
            $integration->setLastSynced(new DateTime());
            $this->entityManager->persist($integration);
            $this->entityManager->flush();
            
            // Return event data
            return [
                'outlook_event_id' => $response->getId(),
                'calendar_id' => $calendarId,
                'web_link' => $response->getWebLink(),
                'title' => $title,
                'start_time' => $startTime->format('Y-m-d H:i:s'),
                'end_time' => $endTime->format('Y-m-d H:i:s')
            ];
        } catch (\Exception $e) {
            $this->logger->error('Error creating Outlook Calendar event: ' . $e->getMessage());
            throw new IntegrationException('Failed to create Outlook Calendar event: ' . $e->getMessage());
        }
    }

    /**
     * Delete event from Outlook Calendar
     */
    public function deleteEvent(IntegrationEntity $integration, string $eventId): bool
    {
        try {
            $graph = $this->getGraphClient($integration);
            
            $graph->createRequest('DELETE', "/me/events/{$eventId}")
                ->execute();
            
            return true;
        } catch (\Exception $e) {
            $this->logger->error('Error deleting event: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Determine if an event is created by Skedi
     */
    private function isSkediEvent(Model\Event $event): bool
    {
        // Check for Skedi-specific markers in the description
        $description = $event->getBodyPreview() ?? '';
        if (strpos($description, 'Booking for:') !== false || 
            strpos($description, 'Booking details:') !== false) {
            return true;
        }
        
        // Check for Skedi-specific formats in the title
        $title = $event->getSubject() ?? '';
        if (strpos($title, ' - Booking') !== false) {
            return true;
        }
        
        // In a real implementation, we would check extended properties
        // but that requires additional graph queries
        
        return false;
    }

    /**
     * Sync user availability records from Outlook Calendar events
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
                $sourceId = 'outlook_' . $event->getCalendarId() . '_' . $event->getOutlookEventId();
                
                // Use the availability service to create/update availability
                $this->userAvailabilityService->createExternalAvailability(
                    $user,
                    $event->getTitle() ?: 'Busy',
                    $event->getStartTime(),
                    $event->getEndTime(),
                    'outlook_calendar',
                    $sourceId,
                    $event->getDescription(),
                    $event->getStatus()
                );
            }
        } catch (\Exception $e) {
            $this->logger->error('Error syncing user availability: ' . $e->getMessage());
        }
    }
}