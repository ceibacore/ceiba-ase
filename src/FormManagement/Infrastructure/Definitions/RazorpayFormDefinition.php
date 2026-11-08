<?php

declare(strict_types=1);

namespace LemurAse\FormManagement\Infrastructure\Definitions;

use LemurAse\FormManagement\Domain\FieldDefinition;
use LemurAse\FormManagement\Domain\FieldType;
use LemurAse\FormManagement\Domain\GatewayFormDefinitionInterface;

final class RazorpayFormDefinition implements GatewayFormDefinitionInterface
{
    public function provider(): string { return 'razorpay'; }
    public function label(): string    { return 'Razorpay'; }
    public function version(): string  { return '1.0.0'; }

    public function groups(): array
    {
        return [['id' => 'credentials', 'title' => 'Credenciales API', 'order' => 0]];
    }

    public function fields(): array
    {
        return [
            new FieldDefinition(
                name:        'key_id',
                type:        FieldType::TEXT,
                label:       'Key ID',
                sensitive:   false,
                required:    true,
                placeholder: 'rzp_live_...',
                helpText:    'Razorpay Dashboard → Account & Settings → API Keys. Usa rzp_test_ para pruebas.',
                validation:  ['maxLength' => 255],
                group:       'credentials',
            ),
            new FieldDefinition(
                name:        'key_secret',
                type:        FieldType::PASSWORD,
                label:       'Key Secret',
                sensitive:   true,
                required:    true,
                placeholder: '••••••••',
                helpText:    'Se muestra una sola vez al generar la clave.',
                validation:  ['maxLength' => 255],
                group:       'credentials',
            ),
            new FieldDefinition(
                name:        'webhook_secret',
                type:        FieldType::PASSWORD,
                label:       'Webhook Secret',
                sensitive:   true,
                required:    true,
                placeholder: '••••••••',
                helpText:    'Dashboard → Webhooks: el secreto que definiste al crear el webhook (eventos payment_link.paid y refund.processed). No es el Key Secret.',
                validation:  ['maxLength' => 255],
                group:       'credentials',
            ),
        ];
    }
}
