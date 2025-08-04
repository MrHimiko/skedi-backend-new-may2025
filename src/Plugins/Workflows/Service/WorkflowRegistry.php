<?php
// src/Plugins/Workflows/Service/WorkflowRegistry.php

namespace App\Plugins\Workflows\Service;

use App\Plugins\Workflows\Interface\TriggerInterface;
use App\Plugins\Workflows\Interface\ActionInterface;

class WorkflowRegistry
{
    private array $triggers = [];
    private array $actions = [];

    public function addTrigger(TriggerInterface $trigger): void
    {
        $this->triggers[$trigger->getId()] = $trigger;
    }

    public function addAction(ActionInterface $action): void
    {
        $this->actions[$action->getId()] = $action;
    }

    public function getTrigger(string $id): ?TriggerInterface
    {
        return $this->triggers[$id] ?? null;
    }

    public function getAction(string $id): ?ActionInterface
    {
        return $this->actions[$id] ?? null;
    }

    public function getAllTriggers(): array
    {
        return $this->triggers;
    }

    public function getAllActions(): array
    {
        return $this->actions;
    }

    public function getTriggersByCategory(string $category): array
    {
        return array_filter($this->triggers, fn($trigger) => $trigger->getCategory() === $category);
    }

    public function getActionsByCategory(string $category): array
    {
        return array_filter($this->actions, fn($action) => $action->getCategory() === $category);
    }

    public function getTriggersAsArray(): array
    {
        return array_map(fn($trigger) => [
            'id' => $trigger->getId(),
            'name' => $trigger->getName(),
            'description' => $trigger->getDescription(),
            'category' => $trigger->getCategory(),
            'variables' => $trigger->getVariables(),
            'config_schema' => $trigger->getConfigSchema(),
        ], $this->triggers);
    }

    public function getActionsAsArray(): array
    {
        return array_map(fn($action) => [
            'id' => $action->getId(),
            'name' => $action->getName(),
            'description' => $action->getDescription(),
            'category' => $action->getCategory(),
            'icon' => $action->getIcon(),
            'config_schema' => $action->getConfigSchema(),
        ], $this->actions);
    }
}