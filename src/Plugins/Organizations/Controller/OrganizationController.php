<?php

namespace App\Plugins\Organizations\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Request;
use App\Service\ResponseService;
use App\Plugins\Organizations\Service\OrganizationService;
use App\Plugins\Organizations\Service\UserOrganizationService;
use App\Plugins\Organizations\Exception\OrganizationsException;
use Doctrine\ORM\EntityManagerInterface;
use App\Plugins\Teams\Entity\TeamEntity;
use App\Plugins\Events\Entity\EventEntity;

#[Route('/api')]
class OrganizationController extends AbstractController
{
    private ResponseService $responseService;
    private OrganizationService $organizationService;
    private UserOrganizationService $userOrganizationService;
    private EntityManagerInterface $entityManager;

    public function __construct(
        ResponseService $responseService,
        OrganizationService $organizationService,
        UserOrganizationService $userOrganizationService,
        EntityManagerInterface $entityManager
    ) {
        $this->responseService = $responseService;
        $this->organizationService = $organizationService;
        $this->userOrganizationService = $userOrganizationService;
        $this->entityManager = $entityManager;
    }

    #[Route('/organizations', name: 'organizations_get_many#', methods: ['GET'])]
    public function getOrganizations(Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');

        try 
        {
            $organizations = $this->userOrganizationService->getOrganizationsByUser($user);

            $result = [];
            foreach($organizations as $organization)
            {
                $result[] = $organization->entity->toArray();
            }
       
            return $this->responseService->json(true, 'Organizations retrieved successfully.', $result);
        } 
        catch (OrganizationsException $e) 
        {
            return $this->responseService->json(false, $e->getMessage(), null, 400);
        } 
        catch (\Exception $e) 
        {
            return $this->responseService->json(false, $e->getMessage(), null, 500);
        }
    }

    #[Route('/organizations/{id}', name: 'organizations_get_one#', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function getOrganizationById(int $id, Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');
        
        try 
        {
            if(!$organization = $this->userOrganizationService->getOrganizationByUser($id, $user))
            {
                return $this->responseService->json(false, 'Organization was not found.');
            }

            return $this->responseService->json(true, 'Organization retrieved successfully.', $organization->entity->toArray());
        } 
        catch (OrganizationsException $e) 
        {
            return $this->responseService->json(false, $e->getMessage(), null, 400);
        } 
        catch (\Exception $e) 
        {
            return $this->responseService->json(false, $e->getMessage(), null, 500);
        }
    }

    #[Route('/organizations', name: 'organizations_create#', methods: ['POST'])]
    public function createOrganization(Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');
        $data = $request->attributes->get('data');

        try 
        {
            $organization = $this->organizationService->create($data);

            $userOrganization = $this->userOrganizationService->create([], function($userOrganization) use($user, $organization)
            {
                $userOrganization->setUser($user);
                $userOrganization->setOrganization($organization);
                $userOrganization->setRole('admin');
            });

            return $this->responseService->json(true, 'Organization created successfully.', $organization->toArray(), 201);
        } 
        catch (OrganizationsException $e)
        {
            if(isset($organization) && $organization->getId())
            {
                $this->organizationService->delete($organization, true);
            }

            return $this->responseService->json(false, $e->getMessage(), null, 400);
        } 
        catch (\Exception $e) 
        {
            return $this->responseService->json(false, $e->getMessage(), null, 500);
        }
    }

    #[Route('/organizations/{id}', name: 'organizations_update#', methods: ['PUT'], requirements: ['id' => '\d+'])]
    public function updateOrganization(int $id, Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');
        $data = $request->attributes->get('data');

        try 
        {
            if(!$organization = $this->userOrganizationService->getOrganizationByUser($id, $user))
            {
                return $this->responseService->json(false, 'Organization was not found.');
            }

            if($organization->role !== 'admin')
            {
                return $this->responseService->json(false, 'Organization was not found.');
            }

            $this->organizationService->update($organization->entity, $data);

            return $this->responseService->json(true, 'Organization updated successfully.', $organization->entity->toArray());
        } 
        catch (OrganizationsException $e) 
        {
            return $this->responseService->json(false, $e->getMessage(), null, 400);
        } 
        catch (\Exception $e) 
        {
            return $this->responseService->json(false, $e->getMessage(), null, 500);
        }
    }

    #[Route('/organizations/{id}', name: 'organizations_delete#', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function deleteOrganization(int $id, Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');

        try 
        {
            if(!$organization = $this->userOrganizationService->getOrganizationByUser($id, $user))
            {
                return $this->responseService->json(false, 'Organization was not found.');
            }

            if($organization->role !== 'admin')
            {
                return $this->responseService->json(false, 'You do not have permission to delete this organization.');
            }

            // Handle cascade deletion directly here
            $this->entityManager->beginTransaction();
            
            try {
                // Get all teams in this organization
                $teams = $this->entityManager->getRepository(TeamEntity::class)->findBy([
                    'organization' => $organization->entity,
                    'deleted' => false
                ]);

                // Delete all teams and their events
                foreach ($teams as $team) {
                    $this->cascadeDeleteTeam($team);
                }

                // Get and delete organization-level events (events without a team)
                $orgEvents = $this->entityManager->getRepository(EventEntity::class)->findBy([
                    'organization' => $organization->entity,
                    'team' => null,
                    'deleted' => false
                ]);

                foreach ($orgEvents as $event) {
                    $event->setDeleted(true);
                    $this->entityManager->persist($event);
                }

                // Finally, delete the organization
                $this->organizationService->delete($organization->entity);
                
                $this->entityManager->flush();
                $this->entityManager->commit();

                return $this->responseService->json(true, 'Organization deleted successfully.');
            } catch (\Exception $e) {
                $this->entityManager->rollback();
                throw $e;
            }
        } 
        catch (OrganizationsException $e) 
        {
            return $this->responseService->json(false, $e->getMessage(), null, 400);
        } 
        catch (\Exception $e) 
        {
            return $this->responseService->json(false, 'An error occurred while deleting the organization.', null, 500);
        }
    }

    /**
     * Helper method to cascade delete a team
     */
    private function cascadeDeleteTeam(TeamEntity $team): void
    {
        // Get all child teams
        $childTeams = $this->entityManager->getRepository(TeamEntity::class)->findBy([
            'parentTeam' => $team,
            'deleted' => false
        ]);
        
        // Recursively delete child teams
        foreach ($childTeams as $childTeam) {
            $this->cascadeDeleteTeam($childTeam);
        }
        
        // Delete events for this team
        $events = $this->entityManager->getRepository(EventEntity::class)->findBy([
            'team' => $team,
            'deleted' => false
        ]);
        
        foreach ($events as $event) {
            $event->setDeleted(true);
            $this->entityManager->persist($event);
        }
        
        // Delete the team
        $team->setDeleted(true);
        $this->entityManager->persist($team);
    }
}