<?php

declare(strict_types=1);

namespace LemurAse\FormManagement\Infrastructure\Definitions;

use LemurAse\FormManagement\Domain\FieldDefinition;
use LemurAse\FormManagement\Domain\FieldType;
use LemurAse\FormManagement\Domain\GatewayFormDefinitionInterface;

final class MercadoPagoFormDefinition implements GatewayFormDefinitionInterface
{
    public function provider(): string { return 'mercadopago'; }
    public function label(): string    { return 'Mercado Pago'; }
    public function version(): string  { return '1.0.0'; }

    public function groups(): array
    {
        return [
            ['id' => 'credentials', 'title' => 'Credenciales API', 'order' => 0],
            ['id' => 'settings',    'title' => 'Configuración',    'order' => 1],
        ];
    }

    public function fields(): array
    {
        return [
            new FieldDefinition(
                name:        'access_token',
                type:        FieldType::PASSWORD,
                label:       'Access Token',
                sensitive:   true,
                required:    true,
                placeholder: 'APP_USR-xxxx...',
                helpText:    'Token de acceso de tu aplicación. Panel de Mercado Pago → Tus integraciones → Credenciales de producción.',
                validation:  ['maxLength' => 255],
                group:       'credentials',
            ),
            new FieldDefinition(
                name:        'public_key',
                type:        FieldType::TEXT,
                label:       'Public Key',
                sensitive:   false,
                required:    true,
                placeholder: 'APP_USR-xxxx...',
                helpText:    'Clave pública para el frontend. Panel de Mercado Pago → Tus integraciones.',
                validation:  ['maxLength' => 255],
                group:       'credentials',
            ),
            new FieldDefinition(
                name:        'webhook_secret',
                type:        FieldType::PASSWORD,
                label:       'Webhook Secret Key',
                sensitive:   true,
                required:    true,
                placeholder: 'xxxx...',
                helpText:    'Clave secreta para verificar la autenticidad de los webhooks. Panel → Tus integraciones → Webhooks.',
                validation:  ['maxLength' => 255],
                group:       'credentials',
            ),
            new FieldDefinition(
                name:        'sandbox',
                type:        FieldType::CHECKBOX,
                label:       'Modo Sandbox',
                sensitive:   false,
                required:    false,
                helpText:    'Habilitar para usar credenciales de prueba de Mercado Pago. Las transacciones no serán reales.',
                default:     false,
                group:       'settings',
            ),
        ];
    }
}
