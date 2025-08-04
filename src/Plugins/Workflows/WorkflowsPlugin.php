<?php


namespace App\Plugins\Workflows;

use App\Plugins\Workflows\Service\WorkflowRegistry;
use App\Plugins\Workflows\Trigger\BookingCreatedTrigger;
use App\Plugins\Workflows\Trigger\BookingCancelledTrigger;
use App\Plugins\Workflows\Action\SendEmailAction;
use App\Plugins\Workflows\Action\SendWebhookAction;

class WorkflowsPlugin
{
    private WorkflowRegistry $registry;

    public function __construct(WorkflowRegistry $registry)
    {
        $this->registry = $registry;
    }

    public function register(): void
    {
        // Register built-in triggers
        $this->registry->addTrigger(new BookingCreatedTrigger());
        $this->registry->addTrigger(new BookingCancelledTrigger());
        
        // Register built-in actions
        // Note: These need to be created with dependency injection in services.yaml
    }
}