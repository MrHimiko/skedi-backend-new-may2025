<?php

namespace App\Plugins\Events\Service;

use App\Service\CrudManager;
use App\Exception\CrudException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\Constraints as Assert;

use App\Plugins\Events\Entity\EventEntity;
use App\Plugins\Events\Entity\EventBookingEntity;
use App\Plugins\Events\Entity\EventGuestEntity;
use App\Plugins\Events\Entity\ContactEntity;
use App\Plugins\Events\Exception\EventsException;
use App\Plugins\Integrations\Service\GoogleCalendarService;
use App\Plugins\Integrations\Service\OutlookCalendarService;

use DateTime;

class EventBookingService
{
    private CrudManager $crudManager;
    private EntityManagerInterface $entityManager;
    private ContactService $contactService;
    private EventScheduleService $scheduleService;
    private GoogleCalendarService $googleCalendarService;
    private OutlookCalendarService $outlookCalendarService;

    public function __construct(
        CrudManager $crudManager,
        EntityManagerInterface $entityManager,
        ContactService $contactService,
        EventScheduleService $scheduleService,
        OutlookCalendarService $outlookCalendarService,
        GoogleCalendarService $googleCalendarService
    ) {
        $this->crudManager = $crudManager;
        $this->entityManager = $entityManager;
        $this->contactService = $contactService;
        $this->scheduleService = $scheduleService;
        $this->googleCalendarService = $googleCalendarService;
        $this->outlookCalendarService = $outlookCalendarService;
    }

    public function getMany(array $filters, int $page, int $limit, array $criteria = []): array
    {
        try {
            return $this->crudManager->findMany(
                EventBookingEntity::class,
                $filters,
                $page,
                $limit,
                $criteria
            );
        } catch (CrudException $e) {
            throw new EventsException($e->getMessage());
        }
    }

    public function getOne(int $id, array $criteria = []): ?EventBookingEntity
    {
        return $this->crudManager->findOne(EventBookingEntity::class, $id, $criteria);
    }

    public function create(array $data): EventBookingEntity
    {
        try {
            if (empty($data['event_id'])) {
                throw new EventsException('Event ID is required');
            }
            
            $event = $this->entityManager->getRepository(EventEntity::class)->find($data['event_id']);
            if (!$event) {
                throw new EventsException('Event not found');
            }
            
            // Validate time slot
            if (empty($data['start_time']) || empty($data['end_time'])) {
                throw new EventsException('Start and end time are required');
            }
            
            // Handle timezone if provided
            $timezone = $data['timezone'] ?? 'UTC';
            try {
                new \DateTimeZone($timezone);
            } catch (\Exception $e) {
                $timezone = 'UTC';
            }
            
            // Convert to UTC timestamps for storage
            $startTime = $data['start_time'] instanceof \DateTimeInterface 
                ? clone $data['start_time'] 
                : new \DateTime($data['start_time'], new \DateTimeZone($timezone));
            
            $endTime = $data['end_time'] instanceof \DateTimeInterface 
                ? clone $data['end_time'] 
                : new \DateTime($data['end_time'], new \DateTimeZone($timezone));
            
            // Ensure timestamps are in UTC
            if ($startTime->getTimezone()->getName() !== 'UTC') {
                $startTime->setTimezone(new \DateTimeZone('UTC'));
            }
            
            if ($endTime->getTimezone()->getName() !== 'UTC') {
                $endTime->setTimezone(new \DateTimeZone('UTC'));
            }
            
            if ($startTime >= $endTime) {
                throw new EventsException('End time must be after start time');
            }
            
            // Check if the slot is available based on schedule, existing bookings, and host availability
            if (!$this->scheduleService->isTimeSlotAvailableForAll($event, $startTime, $endTime)) {
                throw new EventsException('The selected time slot is not available for the event or its hosts');
            }
            
            // Create the booking
            $booking = new EventBookingEntity();
            $booking->setEvent($event);
            $booking->setStartTime($startTime);
            $booking->setEndTime($endTime);
            $booking->setStatus('confirmed'); // Default status
            
            // Store original timezone in form data for reference
            if (empty($data['form_data']) || !is_array($data['form_data'])) {
                $data['form_data'] = [];
            }
            $data['form_data']['booking_timezone'] = $timezone;
            
            // Handle duration option selection
            if (isset($data['duration_index']) && is_numeric($data['duration_index'])) {
                $durations = $event->getDuration();
                $durationIndex = (int)$data['duration_index'];
                
                if (isset($durations[$durationIndex])) {
                    $data['form_data']['selected_duration'] = $durations[$durationIndex];
                }
            }
            
            // Save form data
            $booking->setFormDataFromArray($data['form_data']);
            
            $this->entityManager->persist($booking);
            $this->entityManager->flush();
            
            // Process guests if provided
            if (!empty($data['guests']) && is_array($data['guests'])) {
                foreach ($data['guests'] as $guestData) {
                    $this->addGuest($booking, $guestData);
                }
            }
            
            // Create availability records for event hosts
            $this->scheduleService->handleBookingCreated($booking);

            // Sync with Google Calendar if integration exists
            try {
                // Fixed reference - use $this instead of $this->eventBookingService
                $this->syncEventBooking($booking);
            } catch (\Exception $e) {
                // Silently catch the exception - don't let calendar sync issues prevent booking creation
                // Do nothing with the exception
            }
            
            return $booking;
        } catch (\Exception $e) {
            throw new EventsException('Failed to create booking: ' . $e->getMessage());
        }
    }

