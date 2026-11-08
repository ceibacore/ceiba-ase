<?php

declare(strict_types=1);

namespace LemurAse\FormManagement\Infrastructure\Definitions;

use LemurAse\FormManagement\Domain\FieldDefinition;
use LemurAse\FormManagement\Domain\FieldType;
use LemurAse\FormManagement\Domain\GatewayFormDefinitionInterface;

final class PaystackFormDefinition implements GatewayFormDefinitionInterface
{
    public function provider(): string { return 'paystack'; }
    public function label(): string    { return 'Paystack'; }
    public function version(): string  { return '1.0.0'; }

    public function groups(): array
    {
        return [['id' => 'credentials', 'title' => 'Credenciales API', 'order' => 0]];
    }

    public function fields(): array
    {
        return [
            new FieldDefinition(
                name:        'secret_key',
                type:        FieldType::PASSWORD,
                label:       'Secret Key',
                sensitive:   true,
                required:    true,
                placeholder: 'sk_live_...',
                helpText:    'Paystack Dashboard → Settings → API Keys & Webhooks. También firma los webhooks. Usa sk_test_ para pruebas.',
                validation:  ['maxLength' => 255],
                group:       'credentials',
            ),
            new FieldDefinition(
                name:        'public_key',
                type:        FieldType::TEXT,
                label:       'Public Key',
                sensitive:   false,
                required:    true,
                placeholder: 'pk_live_...',
                helpText:    'Clave pública de la misma cuenta (pk_live_ o pk_test_).',
                validation:  ['maxLength' => 255],
                group:       'credentials',
            ),
        ];
    }
}
