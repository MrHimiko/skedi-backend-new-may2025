<?php

namespace App\Plugins\Integrations\Google\Calendar\Service;

use App\Plugins\Integrations\Common\Abstract\BaseCalendarIntegration;
use App\Plugins\Integrations\Common\Entity\IntegrationEntity;
use App\Plugins\Integrations\Google\Calendar\Entity\GoogleCalendarEventEntity;
use App\Plugins\Integrations\Common\Exception\IntegrationException;
use App\Plugins\Account\Entity\UserEntity;
use DateTime;
use DateTimeInterface;

use Google\Client as GoogleClient;
use Google\Service\Calendar as GoogleCalendar;
use Google\Service\Oauth2;

class GoogleCalendarService extends BaseCalendarIntegration
{
    private string $clientId = '263415563843-iisvu1oericu0v5mvc7bl2c1p3obq2mq.apps.googleusercontent.com';
    private string $clientSecret = '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';
    private string $redirectUri = 'https://app.skedi.com/oauth/google/callback';
    
    /**
     * {@inheritdoc}
     */
    public function getProvider(): string
    {
        return 'google_calendar';
    }
    
    /**
     * {@inheritdoc}
     */
    protected function getEventEntityClass(): string
    {
        return GoogleCalendarEventEntity::class;
    }
    
    /**
     * {@inheritdoc}
     */
    protected function getOAuthConfig(): array
    {
        return [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'redirect_uri' => $this->redirectUri,
            'auth_url' => 'https://accounts.google.com/o/oauth2/v2/auth',
            'token_url' => 'https://oauth2.googleapis.com/token',
            'scope' => 'https://www.googleapis.com/auth/calendar https://www.googleapis.com/auth/userinfo.email'
        ];
    }
    
    /**
     * Get Google Client
     */
    public function getGoogleClient(?IntegrationEntity $integration = null): GoogleClient
    {
        $client = new GoogleClient();
        
        $client->setClientId($this->clientId);
        $client->setClientSecret($this->clientSecret);
        $client->setRedirectUri($this->redirectUri);
        
        $client->setScopes([
            'https://www.googleapis.com/auth/calendar',
            'https://www.googleapis.com/auth/userinfo.email'
        ]);
        
        $client->setAccessType('offline');
        $client->setPrompt('consent');
        $client->setIncludeGrantedScopes(true);
        
        if ($integration && $integration->getAccessToken()) {
            $tokenData = ['access_token' => $integration->getAccessToken()];
            
            if ($integration->getRefreshToken()) {
                $tokenData['refresh_token'] = $integration->getRefreshToken();
            }
            
            $client->setAccessToken($tokenData);
        }
        
        return $client;
    }
    
    /**
     * {@inheritdoc}
     */
    protected function exchangeCodeForToken(string $code): array
    {
        $client = $this->getGoogleClient();
        
        try {
            $accessToken = $client->fetchAccessTokenWithAuthCode($code);
            
            if (isset($accessToken['error'])) {
                throw new IntegrationException('Failed to get access token: ' . 
                    ($accessToken['error_description'] ?? $accessToken['error']));
            }
            
            return $accessToken;
        } catch (\Exception $e) {
            throw new IntegrationException('Token exchange failed: ' . $e->getMessage());
        }
    }
    
    /**
     * {@inheritdoc}
     */
    protected function getUserInfo(array $tokenData): array
    {
        try {
            $client = new GoogleClient();
            $client->setClientId($this->clientId);
            $client->setClientSecret($this->clientSecret);
            $client->setAccessToken($tokenData);
            
            $oauth2 = new Oauth2($client);
            $userInfo = $oauth2->userinfo->get();
            
            return [
                'id' => $userInfo->getId(),
                'email' => $userInfo->getEmail()
            ];
        } catch (\Exception $e) {
            return [];
        }
    }
    
