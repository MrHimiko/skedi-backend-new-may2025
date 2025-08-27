<?php

namespace App\Plugins\Events\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Request;
use App\Service\ResponseService;
use App\Plugins\Events\Service\EventService;
use App\Plugins\Events\Service\EventBookingService;
use App\Plugins\Events\Exception\EventsException;
use App\Plugins\Organizations\Service\UserOrganizationService;
use Doctrine\ORM\EntityManagerInterface;
use App\Plugins\Email\Service\EmailService;
use App\Plugins\Events\Entity\EventBookingEntity;
use App\Plugins\Events\Service\EventAssigneeService;
use App\Plugins\Events\Service\BookingReminderService;
use App\Plugins\Contacts\Service\ContactService;
use App\Plugins\Forms\Service\FormService;
use App\Plugins\Forms\Entity\FormSubmissionEntity;

#[Route('/api/organizations/{organization_id}', requirements: ['organization_id' => '\d+'])]
class EventBookingController extends AbstractController
{
    private ResponseService $responseService;
    private EventService $eventService;
    private EventBookingService $bookingService;
    private ContactService $contactService;
    private UserOrganizationService $userOrganizationService;
    private EntityManagerInterface $entityManager;
    private EmailService $emailService;
    private EventAssigneeService $assigneeService;
    private BookingReminderService $reminderService;
    private FormService $formService;

    public function __construct(
        ResponseService $responseService,
        EventService $eventService,
        EventBookingService $bookingService,
        ContactService $contactService,
        UserOrganizationService $userOrganizationService,
        EntityManagerInterface $entityManager,
        EmailService $emailService,
        BookingReminderService $reminderService,
        EventAssigneeService $assigneeService,
        FormService $formService
    ) {
        $this->responseService = $responseService;
        $this->eventService = $eventService;
        $this->bookingService = $bookingService;
        $this->contactService = $contactService;
        $this->userOrganizationService = $userOrganizationService;
        $this->entityManager = $entityManager;
        $this->emailService = $emailService;
        $this->assigneeService = $assigneeService;
        $this->reminderService = $reminderService;
        $this->formService = $formService;
    }