    public function update(EventBookingEntity $booking, array $data): void
    {
        try {
            // Check if status is being changed to a cancellation state
            $isCancellation = false;
            if (!empty($data['status']) && 
                in_array($data['status'], ['cancelled', 'canceled', 'removed', 'deleted']) && 
                $booking->getStatus() !== $data['status']) {
                $isCancellation = true;
            }
            
            // Track if booking was previously cancelled
            $wasPreviouslyCancelled = $booking->isCancelled();
            
            // Update times if provided
            $timesChanged = false;
            if (!empty($data['start_time']) && !empty($data['end_time'])) {
                $startTime = $data['start_time'] instanceof \DateTimeInterface 
                    ? $data['start_time'] 
                    : new \DateTime($data['start_time']);
                    
                $endTime = $data['end_time'] instanceof \DateTimeInterface 
                    ? $data['end_time'] 
                    : new \DateTime($data['end_time']);
                
                if ($startTime >= $endTime) {
                    throw new EventsException('End time must be after start time');
                }
                
                // Only check availability if times are changing
                if ($startTime != $booking->getStartTime() || $endTime != $booking->getEndTime()) {
                    $timesChanged = true;
                    
                    // Check if the new slot is available, excluding this booking
                    if (!$this->scheduleService->isTimeSlotAvailableForAll(
                        $booking->getEvent(), 
                        $startTime, 
                        $endTime, 
                        null,
                        $booking->getId()
                    )) {
                        throw new EventsException('The selected time slot is not available');
                    }
                    
                    $booking->setStartTime($startTime);
                    $booking->setEndTime($endTime);
                }
            }
            
            // Update status if provided
            if (!empty($data['status'])) {
                $booking->setStatus($data['status']);
                
                // If status is a cancellation type, also set cancelled flag
                if (in_array($data['status'], ['cancelled', 'canceled', 'removed', 'deleted'])) {
                    $booking->setCancelled(true);
                }
            }
            
            // Update form data if provided
            if (!empty($data['form_data']) && is_array($data['form_data'])) {
                $booking->setFormDataFromArray($data['form_data']);
            }
            
            // Update cancellation status if explicitly provided
            if (isset($data['cancelled'])) {
                $booking->setCancelled((bool)$data['cancelled']);
                if ((bool)$data['cancelled'] && !$wasPreviouslyCancelled) {
                    $isCancellation = true;
                }
            }
            
            $this->entityManager->persist($booking);
            $this->entityManager->flush();
            
            // Update guests if provided
            if (!empty($data['guests']) && is_array($data['guests'])) {
                // Remove existing guests
                $existingGuests = $this->crudManager->findMany(
                    EventGuestEntity::class,
                    [],
                    1,
                    1000,
                    ['booking' => $booking]
                );
                    
                foreach ($existingGuests as $existingGuest) {
                    $this->entityManager->remove($existingGuest);
                }
                $this->entityManager->flush();
                
                // Add new guests
                foreach ($data['guests'] as $guestData) {
                    $this->addGuest($booking, $guestData);
                }
            }
            
            // Handle cancellation - updating availability and Google Calendar
            if ($isCancellation) {
                // Update availability records
                $this->scheduleService->handleBookingCancelled($booking);
                
                // Delete from Google Calendar
                try {
                    // Get all assignees for this event
                    $event = $booking->getEvent();
                    $assignees = $this->entityManager->getRepository('App\Plugins\Events\Entity\EventAssigneeEntity')
                        ->findBy(['event' => $event]);
                    
                    foreach ($assignees as $assignee) {
                        $user = $assignee->getUser();
                        
                        // Find Google Calendar integrations for this user
                        $integrations = $this->entityManager->getRepository('App\Plugins\Integrations\Entity\IntegrationEntity')
                            ->findBy([
                                'user' => $user,
                                'provider' => 'google_calendar',
                                'status' => 'active'
                            ]);
                        
                        foreach ($integrations as $integration) {
                            try {
                                // Use the GoogleCalendarService to delete from Google
                                $this->googleCalendarService->deleteEventForCancelledBooking($integration, $booking);
                            } catch (\Exception $e) {
                                // Just silently catch the exception
                                // Don't let Google API errors stop us
                            }
                        }
                    }
                } catch (\Exception $e) {
                    // Silently catch the exception
                    // Don't let Google errors break our app
                }
            }
            // Handle time changes for non-cancelled bookings
            else if ($timesChanged && !$booking->isCancelled()) {
                $this->scheduleService->handleBookingUpdated($booking);
            }
        } catch (\Exception $e) {
            throw new EventsException('Failed to update booking: ' . $e->getMessage());
        }
    }

