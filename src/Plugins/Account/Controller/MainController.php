<?php

namespace App\Plugins\Account\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\Request;
use App\Service\ResponseService;
use App\Plugins\Events\Service\EventService;
use App\Plugins\Account\Service\LoginService;
use App\Plugins\Account\Service\UserService;
use App\Plugins\Teams\Service\TeamService;
use App\Plugins\Account\Exception\AccountException;
use App\Plugins\Account\Service\RegisterService;
use Doctrine\ORM\EntityManagerInterface;
use App\Plugins\Organizations\Service\OrganizationService;
use App\Plugins\Organizations\Service\UserOrganizationService;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use App\Plugins\Teams\Service\TeamPermissionService;

#[Route('/api/account')]
class MainController extends AbstractController
{
    private ResponseService $responseService;
    private LoginService $loginService;
    private UserService $userService;
    private TeamService $teamService;
    private EventService $eventService;
    private RegisterService $registerService;
    private OrganizationService $organizationService;
    private EntityManagerInterface $entityManager;
    private UserOrganizationService $userOrganizationService;
    private UserPasswordHasherInterface $passwordHasher;
    private TeamPermissionService $permissionService;

    public function __construct(
        ResponseService $responseService,
        LoginService $loginService,
        UserService $userService,
        TeamService $teamService,
        EventService $eventService,
        RegisterService $registerService,
        OrganizationService $organizationService,
        EntityManagerInterface $entityManager,
        UserOrganizationService $userOrganizationService,
        UserPasswordHasherInterface $passwordHasher,
        TeamPermissionService $permissionService
    ) {
        $this->responseService = $responseService;
        $this->loginService = $loginService;
        $this->userService = $userService;
        $this->teamService = $teamService;
        $this->eventService = $eventService;
        $this->registerService = $registerService;
        $this->organizationService = $organizationService;
        $this->entityManager = $entityManager;
        $this->userOrganizationService = $userOrganizationService;
        $this->passwordHasher = $passwordHasher;
        $this->permissionService = $permissionService;
    }


