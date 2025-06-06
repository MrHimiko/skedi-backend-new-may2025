<?php
// src/Plugins/Integrations/Service/GoogleMeetService.php

namespace App\Plugins\Integrations\Google\Meet\Service;

use Doctrine\ORM\EntityManagerInterface;
use App\Plugins\Integrations\Common\Repository\IntegrationRepository;
use App\Plugins\Integrations\Google\Meet\Repository\GoogleMeetEventRepository;
use App\Plugins\Integrations\Common\Entity\IntegrationEntity;
use App\Plugins\Integrations\Google\Meet\Entity\GoogleMeetEventEntity;
use App\Plugins\Account\Entity\UserEntity;
use App\Plugins\Integrations\Common\Exception\IntegrationException;
use App\Service\CrudManager;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use DateTime;
use DateTimeInterface;

// Google API imports
use Google\Client as GoogleClient;
use Google\Service\Calendar as GoogleCalendar;
use Google\Service\Oauth2;

class GoogleMeetService
{
    private EntityManagerInterface $entityManager;
    private IntegrationRepository $integrationRepository;
    private GoogleMeetEventRepository $googleMeetEventRepository;
    private CrudManager $crudManager;
    private string $clientId;
    private string $clientSecret;
    private string $redirectUri;
    private string $tenantId;
    private string $authority;
    