    /**
     * {@inheritdoc}
     */
    protected function refreshToken(IntegrationEntity $integration): array
    {
        $client = $this->getGoogleClient($integration);
        
        if (!$integration->getRefreshToken()) {
            throw new IntegrationException('No refresh token available');
        }
        
        try {
            $accessToken = $client->fetchAccessTokenWithRefreshToken($integration->getRefreshToken());
            
            if (isset($accessToken['error'])) {
                throw new IntegrationException('Failed to refresh token: ' . $accessToken['error']);
            }
            
            return $accessToken;
        } catch (\Exception $e) {
            throw new IntegrationException('Failed to refresh token: ' . $e->getMessage());
        }
    }
    
    /**
     * {@inheritdoc}
     */
    public function syncEvents(IntegrationEntity $integration, DateTime $startDate, DateTime $endDate): array
    {
        try {
            // Check rate limit
            $this->checkRateLimit($integration, 'sync');
            
            $user = $integration->getUser();
            $client = $this->getGoogleClient($integration);
            
            // Refresh token if needed
            $this->refreshTokenIfNeeded($integration);
            
            $service = new GoogleCalendar($client);
            
            // Get calendar list (with caching)
            $calendarList = $this->remember(
                $this->generateCacheKey('google_calendar', $integration->getId(), 'calendars'),
                function() use ($service) {
                    return $service->calendarList->listCalendarList();
                },
                $this->cacheTTLs['calendars_list']
            );
            
            $savedEvents = [];
            
            // Format dates for Google API
            $timeMin = $startDate->format('c');
            $timeMax = $endDate->format('c');
            
            $this->entityManager->beginTransaction();
            
            try {
                foreach ($calendarList->getItems() as $calendarListEntry) {
                    $calendarId = $calendarListEntry->getId();
                    $calendarName = $calendarListEntry->getSummary();
                    
                    // Only sync primary and selected calendars
                    $isPrimary = $calendarListEntry->getPrimary() ?? false;
                    $isSelected = $calendarListEntry->getSelected() ?? false;
                    
                    if (!$isPrimary && !$isSelected) {
                        continue;
                    }
                    
                    $calendarEventIds = [];
                    $pageToken = null;
                    
                    do {
                        $optParams = [
                            'timeMin' => $timeMin,
                            'timeMax' => $timeMax,
                            'showDeleted' => true,
                            'singleEvents' => true,
                            'orderBy' => 'startTime',
                            'maxResults' => 250
                        ];
                        
                        if ($pageToken) {
                            $optParams['pageToken'] = $pageToken;
                        }
                        
                        $eventsResult = $service->events->listEvents($calendarId, $optParams);
                        $events = $eventsResult->getItems();
                        
                        foreach ($events as $event) {
                            // Skip events created by our application
                            if ($this->isSkediEvent($event)) {
                                $calendarEventIds[] = $event->getId();
                                continue;
                            }
                            
                            $savedEvent = $this->saveEvent($integration, $user, $event, $calendarId, $calendarName);
                            if ($savedEvent) {
                                $savedEvents[] = $savedEvent;
                                $calendarEventIds[] = $event->getId();
                            }
                        }
                        
                        $pageToken = $eventsResult->getNextPageToken();
                    } while ($pageToken);
                    
                    // Clean up deleted events
                    $this->cleanupDeletedEvents($user, $calendarEventIds, $calendarId, $startDate, $endDate);
                }
                
                // Update last synced
                $integration->setLastSynced(new DateTime());
                $this->entityManager->persist($integration);
                $this->entityManager->flush();
                
                $this->entityManager->commit();
                
                // Sync user availability
                $this->syncUserAvailability($user, $savedEvents);
                
                return $savedEvents;
            } catch (\Exception $e) {
                $this->entityManager->rollback();
                throw $e;
            }
        } catch (\Exception $e) {
            throw new IntegrationException('Failed to sync calendar events: ' . $e->getMessage());
        }
    }
    