    public function cancel(EventBookingEntity $booking): void
    {
        try {
            $booking->setCancelled(true);
            $booking->setStatus('cancelled');
            
            $this->entityManager->persist($booking);
            $this->entityManager->flush();
            
            // Update availability records
            $this->scheduleService->handleBookingCancelled($booking);
            
            // Delete from Google Calendar using the GoogleCalendarService
            $this->googleCalendarService->deleteGoogleEventsForBooking($booking);
        } catch (\Exception $e) {
            throw new EventsException('Failed to cancel booking: ' . $e->getMessage());
        }
    }

    public function delete(EventBookingEntity $booking): void
    {
        try {
            // First cancel the booking if not already cancelled
            if (!$booking->isCancelled()) {
                $booking->setCancelled(true);
                
                // Update availability records
                $this->scheduleService->handleBookingCancelled($booking);
                
                // Delete from Google Calendar
                try {
                    $this->googleCalendarService->deleteGoogleEventsForBooking($booking);
                } catch (\Exception $e) {
                    // Silently catch exceptions
                }
            }
            
            // Remove related guests
            $guests = $this->crudManager->findMany(
                EventGuestEntity::class,
                [],
                1,
                1000,
                ['booking' => $booking]
            );
                
            foreach ($guests as $guest) {
                $this->entityManager->remove($guest);
            }
            
            // Remove the booking
            $this->entityManager->remove($booking);
            $this->entityManager->flush();
        } catch (\Exception $e) {
            throw new EventsException('Failed to delete booking: ' . $e->getMessage());
        }
    }
    
    /**
     * Add a guest to a booking
     */
    private function addGuest(EventBookingEntity $booking, array $guestData): EventGuestEntity
    {
        try {
            if (empty($guestData['name']) || empty($guestData['email'])) {
                throw new EventsException('Guest must have a name and email');
            }
            
            $guest = new EventGuestEntity();
            $guest->setBooking($booking);
            $guest->setName($guestData['name']);
            $guest->setEmail($guestData['email']);
            
            if (!empty($guestData['phone'])) {
                $guest->setPhone($guestData['phone']);
            }
            
            $this->entityManager->persist($guest);
            $this->entityManager->flush();
            
            // Create a new contact record
            $contact = new ContactEntity();
            $contact->setName($guestData['name']);
            $contact->setEmail($guestData['email']);
            
            if (!empty($guestData['phone'])) {
                $contact->setPhone($guestData['phone']);
            }
            
            // Set direct entity references instead of IDs
            $contact->setEvent($booking->getEvent());
            $contact->setBooking($booking);
            
            $this->entityManager->persist($contact);
            $this->entityManager->flush();
            
            return $guest;
        } catch (\Exception $e) {
            throw new EventsException('Failed to add guest: ' . $e->getMessage());
        }
    }
    
