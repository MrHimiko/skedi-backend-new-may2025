<?php
// src/Plugins/Workflows/Service/WorkflowService.php

namespace App\Plugins\Workflows\Service;

use App\Service\CrudManager;
use App\Exception\CrudException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\Constraints as Assert;

use App\Plugins\Workflows\Entity\WorkflowEntity;
use App\Plugins\Workflows\Entity\WorkflowNodeEntity;
use App\Plugins\Workflows\Entity\WorkflowConnectionEntity;
use App\Plugins\Workflows\Exception\WorkflowsException;
use App\Plugins\Organizations\Entity\OrganizationEntity;
use App\Plugins\Account\Entity\UserEntity;

class WorkflowService
{
    private CrudManager $crudManager;
    private EntityManagerInterface $entityManager;

    public function __construct(
        CrudManager $crudManager,
        EntityManagerInterface $entityManager
    ) {
        $this->crudManager = $crudManager;
        $this->entityManager = $entityManager;
    }

    public function getMany(array $filters, int $page, int $limit, array $criteria = [], ?callable $callback = null, bool $count = false): array
    {
        try {
            return $this->crudManager->findMany(WorkflowEntity::class, $filters, $page, $limit, $criteria, $callback, $count);
        } catch (CrudException $e) {
            throw new WorkflowsException($e->getMessage());
        }
    }

    public function getOne(int $id, array $criteria = []): ?WorkflowEntity
    {
        return $this->crudManager->findOne(WorkflowEntity::class, $id, $criteria);
    }

    public function create(array $data, OrganizationEntity $organization, UserEntity $user): WorkflowEntity
    {
        try {
            $workflow = new WorkflowEntity();
            $workflow->setOrganization($organization);
            $workflow->setCreatedBy($user);

            $this->crudManager->create(
                $workflow,
                $data,
                [
                    'name' => [
                        new Assert\NotBlank(),
                        new Assert\Type('string'),
                        new Assert\Length(['min' => 2, 'max' => 255]),
                    ],
                    'description' => new Assert\Optional([
                        new Assert\Type('string'),
                    ]),
                    'trigger_type' => [
                        new Assert\NotBlank(),
                        new Assert\Type('string'),
                        new Assert\Choice([
                            'choices' => $this->getAvailableTriggers(),
                        ]),
                    ],
                    'trigger_config' => new Assert\Optional([
                        new Assert\Type('array'),
                    ]),
                    'status' => new Assert\Optional([
                        new Assert\Choice(['choices' => ['active', 'inactive', 'draft']]),
                    ]),
                ]
            );

            return $workflow;
        } catch (CrudException $e) {
            throw new WorkflowsException($e->getMessage());
        }
    }

    public function update(WorkflowEntity $workflow, array $data): void
    {
        try {
            $this->crudManager->update(
                $workflow,
                $data,
                [
                    'name' => new Assert\Optional([
                        new Assert\Type('string'),
                        new Assert\Length(['min' => 2, 'max' => 255]),
                    ]),
                    'description' => new Assert\Optional([
                        new Assert\Type('string'),
                    ]),
                    'trigger_type' => new Assert\Optional([
                        new Assert\Type('string'),
                        new Assert\Choice([
                            'choices' => $this->getAvailableTriggers(),
                        ]),
                    ]),
                    'trigger_config' => new Assert\Optional([
                        new Assert\Type('array'),
                    ]),
                    'status' => new Assert\Optional([
                        new Assert\Choice(['choices' => ['active', 'inactive', 'draft']]),
                    ]),
                ]
            );
        } catch (CrudException $e) {
            throw new WorkflowsException($e->getMessage());
        }
    }

    public function delete(WorkflowEntity $workflow): void
    {
        try {
            $this->crudManager->delete($workflow);
        } catch (CrudException $e) {
            throw new WorkflowsException($e->getMessage());
        }
    }

    public function getWorkflowsByOrganization(OrganizationEntity $organization): array
    {
        return $this->crudManager->findMany(
            WorkflowEntity::class,
            [],
            1,
            1000,
            [
                'organization' => $organization,
                'deleted' => false
            ]
        );
    }

    public function getActiveWorkflowsForTrigger(string $triggerType, OrganizationEntity $organization): array
    {
        return $this->crudManager->findMany(
            WorkflowEntity::class,
            [],
            1,
            1000,
            [
                'organization' => $organization,
                'triggerType' => $triggerType,
                'status' => 'active',
                'deleted' => false
            ]
        );
    }

    // Node management
    public function createNode(WorkflowEntity $workflow, array $data): WorkflowNodeEntity
    {
        try {
            $node = new WorkflowNodeEntity();
            $node->setWorkflow($workflow);
            
            // Prepare clean data for CrudManager (remove position data)
            $cleanData = [];
            if (isset($data['node_type'])) {
                $cleanData['nodeType'] = $data['node_type'];
            }
            if (isset($data['action_type'])) {
                $cleanData['actionType'] = $data['action_type'];
            }
            if (isset($data['name'])) {
                $cleanData['name'] = $data['name'];
            }
            if (isset($data['config'])) {
                $cleanData['config'] = $data['config'];
            }

            // Set position manually
            if (isset($data['position_x'])) {
                $node->setPositionX($data['position_x']);
            }
            if (isset($data['position_y'])) {
                $node->setPositionY($data['position_y']);
            }

            $this->crudManager->create(
                $node,
                $cleanData,
                [
                    'nodeType' => [
                        new Assert\NotBlank(),
                        new Assert\Choice(['choices' => ['trigger', 'action', 'condition']]),
                    ],
                    'actionType' => new Assert\Optional([
                        new Assert\Type('string'),
                    ]),
                    'name' => new Assert\Optional([
                        new Assert\Type('string'),
                    ]),
                    'config' => new Assert\Optional([
                        new Assert\Type('array'),
                    ]),
                ]
            );

            return $node;
        } catch (CrudException $e) {
            throw new WorkflowsException($e->getMessage());
        }
    }