    public function __construct(
        EntityManagerInterface $entityManager,
        IntegrationRepository $integrationRepository,
        GoogleMeetEventRepository $googleMeetEventRepository,
        CrudManager $crudManager,
        ParameterBagInterface $parameterBag
    ) {
        $this->entityManager = $entityManager;
        $this->integrationRepository = $integrationRepository;
        $this->googleMeetEventRepository = $googleMeetEventRepository;
        $this->crudManager = $crudManager;
        
        // Try to get the parameters
        try {
            $this->clientId = $parameterBag->get('google.client_id');
        } catch (\Exception $e) {
            // Temporary fallback for testing
            $this->clientId = '263415563843-iisvu1oericu0v5mvc7bl2c1p3obq2mq.apps.googleusercontent.com';
        }
        
        try {
            $this->clientSecret = $parameterBag->get('google.client_secret');
        } catch (\Exception $e) {
            // Temporary fallback
            $this->clientSecret = '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';
        }
        
        try {
            $this->redirectUri = $parameterBag->get('google.redirect_uri');
        } catch (\Exception $e) {
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
        
        // Set client parameters
        $client->setClientId($clientId);
        $client->setClientSecret($clientSecret);
        $client->setRedirectUri($redirectUri);
        
        // Set scopes specifically for Google Meet
        $client->setScopes([
            'https://www.googleapis.com/auth/calendar',
            'https://www.googleapis.com/auth/meetings.space.created',
            'https://www.googleapis.com/auth/meetings.space.readonly'
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
        
        // Include userinfo.email scope and meet-specific scopes
        $client->setScopes([
            'https://www.googleapis.com/auth/calendar',
            'https://www.googleapis.com/auth/meetings.space.created',
            'https://www.googleapis.com/auth/meetings.space.readonly',
            'https://www.googleapis.com/auth/userinfo.email'
        ]);
        
        return $client->createAuthUrl();
    }

    /**
     * Handle OAuth callback and exchange code for tokens
     * This follows EXACTLY the same pattern as GoogleCalendarService
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
            
            // Set scopes same as in getAuthUrl
            $client->setScopes([
                'https://www.googleapis.com/auth/calendar',
                'https://www.googleapis.com/auth/meetings.space.created',
                'https://www.googleapis.com/auth/meetings.space.readonly',
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
                // Continue without email/user info
            }
            
            // Use Google email if available, otherwise fall back to user's system email
            $integrationName = 'Google Meet';
            if ($googleEmail) {
                $integrationName .= ' (' . $googleEmail . ')';
            } else {
                $integrationName .= ' (' . $user->getEmail() . ')';
            }
            
            // Use Google user ID if available, otherwise generate one
            $externalId = $googleUserId ?? 'google_meet_' . uniqid();
            
            // Check if this user already has a Google Meet integration
            $existingIntegration = $this->integrationRepository->findOneBy([
                'user' => $user,
                'provider' => 'google_meet',
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
                $integration->setProvider('google_meet');
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
                    'google_email' => $googleEmail
                ];
                
                $integration->setConfig($config);
                $integration->setStatus('active');
                
                $this->entityManager->persist($integration);
                $this->entityManager->flush();
            }
            
            return $integration;
        } catch (IntegrationException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new IntegrationException('Failed to authenticate with Google: ' . $e->getMessage());
        }
    }

    /**
     * Get user's Google Meet integration
     */
    public function getUserIntegration(UserEntity $user, ?int $integrationId = null): ?IntegrationEntity
    {
        if ($integrationId) {
            $integration = $this->integrationRepository->find($integrationId);
            if ($integration && $integration->getUser()->getId() === $user->getId() && 
                $integration->getProvider() === 'google_meet' && 
                $integration->getStatus() === 'active') {
                return $integration;
            }
            return null;
        }
        
        // Get the most recently created active integration
        return $this->integrationRepository->findOneBy(
            [
                'user' => $user,
                'provider' => 'google_meet',
                'status' => 'active'
            ],
            ['created' => 'DESC']
        );
    }
    
    /**
     * Find a Meet event by booking ID
     */
    public function getMeetLinkForBooking(int $bookingId): ?GoogleMeetEventEntity
    {
        return $this->googleMeetEventRepository->findByBookingId($bookingId);
    }
    
    /**
     * Create a Google Meet link
     */
    public function createMeetLink(
        IntegrationEntity $integration,
        string $title,
        DateTimeInterface $startTime,
        DateTimeInterface $endTime,
        ?int $eventId = null,
        ?int $bookingId = null,
        array $options = []
    ): GoogleMeetEventEntity {
        try {
            // Check if token needs refresh
            if ($integration->getTokenExpires() && $integration->getTokenExpires() < new DateTime()) {
                $this->refreshToken($integration);
            }
            
            $client = $this->getGoogleClient($integration);
            $service = new GoogleCalendar($client);
            
            // Create a new event with conference data to generate Meet link
            $event = new \Google\Service\Calendar\Event();
            $event->setSummary('Skedi: ' . $title);
            
            // Set start and end times
            $startDateTime = clone $startTime;
            $endDateTime = clone $endTime;
            
            $start = new \Google\Service\Calendar\EventDateTime();
            $start->setDateTime($startDateTime->format('c'));
            $event->setStart($start);
            
            $end = new \Google\Service\Calendar\EventDateTime();
            $end->setDateTime($endDateTime->format('c'));
            $event->setEnd($end);
            
            // Add conference data request
            $conferenceData = new \Google\Service\Calendar\ConferenceData();
            $conferenceRequest = new \Google\Service\Calendar\CreateConferenceRequest();
            
            // Set optional conference parameters if provided
            $conferenceDataParams = [];
            
            // Check for available settings
            if (isset($options['is_guest_allowed']) && $options['is_guest_allowed'] === false) {
                $conferenceDataParams[] = [
                    'key' => 'allowExternalUsers',
                    'value' => 'false'
                ];
            }
            
            // Add recording settings if specified
            if (isset($options['enable_recording']) && $options['enable_recording'] === true) {
                $conferenceDataParams[] = [
                    'key' => 'autoRecord',
                    'value' => 'true'
                ];
            }
            
            // Set conference data parameters if we have any
            if (!empty($conferenceDataParams)) {
                $parameters = [];
                foreach ($conferenceDataParams as $param) {
                    $parameter = new \Google\Service\Calendar\ConferenceParameter();
                    $parameter->setKey($param['key']);
                    $parameter->setValue($param['value']);
                    $parameters[] = $parameter;
                }
                
                $conferenceRequest->setRequestId(uniqid('meet_'));
                $conferenceRequest->setConferenceSolutionKey(
                    new \Google\Service\Calendar\ConferenceSolutionKey(['type' => 'hangoutsMeet'])
                );
                
                if (!empty($parameters)) {
                    $conferenceRequest->setParameters($parameters);
                }
                
                $conferenceData->setCreateRequest($conferenceRequest);
                $event->setConferenceData($conferenceData);
            } else {
                // Simple conference request without custom parameters
                $conferenceRequest->setRequestId(uniqid('meet_'));
                $conferenceRequest->setConferenceSolutionKey(
                    new \Google\Service\Calendar\ConferenceSolutionKey(['type' => 'hangoutsMeet'])
                );
                
                $conferenceData->setCreateRequest($conferenceRequest);
                $event->setConferenceData($conferenceData);
            }
            
            // Set extended properties to mark this as our own event with enhanced metadata
            $extendedProperties = new \Google\Service\Calendar\EventExtendedProperties();
            $privateProperties = [
                'skedi_event' => 'true',
                'skedi_meet' => 'true',
                'skedi_created_at' => (new DateTime())->format('c'),
                'skedi_integration_id' => (string)$integration->getId(),
                'skedi_user_id' => (string)$integration->getUser()->getId()
            ];
            
            // Add event and booking IDs if available
            if ($eventId) {
                $privateProperties['skedi_event_id'] = (string)$eventId;
            }
            if ($bookingId) {
                $privateProperties['skedi_booking_id'] = (string)$bookingId;
                $privateProperties['skedi_source_id'] = 'booking_' . $bookingId;
            }
            
            $extendedProperties->setPrivate($privateProperties);
            $event->setExtendedProperties($extendedProperties);
            
            // Set description if provided
            if (!empty($options['description'])) {
                $event->setDescription($options['description']);
            }
            
            // Add attendees if provided
            if (!empty($options['attendees']) && is_array($options['attendees'])) {
                $attendees = [];
                foreach ($options['attendees'] as $attendee) {
                    $eventAttendee = new \Google\Service\Calendar\EventAttendee();
                    $eventAttendee->setEmail($attendee['email']);
                    if (!empty($attendee['name'])) {
                        $eventAttendee->setDisplayName($attendee['name']);
                    }
                    $attendees[] = $eventAttendee;
                }
                $event->setAttendees($attendees);
            }
            
            // Create the event in a temporary calendar to generate Meet link
            $calendarId = 'primary';
            $createdEvent = $service->events->insert($calendarId, $event, ['conferenceDataVersion' => 1]);
            
            // Extract Meet conference data
            $conferenceData = $createdEvent->getConferenceData();
            if (!$conferenceData || !$conferenceData->getEntryPoints()) {
                throw new IntegrationException('Failed to create Google Meet link');
            }
            
            // Find the Meet link in entry points
            $meetLink = null;
            foreach ($conferenceData->getEntryPoints() as $entryPoint) {
                if ($entryPoint->getEntryPointType() === 'video') {
                    $meetLink = $entryPoint->getUri();
                    break;
                }
            }
            
            if (!$meetLink) {
                throw new IntegrationException('No Google Meet link found in created event');
            }
            
            // Create a GoogleMeetEventEntity to store the Meet information
            $meetEvent = new GoogleMeetEventEntity();
            $meetEvent->setUser($integration->getUser());
            $meetEvent->setIntegration($integration);
            
            if ($eventId) {
                $meetEvent->setEventId($eventId);
            }
            
            if ($bookingId) {
                $meetEvent->setBookingId($bookingId);
            }
            
            $meetEvent->setMeetId($createdEvent->getId());
            $meetEvent->setMeetLink($meetLink);
            
            // Store full conference data as JSON
            $meetEvent->setConferenceData([
                'conferenceId' => $conferenceData->getConferenceId(),
                'conferenceType' => $conferenceData->getConferenceSolution()->getKey()->getType(),
                'entryPoints' => array_map(function($entryPoint) {
                    return [
                        'type' => $entryPoint->getEntryPointType(),
                        'uri' => $entryPoint->getUri(),
                        'label' => $entryPoint->getLabel()
                    ];
                }, $conferenceData->getEntryPoints()),
                'notes' => $conferenceData->getNotes(),
                'parameters' => $conferenceDataParams
            ]);
            
            $meetEvent->setTitle($title);
            $meetEvent->setDescription($options['description'] ?? null);
            $meetEvent->setStartTime($startTime);
            $meetEvent->setEndTime($endTime);
            $meetEvent->setStatus('active');
            
            $this->entityManager->persist($meetEvent);
            $this->entityManager->flush();
            
            return $meetEvent;
        } catch (IntegrationException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new IntegrationException('Failed to create Google Meet link: ' . $e->getMessage());
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
            throw new IntegrationException('Failed to refresh token: ' . $e->getMessage());
        }
    }
}