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


    public function __construct(
        ResponseService $responseService,
        EventService $eventService,
        EventBookingService $bookingService,
        ContactService $contactService,
        UserOrganizationService $userOrganizationService,
        EntityManagerInterface $entityManager,
        EmailService $emailService,
        BookingReminderService $reminderService,
        EventAssigneeService $assigneeService
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
    }

    #[Route('/events/{event_id}/bookings', name: 'event_bookings_get_many#', methods: ['GET'], requirements: ['event_id' => '\d+'])]
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
            
            // Get bookings for this event
            $bookings = $this->bookingService->getBookingsByEvent($event, $filters);
            
            $result = [];
            foreach ($bookings as $booking) {
                $bookingData = $booking->toArray();
                
                // Add guests
                $guests = $this->bookingService->getGuests($booking);
                $bookingData['guests'] = array_map(function($guest) {
                    return $guest->toArray();
                }, $guests);
                
                $result[] = $bookingData;
            }
            
            return $this->responseService->json(true, 'Bookings retrieved successfully.', $result);
        } catch (EventsException $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 400);
        } catch (\Exception $e) {
            return $this->responseService->json(false, $e, null, 500);
        }
    }

    #[Route('/events/{event_id}/bookings/{id}', name: 'event_bookings_get_one#', methods: ['GET'], requirements: ['event_id' => '\d+', 'id' => '\d+'])]
    public function getBookingById(int $organization_id, int $event_id, int $id, Request $request): JsonResponse
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
            
            // Get booking by ID
            $booking = $this->bookingService->getOne($id);
            
            if (!$booking || $booking->getEvent()->getId() !== $event->getId()) {
                return $this->responseService->json(false, 'Booking was not found.');
            }
            
            $bookingData = $booking->toArray();
            
            // Add guests
            $guests = $this->bookingService->getGuests($booking);
            $bookingData['guests'] = array_map(function($guest) {
                return $guest->toArray();
            }, $guests);
            
            return $this->responseService->json(true, 'Booking retrieved successfully.', $bookingData);
        } catch (EventsException $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 400);
        } catch (\Exception $e) {
            return $this->responseService->json(false, $e, null, 500);
        }
    }

    #[Route('/events/{event_id}/bookings', name: 'event_bookings_create', methods: ['POST'])]  
    public function createBooking(int $organization_id, int $event_id, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        
        try {
            $event = $this->eventService->getOne($event_id);

            if (!$event) {
                return $this->responseService->json(false, 'Event was not found.');
            }

            $data['event_id'] = $event->getId();

            $organization = $event->getOrganization();
            
            if (!$organization) {
                return $this->responseService->json(false, 'Organization was not found.');
            }
            
            if (!$event = $this->eventService->getEventByIdAndOrganization($event_id, $organization)) {
                return $this->responseService->json(false, 'Event was not found.');
            }
            
            // Process form data
            if (isset($data['form_data']) && is_string($data['form_data'])) {
                $data['form_data'] = json_decode($data['form_data'], true);
            }
            
            // Ensure form data has required fields
            if (!empty($data['form_data'])) {
                // Validate that name and email exist in form data
                if (empty($data['form_data']['primary_contact']['name'])) {
                    return $this->responseService->json(false, 'Name is required.', null, 400);
                }
                
                if (empty($data['form_data']['primary_contact']['email'])) {
                    return $this->responseService->json(false, 'Email is required.', null, 400);
                }
                
                // Validate email format
                if (!filter_var($data['form_data']['primary_contact']['email'], FILTER_VALIDATE_EMAIL)) {
                    return $this->responseService->json(false, 'Invalid email format.', null, 400);
                }
            }
            
            $booking = $this->bookingService->create($data);
            
            // Create or update contact from booking
            try {
                $this->contactService->createOrUpdateFromBooking($booking);
            } catch (\Exception $e) {
                // Log error but don't fail the booking
                error_log('Failed to create contact from booking: ' . $e->getMessage());
            }
            
            // Send confirmation email
            $this->sendBookingConfirmationEmail($booking);
            $this->sendHostNotificationEmail($booking);
            $this->reminderService->queueRemindersForBooking($booking);
            
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
            return $this->responseService->json(false, $e, null, 500);
        }
    }

    #[Route('/events/{event_id}/bookings/{id}', name: 'event_bookings_update#', methods: ['PUT'], requirements: ['event_id' => '\d+', 'id' => '\d+'])]
    public function updateBooking(int $organization_id, int $event_id, int $id, Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');
        $data = $request->attributes->get('data');

        try {
            // Check if user has access to this organization
            if (!$organization = $this->userOrganizationService->getOrganizationByUser($organization_id, $user)) {
                return $this->responseService->json(false, 'Organization was not found.');
            }
            
            // Get event by ID ensuring it belongs to the organization
            if (!$event = $this->eventService->getEventByIdAndOrganization($event_id, $organization->entity)) {
                return $this->responseService->json(false, 'Event was not found.');
            }
            
            // Get booking by ID
            $booking = $this->bookingService->getOne($id);
            
            if (!$booking || $booking->getEvent()->getId() !== $event->getId()) {
                return $this->responseService->json(false, 'Booking was not found.');
            }
            
            // Update booking
            $this->bookingService->update($booking, $data);
            
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
            return $this->responseService->json(false, $e, null, 500);
        }
    }

    #[Route('/events/{event_id}/bookings/{id}/cancel', name: 'event_bookings_cancel#', methods: ['PUT'], requirements: ['event_id' => '\d+', 'id' => '\d+'])]
    public function cancelBooking(int $organization_id, int $event_id, int $id, Request $request): JsonResponse
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
            
            // Get booking by ID
            $booking = $this->bookingService->getOne($id);
            
            if (!$booking || $booking->getEvent()->getId() !== $event->getId()) {
                return $this->responseService->json(false, 'Booking was not found.');
            }
            
            // Cancel booking
            $this->bookingService->cancel($booking);
            
            return $this->responseService->json(true, 'Booking cancelled successfully.', $booking->toArray());
        } catch (EventsException $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 400);
        } catch (\Exception $e) {
            return $this->responseService->json(false, $e, null, 500);
        }
    }

    #[Route('/events/{event_id}/bookings/{id}', name: 'event_bookings_delete#', methods: ['DELETE'], requirements: ['event_id' => '\d+', 'id' => '\d+'])]
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
            
            // Get booking by ID
            $booking = $this->bookingService->getOne($id);
            
            if (!$booking || $booking->getEvent()->getId() !== $event->getId()) {
                return $this->responseService->json(false, 'Booking was not found.');
            }
            
            // Delete booking
            $this->bookingService->delete($booking);
            
            return $this->responseService->json(true, 'Booking deleted successfully.');
        } catch (EventsException $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 400);
        } catch (\Exception $e) {
            return $this->responseService->json(false, $e, null, 500);
        }
    }




   private function sendBookingConfirmationEmail(EventBookingEntity $booking): void
    {
        try {
            $formData = $booking->getFormDataAsArray();
            
            if (empty($formData['primary_contact']['name']) || empty($formData['primary_contact']['email'])) {
                // No contact info, skip email
                return;
            }
            
            $guestName = $formData['primary_contact']['name'] ?? 'Guest';
            $guestEmail = $formData['primary_contact']['email'];
            
            // Get event info
            $event = $booking->getEvent();
            $startTime = $booking->getStartTime();
            $duration = round(($booking->getEndTime()->getTimestamp() - $startTime->getTimestamp()) / 60);
            
            // Get organizer info - the event creator
            $organizer = $event->getCreatedBy();
            $organizerName = $organizer ? $organizer->getName() : 'Organizer';
            
            // Get organization info for company name
            $organization = $event->getOrganization();
            $companyName = $organization ? $organization->getName() : '';
            
            // Get meeting link if available
            $meetingLink = '';
            $rescheduleLink = '';
            
            // Check if meeting link was generated and stored in form data
            $formDataArray = $booking->getFormDataAsArray();
            if (!empty($formDataArray['online_meeting']['link'])) {
                $meetingLink = $formDataArray['online_meeting']['link'];
            } elseif (!empty($formDataArray['meeting_link'])) {
                $meetingLink = $formDataArray['meeting_link'];
            }
            
            // Generate reschedule link - you'll need to adjust this based on your URL structure
            $baseUrl = $_ENV['APP_URL'] ?? 'https://app.skedi.com';
            $orgSlug = $organization ? $organization->getSlug() : '';
            $eventSlug = $event->getSlug();
            $rescheduleLink = $baseUrl . '/organizations/' . $orgSlug . '/events/' . $eventSlug . '/bookings/' . $booking->getId() . '/reschedule';
            
            // Determine location
            $location = 'Online Meeting'; 
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
                // Could be array of locations - just use the first one
                elseif (is_array($eventLocation) && !empty($eventLocation[0])) {
                    $firstLocation = $eventLocation[0];
                    if (isset($firstLocation['type']) && $firstLocation['type'] === 'in_person') {
                        $location = $firstLocation['address'] ?? 'In-Person Meeting';
                    } else {
                        $location = 'Online Meeting';
                    }
                }
            }
            
            // Send the email with variables matching the SendGrid template
            $this->emailService->send(
                $guestEmail,
                'meeting_scheduled',
                [
                    // Guest info
                    'guest_name' => $guestName,
                    
                    // Meeting details - these MUST match your SendGrid template variables exactly
                    'meeting_name' => $event->getName(),
                    'meeting_date' => $startTime->format('F j, Y'),
                    'meeting_time' => $startTime->format('g:i A'),
                    'meeting_duration' => $duration,  // SendGrid might expect just the number
                    'meeting_location' => $location,
                    'meeting_link' => $meetingLink,
                    
                    // Organizer info
                    'organizer_name' => $organizerName,
                    'company_name' => $companyName,
                    
                    // Action links
                    'reschedule_link' => $rescheduleLink,
                    
                    // Keep original fields for backward compatibility
                    'date' => $startTime->format('F j, Y'),
                    'time' => $startTime->format('g:i A'),
                    'duration' => $duration . ' minutes'
                ]
            );
            
        } catch (\Exception $e) {
            // Log error but don't fail the booking
            error_log('Failed to send booking confirmation email: ' . $e->getMessage());
            
            // Optionally log more details for debugging
            if ($_ENV['APP_DEBUG'] ?? false) {
                error_log('Email data: ' . json_encode([
                    'to' => $guestEmail ?? 'unknown',
                    'template' => 'meeting_scheduled',
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]));
            }
        }
    }


    private function sendHostNotificationEmail(EventBookingEntity $booking): void
    {
        try {
            // Get the form data to extract guest information
            $formData = $booking->getFormDataAsArray();
            if (empty($formData['primary_contact'])) {
                return;
            }
            
            // Guest information
            $guestName = $formData['primary_contact']['name'] ?? 'Guest';
            $guestEmail = $formData['primary_contact']['email'];
            $guestPhone = $formData['primary_contact']['phone'] ?? '';
            $guestMessage = $formData['message'] ?? $formData['notes'] ?? '';
            
            // Get event and booking details
            $event = $booking->getEvent();
            $startTime = $booking->getStartTime();
            $duration = round(($booking->getEndTime()->getTimestamp() - $startTime->getTimestamp()) / 60);
            
            // Get organization info
            $organization = $event->getOrganization();
            $companyName = $organization ? $organization->getName() : '';
            
            // Determine location
            $location = 'Online Meeting'; // Default
            $meetingLink = '';
            
            // Check for meeting link in form data
            $formDataArray = $booking->getFormDataAsArray();
            if (!empty($formDataArray['online_meeting']['link'])) {
                $meetingLink = $formDataArray['online_meeting']['link'];
            } elseif (!empty($formDataArray['meeting_link'])) {
                $meetingLink = $formDataArray['meeting_link'];
            }
            
            // Process location
            $eventLocation = $event->getLocation();
            if ($eventLocation && is_array($eventLocation)) {
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
                } elseif (is_array($eventLocation) && !empty($eventLocation[0])) {
                    $firstLocation = $eventLocation[0];
                    if (isset($firstLocation['type']) && $firstLocation['type'] === 'in_person') {
                        $location = $firstLocation['address'] ?? 'In-Person Meeting';
                    } else {
                        $location = 'Online Meeting';
                    }
                }
            }
            
            // Generate calendar link (you can customize this based on your needs)
            $baseUrl = $_ENV['APP_URL'] ?? 'https://app.skedi.com';
            $calendarLink = $baseUrl . '/bookings/' . $booking->getId();
            
            // Get all assignees (hosts) for this event using the assignee service
            $assignees = $this->assigneeService->getAssigneesByEvent($event);
            
            // Filter to get only hosts/admins/creators
            $hosts = [];
            foreach ($assignees as $assignee) {
                if (in_array($assignee->getRole(), ['creator', 'admin', 'host'])) {
                    $hosts[] = $assignee->getUser();
                }
            }
            
            // If no specific hosts found, send to event creator
            if (empty($hosts)) {
                $creator = $event->getCreatedBy();
                if ($creator && $creator->getEmail()) {
                    $hosts = [$creator];
                }
            }
            
            // Send email to each host
            foreach ($hosts as $host) {
                if (!$host->getEmail()) {
                    continue;
                }
                
                $this->emailService->send(
                    $host->getEmail(),
                    'meeting_scheduled_host',
                    [
                        // Host info
                        'host_name' => $host->getName() ?? 'Host',
                        
                        // Guest info
                        'guest_name' => $guestName,
                        'guest_email' => $guestEmail,
                        'guest_phone' => $guestPhone,
                        'guest_message' => $guestMessage,
                        
                        // Meeting details
                        'meeting_name' => $event->getName(),
                        'meeting_date' => $startTime->format('F j, Y'),
                        'meeting_time' => $startTime->format('g:i A'),
                        'meeting_duration' => $duration,
                        'meeting_location' => $location,
                        'meeting_link' => $meetingLink,
                        
                        // Company info
                        'company_name' => $companyName,
                        
                        // Calendar link
                        'calendar_link' => $calendarLink
                    ]
                );
            }
            
        } catch (\Exception $e) {
            // Log error but don't fail the booking
            error_log('Failed to send host notification email: ' . $e->getMessage());
            
            if ($_ENV['APP_DEBUG'] ?? false) {
                error_log('Host email error details: ' . json_encode([
                    'booking_id' => $booking->getId(),
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]));
            }
        }
    }
        


    
   
}