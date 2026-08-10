<?php

declare(strict_types=1);

namespace LemurAse\FormManagement\Infrastructure\Definitions;

use LemurAse\FormManagement\Domain\FieldDefinition;
use LemurAse\FormManagement\Domain\FieldType;
use LemurAse\FormManagement\Domain\GatewayFormDefinitionInterface;

final class MockFormDefinition implements GatewayFormDefinitionInterface
{
    public function provider(): string { return 'mock'; }
    public function label(): string    { return 'Mock Gateway (Simulation)'; }
    public function version(): string  { return '1.0.0'; }

    public function groups(): array
    {
        return [
            ['id' => 'settings', 'title' => 'Simulation Settings', 'order' => 0],
        ];
    }

    public function fields(): array
    {
        return [
            new FieldDefinition(
                name:        'auto_approve',
                type:        FieldType::CHECKBOX,
                label:       'Auto Approve Payments',
                sensitive:   false,
                required:    false,
                helpText:    'Automatically redirect to success URL during checkout simulation.',
                default:     true,
                group:       'settings',
            ),
        ];
    }
}
