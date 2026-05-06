<?php

declare(strict_types=1);

namespace LemurAse\Infrastructure\Form\Definitions;

use LemurAse\Domain\Form\FieldDefinition;
use LemurAse\Domain\Form\FieldType;
use LemurAse\Domain\Form\GatewayFormDefinitionInterface;

final class StripeFormDefinition implements GatewayFormDefinitionInterface
{
    public function provider(): string { return 'stripe'; }
    public function label(): string    { return 'Stripe'; }
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
                name:        'publishable_key',
                type:        FieldType::TEXT,
                label:       'Publishable Key',
                sensitive:   false,
                required:    true,
                placeholder: 'pk_test_...',
                helpText:    'Your Stripe publishable key — safe to expose in the frontend.',
                validation:  ['pattern' => '^pk_(test|live)_', 'maxLength' => 255],
                group:       'credentials',
            ),
            new FieldDefinition(
                name:        'secret_key',
                type:        FieldType::PASSWORD,
                label:       'Secret Key',
                sensitive:   true,
                required:    true,
                placeholder: 'sk_test_...',
                helpText:    'Your Stripe secret key. Never expose this publicly.',
                validation:  ['pattern' => '^sk_(test|live)_', 'maxLength' => 255],
                group:       'credentials',
            ),
            new FieldDefinition(
                name:        'webhook_secret',
                type:        FieldType::PASSWORD,
                label:       'Webhook Signing Secret',
                sensitive:   true,
                required:    true,
                placeholder: 'whsec_...',
                helpText:    'Found in Stripe Dashboard → Webhooks → your endpoint → Signing secret.',
                validation:  ['pattern' => '^whsec_', 'maxLength' => 255],
                group:       'credentials',
            ),
            new FieldDefinition(
                name:        'test_mode',
                type:        FieldType::CHECKBOX,
                label:       'Test Mode',
                sensitive:   false,
                required:    false,
                helpText:    'Enable to use Stripe test keys and sandbox charges.',
                default:     false,
                group:       'settings',
            ),
        ];
    }
}