    /**
     * Get bookings for an event within a date range
     */
    public function getBookingsByEvent(EventEntity $event, array $filters = [], bool $includeCancelled = false): array
    {
        $criteria = ['event' => $event];
        
        if (!$includeCancelled) {
            $criteria['cancelled'] = false;
        }
        
        return $this->getMany($filters, 1, 1000, $criteria);
    }
    
    /**
     * Get bookings for an event within a date range
     */
    public function getBookingsByDateRange(EventEntity $event, \DateTimeInterface $startDate, \DateTimeInterface $endDate, bool $includeCancelled = false): array
    {
        try {
            $criteria = [
                'event' => $event
            ];
            
            if (!$includeCancelled) {
                $criteria['cancelled'] = false;
            }
            
            return $this->crudManager->findMany(
                EventBookingEntity::class,
                [
                    [
                        'field' => 'startTime',
                        'operator' => 'greater_than_or_equal',
                        'value' => $startDate
                    ],
                    [
                        'field' => 'startTime',
                        'operator' => 'less_than_or_equal',
                        'value' => $endDate
                    ]
                ],
                1,
                1000,
                $criteria
            );
        } catch (CrudException $e) {
            throw new EventsException($e->getMessage());
        }
    }
    
    /**
     * Get guests for a booking
     */
    public function getGuests(EventBookingEntity $booking): array
    {
        try {
            return $this->crudManager->findMany(
                EventGuestEntity::class,
                [],
                1,
                1000,
                ['booking' => $booking]
            );
        } catch (CrudException $e) {
            throw new EventsException($e->getMessage());
        }
    }

