<?php
// src/Plugins/Workflows/Service/WorkflowEngine.php

namespace App\Plugins\Workflows\Service;

use App\Plugins\Workflows\Entity\WorkflowEntity;
use App\Plugins\Workflows\Entity\WorkflowExecutionEntity;
use App\Service\CrudManager;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class WorkflowEngine
{
    private WorkflowRegistry $registry;
    private CrudManager $crudManager;
    private EntityManagerInterface $entityManager;
    private LoggerInterface $logger;

    public function __construct(
        WorkflowRegistry $registry,
        CrudManager $crudManager,
        EntityManagerInterface $entityManager,
        LoggerInterface $logger
    ) {
        $this->registry = $registry;
        $this->crudManager = $crudManager;
        $this->entityManager = $entityManager;
        $this->logger = $logger;
    }

    /**
     * Execute a workflow with the given trigger data
     */
    public function execute(WorkflowEntity $workflow, string $triggerType, array $triggerData): void
    {
        // Create execution record
        $execution = new WorkflowExecutionEntity();
        $execution->setWorkflow($workflow);
        $execution->setTriggerData($triggerData);
        $execution->setStatus('running');
        
        $this->entityManager->persist($execution);
        $this->entityManager->flush();

        try {
            // Initialize context with trigger data
            $context = $triggerData;
            
            // Get the first node (connected to trigger)
            $firstConnection = $this->getConnectionsFromNode($workflow, null);
            if (empty($firstConnection)) {
                throw new \Exception('No starting node found in workflow');
            }

            // Execute nodes recursively
            $this->executeNode($workflow, $firstConnection[0]->getToNode(), $context, $execution);

            // Mark execution as completed
            $execution->setStatus('completed');
            $execution->setCompletedAt(new \DateTime());
        } catch (\Exception $e) {
            $this->logger->error('Workflow execution failed', [
                'workflow_id' => $workflow->getId(),
                'error' => $e->getMessage()
            ]);

            $execution->setStatus('failed');
            $execution->setError($e->getMessage());
            $execution->setCompletedAt(new \DateTime());
        }

        $this->entityManager->persist($execution);
        $this->entityManager->flush();
    }

    /**
     * Execute a single node and continue to next nodes
     */
    private function executeNode($workflow, $node, array &$context, $execution): void
    {
        if (!$node) {
            return;
        }

        $this->logger->info('Executing node', [
            'workflow_id' => $workflow->getId(),
            'node_id' => $node->getId(),
            'node_type' => $node->getNodeType()
        ]);

        // Update execution context
        $execution->setContext($context);
        $this->entityManager->persist($execution);
        $this->entityManager->flush();

        if ($node->getNodeType() === 'action') {
            // Execute action
            $this->executeAction($node, $context);
            
            // Continue to next node
            $connections = $this->getConnectionsFromNode($workflow, $node);
            foreach ($connections as $connection) {
                $this->executeNode($workflow, $connection->getToNode(), $context, $execution);
            }
        } elseif ($node->getNodeType() === 'condition') {
            // Evaluate condition
            $result = $this->evaluateCondition($node, $context);
            
            // Get connections based on condition result
            $connections = $this->getConnectionsFromNode($workflow, $node);
            foreach ($connections as $connection) {
                $conditionType = $connection->getConditionType();
                
                // Execute path based on condition result
                if (($result && $conditionType === 'true') || 
                    (!$result && $conditionType === 'false') ||
                    ($conditionType === null)) {
                    $this->executeNode($workflow, $connection->getToNode(), $context, $execution);
                }
            }
        }
    }

    /**
     * Execute an action node
     */
    private function executeAction($node, array &$context): void
    {
        $actionType = $node->getActionType();
        $action = $this->registry->getAction($actionType);
        
        if (!$action) {
            throw new \Exception("Unknown action type: {$actionType}");
        }

        // Validate configuration
        $errors = $action->validate($node->getConfig());
        if (!empty($errors)) {
            throw new \Exception("Invalid action configuration: " . implode(', ', $errors));
        }

        // Execute action
        $result = $action->execute($node->getConfig(), $context);
        
        // Store action result in context
        $context['actions'][$node->getId()] = $result;
        
        $this->logger->info('Action executed', [
            'node_id' => $node->getId(),
            'action_type' => $actionType,
            'result' => $result
        ]);
    }

    /**
     * Evaluate a condition node
     */
    private function evaluateCondition($node, array $context): bool
    {
        $config = $node->getConfig();
        
        // Simple condition evaluation
        if (isset($config['field']) && isset($config['operator']) && isset($config['value'])) {
            $fieldValue = $this->getNestedValue($context, $config['field']);
            $compareValue = $config['value'];
            
            switch ($config['operator']) {
                case 'equals':
                    return $fieldValue == $compareValue;
                case 'not_equals':
                    return $fieldValue != $compareValue;
                case 'contains':
                    return strpos($fieldValue, $compareValue) !== false;
                case 'greater_than':
                    return $fieldValue > $compareValue;
                case 'less_than':
                    return $fieldValue < $compareValue;
                case 'is_empty':
                    return empty($fieldValue);
                case 'is_not_empty':
                    return !empty($fieldValue);
                default:
                    return false;
            }
        }
        
        return false;
    }

    /**
     * Get connections from a specific node
     */
    private function getConnectionsFromNode($workflow, $fromNode): array
    {
        $fromNodeId = $fromNode ? $fromNode->getId() : null;
        
        return $this->crudManager->findMany(
            'App\Plugins\Workflows\Entity\WorkflowConnectionEntity',
            [],
            1,
            100,
            [
                'workflow' => $workflow,
                'fromNode' => $fromNode
            ]
        );
    }

    /**
     * Get nested value from array using dot notation
     */
    private function getNestedValue(array $data, string $path)
    {
        $keys = explode('.', $path);
        $value = $data;

        foreach ($keys as $key) {
            if (is_array($value) && isset($value[$key])) {
                $value = $value[$key];
            } else {
                return null;
            }
        }

        return $value;
    }
}