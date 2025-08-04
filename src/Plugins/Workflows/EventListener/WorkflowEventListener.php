<?php
// src/Plugins/Workflows/EventListener/WorkflowEventListener.php

namespace App\Plugins\Workflows\EventListener;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use App\Plugins\Workflows\Service\WorkflowService;
use App\Plugins\Workflows\Service\WorkflowEngine;
use App\Plugins\Workflows\Service\WorkflowRegistry;
use App\Plugins\Events\Event\BookingCreatedEvent;
use App\Plugins\Events\Event\BookingCancelledEvent;
use App\Plugins\Events\Event\EventCreatedEvent;
use App\Plugins\Events\Event\EventUpdatedEvent;
use Doctrine\ORM\EntityManagerInterface;

class WorkflowEventListener implements EventSubscriberInterface
{
    private WorkflowService $workflowService;
    private WorkflowRegistry $registry;
    private WorkflowEngine $workflowEngine;
    private EntityManagerInterface $entityManager;

    public function __construct(
        WorkflowService $workflowService,
        WorkflowRegistry $registry,
        WorkflowEngine $workflowEngine,
        EntityManagerInterface $entityManager
    ) {
        $this->workflowService = $workflowService;
        $this->registry = $registry;
        $this->workflowEngine = $workflowEngine;
        $this->entityManager = $entityManager;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            BookingCreatedEvent::class => 'onBookingCreated',
            BookingCancelledEvent::class => 'onBookingCancelled',
            EventCreatedEvent::class => 'onEventCreated',
            EventUpdatedEvent::class => 'onEventUpdated',
        ];
    }

    public function onBookingCreated(BookingCreatedEvent $event): void
    {
        $this->handleEvent('booking.created', $event);
    }

    public function onBookingCancelled(BookingCancelledEvent $event): void
    {
        $this->handleEvent('booking.cancelled', $event);
    }

    public function onEventCreated(EventCreatedEvent $event): void
    {
        $this->handleEvent('event.created', $event);
    }

    public function onEventUpdated(EventUpdatedEvent $event): void
    {
        $this->handleEvent('event.updated', $event);
    }

    private function handleEvent(string $triggerType, $event): void
    {
        try {
            // Get the trigger handler
            $trigger = $this->registry->getTrigger($triggerType);
            if (!$trigger) {
                return;
            }

            // Extract data from the event
            $eventData = $trigger->extractData($event);
            
            // Get organization from event data
            $organizationId = $eventData['organization']['id'] ?? null;
            if (!$organizationId) {
                return;
            }

            // Find active workflows for this trigger
            $organization = $this->entityManager->find('App\Plugins\Organizations\Entity\OrganizationEntity', $organizationId);
            if (!$organization) {
                return;
            }
            
            $workflows = $this->workflowService->getActiveWorkflowsForTrigger($triggerType, $organization);

            foreach ($workflows as $workflow) {
                // Check if trigger should fire
                if ($trigger->shouldFire($event, $workflow->getTriggerConfig())) {
                    // Execute workflow directly (synchronously)
                    // In production, you might want to use a job queue system
                    $this->workflowEngine->execute(
                        $workflow,
                        $triggerType,
                        $eventData
                    );
                }
            }
        } catch (\Exception $e) {
            // Log the error but don't throw - we don't want to break the main flow
            error_log('Workflow execution error: ' . $e->getMessage());
        }
    }
}