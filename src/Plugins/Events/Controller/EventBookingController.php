<?php

namespace App\Plugins\Events\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Request;
use App\Service\ResponseService;
use App\Plugins\Events\Service\EventService;
use App\Plugins\Events\Service\EventBookingService;
use App\Plugins\Events\Service\ContactService;
use App\Plugins\Events\Exception\EventsException;
use App\Plugins\Organizations\Service\UserOrganizationService;
use Doctrine\ORM\EntityManagerInterface;



#[Route('/api/organizations/{organization_id}', requirements: ['organization_id' => '\d+'])]
class EventBookingController extends AbstractController
{
    private ResponseService $responseService;
    private EventService $eventService;
    private EventBookingService $bookingService;
    private ContactService $contactService;
    private UserOrganizationService $userOrganizationService;
    private EntityManagerInterface $entityManager;
    


    public function __construct(
        ResponseService $responseService,
        EventService $eventService,
        EventBookingService $bookingService,
        ContactService $contactService,
        UserOrganizationService $userOrganizationService,
        EntityManagerInterface $entityManager 
    ) {
        $this->responseService = $responseService;
        $this->eventService = $eventService;
        $this->bookingService = $bookingService;
        $this->contactService = $contactService;
        $this->userOrganizationService = $userOrganizationService;
        $this->entityManager = $entityManager;
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
}