    #[Route('/events/{event_id}/bookings', name: 'event_bookings_get_many', methods: ['GET'], requirements: ['event_id' => '\d+'])]
    public function getBookings(int $organization_id, int $event_id, Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');
        $filters = $request->attributes->get('filters');
        $page = $request->attributes->get('page');
        $limit = $request->attributes->get('limit');

        try {
            // Check if user has access to this organization
            if (!$organization = $this->userOrganizationService->getOrganizationByUser($organization_id, $user)) {
                return $this->responseService->json(false, 'Organization was not found.');
            }
            
            // Get event by ID ensuring it belongs to the organization
            if (!$event = $this->eventService->getEventByIdAndOrganization($event_id, $organization->entity)) {
                return $this->responseService->json(false, 'Event was not found.');
            }

            // Add event filter to only get bookings for this event
            $filters['event'] = $event;
            
            $result = $this->bookingService->getMany($filters, $page, $limit);
            
            return $this->responseService->json(true, 'Bookings retrieved successfully.', $result);

        } catch (\Exception $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 500);
        }
    }

    #[Route('/events/{event_id}/bookings/{id}', name: 'event_bookings_get_one', methods: ['GET'], requirements: ['event_id' => '\d+', 'id' => '\d+'])]
    public function getBooking(int $organization_id, int $event_id, int $id, Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');

        try {
            // Check if user has access to this organization
            if (!$organization = $this->userOrganizationService->getOrganizationByUser($organization_id, $user)) {
                return $this->responseService->json(false, 'Organization was not found.');
            }

            // Get event by ID ensuring it belongs to the organization
            if (!$event = $this->eventService->getEventByIdAndOrganization($event_id, $organization->entity)) {
                return $this->responseService->json(false, 'Event was not found.');
            }

            $booking = $this->bookingService->getOne($id);
            
            if (!$booking) {
                return $this->responseService->json(false, 'Booking was not found.');
            }

            // Verify booking belongs to the event
            if ($booking->getEvent()->getId() !== $event->getId()) {
                return $this->responseService->json(false, 'Booking was not found.');
            }

            $bookingData = $booking->toArray();
            
            // Add guests
            $guests = $this->bookingService->getGuests($booking);
            $bookingData['guests'] = array_map(function($guest) {
                return $guest->toArray();
            }, $guests);
            
            return $this->responseService->json(true, 'Booking retrieved successfully.', $bookingData);

        } catch (\Exception $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 500);
        }
    }

    
    #[Route('/events/{event_id}/bookings', name: 'event_bookings_create', methods: ['POST'], requirements: ['event_id' => '\d+'])]
    public function createBooking(int $organization_id, int $event_id, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        
        try {
            // Get event first
            $event = $this->eventService->getOne($event_id);
            
            // Check if event exists BEFORE using it
            if (!$event) {
                return $this->responseService->json(false, 'Event was not found.');
            }
            
            // Get organization
            $organization = $event->getOrganization();
            
            if (!$organization) {
                return $this->responseService->json(false, 'Organization was not found.');
            }
            
            if ($organization->getId() !== $organization_id) {
                return $this->responseService->json(false, 'Event does not belong to the specified organization.');
            }
            
            // NOW set the event_id
            $data['event_id'] = $event->getId();
            
            // Validate basic form data
            if (isset($data['form_data']) && is_string($data['form_data'])) {
                $data['form_data'] = json_decode($data['form_data'], true);
            }
            
            if (!isset($data['form_data']['primary_contact']['name']) || empty($data['form_data']['primary_contact']['name'])) {
                return $this->responseService->json(false, 'Name is required.', null, 400);
            }
            
            if (empty($data['form_data']['primary_contact']['email'])) {
                return $this->responseService->json(false, 'Email is required.', null, 400);
            }
            
            // Validate email format
            if (!filter_var($data['form_data']['primary_contact']['email'], FILTER_VALIDATE_EMAIL)) {
                return $this->responseService->json(false, 'Invalid email format.', null, 400);
            }
            
            $booking = $this->bookingService->create($data);
            
            // Create or update contact from booking
            try {
                $this->contactService->createOrUpdateFromBooking($booking);
            } catch (\Exception $e) {
                // Log error but don't fail the booking
                error_log('Failed to create contact from booking: ' . $e->getMessage());
            }
            
            // Send confirmation email to guest
            $this->sendBookingConfirmationEmail($booking);
            
            // Send notification email to host(s)
            $this->sendHostNotificationEmail($booking);
            
            // *** ADD REMINDERS FOR NON-PENDING BOOKINGS ***
            if ($booking->getStatus() !== 'pending') {
                try {
                    $this->reminderService->queueRemindersForBooking($booking);
                } catch (\Exception $e) {
                    // Log error but don't fail the booking
                    error_log('Failed to queue reminders for booking: ' . $e->getMessage());
                }
            }
            
            $bookingData = $booking->toArray();
            
            // Get all guests (including those created from form data)
            $guests = $this->bookingService->getGuests($booking);
            $bookingData['guests'] = array_map(function($guest) {
                return $guest->toArray();
            }, $guests);
            
            // Also create a form submission if a form is attached to the event
            try {
                $attachedForm = $this->formService->getFormForEvent($event);
                if ($attachedForm && !empty($data['form_data'])) {
                    $submissionData = [
                        'form_id' => $attachedForm->getId(),
                        'event_id' => $event->getId(),
                        'booking_id' => $booking->getId(),
                        'data' => $data['form_data'],
                        'ip_address' => $request->getClientIp(),
                        'user_agent' => $request->headers->get('User-Agent'),
                        'submission_source' => 'booking'
                    ];
                    
                    // Create form submission
                    $this->entityManager->getRepository(FormSubmissionEntity::class)
                        ->create($submissionData);
                }
            } catch (\Exception $e) {
                // Log but don't fail the booking
                error_log('Failed to create form submission for booking: ' . $e->getMessage());
            }
            
            return $this->responseService->json(true, 'Booking created successfully.', $bookingData, 201);
        } catch (EventsException $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 400);
        } catch (\Exception $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 500);
        }
    }

    #[Route('/events/{event_id}/bookings/{id}', name: 'event_bookings_update', methods: ['PUT'], requirements: ['event_id' => '\d+', 'id' => '\d+'])]
    public function updateBooking(int $organization_id, int $event_id, int $id, Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');
        $data = json_decode($request->getContent(), true);

        try {
            // Get event first (like in createBooking method)
            $event = $this->eventService->getOne($event_id);
            
            // Check if event exists BEFORE using it
            if (!$event) {
                return $this->responseService->json(false, 'Event was not found.');
            }
            
            // Get organization from event (like in createBooking method)
            $organization = $event->getOrganization();
            
            if (!$organization) {
                return $this->responseService->json(false, 'Organization was not found.');
            }
            
            if ($organization->getId() !== $organization_id) {
                return $this->responseService->json(false, 'Event does not belong to the specified organization.');
            }

            $booking = $this->bookingService->getOne($id);
            
            if (!$booking) {
                return $this->responseService->json(false, 'Booking was not found.');
            }

            // Verify booking belongs to the event
            if ($booking->getEvent()->getId() !== $event->getId()) {
                return $this->responseService->json(false, 'Booking was not found.');
            }

            // *** CAPTURE PREVIOUS STATUS FOR REMINDER LOGIC ***
            $previousStatus = $booking->getStatus();
            // *** ADD: CAPTURE PREVIOUS CANCELLED STATE ***
            $wasPreviouslyCancelled = $booking->isCancelled();

            // *** FIX: Pass EventBookingEntity object, not ID ***
            $this->bookingService->update($booking, $data);
            
            // If status was updated to confirmed from pending, send confirmation email
            if (isset($data['status']) && $data['status'] === 'confirmed' && $booking->getStatus() === 'confirmed' && $previousStatus === 'pending') {
      
                $this->sendBookingConfirmationEmail($booking);
                
                // Queue reminders for the newly confirmed booking
                try {
                    $this->reminderService->queueRemindersForBooking($booking);
                } catch (\Exception $e) {
                    // Log error but don't fail the update
                    error_log('Failed to queue reminders after booking confirmation: ' . $e->getMessage());
                }
            }
            
            // *** ADD: HANDLE CANCELLATION (NEW BLOCK) ***
            if (!$wasPreviouslyCancelled && $booking->isCancelled()) {
                try {
                    $this->sendBookingCancellationEmail($booking, $data['cancellation_reason'] ?? null);
                } catch (\Exception $e) {
                    error_log('Failed to send booking cancellation email: ' . $e->getMessage());
                }
            }
            
            $bookingData = $booking->toArray();
            
            // Add guests
            $guests = $this->bookingService->getGuests($booking);
            $bookingData['guests'] = array_map(function($guest) {
                return $guest->toArray();
            }, $guests);
            
            return $this->responseService->json(true, 'Booking updated successfully.', $bookingData);
        } catch (EventsException $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 400);
        } catch (\Exception $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 500);
        }
    }


    #[Route('/events/{event_id}/bookings/{id}', name: 'event_bookings_delete', methods: ['DELETE'], requirements: ['event_id' => '\d+', 'id' => '\d+'])]
    public function deleteBooking(int $organization_id, int $event_id, int $id, Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');

        try {
            // Check if user has access to this organization
            if (!$organization = $this->userOrganizationService->getOrganizationByUser($organization_id, $user)) {
                return $this->responseService->json(false, 'Organization was not found.');
            }

            // Get event by ID ensuring it belongs to the organization
            if (!$event = $this->eventService->getEventByIdAndOrganization($event_id, $organization->entity)) {
                return $this->responseService->json(false, 'Event was not found.');
            }

            $booking = $this->bookingService->getOne($id);
            
            if (!$booking) {
                return $this->responseService->json(false, 'Booking was not found.');
            }

            // Verify booking belongs to the event
            if ($booking->getEvent()->getId() !== $event->getId()) {
                return $this->responseService->json(false, 'Booking was not found.');
            }

            $this->bookingService->delete($id);
            
            return $this->responseService->json(true, 'Booking deleted successfully.');

        } catch (\Exception $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 500);
        }
    }

    #[Route('/events/{event_id}/bookings/{id}/cancel', name: 'event_bookings_cancel', methods: ['POST'], requirements: ['event_id' => '\d+', 'id' => '\d+'])]
    public function cancelBooking(int $organization_id, int $event_id, int $id, Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');
        $data = json_decode($request->getContent(), true);

        try {
            // Get event first
            $event = $this->eventService->getOne($event_id);
            
            if (!$event) {
                return $this->responseService->json(false, 'Event was not found.');
            }
            
            $organization = $event->getOrganization();
            
            if (!$organization || $organization->getId() !== $organization_id) {
                return $this->responseService->json(false, 'Event does not belong to the specified organization.');
            }

            $booking = $this->bookingService->getOne($id);
            
            if (!$booking || $booking->getEvent()->getId() !== $event->getId()) {
                return $this->responseService->json(false, 'Booking was not found.');
            }

            // Cancel the booking
            $cancelData = [
                'status' => 'canceled',
                'cancelled' => true,
                'cancellation_reason' => $data['reason'] ?? null,
                'cancelled_at' => new \DateTime(),
                'cancelled_by' => $user
            ];

            $this->bookingService->update($booking, $cancelData);
            
            // *** SEND CANCELLATION EMAIL ***
            $this->sendBookingCancellationEmail($booking, $data['reason'] ?? null);
            
            $bookingData = $booking->toArray();
            
            return $this->responseService->json(true, 'Booking cancelled successfully.', $bookingData);

        } catch (\Exception $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 500);
        }
    }

    #[Route('/events/{event_id}/bookings/{id}/reminders', name: 'event_bookings_reminders', methods: ['POST'], requirements: ['event_id' => '\d+', 'id' => '\d+'])]
    public function scheduleReminder(int $organization_id, int $event_id, int $id, Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');
        $data = json_decode($request->getContent(), true);

        try {
            // Check if user has access to this organization
            if (!$organization = $this->userOrganizationService->getOrganizationByUser($organization_id, $user)) {
                return $this->responseService->json(false, 'Organization was not found.');
            }

            // Get event by ID ensuring it belongs to the organization
            if (!$event = $this->eventService->getEventByIdAndOrganization($event_id, $organization->entity)) {
                return $this->responseService->json(false, 'Event was not found.');
            }

            $booking = $this->bookingService->getOne($id);
            
            if (!$booking) {
                return $this->responseService->json(false, 'Booking was not found.');
            }

            // Verify booking belongs to the event
            if ($booking->getEvent()->getId() !== $event->getId()) {
                return $this->responseService->json(false, 'Booking was not found.');
            }

            // Schedule reminder
            $reminderType = $data['type'] ?? '1_hour';
            $reminder = $this->reminderService->scheduleReminder($booking, $reminderType);
            
            return $this->responseService->json(true, 'Reminder scheduled successfully.', [
                'reminder_id' => $reminder->getId(),
                'scheduled_at' => $reminder->getScheduledAt()->format('Y-m-d H:i:s')
            ]);

        } catch (\Exception $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 500);
        }
    }

    /**
     * Send booking confirmation email to guest
     */
    private function sendBookingConfirmationEmail(EventBookingEntity $booking): void
    {
        try {
            $event = $booking->getEvent();
            $formData = $booking->getFormDataAsArray();
            
            // Get guest information
            $guestName = $formData['primary_contact']['name'] ?? 'Guest';
            $guestEmail = $formData['primary_contact']['email'];
            
            // Get event details
            $startTime = $booking->getStartTime();
            $duration = round(($booking->getEndTime()->getTimestamp() - $startTime->getTimestamp()) / 60);
            
            // Get organizer/creator info
            $organizer = $event->getCreatedBy();
            $organizerName = $organizer ? $organizer->getName() : 'Host';
            
            // Get organization info
            $organization = $event->getOrganization();
            $companyName = $organization ? $organization->getName() : '';
            
            // Determine location
            $location = $this->getEventLocation($event);
            
            // Get meeting link if available
            $meetingLink = $formData['online_meeting']['link'] ?? '';
            
            // Generate frontend URLs
            $frontendUrl = $_ENV['FRONTEND_URL'] ?? 'https://app.skedi.com';
            $orgSlug = $organization ? $organization->getSlug() : '';
            $eventSlug = $event->getSlug();
            $rescheduleLink = $frontendUrl . '/' . $orgSlug . '/schedule/' . $eventSlug;
            $calendarLink = $rescheduleLink; // Or generate ICS link
            
            // **GUEST EMAIL** - Uses meeting_scheduled template
            $this->emailService->send(
                $guestEmail,
                'meeting_scheduled', // Template handles status internally
                [
                    'guest_name' => $guestName,
                    'host_name' => $organizerName,
                    'meeting_name' => $event->getName(),
                    'event_name' => $event->getName(),
                    'meeting_date' => $startTime->format('F j, Y'),
                    'meeting_time' => $startTime->format('g:i A'),
                    'meeting_duration' => $duration . ' minutes',
                    'duration' => $duration . ' minutes',
                    'meeting_location' => $location,
                    'location' => $location,
                    'meeting_link' => $meetingLink,
                    'company_name' => $companyName,
                    'reschedule_link' => $rescheduleLink,
                    'calendar_link' => $calendarLink,
                    'meeting_status' => $booking->getStatus(), // Key field for template logic
                    'booking_id' => $booking->getId(),
                    'organization_id' => $organization ? $organization->getId() : null
                ]
            );
            
        } catch (\Exception $e) {
            // Log error but don't fail the booking
            error_log('Failed to send booking confirmation email: ' . $e->getMessage());
            
            if ($_ENV['APP_DEBUG'] ?? false) {
                error_log('Email data: ' . json_encode([
                    'booking_id' => $booking->getId(),
                    'status' => $booking->getStatus(),
                    'error' => $e->getMessage()
                ]));
            }
        }
    }
    
    /**
     * Send notification email to host(s)
     */
    private function sendHostNotificationEmail(EventBookingEntity $booking): void
    {
        try {
            $event = $booking->getEvent();
            $formData = $booking->getFormDataAsArray();
            
            // Get guest information
            $guestName = $formData['primary_contact']['name'] ?? 'Guest';
            $guestEmail = $formData['primary_contact']['email'];
            $guestPhone = $formData['primary_contact']['phone'] ?? '';
            $guestMessage = $formData['notes'] ?? '';
            
            // Get event details
            $startTime = $booking->getStartTime();
            $duration = round(($booking->getEndTime()->getTimestamp() - $startTime->getTimestamp()) / 60);
            
            // Get organization info
            $organization = $event->getOrganization();
            $companyName = $organization ? $organization->getName() : '';
            
            // Determine location
            $location = $this->getEventLocation($event);
            
            // Get meeting link if available
            $meetingLink = $formData['online_meeting']['link'] ?? '';
            
            // Generate frontend URLs
            $frontendUrl = $_ENV['FRONTEND_URL'] ?? 'https://app.skedi.com';
            $orgSlug = $organization ? $organization->getSlug() : '';
            $eventSlug = $event->getSlug();
            $rescheduleLink = $frontendUrl . '/' . $orgSlug . '/schedule/' . $eventSlug;
            
            // Common email data for hosts
            $commonHostData = [
                'guest_name' => $guestName,
                'guest_email' => $guestEmail,
                'guest_phone' => $guestPhone,
                'guest_message' => $guestMessage,
                'meeting_name' => $event->getName(),
                'event_name' => $event->getName(),
                'meeting_date' => $startTime->format('F j, Y'),
                'meeting_time' => $startTime->format('g:i A'),
                'meeting_duration' => $duration . ' minutes',
                'duration' => $duration . ' minutes',
                'meeting_location' => $location,
                'location' => $location,
                'meeting_link' => $meetingLink,
                'company_name' => $companyName,
                'reschedule_link' => $rescheduleLink,
                'meeting_status' => $booking->getStatus(), // Key field for template logic
                'booking_id' => $booking->getId(),
                'organization_id' => $organization ? $organization->getId() : null,
                'custom_fields' => $formData['custom_fields'] ?? []
            ];
            
            // **HOST EMAILS** - Send to all assignees
            $assignees = $this->assigneeService->getAssigneesByEvent($event);
            
            foreach ($assignees as $assignee) {
                $hostData = array_merge($commonHostData, [
                    'host_name' => $assignee->getUser()->getName()
                ]);
                
                $this->emailService->send(
                    $assignee->getUser()->getEmail(),
                    'meeting_scheduled_host', // Template handles status internally
                    $hostData
                );
            }
            
        } catch (\Exception $e) {
            // Log error but don't fail the booking
            error_log('Failed to send host notification email: ' . $e->getMessage());
            
            if ($_ENV['APP_DEBUG'] ?? false) {
                error_log('Host email error: ' . json_encode([
                    'booking_id' => $booking->getId(),
                    'status' => $booking->getStatus(),
                    'error' => $e->getMessage()
                ]));
            }
        }
    }
    
    /**
     * Helper method to determine event location
     */
    private function getEventLocation($event): string
    {
        $location = 'Online Meeting'; // Default
        $eventLocation = $event->getLocation();
        
        if ($eventLocation && is_array($eventLocation)) {
            // Check if it's a single location with 'type' key
            if (isset($eventLocation['type'])) {
                switch ($eventLocation['type']) {
                    case 'in_person':
                        $location = $eventLocation['address'] ?? 'In-Person Meeting';
                        break;
                    case 'phone':
                        $location = 'Phone Call';
                        break;
                    case 'google_meet':
                        $location = 'Google Meet';
                        break;
                    case 'zoom':
                        $location = 'Zoom Meeting';
                        break;
                    case 'custom':
                        $location = $eventLocation['label'] ?? 'Custom Location';
                        break;
                    default:
                        $location = 'Online Meeting';
                }
            }
            // Could be array of locations - use the first one
            elseif (is_array($eventLocation) && !empty($eventLocation[0])) {
                $firstLocation = $eventLocation[0];
                if (isset($firstLocation['type']) && $firstLocation['type'] === 'in_person') {
                    $location = $firstLocation['address'] ?? 'In-Person Meeting';
                } else {
                    $location = 'Online Meeting';
                }
            }
        }
        
        return $location;
    }


    /**
     * Send booking cancellation email to guest
     */
    private function sendBookingCancellationEmail(EventBookingEntity $booking, ?string $reason = null): void
    {
        try {
            $event = $booking->getEvent();
            $formData = $booking->getFormDataAsArray();
            
            // Get guest information
            $guestName = $formData['primary_contact']['name'] ?? 'Guest';
            $guestEmail = $formData['primary_contact']['email'];
            
            if (empty($guestEmail)) {
                return; // No email to send to
            }
            
            // Get event details
            $startTime = $booking->getStartTime();
            $duration = round(($booking->getEndTime()->getTimestamp() - $startTime->getTimestamp()) / 60);
            
            // Get organizer/creator info
            $organizer = $event->getCreatedBy();
            $organizerName = $organizer ? $organizer->getName() : 'Host';
            
            // Get organization info
            $organization = $event->getOrganization();
            $companyName = $organization ? $organization->getName() : '';
            
            // Determine location
            $location = $this->getEventLocation($event);
            
            // Generate rebook link
            $frontendUrl = $_ENV['FRONTEND_URL'] ?? 'https://app.skedi.com';
            $orgSlug = $organization ? $organization->getSlug() : '';
            $eventSlug = $event->getSlug();
            $rebookLink = $frontendUrl . '/' . $orgSlug . '/schedule/' . $eventSlug;
            
            // Send cancellation email
            $this->emailService->send(
                $guestEmail,
                'meeting_cancelled',
                [
                    'guest_name' => $guestName,
                    'host_name' => $organizerName,
                    'meeting_name' => $event->getName(),
                    'meeting_date' => $startTime->format('F j, Y'),
                    'meeting_time' => $startTime->format('g:i A'),
                    'meeting_duration' => $duration . ' minutes',
                    'meeting_location' => $location,
                    'company_name' => $companyName,
                    'cancellation_reason' => $reason,
                    'cancelled_by' => 'the host',
                    'rebook_link' => $rebookLink,
                    'booking_id' => $booking->getId(),
                    'organization_id' => $organization ? $organization->getId() : null
                ]
            );
            
        } catch (\Exception $e) {
            // Log error but don't fail the cancellation
            error_log('Failed to send booking cancellation email: ' . $e->getMessage());
        }
    }

}