    #[Route('/login', name: 'account_login', methods: ['POST'])]
    public function login(Request $request): JsonResponse
    {
        try {
            $token = $this->loginService->login($request->attributes->get('data'));

            return $this->responseService->json(true, 'Login successful.', [
                'token' => $token->getValue(),
                'expires' => $token->getExpires()->format('Y-m-d H:i:s')
            ]);
        } catch (AccountException $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 400);
        } catch (\Exception $e) {
            return $this->responseService->json(false, $e, null, 400);
        }
    }


    #[Route('/register', name: 'account_register', methods: ['POST'])]
    public function register(Request $request): JsonResponse
    {
        try {
            $data = $request->attributes->get('data');
            
            // Basic validation
            if (empty($data['email']) || empty($data['name']) || empty($data['password'])) {
                return $this->responseService->json(false, 'Email, name, and password are required.', null, 400);
            }

            // Start a transaction for atomicity
            $this->entityManager->beginTransaction();
            
            try {
                // 1. Create the user entity directly
                $user = new \App\Plugins\Account\Entity\UserEntity();
                $user->setName($data['name']);
                $user->setEmail($data['email']);
                
                // Hash the password properly
                $hashedPassword = password_hash($data['password'], PASSWORD_DEFAULT);
                $user->setPassword($hashedPassword);
                
                $this->entityManager->persist($user);
                $this->entityManager->flush();
                
                // 2. Create the organization with a unique slug
                $firstName = explode(' ', $data['name'])[0];
                $orgName = $firstName . "'s Organization";
                $slug = strtolower(preg_replace('/[^a-zA-Z0-9]/', '-', $firstName . "-organization")) . '-' . time();
                
                $organization = new \App\Plugins\Organizations\Entity\OrganizationEntity();
                $organization->setName($orgName);
                $organization->setSlug($slug);
                
                $this->entityManager->persist($organization);
                $this->entityManager->flush();
                
                // 3. Create the user-organization relationship
                $userOrg = new \App\Plugins\Organizations\Entity\UserOrganizationEntity();
                $userOrg->setUser($user);
                $userOrg->setOrganization($organization);
                $userOrg->setRole('admin');
                
                $this->entityManager->persist($userOrg);
                $this->entityManager->flush();
                
                // 4. Generate a token for the user directly
                $tokenEntity = new \App\Plugins\Account\Entity\TokenEntity();
                $tokenEntity->setUser($user);
                $expires = new \DateTime('+1 month');
                $tokenValue = bin2hex(random_bytes(32)) . ':' . $expires->getTimestamp();
                $tokenEntity->setValue($tokenValue);
                $tokenEntity->setExpires($expires);
                $this->entityManager->persist($tokenEntity);
                $this->entityManager->flush();
                
                // Encode the token ID with the value for the final token
                $tokenEntity->setValue(base64_encode($tokenEntity->getId() . ':' . $tokenEntity->getValue()));
                $this->entityManager->flush();
                
                $this->entityManager->commit();
                
                // Return success with token, matching login API format
                return $this->responseService->json(true, 'Registration successful!', [
                    'user' => $user->toArray(),
                    'token' => $tokenEntity->getValue(),
                    'expires' => $tokenEntity->getExpires()->format('Y-m-d H:i:s')
                ], 201);
                
            } catch (\Exception $e) {
                $this->entityManager->rollback();
                throw $e;
            }
        } catch (AccountException $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 400);
        } catch (\Exception $e) {
            return $this->responseService->json(false, 'An error occurred: ' . $e->getMessage(), null, 500);
        }
    }

    #[Route('/user', name: 'account_get_user#', methods: ['GET'])]
    public function getAccountUser(Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');
        $organizations = $request->attributes->get('organizations');
        $directTeams = $request->attributes->get('teams');

        // Process organizations
        foreach ($organizations as $organization) {
            $organizationEntity = $organization->entity;
            $organization->entity = $organizationEntity->toArray();
            
            // Add events for this organization (events without a team)
            $orgEvents = $this->eventService->getMany([], 1, 1000, [
                'organization' => $organizationEntity,
                'deleted' => false
            ]);
            
            // Filter to only include events without a team
            $orgEventsWithoutTeam = array_filter($orgEvents, function($event) {
                return $event->getTeam() === null;
            });
            
            $organization->entity['events'] = array_map(function($event) {
                return $event->toArray();
            }, $orgEventsWithoutTeam);
        }

        // Process and expand teams with all accessible teams - PASS THE USER HERE
        $allTeams = $this->getAllAccessibleTeams($directTeams, $organizations, $user);
        
        // Add events to each team
        foreach ($allTeams as $team) {
            if (is_object($team->entity) && !is_array($team->entity)) {
                $teamId = $team->entity->getId();
                $team->entity = $team->entity->toArray();
            } else {
                $teamId = $team->entity['id'];
            }
            
            // Get events for this team
            $teamEvents = $this->eventService->getMany([], 1, 1000, [
                'team' => $teamId,
                'deleted' => false
            ]);
            
            $team->entity['events'] = array_map(function($event) {
                return $event->toArray();
            }, $teamEvents);
        }

        return $this->responseService->json(true, 'retrieve', $user->toArray() + [
            'organizations' => $organizations,
            'teams' => $allTeams
        ]);
    }

    /**
     * Get all teams a user has access to through different means:
     * 1. Direct team membership
     * 2. Organization membership
     * 3. Parent-child team relationships
     */
    private function getAllAccessibleTeams(array $directTeams, array $organizations, $user): array
    {
        // Prepare a map to prevent duplicates using team IDs as keys
        $teamsMap = [];
        
        // 1. First, add direct teams the user is a member of
        foreach ($directTeams as $team) {
            $teamId = $team->entity->getId();
            if (!isset($teamsMap[$teamId])) {
                // Get effective role using permission service
                $effectiveRole = $this->permissionService->getEffectiveRole($user, $team->entity);
                
                // Convert entity to array if it's not already
                if (is_object($team->entity) && method_exists($team->entity, 'toArray')) {
                    $team->entity = $team->entity->toArray();
                }
                
                // Update roles to effective role
                $team->role = $effectiveRole;
                $team->effective_role = $effectiveRole;
                
                $teamsMap[$teamId] = $team;
            }
        }
        
        // 2. Add teams from organizations the user is a member of
        foreach ($organizations as $organization) {
            // Get the organization entity properly
            $orgEntity = $organization->entity;
            if (is_array($orgEntity)) {
                // If it's already converted to array, we need to get the actual entity
                $orgId = $orgEntity['id'];
                $orgEntity = $this->organizationService->getOne($orgId);
            }
            
            if (!$orgEntity) {
                continue;
            }
            
            try {
                // Get all teams in this organization
                $orgTeams = $this->teamService->getTeamsByOrganization($orgEntity);
                
                foreach ($orgTeams as $orgTeam) {
                    $teamId = $orgTeam->getId();
                    if (!isset($teamsMap[$teamId])) {
                        // Get effective role for this team
                        $effectiveRole = $this->permissionService->getEffectiveRole($user, $orgTeam);
                        
                        // Create an object with the same structure as direct teams
                        $teamsMap[$teamId] = (object) [
                            'entity' => $orgTeam->toArray(),
                            'role' => $effectiveRole,
                            'effective_role' => $effectiveRole,
                            'access_via' => 'organization'
                        ];
                    }
                }
            } catch (\Exception $e) {
                // Log error but continue processing other organizations
                error_log('Error getting teams for organization: ' . $e->getMessage());
                continue;
            }
        }
        
        // 3. Return all teams as array
        return array_values($teamsMap);
    }


}