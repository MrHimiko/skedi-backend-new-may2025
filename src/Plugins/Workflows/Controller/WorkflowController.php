<?php
// src/Plugins/Workflows/Controller/WorkflowController.php

namespace App\Plugins\Workflows\Controller;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Request;
use App\Service\ResponseService;
use App\Plugins\Workflows\Service\WorkflowService;
use App\Plugins\Organizations\Service\OrganizationService;
use App\Plugins\Workflows\Exception\WorkflowsException;
use Doctrine\ORM\EntityManagerInterface;

#[Route('/api/user/workflows')]
class WorkflowController extends AbstractController
{
    private ResponseService $responseService;
    private WorkflowService $workflowService;
    private OrganizationService $organizationService;
    private EntityManagerInterface $entityManager;

    public function __construct(
        ResponseService $responseService,
        WorkflowService $workflowService,
        OrganizationService $organizationService,
        EntityManagerInterface $entityManager
    ) {
        $this->responseService = $responseService;
        $this->workflowService = $workflowService;
        $this->organizationService = $organizationService;
        $this->entityManager = $entityManager;
    }

    #[Route('', name: 'workflows_list#', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        try {
            $user = $request->attributes->get('user');
            $organizationId = $request->query->get('organization_id');
            
            if (!$organizationId) {
                return $this->responseService->json(false, 'Organization ID is required', null, 400);
            }

            $organization = $this->organizationService->getOne($organizationId);
            if (!$organization) {
                return $this->responseService->json(false, 'Organization not found', null, 404);
            }

            // TODO: Check user permissions for organization

            $page = max(1, (int)$request->query->get('page', 1));
            $limit = min(100, max(10, (int)$request->query->get('limit', 50)));

            $workflows = $this->workflowService->getWorkflowsByOrganization($organization);

