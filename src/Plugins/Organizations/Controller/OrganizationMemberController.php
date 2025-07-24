<?php

namespace App\Plugins\Organizations\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Request;
use App\Service\ResponseService;
use App\Plugins\Organizations\Service\OrganizationService;
use App\Plugins\Organizations\Service\UserOrganizationService;
use App\Plugins\Account\Service\UserService;
use App\Plugins\Email\Service\EmailService;
use App\Plugins\Organizations\Exception\OrganizationsException;
use Doctrine\ORM\EntityManagerInterface;

#[Route('/api/organizations/{organization_id}', requirements: ['organization_id' => '\d+'])]
class OrganizationMemberController extends AbstractController
{
    private ResponseService $responseService;
    private OrganizationService $organizationService;
    private UserOrganizationService $userOrganizationService;
    private UserService $userService;
    private EmailService $emailService;
    private EntityManagerInterface $entityManager;

    public function __construct(
        ResponseService $responseService,
        OrganizationService $organizationService,
        UserOrganizationService $userOrganizationService,
        UserService $userService,
        EmailService $emailService,
        EntityManagerInterface $entityManager
    ) {
        $this->responseService = $responseService;
        $this->organizationService = $organizationService;
        $this->userOrganizationService = $userOrganizationService;
        $this->userService = $userService;
        $this->emailService = $emailService;
        $this->entityManager = $entityManager;
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
                    'joined' => $member->getCreated()->format('Y-m-d H:i:s')
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
            $invitedUser = $this->userService->getByEmail($data['email']);
            
            if ($invitedUser) {
                // Check if user is already a member
                $existingMember = $this->userOrganizationService->getOrganizationByUser($organization_id, $invitedUser);
                if ($existingMember) {
                    return $this->responseService->json(false, 'User is already a member of this organization.');
                }

                // Add existing user to organization
                $this->userOrganizationService->create($invitedUser, $organization, $data['role'] ?? 'member');
                
                // Send notification email
                $this->emailService->send(
                    $invitedUser->getEmail(),
                    'Organization Invitation',
                    'You have been added to the organization: ' . $organization->getName()
                );
            } else {
                // Create invitation for new user
                // This would require an invitation system - for now, we'll return a message
                return $this->responseService->json(
                    true, 
                    'User not found. Please ask them to create an account first.',
                    ['email' => $data['email']]
                );
            }

            return $this->responseService->json(true, 'Member added successfully.');
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
            $member = $this->userOrganizationService->getById($member_id);
            if (!$member || $member->organization->getId() !== $organization_id) {
                return $this->responseService->json(false, 'Member not found.');
            }

            // Update role if provided
            if (isset($data['role']) && in_array($data['role'], ['admin', 'member'])) {
                $member->role = $data['role'];
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
            $member = $this->userOrganizationService->getById($member_id);
            if (!$member || $member->organization->getId() !== $organization_id) {
                return $this->responseService->json(false, 'Member not found.');
            }

            // Prevent removing the organization creator
            $organization = $this->organizationService->getOne($organization_id);
            if ($member->user->getId() === $organization->getCreatedBy()->getId()) {
                return $this->responseService->json(false, 'Cannot remove the organization creator.');
            }

            // Remove member
            $this->userOrganizationService->delete($member);

            return $this->responseService->json(true, 'Member removed successfully.');
        } catch (\Exception $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 500);
        }
    }
}