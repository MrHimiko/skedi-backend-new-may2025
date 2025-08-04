<?php
// src/Plugins/Workflows/Interface/TriggerInterface.php

namespace App\Plugins\Workflows\Interface;

interface TriggerInterface
{
    public function getId(): string;
    public function getName(): string;
    public function getDescription(): string;
    public function getCategory(): string;
    public function getVariables(): array;
    public function getConfigSchema(): array;
    public function shouldFire($event, array $config): bool;
    public function extractData($event): array;
}

// src/Plugins/Workflows/Interface/ActionInterface.php

namespace App\Plugins\Workflows\Interface;

interface ActionInterface
{
    public function getId(): string;
    public function getName(): string;
    public function getDescription(): string;
    public function getCategory(): string;
    public function getIcon(): string;
    public function getConfigSchema(): array;
    public function execute(array $config, array $context): array;
    public function validate(array $config): array;
}

// src/Plugins/Workflows/Interface/WorkflowProviderInterface.php

namespace App\Plugins\Workflows\Interface;

use App\Plugins\Workflows\Service\WorkflowRegistry;

interface WorkflowProviderInterface
{
    public function register(WorkflowRegistry $registry): void;
}