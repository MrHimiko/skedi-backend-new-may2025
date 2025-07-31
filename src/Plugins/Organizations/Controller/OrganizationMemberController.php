<?php

namespace App\Plugins\Organizations\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Request;
use App\Service\ResponseService;
use App\Plugins\Organizations\Service\OrganizationService;
use App\Plugins\Organizations\Service\UserOrganizationService;
use App\Plugins\Organizations\Entity\UserOrganizationEntity;
use App\Plugins\Account\Service\UserService;
use App\Plugins\Email\Service\EmailService;
use App\Plugins\Organizations\Exception\OrganizationsException;
use Doctrine\ORM\EntityManagerInterface;
use App\Plugins\Account\Repository\UserRepository;
use App\Plugins\Invitations\Service\InvitationService;
use App\Plugins\Teams\Service\TeamService;
use App\Plugins\Teams\Service\UserTeamService;
use App\Plugins\Teams\Service\TeamPermissionService;


#[Route('/api/organizations/{organization_id}', requirements: ['organization_id' => '\d+'])]
class OrganizationMemberController extends AbstractController
{
    private ResponseService $responseService;
    private OrganizationService $organizationService;
    private UserOrganizationService $userOrganizationService;
    private UserService $userService;
    private EmailService $emailService;
    private EntityManagerInterface $entityManager;
    private UserRepository $userRepository;
    private InvitationService $invitationService; 
    private TeamService $teamService;             
    private UserTeamService $userTeamService;     
    private TeamPermissionService $permissionService;

    public function __construct(
        ResponseService $responseService,
        OrganizationService $organizationService,
        UserOrganizationService $userOrganizationService,
        UserService $userService,
        EmailService $emailService,
        EntityManagerInterface $entityManager,
        UserRepository $userRepository,
        InvitationService $invitationService,  
        TeamService $teamService,             
        UserTeamService $userTeamService,      
        TeamPermissionService $permissionService
    ) {
        $this->responseService = $responseService;
        $this->organizationService = $organizationService;
        $this->userOrganizationService = $userOrganizationService;
        $this->userService = $userService;
        $this->emailService = $emailService;
        $this->entityManager = $entityManager;
        $this->userRepository = $userRepository;
        $this->invitationService = $invitationService;  
        $this->teamService = $teamService;              
        $this->userTeamService = $userTeamService;  
        $this->permissionService = $permissionService;
    }