    /**
     * Sync cancelled booking with Google Calendar integrations
     */
    public function syncCancellationWithGoogle(EventBookingEntity $booking): void
    {
        try {
            // Get the event
            $event = $booking->getEvent();
            
            // Get all assignees for this event
            $assignees = $this->entityManager->getRepository('App\Plugins\Events\Entity\EventAssigneeEntity')
                ->findBy(['event' => $event]);
            
            // Process each assignee
            foreach ($assignees as $assignee) {
                // Get the user
                $user = $assignee->getUser();
                
                // Find any Google Calendar integrations for this user
                $integrations = $this->entityManager->getRepository('App\Plugins\Integrations\Entity\IntegrationEntity')
                    ->findBy([
                        'user' => $user,
                        'provider' => 'google_calendar',
                        'status' => 'active'
                    ]);
                
                // Process each integration
                foreach ($integrations as $integration) {
                    try {
                        // Delete the event from Google Calendar
                        $this->googleCalendarService->deleteEventForCancelledBooking($integration, $booking);
                    } catch (\Exception $e) {
                        // Silently catch exceptions
                    }
                }
            }
        } catch (\Exception $e) {
            // Silently catch exceptions
        }
    }   

   
    /**
     * Sync a Skedi event booking to Calendar systems
     */
    public function syncEventBooking(
        \App\Plugins\Events\Entity\EventBookingEntity $booking, 
        ?\App\Plugins\Account\Entity\UserEntity $specificUser = null
    ): array {
        $results = [
            'success' => 0,
            'failure' => 0,
            'skipped' => 0,
            'providers' => [],
            'debug_info' => [
                'google' => [],
                'outlook' => [],
                'general' => []
            ]
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
            
            $results['debug_info']['general']['assignee_count'] = count($assignees);
            
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
            
            $results['debug_info']['general']['attendee_count'] = count($attendees);
            $results['debug_info']['general']['booking_id'] = $booking->getId();
            $results['debug_info']['general']['booking_start'] = $booking->getStartTime()->format('Y-m-d H:i:s');
            $results['debug_info']['general']['booking_end'] = $booking->getEndTime()->format('Y-m-d H:i:s');
            
            // Process each assignee
            foreach ($assignees as $assignee) {
                $user = $assignee->getUser();
                $results['debug_info']['general']['processing_user_id'] = $user->getId();
                
                // Get active Google Calendar integrations for this user
                $googleIntegrations = $this->crudManager->findMany(
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
                
                $results['debug_info']['google']['integration_count'] = count($googleIntegrations);
                
                if (!empty($googleIntegrations)) {
                    $integration = $googleIntegrations[0];
                    $results['debug_info']['google']['integration_id'] = $integration->getId();
                    $results['debug_info']['google']['token_expiry'] = $integration->getTokenExpires() ? 
                        $integration->getTokenExpires()->format('Y-m-d H:i:s') : 'null';
                    
                    try {
                        // Create the event in Google Calendar
                        $createdEvent = $this->googleCalendarService->createCalendarEvent(
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
                        
                        $results['debug_info']['google']['event_created'] = true;
                        $results['debug_info']['google']['event_id'] = $createdEvent['google_event_id'] ?? 'unknown';
                        
                        // Track results
                        if (!isset($results['providers']['google_calendar'])) {
                            $results['providers']['google_calendar'] = [
                                'success' => 0,
                                'failure' => 0
                            ];
                        }
                        
                        $results['providers']['google_calendar']['success']++;
                        $results['success']++;
                    } catch (\Exception $e) {
                        $results['debug_info']['google']['error'] = $e->getMessage();
                        $results['debug_info']['google']['error_trace'] = $e->getTraceAsString();
                        
                        if (!isset($results['providers']['google_calendar'])) {
                            $results['providers']['google_calendar'] = [
                                'success' => 0,
                                'failure' => 0
                            ];
                        }
                        
                        $results['providers']['google_calendar']['failure']++;
                        $results['failure']++;
                    }
                }
                
                // Get active Outlook Calendar integrations for this user
                $outlookIntegrations = $this->crudManager->findMany(
                    'App\Plugins\Integrations\Entity\IntegrationEntity',
                    [],
                    1,
                    10,
                    [
                        'user' => $user,
                        'provider' => 'outlook_calendar',
                        'status' => 'active'
                    ]
                );
                
                $results['debug_info']['outlook']['integration_count'] = count($outlookIntegrations);
                
                if (!empty($outlookIntegrations)) {
                    $integration = $outlookIntegrations[0];
                    $results['debug_info']['outlook']['integration_id'] = $integration->getId();
                    $results['debug_info']['outlook']['token_expiry'] = $integration->getTokenExpires() ? 
                        $integration->getTokenExpires()->format('Y-m-d H:i:s') : 'null';
                    $results['debug_info']['outlook']['access_token_length'] = $integration->getAccessToken() ? 
                        strlen($integration->getAccessToken()) : 0;
                    $results['debug_info']['outlook']['has_refresh_token'] = !empty($integration->getRefreshToken());
                    $results['debug_info']['outlook']['scopes'] = $integration->getScopes();
                    
                    try {
                        // Create the event in Outlook Calendar
                        $createdEvent = $this->outlookCalendarService->createCalendarEvent(
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
                        
                        $results['debug_info']['outlook']['event_created'] = true;
                        $results['debug_info']['outlook']['event_id'] = $createdEvent['outlook_event_id'] ?? 'unknown';
                        
                        if (!isset($results['providers']['outlook_calendar'])) {
                            $results['providers']['outlook_calendar'] = [
                                'success' => 0,
                                'failure' => 0
                            ];
                        }
                        
                        $results['providers']['outlook_calendar']['success']++;
                        $results['success']++;
                    } catch (\Exception $e) {
                        $results['debug_info']['outlook']['error'] = $e->getMessage();
                        $results['debug_info']['outlook']['error_trace'] = $e->getTraceAsString();
                        
                        if (!isset($results['providers']['outlook_calendar'])) {
                            $results['providers']['outlook_calendar'] = [
                                'success' => 0,
                                'failure' => 0
                            ];
                        }
                        
                        $results['providers']['outlook_calendar']['failure']++;
                        $results['failure']++;
                    }
                }
                
                // If no active calendars, count as skipped
                if (empty($googleIntegrations) && empty($outlookIntegrations)) {
                    $results['skipped']++;
                }
            }
            
            return $results;
        } catch (\Exception $e) {
            $results['debug_info']['general']['unhandled_error'] = $e->getMessage();
            $results['debug_info']['general']['error_trace'] = $e->getTraceAsString();
            return $results;
        }
    }

}