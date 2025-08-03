<?php

namespace App\Plugins\Account\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Request;
use App\Service\ResponseService;
use App\Plugins\Account\Service\UserAvailabilityService;
use App\Plugins\Account\Repository\UserRepository;
use App\Plugins\Account\Exception\AccountException;
use App\Plugins\Account\Entity\UserAvailabilityEntity;
use Doctrine\ORM\EntityManagerInterface;
use App\Service\CrudManager;

#[Route('/api')]
class UserAvailabilityController extends AbstractController
{
    private ResponseService $responseService;
    private UserAvailabilityService $availabilityService;
    private UserRepository $userRepository;
    private EntityManagerInterface $entityManager;
    private CrudManager $crudManager;

    public function __construct(
        ResponseService $responseService,
        UserAvailabilityService $availabilityService,
        UserRepository $userRepository,
        EntityManagerInterface $entityManager,
        CrudManager $crudManager
    ) {
        $this->responseService = $responseService;
        $this->availabilityService = $availabilityService;
        $this->userRepository = $userRepository;
        $this->entityManager = $entityManager;
        $this->crudManager = $crudManager;
    }

    /**
     * Public endpoint to check if a user is available at a specific time
     * No authentication required
     */
    #[Route('/public/users/{user_id}/availability', name: 'public_user_availability', methods: ['GET'])]
    public function checkPublicAvailability(int $user_id, Request $request): JsonResponse
    {
        $startStr = $request->query->get('start_time');
        $endStr = $request->query->get('end_time');
        $timezone = $request->query->get('timezone', 'UTC');

        try {
            // Find the user
            $user = $this->userRepository->find($user_id);
            if (!$user) {
                return $this->responseService->json(false, 'not-found', null, 404);
            }

            if (!$startStr || !$endStr) {
                return $this->responseService->json(false, 'Start time and end time are required', null, 400);
            }

            // Validate and convert timezone
            try {
                $tz = new \DateTimeZone($timezone);
            } catch (\Exception $e) {
                $timezone = 'UTC';
                $tz = new \DateTimeZone($timezone);
            }

            // Parse times in the specified timezone
            $startTime = new \DateTime($startStr, $tz);
            $endTime = new \DateTime($endStr, $tz);

            if ($startTime >= $endTime) {
                return $this->responseService->json(false, 'End time must be after start time', null, 400);
            }

            // Convert to UTC for database operations
            $startTime->setTimezone(new \DateTimeZone('UTC'));
            $endTime->setTimezone(new \DateTimeZone('UTC'));

            $isAvailable = $this->availabilityService->isUserAvailable($user, $startTime, $endTime);

            return $this->responseService->json(true, 'retrieve', [
                'user_id' => $user_id,
                'available' => $isAvailable,
                'start_time' => $startStr,
                'end_time' => $endStr,
                'timezone' => $timezone
            ]);
        } catch (AccountException $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 400);
        } catch (\Exception $e) {
            return $this->responseService->json(false, $e, null, 500);
        }
    }
    
    /**
     * Get authenticated user's availability within a time range
     * Requires authentication
     */
    #[Route('/user/availability', name: 'user_availability_get#', methods: ['GET'])]
    public function getUserAvailability(Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');
        $startStr = $request->query->get('start_time');
        $endStr = $request->query->get('end_time');

        try {
            if (!$startStr || !$endStr) {
                return $this->responseService->json(false, 'Start time and end time are required', null, 400);
            }

            $startTime = new \DateTime($startStr);
            $endTime = new \DateTime($endStr);

            if ($startTime >= $endTime) {
                return $this->responseService->json(false, 'End time must be after start time', null, 400);
            }

            $availability = $this->availabilityService->getAvailabilityByRange($user, $startTime, $endTime);
            
            // Convert to public format (without sensitive details)
            $result = array_map(function ($item) {
                return $item->toPublicArray();
            }, $availability);

            return $this->responseService->json(true, 'Availability retrieved successfully', $result);
        } catch (AccountException $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 400);
        } catch (\Exception $e) {
            return $this->responseService->json(false, $e, null, 500);
        }
    }
    
    /**
     * Create a new availability block for authenticated user
     * Requires authentication
     */
    #[Route('/user/availability', name: 'user_availability_create#', methods: ['POST'])]
    public function createAvailability(Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');
        $data = $request->attributes->get('data');

        try {
            if (empty($data['title']) || empty($data['start_time']) || empty($data['end_time'])) {
                return $this->responseService->json(false, 'Title, start time, and end time are required', null, 400);
            }

            $startTime = $data['start_time'] instanceof \DateTimeInterface 
                ? $data['start_time'] 
                : new \DateTime($data['start_time']);
            
            $endTime = $data['end_time'] instanceof \DateTimeInterface 
                ? $data['end_time'] 
                : new \DateTime($data['end_time']);

            $availability = $this->availabilityService->createInternalAvailability(
                $user,
                $data['title'],
                $startTime,
                $endTime,
                null, // No event
                null, // No booking
                $data['description'] ?? null
            );

            return $this->responseService->json(true, 'Availability created successfully', $availability->toArray());
        } catch (AccountException $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 400);
        } catch (\Exception $e) {
            return $this->responseService->json(false, $e, null, 500);
        }
    }
    
    /**
     * Update an existing availability block
     * Requires authentication and ownership
     */
    #[Route('/user/availability/{id}', name: 'user_availability_update#', methods: ['PUT'], requirements: ['id' => '\d+'])]
    public function updateAvailability(int $id, Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');
        $data = $request->attributes->get('data');

        try {
            $repo = $this->getDoctrine()->getRepository(UserAvailabilityEntity::class);
            $availability = $repo->find($id);
            
            if (!$availability) {
                return $this->responseService->json(false, 'Availability not found', null, 404);
            }
            
            // Security check - only allow users to update their own availability
            if ($availability->getUser()->getId() !== $user->getId()) {
                return $this->responseService->json(false, 'Access denied', null, 403);
            }
            
            // Only allow updating internal availability records
            if ($availability->getSource() !== 'internal') {
                return $this->responseService->json(false, 'Cannot update external availability', null, 400);
            }

            // Convert date strings to DateTime objects
            if (!empty($data['start_time']) && is_string($data['start_time'])) {
                $data['start_time'] = new \DateTime($data['start_time']);
            }
            
            if (!empty($data['end_time']) && is_string($data['end_time'])) {
                $data['end_time'] = new \DateTime($data['end_time']);
            }

            $this->availabilityService->updateAvailability($availability, $data);

            return $this->responseService->json(true, 'Availability updated successfully', $availability->toArray());
        } catch (AccountException $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 400);
        } catch (\Exception $e) {
            return $this->responseService->json(false, $e, null, 500);
        }
    }
    
    /**
     * Delete an availability block
     * Requires authentication and ownership
     */
    #[Route('/user/availability/{id}', name: 'user_availability_delete#', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function deleteAvailability(int $id, Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');

        try {
            $repo = $this->getDoctrine()->getRepository(UserAvailabilityEntity::class);
            $availability = $repo->find($id);
            
            if (!$availability) {
                return $this->responseService->json(false, 'Availability not found', null, 404);
            }
            
            // Security check - only allow users to delete their own availability
            if ($availability->getUser()->getId() !== $user->getId()) {
                return $this->responseService->json(false, 'Access denied', null, 403);
            }
            
            // Only allow deleting internal availability records
            if ($availability->getSource() !== 'internal') {
                return $this->responseService->json(false, 'Cannot delete external availability', null, 400);
            }

            $this->availabilityService->deleteAvailability($availability);

            return $this->responseService->json(true, 'Availability deleted successfully');
        } catch (AccountException $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 400);
        } catch (\Exception $e) {
            return $this->responseService->json(false, $e, null, 500);
        }
    }



    /**
     * Create out of office entry for authenticated user
     * Requires authentication
     */
    #[Route('/user/out-of-office', name: 'user_out_of_office_create#', methods: ['POST'])]
    public function createOutOfOffice(Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');
        $data = $request->attributes->get('data');

        try {
            if (empty($data['start_time']) || empty($data['end_time'])) {
                return $this->responseService->json(false, 'Start time and end time are required', null, 400);
            }

            $startTime = new \DateTime($data['start_time']);
            $endTime = new \DateTime($data['end_time']);

            if ($startTime >= $endTime) {
                return $this->responseService->json(false, 'End time must be after start time', null, 400);
            }

            // Build description from reason and notes
            $description = '';
            if (!empty($data['reason'])) {
                $description = $data['reason'];
                if (!empty($data['notes'])) {
                    $description .= ': ' . $data['notes'];
                }
            } else if (!empty($data['notes'])) {
                $description = $data['notes'];
            }

            $availability = $this->availabilityService->createInternalAvailability(
                $user,
                'Out of Office',
                $startTime,
                $endTime,
                null, // No event
                null, // No booking
                $description,
                'confirmed'
            );
            
            // Mark this as out of office by setting source
            $availability->setSource('out_of_office');
            $this->entityManager->persist($availability);
            $this->entityManager->flush();

            return $this->responseService->json(true, 'Out of office created successfully', $availability->toArray());
        } catch (AccountException $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 400);
        } catch (\Exception $e) {
            return $this->responseService->json(false, 'An error occurred', null, 500);
        }
    }

    /**
     * Get out of office entries for authenticated user
     * Requires authentication
     */
    #[Route('/user/out-of-office', name: 'user_out_of_office_list#', methods: ['GET'])]
    public function getOutOfOfficeList(Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');
        
        try {
            // Use CrudManager properly - no QueryBuilder in callback!
            $now = new \DateTime();
            $outOfOfficeEntries = $this->crudManager->findMany(
                UserAvailabilityEntity::class,
                [], // filters
                1,  // page
                1000, // limit
                [
                    'user' => $user,
                    'source' => 'out_of_office',
                    'deleted' => false
                ]
            );
            
            // Sort by startTime DESC in PHP since we can't use orderBy in callback
            usort($outOfOfficeEntries, function($a, $b) {
                return $b->getStartTime() <=> $a->getStartTime();
            });
            
            $result = array_map(function ($item) {
                $data = $item->toArray();
                
                // Parse description back to reason and notes
                $description = $item->getDescription();
                if ($description) {
                    $data['reason'] = $description;
                    $data['notes'] = $description;
                } else {
                    $data['reason'] = 'Unspecified';
                    $data['notes'] = '';
                }
                
                return $data;
            }, $outOfOfficeEntries);

            return $this->responseService->json(true, 'Out of office entries retrieved', $result);
        } catch (\Exception $e) {
            return $this->responseService->json(false, 'An error occurred', null, 500);
        }
    }

    /**
     * Update out of office entry
     * Requires authentication
     */
    #[Route('/user/out-of-office/{id}', name: 'user_out_of_office_update#', methods: ['PUT'])]
    public function updateOutOfOffice(Request $request, int $id): JsonResponse
    {
        $user = $request->attributes->get('user');
        $data = $request->attributes->get('data');
        
        try {
            $availability = $this->crudManager->findOne(
                UserAvailabilityEntity::class, 
                $id, 
                [
                    'user' => $user,
                    'source' => 'out_of_office',
                    'deleted' => false
                ]
            );
            
            if (!$availability) {
                return $this->responseService->json(false, 'Out of office entry not found', null, 404);
            }
            
            if (!empty($data['start_time'])) {
                $availability->setStartTime(new \DateTime($data['start_time']));
            }
            
            if (!empty($data['end_time'])) {
                $availability->setEndTime(new \DateTime($data['end_time']));
            }
            
            if ($availability->getStartTime() >= $availability->getEndTime()) {
                return $this->responseService->json(false, 'End time must be after start time', null, 400);
            }
            
            // Update description from reason and notes
            if (isset($data['reason']) || isset($data['notes'])) {
                $description = '';
                $reason = $data['reason'] ?? 'Unspecified';
                $notes = $data['notes'] ?? '';
                
                if ($reason && $reason !== 'Unspecified') {
                    $description = $reason;
                    if ($notes) {
                        $description .= ': ' . $notes;
                    }
                } else if ($notes) {
                    $description = $notes;
                }
                
                $availability->setDescription($description);
            }
            
            $this->entityManager->persist($availability);
            $this->entityManager->flush();
            
            return $this->responseService->json(true, 'Out of office updated successfully', $availability->toArray());
        } catch (\Exception $e) {
            return $this->responseService->json(false, 'An error occurred', null, 500);
        }
    }

    /**
     * Delete out of office entry
     * Requires authentication
     */
    #[Route('/user/out-of-office/{id}', name: 'user_out_of_office_delete#', methods: ['DELETE'])]
    public function deleteOutOfOffice(Request $request, int $id): JsonResponse
    {
        $user = $request->attributes->get('user');
        
        try {
            $availability = $this->crudManager->findOne(
                UserAvailabilityEntity::class, 
                $id, 
                [
                    'user' => $user,
                    'source' => 'out_of_office',
                    'deleted' => false
                ]
            );
            
            if (!$availability) {
                return $this->responseService->json(false, 'Out of office entry not found', null, 404);
            }
            
            // Soft delete
            $this->crudManager->delete($availability);
            
            return $this->responseService->json(true, 'Out of office deleted successfully');
        } catch (\Exception $e) {
            return $this->responseService->json(false, 'An error occurred', null, 500);
        }
    }


}