            return $this->responseService->json(true, 'Workflows retrieved successfully', [
                'data' => array_map(fn($w) => $w->toArray(), $workflows),
                'total' => count($workflows),
                'page' => $page,
                'limit' => $limit
            ]);
        } catch (WorkflowsException $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 400);
        } catch (\Exception $e) {
            return $this->responseService->json(false, 'An error occurred', null, 500);
        }
    }

    #[Route('/available-triggers', name: 'workflow_triggers#', methods: ['GET'])]
    public function getAvailableTriggers(): JsonResponse
    {
        try {
            // This will be extended by the registry system
            $triggers = [
                [
                    'id' => 'booking.created',
                    'name' => 'Booking Created',
                    'description' => 'Triggered when a new booking is created',
                    'category' => 'booking',
                    'variables' => [
                        'booking.id',
                        'booking.start_time',
                        'booking.end_time',
                        'booking.customer_name',
                        'booking.customer_email',
                        'event.name',
                        'event.duration',
                    ]
                ],
                [
                    'id' => 'booking.cancelled',
                    'name' => 'Booking Cancelled',
                    'description' => 'Triggered when a booking is cancelled',
                    'category' => 'booking',
                    'variables' => [
                        'booking.id',
                        'booking.customer_name',
                        'booking.customer_email',
                        'booking.cancellation_reason',
                    ]
                ],
                [
                    'id' => 'booking.reminder',
                    'name' => 'Booking Reminder',
                    'description' => 'Triggered X minutes before booking starts',
                    'category' => 'booking',
                    'config_schema' => [
                        'minutes_before' => [
                            'type' => 'integer',
                            'label' => 'Minutes before event',
                            'default' => 60,
                            'min' => 5,
                            'max' => 10080
                        ]
                    ]
                ]
            ];

            return $this->responseService->json(true, 'Available triggers', $triggers);
        } catch (\Exception $e) {
            return $this->responseService->json(false, 'An error occurred', null, 500);
        }
    }

    #[Route('/available-actions', name: 'workflow_actions#', methods: ['GET'])]
    public function getAvailableActions(): JsonResponse
    {
        try {
            // This will be extended by the registry system
            $actions = [
                [
                    'id' => 'email.send',
                    'name' => 'Send Email',
                    'description' => 'Send an email to specified recipients',
                    'category' => 'communication',
                    'icon' => 'PhEnvelope',
                    'config_schema' => [
                        'to' => [
                            'type' => 'string',
                            'label' => 'To Email',
                            'placeholder' => '{{booking.customer_email}}',
                            'required' => true
                        ],
                        'subject' => [
                            'type' => 'string',
                            'label' => 'Subject',
                            'required' => true
                        ],
                        'body' => [
                            'type' => 'textarea',
                            'label' => 'Email Body',
                            'required' => true,
                            'rows' => 10
                        ]
                    ]
                ],
                [
                    'id' => 'webhook.send',
                    'name' => 'Send Webhook',
                    'description' => 'Send data to an external URL',
                    'category' => 'integration',
                    'icon' => 'PhWebhooksLogo',
                    'config_schema' => [
                        'url' => [
                            'type' => 'string',
                            'label' => 'Webhook URL',
                            'placeholder' => 'https://example.com/webhook',
                            'required' => true
                        ],
                        'method' => [
                            'type' => 'select',
                            'label' => 'HTTP Method',
                            'options' => ['POST', 'PUT', 'PATCH'],
                            'default' => 'POST',
                            'required' => true
                        ],
                        'headers' => [
                            'type' => 'json',
                            'label' => 'Headers (JSON)',
                            'placeholder' => '{"Authorization": "Bearer token"}'
                        ],
                        'body' => [
                            'type' => 'json',
                            'label' => 'Body (JSON)',
                            'placeholder' => '{"booking_id": "{{booking.id}}"}'
                        ]
                    ]
                ]
            ];

            return $this->responseService->json(true, 'Available actions', $actions);
        } catch (\Exception $e) {
            return $this->responseService->json(false, 'An error occurred', null, 500);
        }
    }

    #[Route('/{id}', name: 'workflow_get#', methods: ['GET'])]
    public function get(int $id, Request $request): JsonResponse
    {
        try {
            $user = $request->attributes->get('user');
            $workflow = $this->workflowService->getOne($id);
            
            if (!$workflow || $workflow->getDeleted()) {
                return $this->responseService->json(false, 'Workflow not found', null, 404);
            }

            // TODO: Check user permissions

            // Get nodes and connections
            $nodes = $this->workflowService->getNodesByWorkflow($workflow);
            $connections = $this->workflowService->getConnectionsByWorkflow($workflow);

            $data = $workflow->toArray();
            $data['nodes'] = array_map(fn($n) => $n->toArray(), $nodes);
            $data['connections'] = array_map(fn($c) => $c->toArray(), $connections);

            return $this->responseService->json(true, 'Workflow retrieved successfully', $data);
        } catch (WorkflowsException $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 400);
        } catch (\Exception $e) {
            return $this->responseService->json(false, 'An error occurred', null, 500);
        }
    }

    #[Route('', name: 'workflow_create#', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        try {
            $user = $request->attributes->get('user');
            $data = $request->attributes->get('data');

            if (!isset($data['organization_id'])) {
                return $this->responseService->json(false, 'Organization ID is required', null, 400);
            }

            $organizationId = $data['organization_id'];
            unset($data['organization_id']); // Remove it from data before passing to create

            $organization = $this->organizationService->getOne($organizationId);
            
            if (!$organization) {
                return $this->responseService->json(false, 'Organization not found', null, 404);
            }

            // TODO: Check user permissions

            // Set default values if not provided
            if (!isset($data['trigger_type'])) {
                $data['trigger_type'] = 'booking.created';
            }
            if (!isset($data['trigger_config'])) {
                $data['trigger_config'] = [];
            }
            if (!isset($data['status'])) {
                $data['status'] = 'draft';
            }
            
            // Create workflow entity and set values directly
            $workflow = new \App\Plugins\Workflows\Entity\WorkflowEntity();
            $workflow->setOrganization($organization);
            $workflow->setCreatedBy($user);
            $workflow->setName($data['name']);
            $workflow->setTriggerType($data['trigger_type']);
            $workflow->setTriggerConfig($data['trigger_config']);
            $workflow->setStatus($data['status']);
            if (isset($data['description'])) {
                $workflow->setDescription($data['description']);
            }
            
            // Persist the workflow
            $this->entityManager->persist($workflow);
            $this->entityManager->flush();

            return $this->responseService->json(true, 'Workflow created successfully', $workflow->toArray(), 201);
        } catch (WorkflowsException $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 400);
        } catch (\Exception $e) {
            return $this->responseService->json(false, 'An error occurred', null, 500);
        }
    }

    #[Route('/{id}', name: 'workflow_update#', methods: ['PUT', 'PATCH'])]
    public function update(int $id, Request $request): JsonResponse
    {
        try {
            $user = $request->attributes->get('user');
            $data = $request->attributes->get('data');
            $workflow = $this->workflowService->getOne($id);
            
            if (!$workflow || $workflow->getDeleted()) {
                return $this->responseService->json(false, 'Workflow not found', null, 404);
            }

            // TODO: Check user permissions

            $this->workflowService->update($workflow, $data);

            return $this->responseService->json(true, 'Workflow updated successfully', $workflow->toArray());
        } catch (WorkflowsException $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 400);
        } catch (\Exception $e) {
            return $this->responseService->json(false, 'An error occurred', null, 500);
        }
    }

    #[Route('/{id}', name: 'workflow_delete#', methods: ['DELETE'])]
    public function delete(int $id, Request $request): JsonResponse
    {
        try {
            $user = $request->attributes->get('user');
            $workflow = $this->workflowService->getOne($id);
            
            if (!$workflow || $workflow->getDeleted()) {
                return $this->responseService->json(false, 'Workflow not found', null, 404);
            }

            // TODO: Check user permissions

            $this->workflowService->delete($workflow);

            return $this->responseService->json(true, 'Workflow deleted successfully');
        } catch (WorkflowsException $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 400);
        } catch (\Exception $e) {
            return $this->responseService->json(false, 'An error occurred', null, 500);
        }
    }

    #[Route('/{id}/nodes', name: 'workflow_node_create#', methods: ['POST'])]
    public function createNode(int $id, Request $request): JsonResponse
    {
        try {
            $user = $request->attributes->get('user');
            $data = $request->attributes->get('data');
            $workflow = $this->workflowService->getOne($id);
            
            if (!$workflow || $workflow->getDeleted()) {
                return $this->responseService->json(false, 'Workflow not found', null, 404);
            }

            // TODO: Check user permissions

            $node = $this->workflowService->createNode($workflow, $data);

            return $this->responseService->json(true, 'Node created successfully', $node->toArray(), 201);
        } catch (WorkflowsException $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 400);
        } catch (\Exception $e) {
            return $this->responseService->json(false, 'An error occurred', null, 500);
        }
    }

    #[Route('/nodes/{nodeId}', name: 'workflow_node_update#', methods: ['PUT', 'PATCH'])]
    public function updateNode(int $nodeId, Request $request): JsonResponse
    {
        try {
            $user = $request->attributes->get('user');
            $data = $request->attributes->get('data');
            $node = $this->workflowService->getNode($nodeId); // Fixed: using getNode instead of getOne
            
            if (!$node) {
                return $this->responseService->json(false, 'Node not found', null, 404);
            }

            // TODO: Check user permissions

            $this->workflowService->updateNode($node, $data);

            return $this->responseService->json(true, 'Node updated successfully', $node->toArray());
        } catch (WorkflowsException $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 400);
        } catch (\Exception $e) {
            return $this->responseService->json(false, 'An error occurred', null, 500);
        }
    }

    #[Route('/nodes/{nodeId}', name: 'workflow_node_delete#', methods: ['DELETE'])]
    public function deleteNode(int $nodeId, Request $request): JsonResponse
    {
        try {
            $user = $request->attributes->get('user');
            $node = $this->workflowService->getNode($nodeId); // Fixed: using getNode instead of getOne
            
            if (!$node) {
                return $this->responseService->json(false, 'Node not found', null, 404);
            }

            // TODO: Check user permissions

            $this->workflowService->deleteNode($node);

            return $this->responseService->json(true, 'Node deleted successfully');
        } catch (WorkflowsException $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 400);
        } catch (\Exception $e) {
            return $this->responseService->json(false, 'An error occurred', null, 500);
        }
    }

    #[Route('/{id}/connections', name: 'workflow_connection_create#', methods: ['POST'])]
    public function createConnection(int $id, Request $request): JsonResponse
    {
        try {
            $user = $request->attributes->get('user');
            $data = $request->attributes->get('data');
            $workflow = $this->workflowService->getOne($id);
            
            if (!$workflow || $workflow->getDeleted()) {
                return $this->responseService->json(false, 'Workflow not found', null, 404);
            }

            // TODO: Check user permissions

            $connection = $this->workflowService->createConnection($workflow, $data);

            return $this->responseService->json(true, 'Connection created successfully', $connection->toArray(), 201);
        } catch (WorkflowsException $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 400);
        } catch (\Exception $e) {
            return $this->responseService->json(false, 'An error occurred', null, 500);
        }
    }

    #[Route('/connections/{connectionId}', name: 'workflow_connection_delete#', methods: ['DELETE'])]
    public function deleteConnection(int $connectionId, Request $request): JsonResponse
    {
        try {
            $user = $request->attributes->get('user');
            $connection = $this->workflowService->getConnection($connectionId); // Fixed: using getConnection instead of getOne
            
            if (!$connection) {
                return $this->responseService->json(false, 'Connection not found', null, 404);
            }

            // TODO: Check user permissions

            $this->workflowService->deleteConnection($connection);

            return $this->responseService->json(true, 'Connection deleted successfully');
        } catch (WorkflowsException $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 400);
        } catch (\Exception $e) {
            return $this->responseService->json(false, 'An error occurred', null, 500);
        }
    }

    #[Route('/{id}/test', name: 'workflow_test#', methods: ['POST'])]
    public function test(int $id, Request $request): JsonResponse
    {
        try {
            $user = $request->attributes->get('user');
            $workflow = $this->workflowService->getOne($id);
            
            if (!$workflow || $workflow->getDeleted()) {
                return $this->responseService->json(false, 'Workflow not found', null, 404);
            }

            // TODO: Check user permissions
            // TODO: Implement test execution

            return $this->responseService->json(true, 'Workflow test executed successfully');
        } catch (WorkflowsException $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 400);
        } catch (\Exception $e) {
            return $this->responseService->json(false, 'An error occurred', null, 500);
        }
    }
}