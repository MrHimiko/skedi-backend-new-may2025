<?php

namespace App\Plugins\Contacts\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

use App\Service\ResponseService;
use App\Plugins\Organizations\Service\OrganizationService;
use App\Plugins\Organizations\Service\UserOrganizationService;
use App\Plugins\Contacts\Service\ContactService;
use App\Plugins\Contacts\Exception\ContactsException;

#[Route('/api/user/organizations/{organization_id}/contacts', requirements: ['organization_id' => '\d+'])]
class ContactController extends AbstractController
{
    private ResponseService $responseService;
    private OrganizationService $organizationService;
    private UserOrganizationService $userOrganizationService;
    private ContactService $contactService;

    public function __construct(
        ResponseService $responseService,
        OrganizationService $organizationService,
        UserOrganizationService $userOrganizationService,
        ContactService $contactService
    ) {
        $this->responseService = $responseService;
        $this->organizationService = $organizationService;
        $this->userOrganizationService = $userOrganizationService;
        $this->contactService = $contactService;
    }

    #[Route('', name: 'contacts_list#', methods: ['GET'])]
    public function list(int $organization_id, Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');

        try {
            // Get organization
            $organization = $this->organizationService->getOne($organization_id);
            
            if (!$organization) {
                return $this->responseService->json(false, 'Organization was not found.');
            }

            // Check if user has access to this organization
            if (!$this->userOrganizationService->isUserInOrganization($user, $organization)) {
                return $this->responseService->json(false, 'You do not have access to this organization.');
            }

            // Get query parameters
            $page = (int) $request->query->get('page', 1);
            $limit = (int) $request->query->get('limit', 50);
            $filters = [
                'search' => $request->query->get('search', '')
            ];
            
            // Check if user is admin/owner of the organization
            $userOrg = $this->userOrganizationService->getOrganizationByUser($organization_id, $user);
            $isAdmin = false;
            
            if ($userOrg && isset($userOrg->role)) {
                $role = strtolower($userOrg->role);
                $isAdmin = in_array($role, ['admin', 'owner', 'creator']);
            }
            
            // Get all contacts for the organization
            $result = $this->contactService->getContactsWithMeetingInfo(
                $organization,
                $filters,
                $page,
                $limit
            );
            
            // If not admin, filter the results to only show contacts where user is a host
            if (!$isAdmin && isset($result['data'])) {
                $filteredData = [];
                
                foreach ($result['data'] as $contactData) {
                    // Check if this user has a host_contact relationship
                    $contact = $this->entityManager->find(ContactEntity::class, $contactData['contact']['id']);
                    
                    $hostContact = $this->entityManager->getRepository(HostContactEntity::class)
                        ->findOneBy([
                            'contact' => $contact,
                            'host' => $user,
                            'organization' => $organization,
                            'deleted' => false
                        ]);
                    
                    if ($hostContact) {
                        // Add host info to the contact data
                        $contactData['host_info'] = [
                            'meeting_count' => $hostContact->getMeetingCount(),
                            'first_meeting' => $hostContact->getFirstMeeting() ? 
                                $hostContact->getFirstMeeting()->format('Y-m-d H:i:s') : null,
                            'last_meeting' => $hostContact->getLastMeeting() ? 
                                $hostContact->getLastMeeting()->format('Y-m-d H:i:s') : null,
                            'is_favorite' => $hostContact->getIsFavorite()
                        ];
                        
                        $filteredData[] = $contactData;
                    }
                }
                
                $result['data'] = $filteredData;
                $result['count'] = count($filteredData);
            }

            return $this->responseService->json(true, 'Contacts retrieved successfully.', $result);
        } catch (ContactsException $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 400);
        } catch (\Exception $e) {
            return $this->responseService->json(false, 'An error occurred.', null, 500);
        }
    }

    #[Route('/{id}', name: 'contacts_get#', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function get(int $organization_id, int $id, Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');

        try {
            // Get organization
            $organization = $this->organizationService->getOne($organization_id);
            
            if (!$organization) {
                return $this->responseService->json(false, 'Organization was not found.');
            }

            // Check if user has access to this organization
            if (!$this->userOrganizationService->isUserInOrganization($user, $organization)) {
                return $this->responseService->json(false, 'You do not have access to this organization.');
            }

            // Get contact
            $orgContact = $this->contactService->getOne($organization, $id);

            if (!$orgContact) {
                return $this->responseService->json(false, 'Contact was not found.');
            }

            return $this->responseService->json(true, 'Contact retrieved successfully.', $orgContact->toArray());
        } catch (ContactsException $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 400);
        } catch (\Exception $e) {
            return $this->responseService->json(false, 'An error occurred.', null, 500);
        }
    }

    #[Route('/{id}', name: 'contacts_update#', methods: ['PUT'], requirements: ['id' => '\d+'])]
    public function update(int $organization_id, int $id, Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');

        try {
            // Get organization
            $organization = $this->organizationService->getOne($organization_id);
            
            if (!$organization) {
                return $this->responseService->json(false, 'Organization was not found.');
            }

            // Check if user has access to this organization
            if (!$this->userOrganizationService->isUserInOrganization($user, $organization)) {
                return $this->responseService->json(false, 'You do not have access to this organization.');
            }

            // Get contact
            $orgContact = $this->contactService->getOne($organization, $id);

            if (!$orgContact) {
                return $this->responseService->json(false, 'Contact was not found.');
            }

            // Update contact
            $data = json_decode($request->getContent(), true);
            $this->contactService->update($orgContact->getContact(), $data);

            return $this->responseService->json(true, 'Contact updated successfully.', $orgContact->toArray());
        } catch (ContactsException $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 400);
        } catch (\Exception $e) {
            return $this->responseService->json(false, 'An error occurred.', null, 500);
        }
    }

    #[Route('/{id}', name: 'contacts_delete#', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(int $organization_id, int $id, Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');

        try {
            // Get organization
            $organization = $this->organizationService->getOne($organization_id);
            
            if (!$organization) {
                return $this->responseService->json(false, 'Organization was not found.');
            }

            // Check if user has access to this organization
            if (!$this->userOrganizationService->isUserInOrganization($user, $organization)) {
                return $this->responseService->json(false, 'You do not have access to this organization.');
            }

            // Get contact
            $orgContact = $this->contactService->getOne($organization, $id);

            if (!$orgContact) {
                return $this->responseService->json(false, 'Contact was not found.');
            }

            // Soft delete the organization contact relationship
            $this->contactService->delete($orgContact);

            return $this->responseService->json(true, 'Contact deleted successfully.');
        } catch (ContactsException $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 400);
        } catch (\Exception $e) {
            return $this->responseService->json(false, 'An error occurred.', null, 500);
        }
    }


    #[Route('/{id}/favorite', name: 'contacts_toggle_favorite#', methods: ['PUT'], requirements: ['id' => '\d+'])]
    public function toggleFavorite(int $organization_id, int $id, Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');

        try {
            // Get organization
            $organization = $this->organizationService->getOne($organization_id);
            
            if (!$organization) {
                return $this->responseService->json(false, 'Organization was not found.');
            }

            // Check if user has access to this organization
            if (!$this->userOrganizationService->isUserInOrganization($user, $organization)) {
                return $this->responseService->json(false, 'You do not have access to this organization.');
            }

            // Get contact
            $orgContact = $this->contactService->getOne($organization, $id);

            if (!$orgContact) {
                return $this->responseService->json(false, 'Contact was not found.');
            }

            // Toggle favorite status
            $orgContact->setIsFavorite(!$orgContact->isFavorite());
            $this->entityManager->persist($orgContact);
            $this->entityManager->flush();

            return $this->responseService->json(true, 'Favorite status updated.', [
                'is_favorite' => $orgContact->isFavorite()
            ]);
        } catch (\Exception $e) {
            return $this->responseService->json(false, 'An error occurred.', null, 500);
        }
    }




}