    /**
     * {@inheritdoc}
     */
    public function getCalendars(IntegrationEntity $integration): array
    {
        // Use caching for calendar list
        $cacheKey = $this->generateCacheKey('google_calendar', $integration->getId(), 'calendars_list');
        
        return $this->remember($cacheKey, function() use ($integration) {
            try {
                // Check rate limit
                $this->checkRateLimit($integration, 'default');
                
                $client = $this->getGoogleClient($integration);
                $service = new GoogleCalendar($client);
                
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
                
                // Update integration config
                $config = $integration->getConfig() ?: [];
                $config['calendars'] = $calendars;
                $integration->setConfig($config);
                
                $this->entityManager->persist($integration);
                $this->entityManager->flush();
                
                return $calendars;
            } catch (\Exception $e) {
                throw new IntegrationException('Failed to fetch calendars: ' . $e->getMessage());
            }
        }, $this->cacheTTLs['calendars_list']);
    }
    
    /**
     * {@inheritdoc}
     */
    public function createCalendarEvent(
        IntegrationEntity $integration,
        string $title,
        DateTimeInterface $startDateTime,
        DateTimeInterface $endDateTime,
        array $options = []
    ): array {
        try {
            // Check rate limit
            $this->checkRateLimit($integration, 'create');
            
            $this->refreshTokenIfNeeded($integration);
            
            $client = $this->getGoogleClient($integration);
            $service = new GoogleCalendar($client);
            
            $event = new \Google\Service\Calendar\Event();
            $event->setSummary($title);
            
            // Set times
            $start = new \Google\Service\Calendar\EventDateTime();
            $start->setDateTime($startDateTime->format('c'));
            $event->setStart($start);
            
            $end = new \Google\Service\Calendar\EventDateTime();
            $end->setDateTime($endDateTime->format('c'));
            $event->setEnd($end);
            
            // Optional fields
            if (!empty($options['description'])) {
                $event->setDescription($options['description']);
            }
            
            if (!empty($options['location'])) {
                $event->setLocation($options['location']);
            }
            
            // Conference data
            if (!empty($options['conference_data'])) {
                $this->setConferenceData($event, $options['conference_data']);
            }
            
            // Extended properties
            if (!empty($options['source_id'])) {
                $extendedProperties = new \Google\Service\Calendar\EventExtendedProperties();
                $private = [
                    'skedi_source_id' => $options['source_id'],
                    'skedi_integration_id' => (string)$integration->getId()
                ];
                $extendedProperties->setPrivate($private);
                $event->setExtendedProperties($extendedProperties);
            }
            
            $calendarId = $options['calendar_id'] ?? 'primary';
            $createParams = [];
            
            if (!empty($options['conference_data'])) {
                $createParams['conferenceDataVersion'] = 1;
            }
            
            $createdEvent = $service->events->insert($calendarId, $event, $createParams);
            
            // Get meet link if created
            $meetLink = null;
            if ($createdEvent->getConferenceData() && $createdEvent->getConferenceData()->getEntryPoints()) {
                foreach ($createdEvent->getConferenceData()->getEntryPoints() as $entryPoint) {
                    if ($entryPoint->getEntryPointType() === 'video') {
                        $meetLink = $entryPoint->getUri();
                        break;
                    }
                }
            }
            
            $integration->setLastSynced(new DateTime());
            $this->entityManager->persist($integration);
            $this->entityManager->flush();
            
            return [
                'google_event_id' => $createdEvent->getId(),
                'html_link' => $createdEvent->getHtmlLink(),
                'meet_link' => $meetLink,
                'calendar_id' => $calendarId,
                'start_time' => $startDateTime->format('c'),
                'end_time' => $endDateTime->format('c'),
                'status' => $createdEvent->getStatus()
            ];
        } catch (\Exception $e) {
            throw new IntegrationException('Failed to create Google Calendar event: ' . $e->getMessage());
        }
    }
    