    public function updateNode(WorkflowNodeEntity $node, array $data): void
    {
        try {
            // Prepare clean data for CrudManager
            $cleanData = [];
            if (isset($data['name'])) {
                $cleanData['name'] = $data['name'];
            }
            if (isset($data['config'])) {
                $cleanData['config'] = $data['config'];
            }
            if (isset($data['position_x'])) {
                $cleanData['positionX'] = $data['position_x'];
            }
            if (isset($data['position_y'])) {
                $cleanData['positionY'] = $data['position_y'];
            }

            $this->crudManager->update(
                $node,
                $cleanData,
                [
                    'name' => new Assert\Optional([
                        new Assert\Type('string'),
                    ]),
                    'config' => new Assert\Optional([
                        new Assert\Type('array'),
                    ]),
                    'positionX' => new Assert\Optional([
                        new Assert\Type('integer'),
                    ]),
                    'positionY' => new Assert\Optional([
                        new Assert\Type('integer'),
                    ]),
                ]
            );
        } catch (CrudException $e) {
            throw new WorkflowsException($e->getMessage());
        }
    }

    public function deleteNode(WorkflowNodeEntity $node): void
    {
        try {
            $this->crudManager->delete($node);
        } catch (CrudException $e) {
            throw new WorkflowsException($e->getMessage());
        }
    }

    // Connection management
    public function createConnection(WorkflowEntity $workflow, array $data): WorkflowConnectionEntity
    {
        try {
            $connection = new WorkflowConnectionEntity();
            $connection->setWorkflow($workflow);

            // Set nodes manually
            if (!empty($data['from_node_id'])) {
                $fromNode = $this->crudManager->findOne(WorkflowNodeEntity::class, $data['from_node_id']);
                if (!$fromNode) {
                    throw new WorkflowsException('From node not found');
                }
                $connection->setFromNode($fromNode);
            }

            if (empty($data['to_node_id'])) {
                throw new WorkflowsException('To node ID is required');
            }

            $toNode = $this->crudManager->findOne(WorkflowNodeEntity::class, $data['to_node_id']);
            if (!$toNode) {
                throw new WorkflowsException('To node not found');
            }
            $connection->setToNode($toNode);

            // Prepare clean data for CrudManager
            $cleanData = [];
            if (isset($data['condition_type'])) {
                $cleanData['conditionType'] = $data['condition_type'];
            }
            if (isset($data['priority'])) {
                $cleanData['priority'] = $data['priority'];
            }

            $this->crudManager->create(
                $connection,
                $cleanData,
                [
                    'conditionType' => new Assert\Optional([
                        new Assert\Choice(['choices' => ['true', 'false', null]]),
                    ]),
                    'priority' => new Assert\Optional([
                        new Assert\Type('integer'),
                    ]),
                ]
            );

            return $connection;
        } catch (CrudException $e) {
            throw new WorkflowsException($e->getMessage());
        }
    }

    public function deleteConnection(WorkflowConnectionEntity $connection): void
    {
        try {
            $this->crudManager->delete($connection);
        } catch (CrudException $e) {
            throw new WorkflowsException($e->getMessage());
        }
    }

    // Get available triggers (this will be extended by integrations)
    private function getAvailableTriggers(): array
    {
        return [
            'booking.created',
            'booking.confirmed',
            'booking.cancelled',
            'booking.reminder',
            'event.created',
            'event.updated',
            'event.deleted',
        ];
    }

    /**
     * Get all nodes for a workflow
     */
    public function getNodesByWorkflow(WorkflowEntity $workflow): array
    {
        try {
            return $this->crudManager->findMany(
                WorkflowNodeEntity::class,
                [],
                1,
                1000,
                [
                    'workflow' => $workflow,
                    'deleted' => false
                ]
            );
        } catch (CrudException $e) {
            throw new WorkflowsException($e->getMessage());
        }
    }

    /**
     * Get all connections for a workflow
     */
    public function getConnectionsByWorkflow(WorkflowEntity $workflow): array
    {
        try {
            return $this->crudManager->findMany(
                WorkflowConnectionEntity::class,
                [],
                1,
                1000,
                [
                    'workflow' => $workflow
                ]
            );
        } catch (CrudException $e) {
            throw new WorkflowsException($e->getMessage());
        }
    }

    /**
     * Get a single node by ID
     */
    public function getNode(int $id): ?WorkflowNodeEntity
    {
        return $this->crudManager->findOne(WorkflowNodeEntity::class, $id);
    }

    /**
     * Get a single connection by ID
     */
    public function getConnection(int $id): ?WorkflowConnectionEntity
    {
        return $this->crudManager->findOne(WorkflowConnectionEntity::class, $id);
    }
}