    #[Route('/members', name: 'organization_members_list#', methods: ['GET'])]
    public function getMembers(int $organization_id, Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');

        try {
            // Check if user has access to this organization
            $userOrg = $this->userOrganizationService->getOrganizationByUser($organization_id, $user);
            if (!$userOrg) {
                return $this->responseService->json(false, 'You do not have access to this organization.');
            }

            // Get all members of the organization
            $members = $this->userOrganizationService->getMembersByOrganization($organization_id);

            $result = [];
            foreach ($members as $member) {
                $memberUser = $member->getUser();
                $result[] = [
                    'id' => $member->getId(),
                    'user' => [
                        'id' => $memberUser->getId(),
                        'name' => $memberUser->getName(),
                        'email' => $memberUser->getEmail()
                    ],
                    'role' => $member->getRole(),
                    'joined' => $member->getCreated()->format('Y-m-d H:i:s'),
                    'is_creator' => $this->isOrganizationCreator($member)
                ];
            }

            return $this->responseService->json(true, 'Members retrieved successfully.', $result);
        } catch (\Exception $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 500);
        }
    }

    #[Route('/members/invite', name: 'organization_members_invite#', methods: ['POST'])]
    public function inviteMember(int $organization_id, Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');
        $data = $request->attributes->get('data');

        try {
            // Check if user is admin of this organization
            $userOrg = $this->userOrganizationService->getOrganizationByUser($organization_id, $user);
            if (!$userOrg || $userOrg->role !== 'admin') {
                return $this->responseService->json(false, 'You do not have permission to invite members.');
            }

            // Validate email
            if (empty($data['email']) || !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
                return $this->responseService->json(false, 'Valid email address is required.');
            }

            $organization = $this->organizationService->getOne($organization_id);
            if (!$organization) {
                return $this->responseService->json(false, 'Organization not found.');
            }

            // Check if user with this email exists
            $invitedUser = $this->userRepository->findOneBy(['email' => $data['email']]);
            
            if ($invitedUser) {
                // Check if user is already a member
                $existingMember = $this->userOrganizationService->getOrganizationByUser($organization_id, $invitedUser);
                if ($existingMember) {
                    return $this->responseService->json(false, 'User is already a member of this organization.');
                }
            }
            
            // Create invitation using the invitation service (for both existing and new users)
            // The service will handle checking for existing invitations internally
            $invitation = $this->invitationService->sendInvitation(
                $data['email'],
                $user,  
                $organization,
                null,   // No team for organization invitations
                $data['role'] ?? 'member'
            );
            
            // Send email notification
            /*
            $this->emailService->send(
                $data['email'],
                'invitation',
                [
                    // Target details
                    'target_name' => $organization->getName(),
                    'target_type' => 'organization',
                    'is_team_invitation' => false,
                    'organization_name' => $organization->getName(),
                    'team_name' => null,
                    
                    // Role info
                    'role' => $data['role'] ?? 'member',
                    'role_display' => ucfirst($data['role'] ?? 'member'),
                    'article' => ($data['role'] ?? 'member') === 'admin' ? 'an' : 'a',
                    
                    // Inviter info
                    'inviter_name' => $user->getName(),
                    'inviter_email' => $user->getEmail(),
                    
                    // User status - differentiate email content
                    'existing_user' => $invitedUser ? true : false,
                    'invitee_name' => $invitedUser ? $invitedUser->getName() : 'there',
                    
                    // Invitation token for accepting
                    'invitation_token' => $invitation->getToken()
                ]
            );

            */
            
            return $this->responseService->json(
                true, 
                'Invitation sent successfully!',
                [
                    'email' => $data['email'], 
                    'invitation_id' => $invitation->getId()
                ]
            );

        } catch (\Exception $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 500);
        }
    }


    #[Route('/members/{member_id}', name: 'organization_members_update#', methods: ['PUT'], requirements: ['member_id' => '\d+'])]
    public function updateMember(int $organization_id, int $member_id, Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');
        $data = $request->attributes->get('data');

        try {
            // Check if user is admin of this organization
            $userOrg = $this->userOrganizationService->getOrganizationByUser($organization_id, $user);
            if (!$userOrg || $userOrg->role !== 'admin') {
                return $this->responseService->json(false, 'You do not have permission to update members.');
            }

            // Get the member relationship
            $member = $this->userOrganizationService->getOne($member_id);
            if (!$member || $member->getOrganization()->getId() !== $organization_id) {
                return $this->responseService->json(false, 'Member not found.');
            }

            // Update role if provided
            if (isset($data['role']) && in_array($data['role'], ['admin', 'member'])) {
                $member->setRole($data['role']);
                $this->entityManager->persist($member);
                $this->entityManager->flush();
            }

            return $this->responseService->json(true, 'Member updated successfully.');
        } catch (\Exception $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 500);
        }
    }

    #[Route('/members/{member_id}', name: 'organization_members_remove#', methods: ['DELETE'], requirements: ['member_id' => '\d+'])]
    public function removeMember(int $organization_id, int $member_id, Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');

        try {
            // Check if user is admin of this organization
            $userOrg = $this->userOrganizationService->getOrganizationByUser($organization_id, $user);
            if (!$userOrg || $userOrg->role !== 'admin') {
                return $this->responseService->json(false, 'You do not have permission to remove members.');
            }

            // Get the member relationship
            $member = $this->userOrganizationService->getOne($member_id);
            if (!$member || $member->getOrganization()->getId() !== $organization_id) {
                return $this->responseService->json(false, 'Member not found.');
            }

            // Check if this member is the organization creator (first admin)
            if ($this->isOrganizationCreator($member)) {
                return $this->responseService->json(false, 'Cannot remove the organization creator. The creator can only leave voluntarily.');
            }

            // Remove member
            $this->userOrganizationService->delete($member, true);

            return $this->responseService->json(true, 'Member removed successfully.');
        } catch (\Exception $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 500);
        }
    }

    #[Route('/leave', name: 'organization_leave#', methods: ['POST'])]
    public function leaveOrganization(int $organization_id, Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');

        try {
            // Check if user is member of this organization
            $userOrg = $this->userOrganizationService->getOrganizationByUser($organization_id, $user);
            if (!$userOrg) {
                return $this->responseService->json(false, 'You are not a member of this organization.');
            }

            // Get the member relationship entity
            $memberRelation = $this->userOrganizationService->isUserInOrganization($user, $userOrg->entity);
            if (!$memberRelation) {
                return $this->responseService->json(false, 'Member relationship not found.');
            }

            // If user is admin, check if there are other admins
            if ($userOrg->role === 'admin') {
                // Count other admins in the organization
                $allMembers = $this->userOrganizationService->getMembersByOrganization($organization_id);
                $adminCount = 0;
                
                foreach ($allMembers as $member) {
                    if ($member->getRole() === 'admin' && $member->getUser()->getId() !== $user->getId()) {
                        $adminCount++;
                    }
                }
                
                if ($adminCount === 0) {
                    return $this->responseService->json(
                        false, 
                        'You cannot leave this organization as you are the only admin. Please assign another admin first.'
                    );
                }
            }

            // Remove the user from organization
            $this->userOrganizationService->delete($memberRelation, true);

            return $this->responseService->json(true, 'Successfully left the organization.');
        } catch (\Exception $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 500);
        }
    }
    
    /**
     * Check if a user is the organization creator (first admin)
     * The creator is identified as the user with the earliest created timestamp
     */
    private function isOrganizationCreator($userOrganization): bool
    {
        // Get all members of the organization using CrudManager
        $allMembers = $this->userOrganizationService->getMany(
            [], 
            1,  
            1000, 
            ['organization' => $userOrganization->getOrganization()]
        );
        
        // Sort by created date (oldest first)
        usort($allMembers, function($a, $b) {
            return $a->getCreated()->getTimestamp() - $b->getCreated()->getTimestamp();
        });
        
        // The first member (oldest created date) is considered the creator
        if (!empty($allMembers) && $allMembers[0]->getId() === $userOrganization->getId()) {
            return true;
        }
        
        return false;
    }


    #[Route('/teams/{team_id}/members/{member_id}', name: 'team_members_update#', methods: ['PUT'], requirements: ['team_id' => '\d+', 'member_id' => '\d+'])]
    public function updateTeamMember(int $organization_id, int $team_id, int $member_id, Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');
        $data = $request->attributes->get('data');

        try {
            // Get team
            $team = $this->teamService->getOne($team_id);
            if (!$team || $team->getOrganization()->getId() !== $organization_id) {
                return $this->responseService->json(false, 'Team not found.');
            }

            // Check if user has admin access
            if (!$this->permissionService->hasAdminAccess($user, $team)) {
                return $this->responseService->json(false, 'You do not have permission to update members.');
            }

            // Get member relationship
            $member = $this->userTeamService->getOne($member_id);
            if (!$member || $member->getTeam()->getId() !== $team_id) {
                return $this->responseService->json(false, 'Member not found.');
            }

            // Update role if provided
            if (isset($data['role']) && in_array($data['role'], ['admin', 'member'])) {
                $member->setRole($data['role']);
                $this->entityManager->persist($member);
                $this->entityManager->flush();
            }

            return $this->responseService->json(true, 'Member updated successfully.');
        } catch (\Exception $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 500);
        }
    }

    #[Route('/teams/{team_id}/members/{member_id}', name: 'team_members_remove#', methods: ['DELETE'], requirements: ['team_id' => '\d+', 'member_id' => '\d+'])]
    public function removeTeamMember(int $organization_id, int $team_id, int $member_id, Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');

        try {
            // Get team
            $team = $this->teamService->getOne($team_id);
            if (!$team || $team->getOrganization()->getId() !== $organization_id) {
                return $this->responseService->json(false, 'Team not found.');
            }

            // Check if user has admin access
            if (!$this->permissionService->hasAdminAccess($user, $team)) {
                return $this->responseService->json(false, 'You do not have permission to remove members.');
            }

            // Get member relationship
            $member = $this->userTeamService->getOne($member_id);
            if (!$member || $member->getTeam()->getId() !== $team_id) {
                return $this->responseService->json(false, 'Member not found.');
            }

            // Remove member
            $this->userTeamService->delete($member);

            return $this->responseService->json(true, 'Member removed successfully.');
        } catch (\Exception $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 500);
        }
    }

    #[Route('/teams/{team_id}/members/leave', name: 'team_leave#', methods: ['POST'], requirements: ['team_id' => '\d+'])]
    public function leaveTeam(int $organization_id, int $team_id, Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');

        try {
            // Get team
            $team = $this->teamService->getOne($team_id);
            if (!$team || $team->getOrganization()->getId() !== $organization_id) {
                return $this->responseService->json(false, 'Team not found.');
            }

            // Check if user is member of this team
            $userTeam = $this->userTeamService->isUserInTeam($user, $team);
            if (!$userTeam) {
                return $this->responseService->json(false, 'You are not a member of this team.');
            }

            // Remove user from team (no admin check needed for teams)
            $this->userTeamService->delete($userTeam);

            return $this->responseService->json(true, 'Successfully left the team.');
        } catch (\Exception $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 500);
        }
    }



    #[Route('/teams/{team_id}/members/invite', name: 'team_members_invite#', methods: ['POST'], requirements: ['team_id' => '\d+'])]
    public function inviteTeamMember(int $organization_id, int $team_id, Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');
        $data = $request->attributes->get('data');

        try {
            // Get team
            $team = $this->teamService->getOne($team_id);
            if (!$team || $team->getOrganization()->getId() !== $organization_id) {
                return $this->responseService->json(false, 'Team not found.');
            }

            // Check if user has admin access to the team
            if (!$this->permissionService->hasAdminAccess($user, $team)) {
                return $this->responseService->json(false, 'You do not have permission to invite members to this team.');
            }

            // Validate email
            if (empty($data['email']) || !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
                return $this->responseService->json(false, 'Valid email address is required.');
            }

            $organization = $this->organizationService->getOne($organization_id);
            if (!$organization) {
                return $this->responseService->json(false, 'Organization not found.');
            }

            // Check if user with this email exists
            $invitedUser = $this->userRepository->findOneBy(['email' => $data['email']]);
            
            if ($invitedUser) {
                // Check if user is already a member of the team
                $existingTeamMember = $this->userTeamService->isUserInTeam($invitedUser, $team);
                if ($existingTeamMember) {
                    return $this->responseService->json(false, 'User is already a member of this team.');
                }
            }
            
            // Create invitation using the invitation service
            // Note: For team invitations, we pass the team as the 4th parameter
            $invitation = $this->invitationService->sendInvitation(
                $data['email'],
                $user,  
                $organization,
                $team,   // Pass the team for team invitations
                $data['role'] ?? 'member'
            );
            
            // Send email notification
            /*
            $this->emailService->send(
                $data['email'],
                'invitation',
                [
                    // Target details
                    'target_name' => $team->getName(),
                    'target_type' => 'team',
                    'is_team_invitation' => true,
                    'organization_name' => $organization->getName(),
                    'team_name' => $team->getName(),
                    
                    // Role info
                    'role' => $data['role'] ?? 'member',
                    'role_display' => ucfirst($data['role'] ?? 'member'),
                    'article' => ($data['role'] ?? 'member') === 'admin' ? 'an' : 'a',
                    
                    // Inviter info
                    'inviter_name' => $user->getName(),
                    'inviter_email' => $user->getEmail(),
                    
                    // User status - differentiate email content
                    'existing_user' => $invitedUser ? true : false,
                    'invitee_name' => $invitedUser ? $invitedUser->getName() : 'there',
                    
                    // Invitation token for accepting
                    'invitation_token' => $invitation->getToken()
                ]
            );
            */
            
            return $this->responseService->json(
                true, 
                'Invitation sent successfully!',
                [
                    'email' => $data['email'], 
                    'invitation_id' => $invitation->getId()
                ]
            );

        } catch (\Exception $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 500);
        }
    }


}