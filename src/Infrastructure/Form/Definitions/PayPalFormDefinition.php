<?php

declare(strict_types=1);

namespace LemurAse\Infrastructure\Form\Definitions;

use LemurAse\Domain\Form\FieldDefinition;
use LemurAse\Domain\Form\FieldType;
use LemurAse\Domain\Form\GatewayFormDefinitionInterface;

final class PayPalFormDefinition implements GatewayFormDefinitionInterface
{
    public function provider(): string { return 'paypal'; }
    public function label(): string    { return 'PayPal'; }
    public function version(): string  { return '1.0.0'; }

    public function groups(): array
    {
        return [
            ['id' => 'credentials', 'title' => 'API Credentials', 'order' => 0],
            ['id' => 'settings',    'title' => 'Settings',         'order' => 1],
        ];
    }

    public function fields(): array
    {
        return [
            new FieldDefinition(
                name:        'client_id',
                type:        FieldType::TEXT,
                label:       'Client ID',
                sensitive:   false,
                required:    true,
                placeholder: 'AYourPayPalClientId...',
                helpText:    'Your PayPal REST API Client ID. Found in Developer Dashboard → Apps.',
                validation:  ['maxLength' => 255],
                group:       'credentials',
            ),
            new FieldDefinition(
                name:        'client_secret',
                type:        FieldType::PASSWORD,
                label:       'Client Secret',
                sensitive:   true,
                required:    true,
                placeholder: 'EGYourClientSecret...',
                helpText:    'Your PayPal REST API Client Secret. Never expose this publicly.',
                validation:  ['maxLength' => 255],
                group:       'credentials',
            ),
            new FieldDefinition(
                name:        'webhook_id',
                type:        FieldType::TEXT,
                label:       'Webhook ID',
                sensitive:   true,
                required:    true,
                placeholder: '1AB23456CD789012E',
                helpText:    'Found in PayPal Developer Dashboard → Webhooks → your webhook → ID.',
                validation:  ['maxLength' => 255],
                group:       'credentials',
            ),
            new FieldDefinition(
                name:        'sandbox',
                type:        FieldType::CHECKBOX,
                label:       'Sandbox Mode',
                sensitive:   false,
                required:    false,
                helpText:    'Enable to use the PayPal sandbox environment for testing.',
                default:     false,
                group:       'settings',
            ),
        ];
    }
}