    /**
     * {@inheritdoc}
     */
    protected function saveEvent(
        IntegrationEntity $integration,
        UserEntity $user,
        $event,
        string $calendarId,
        string $calendarName
    ): ?GoogleCalendarEventEntity {
        try {
            if ($event->getStatus() === 'cancelled' || !$event->getStart() || !$event->getEnd()) {
                return null;
            }
            
            // Check if exists
            $existingEvent = $this->entityManager->getRepository(GoogleCalendarEventEntity::class)->findOneBy([
                'user' => $user,
                'googleEventId' => $event->getId(),
                'calendarId' => $calendarId
            ]);
            
            // Parse times
            $isAllDay = false;
            $startTime = null;
            $endTime = null;
            
            $start = $event->getStart();
            $end = $event->getEnd();
            
            if ($start->date) {
                $isAllDay = true;
                $startTime = new DateTime($start->date, new \DateTimeZone('UTC'));
                $endTime = new DateTime($end->date, new \DateTimeZone('UTC'));
            } else {
                $timezone = $start->timeZone ?: 'UTC';
                $startTime = new DateTime($start->dateTime, new \DateTimeZone($timezone));
                $endTime = new DateTime($end->dateTime, new \DateTimeZone($timezone));
                $startTime->setTimezone(new \DateTimeZone('UTC'));
                $endTime->setTimezone(new \DateTimeZone('UTC'));
            }
            
            if ($existingEvent) {
                // Update existing
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
                
                if ($event->getOrganizer()) {
                    $existingEvent->setOrganizerEmail($event->getOrganizer()->getEmail());
                    $existingEvent->setIsOrganizer($event->getOrganizer()->getSelf() ?? false);
                }
                
                $existingEvent->setSyncedAt(new DateTime());
                $this->entityManager->persist($existingEvent);
                
                return $existingEvent;
            } else {
                // Create new
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
                
                if ($event->getOrganizer()) {
                    $newEvent->setOrganizerEmail($event->getOrganizer()->getEmail());
                    $newEvent->setIsOrganizer($event->getOrganizer()->getSelf() ?? false);
                }
                
                $newEvent->setSyncedAt(new DateTime());
                $this->entityManager->persist($newEvent);
                
                return $newEvent;
            }
        } catch (\Exception $e) {
            return null;
        }
    }
    
    /**
     * Clean up deleted events
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
                    $event->setStatus('cancelled');
                    $this->entityManager->persist($event);
                }
            }
        } catch (\Exception $e) {
            // Continue
        }
    }
    
    /**
     * Check if event is created by Skedi
     */
    private function isSkediEvent(\Google\Service\Calendar\Event $event): bool
    {
        if ($event->getExtendedProperties() && $event->getExtendedProperties()->getPrivate()) {
            $private = $event->getExtendedProperties()->getPrivate();
            if (isset($private['skedi_event']) || isset($private['skedi_source_id'])) {
                return true;
            }
        }
        
        $description = $event->getDescription() ?? '';
        if (strpos($description, 'Booking for:') !== false || 
            strpos($description, 'Booking details:') !== false) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Set conference data on event
     */
    private function setConferenceData(\Google\Service\Calendar\Event $event, array $conferenceData): void
    {
        if (isset($conferenceData['type']) && $conferenceData['type'] === 'hangoutsMeet') {
            if (isset($conferenceData['link'])) {
                // Use existing link
                $conference = new \Google\Service\Calendar\ConferenceData();
                
                $entryPoint = new \Google\Service\Calendar\EntryPoint();
                $entryPoint->setEntryPointType('video');
                $entryPoint->setUri($conferenceData['link']);
                $entryPoint->setLabel('Google Meet');
                
                $conference->setEntryPoints([$entryPoint]);
                
                $conferenceSolution = new \Google\Service\Calendar\ConferenceSolution();
                $solutionKey = new \Google\Service\Calendar\ConferenceSolutionKey();
                $solutionKey->setType('hangoutsMeet');
                $conferenceSolution->setKey($solutionKey);
                $conference->setConferenceSolution($conferenceSolution);
                
                $event->setConferenceData($conference);
            } else {
                // Create new
                $conference = new \Google\Service\Calendar\ConferenceData();
                $createRequest = new \Google\Service\Calendar\CreateConferenceRequest();
                $createRequest->setRequestId('meet_' . uniqid());
                $createRequest->setConferenceSolutionKey(
                    new \Google\Service\Calendar\ConferenceSolutionKey(['type' => 'hangoutsMeet'])
                );
                
                $conference->setCreateRequest($createRequest);
                $event->setConferenceData($conference);
            }
        }
    }
}