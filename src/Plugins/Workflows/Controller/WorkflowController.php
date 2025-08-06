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
            
            $page = max(1, (int)$request->query->get('page', 1));
            $limit = min(100, max(10, (int)$request->query->get('limit', 50)));

            // Get all workflows for the user using CrudManager
            $filters = [
                [
                    'field' => 'deleted',
                    'operator' => 'equals',
                    'value' => false
                ]
            ];
            
            // Get workflows
            $workflows = $this->workflowService->getMany($filters, $page, $limit);
            
            // Get total count
            $total = $this->workflowService->getMany($filters, 1, 1, [], null, true);
            $totalCount = is_array($total) && !empty($total) ? (int)$total[0] : 0;

            // Add organization name to each workflow
            $workflowsData = [];
            foreach ($workflows as $workflow) {
                $workflowData = $workflow->toArray();
                $workflowData['organization_name'] = $workflow->getOrganization()->getName();
                $workflowsData[] = $workflowData;
            }

            return $this->responseService->json(true, 'Workflows retrieved successfully', [
                'data' => $workflowsData,
                'total' => $totalCount,
                'page' => $page,
                'limit' => $limit
            ]);
        } catch (WorkflowsException $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 400);
        } catch (\Exception $e) {
            return $this->responseService->json(false, 'An error occurred: ' . $e->getMessage(), null, 500);
        }
    }

    #[Route('', name: 'workflow_create#', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        try {
            $user = $request->attributes->get('user');
            $data = $request->attributes->get('data');
            
            // Validate organization_id is provided
            if (!isset($data['organization_id'])) {
                return $this->responseService->json(false, 'Organization ID is required', null, 400);
            }

            $organization = $this->organizationService->getOne($data['organization_id']);
            if (!$organization) {
                return $this->responseService->json(false, 'Organization not found', null, 404);
            }

            // TODO: Check user permissions for organization

            $workflow = $this->workflowService->create($data, $organization, $user);

            return $this->responseService->json(true, 'Workflow created successfully', $workflow->toArray(), 201);
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
                    'category' => 'bookings',
                    'variables' => [
                        'booking.id' => 'Booking ID',
                        'booking.customer_name' => 'Customer Name',
                        'booking.customer_email' => 'Customer Email',
                        'booking.date' => 'Booking Date',
                        'booking.time' => 'Booking Time'
                    ],
                    'config_schema' => []
                ],
                [
                    'id' => 'booking.updated',
                    'name' => 'Booking Updated',
                    'description' => 'Triggered when a booking is updated',
                    'category' => 'bookings',
                    'variables' => [
                        'booking.id' => 'Booking ID',
                        'booking.customer_name' => 'Customer Name',
                        'booking.customer_email' => 'Customer Email',
                        'booking.date' => 'Booking Date',
                        'booking.time' => 'Booking Time',
                        'booking.previous_date' => 'Previous Date',
                        'booking.previous_time' => 'Previous Time'
                    ],
                    'config_schema' => []
                ],
                [
                    'id' => 'booking.cancelled',
                    'name' => 'Booking Cancelled',
                    'description' => 'Triggered when a booking is cancelled',
                    'category' => 'bookings',
                    'variables' => [
                        'booking.id' => 'Booking ID',
                        'booking.customer_name' => 'Customer Name',
                        'booking.customer_email' => 'Customer Email',
                        'booking.date' => 'Booking Date',
                        'booking.time' => 'Booking Time',
                        'booking.cancellation_reason' => 'Cancellation Reason'
                    ],
                    'config_schema' => []
                ],
                [
                    'id' => 'time.scheduled',
                    'name' => 'Scheduled Time',
                    'description' => 'Triggered at specific times or intervals',
                    'category' => 'system',
                    'variables' => [
                        'trigger.time' => 'Current Time',
                        'trigger.date' => 'Current Date'
                    ],
                    'config_schema' => [
                        'schedule_type' => [
                            'type' => 'select',
                            'label' => 'Schedule Type',
                            'options' => ['daily', 'weekly', 'monthly'],
                            'required' => true
                        ],
                        'time' => [
                            'type' => 'time',
                            'label' => 'Time',
                            'required' => true
                        ],
                        'days_of_week' => [
                            'type' => 'multiselect',
                            'label' => 'Days of Week',
                            'options' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'],
                            'showIf' => ['schedule_type', 'weekly']
                        ],
                        'day_of_month' => [
                            'type' => 'number',
                            'label' => 'Day of Month',
                            'min' => 1,
                            'max' => 31,
                            'showIf' => ['schedule_type', 'monthly']
                        ]
                    ]
                ],
                [
                    'id' => 'form.submitted',
                    'name' => 'Form Submitted',
                    'description' => 'Triggered when a form is submitted',
                    'category' => 'forms',
                    'variables' => [
                        'form.id' => 'Form ID',
                        'form.name' => 'Form Name',
                        'submission.id' => 'Submission ID',
                        'submission.data' => 'Form Data (JSON)'
                    ],
                    'config_schema' => [
                        'form_id' => [
                            'type' => 'select',
                            'label' => 'Select Form',
                            'options' => [], // Will be populated dynamically
                            'required' => true
                        ]
                    ]
                ],
                [
                    'id' => 'booking.reminder',
                    'name' => 'Booking Reminder',
                    'description' => 'Triggered before a booking starts',
                    'category' => 'bookings',
                    'variables' => [
                        'booking.id' => 'Booking ID',
                        'booking.customer_name' => 'Customer Name',
                        'booking.customer_email' => 'Customer Email',
                        'booking.date' => 'Booking Date',
                        'booking.time' => 'Booking Time',
                        'booking.minutes_until' => 'Minutes Until Booking'
                    ],
                    'config_schema' => [
                        'minutes_before' => [
                            'type' => 'number',
                            'label' => 'Minutes Before Booking',
                            'required' => true,
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

    #[Route('/nodes/{nodeId}', name: 'workflow_node_update#', methods: ['PUT', 'PATCH'])]
    public function updateNode(int $nodeId, Request $request): JsonResponse
    {
        try {
            $user = $request->attributes->get('user');
            $data = $request->attributes->get('data');
            
            $node = $this->workflowService->getNode($nodeId);
            if (!$node) {
                return $this->responseService->json(false, 'Node not found', null, 404);
            }

            // TODO: Check user permissions for workflow

            $this->workflowService->updateNode($node, $data);

            return $this->responseService->json(true, 'Node updated successfully', $node->toArray());
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

            return $this->responseService->json(true, 'Test workflow executed successfully');
        } catch (WorkflowsException $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 400);
        } catch (\Exception $e) {
            return $this->responseService->json(false, 'An error occurred', null, 500);
        }
    }
}