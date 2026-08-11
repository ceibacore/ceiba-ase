<?php

declare(strict_types=1);

namespace LemurAse\FormManagement\Infrastructure\Definitions;

use LemurAse\FormManagement\Domain\FieldDefinition;
use LemurAse\FormManagement\Domain\FieldType;
use LemurAse\FormManagement\Domain\GatewayFormDefinitionInterface;

final class AlipayFormDefinition implements GatewayFormDefinitionInterface
{
    public function provider(): string { return 'alipay'; }
    public function label(): string    { return 'Alipay Global (Antom)'; }
    public function version(): string  { return '1.0.0'; }

    public function groups(): array
    {
        return [
            ['id' => 'credentials', 'title' => 'Credenciales RSA',  'order' => 0],
            ['id' => 'merchant',    'title' => 'Datos del Comercio', 'order' => 1],
            ['id' => 'settings',    'title' => 'Configuración',     'order' => 2],
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
                placeholder: 'SANDBOX_2188xxxx...',
                helpText:    'Client ID de tu aplicación en Antom Developer Portal.',
                validation:  ['maxLength' => 255],
                group:       'credentials',
            ),
            new FieldDefinition(
                name:        'private_key',
                type:        FieldType::TEXTAREA,
                label:       'Private Key (RSA)',
                sensitive:   true,
                required:    true,
                placeholder: '-----BEGIN RSA PRIVATE KEY-----\n...',
                helpText:    'Tu clave privada RSA en formato PEM. Usada para firmar cada solicitud enviada a Alipay.',
                group:       'credentials',
            ),
            new FieldDefinition(
                name:        'alipay_public_key',
                type:        FieldType::TEXTAREA,
                label:       'Alipay Public Key (RSA)',
                sensitive:   true,
                required:    true,
                placeholder: '-----BEGIN PUBLIC KEY-----\n...',
                helpText:    'Clave pública de Alipay proporcionada en el portal de Antom para verificar firmas de webhooks.',
                group:       'credentials',
            ),
            new FieldDefinition(
                name:        'merchant_id',
                type:        FieldType::TEXT,
                label:       'Merchant ID',
                sensitive:   false,
                required:    true,
                placeholder: '2188000123456789',
                helpText:    'ID del comercio registrado en Alipay Global / Antom.',
                validation:  ['maxLength' => 255],
                group:       'merchant',
            ),
            new FieldDefinition(
                name:        'sandbox',
                type:        FieldType::CHECKBOX,
                label:       'Modo Sandbox',
                sensitive:   false,
                required:    false,
                helpText:    'Habilitar para utilizar el entorno de pruebas Sandbox de Antom.',
                default:     false,
                group:       'settings',
            ),
        ];
    }
}
