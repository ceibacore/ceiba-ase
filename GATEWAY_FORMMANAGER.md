# Configuración de Pasarelas de Pago (Gateway Configuration)

El **GatewayFormManager** implementa un sistema robusto, agnóstico y completamente desacoplado para gestionar las credenciales de cualquier pasarela de pago (Stripe, PayPal, MercadoPago, etc.) directamente desde la interfaz de usuario. Garantiza la máxima seguridad sin depender de frameworks pesados de frontend o sesiones de servidor (stateless).

## Arquitectura

- **Seguridad (`CredentialEncryptor.php`)**: Cifra todas las credenciales usando `AES-256-GCM`, previniendo la alteración de datos y asegurando que ninguna clave se guarde en texto plano.
- **Tipado y Definición (`FieldType.php`, `FieldDefinition.php`)**: Estructuras inmutables que definen qué datos se solicitan, cómo se validan (regex, longitudes) y qué tipo de input HTML usar.
- **Registro de Pasarelas (`GatewayFormRegistry.php`)**: Actúa como un registro centralizado (Patrón Registry) donde las pasarelas pueden ser añadidas mediante clases PHP o dinámicamente mediante archivos JSON.
- **Motor de Renderizado y Validación (`GatewayFormManager.php`)**: Renderiza HTML puro (sin JS forzado), inyecta y valida tokens CSRF Stateless por medio de firmas HMAC (no requiere sesiones), y enmascara campos sensibles (`__MASKED__`) durante la edición.

---

## Casos de Uso (API)

A continuación, se describen las interacciones principales con el componente a nivel de código.

### 1. Generación del Token CSRF (Stateless)
El formulario requiere un token CSRF seguro.
```php
use LemurAse\UI\GatewayFormManager;

// Genera un token basado en el ID de usuario y la acción
$csrfToken = GatewayFormManager::generateCsrfToken($userId, 'gateway:stripe');
```

### 2. Renderizado del Formulario
Puedes inyectar esto directamente en tus vistas. Si `$existingCredentials` tiene contenido, el formulario ocultará/enmascarará campos sensibles como secretos.
```php
use LemurAse\UI\GatewayFormManager;

$html = GatewayFormManager::renderForm(
    provider: 'stripe',
    existingCredentials: $existingCredentials, // [] si es creación
    csrfToken: $csrfToken,
    formAttributes: ['action' => '/admin/gateways/stripe', 'method' => 'POST']
);
echo $html;
```

### 3. Procesamiento de Submission
Cuando llega un POST, el motor valida CSRF, expresiones regulares, y combina de forma segura con las credenciales existentes.
```php
use LemurAse\UI\GatewayFormManager;
use LemurAse\UI\GatewayFormException;

try {
    $result = GatewayFormManager::processSubmission(
        provider: 'stripe',
        postData: $_POST,
        csrfToken: $_POST['_token'] ?? '',
        userId: $userId,
        existing: $existingCredentials // Para conservar valores enmascarados
    );

    // $result['credentials'] contiene las claves limpias y combinadas
    // $result['is_active'] indica si el usuario activó la pasarela
} catch (GatewayFormException $e) {
    $errors = $e->getErrors(); // Array mapeando campo => mensaje de error
}
```

### 4. Persistencia (Upsert)
Usa el caso de uso `UpsertGateway` para persistir la configuración.
```php
use LemurAse\Application\UseCases\UpsertGateway;

// Resuelto desde el contenedor de dependencias
$upsertUseCase = new UpsertGateway($gatewayRepository);

$gateway = $upsertUseCase->execute(
    provider: 'stripe',
    credentials: $result['credentials'],
    isActive: $result['is_active']
);
```

---

## Ejemplo de Integración en Laravel

Este ejemplo muestra cómo envolver el `GatewayFormManager` dentro de un Controlador de Laravel para integrarlo de forma natural con los mecanismos de Request/Response de la aplicación.

### `GatewayController.php`

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use LemurAse\UI\GatewayFormManager;
use LemurAse\UI\GatewayFormException;
use LemurAse\AseManager;

class GatewayController extends Controller
{
    /**
     * Muestra el formulario de configuración para una pasarela.
     */
    public function edit(string $provider)
    {
        $userId = (string) Auth::id();
        $ase = AseManager::getInstance();
        
        // Obtener la configuración actual (si existe) para el modo edición
        $gateway = $ase->getGateway($provider);
        $existingCredentials = $gateway ? $gateway->credentials() : [];

        // 1. Generar token CSRF para el formulario (Agnóstico, no usa sesiones de Laravel)
        $csrfToken = GatewayFormManager::generateCsrfToken($userId, "gateway:{$provider}");

        // 2. Renderizar formulario HTML puro
        $formHtml = GatewayFormManager::renderForm(
            provider: $provider,
            existingCredentials: $existingCredentials,
            csrfToken: $csrfToken,
            formAttributes: [
                'action' => route('gateways.update', ['provider' => $provider]),
                'class'  => 'mi-clase-tailwind-form'
            ]
        );

        return view('admin.gateways.edit', [
            'provider' => $provider,
            'formHtml' => $formHtml
        ]);
    }

    /**
     * Procesa y guarda la configuración de la pasarela.
     */
    public function update(Request $request, string $provider)
    {
        $userId = (string) Auth::id();
        $ase = AseManager::getInstance();
        
        $gateway = $ase->getGateway($provider);
        $existingCredentials = $gateway ? $gateway->credentials() : [];

        try {
            // 3. Delegar la validación exhaustiva al Form Manager
            $result = GatewayFormManager::processSubmission(
                provider: $provider,
                postData: $request->all(),
                csrfToken: $request->input('_token', ''),
                userId: $userId,
                existing: $existingCredentials
            );

            // 4. Guardar usando la API pública del AseManager
            $ase->upsertGateway(
                provider: $provider,
                credentials: $result['credentials'],
                isActive: $result['is_active']
            );

            return redirect()
                ->route('gateways.index')
                ->with('success', 'Configuración de la pasarela guardada exitosamente.');

        } catch (GatewayFormException $e) {
            // Si la validación falla, redirigimos de vuelta enviando los errores a Laravel
            return redirect()
                ->back()
                ->withInput()
                ->withErrors($e->getErrors()); // El mapa de errores se integra perfecto con Laravel
        }
    }
}
```

### Vista de Blade (`resources/views/admin/gateways/edit.blade.php`)

La integración en la vista de Laravel se reduce simplemente a imprimir el HTML generado por el `GatewayFormManager`.

```blade
@extends('layouts.admin')

@section('content')
<div class="max-w-3xl mx-auto py-8">
    <h1 class="text-2xl font-bold mb-6">Configurar Pasarela: {{ ucfirst($provider) }}</h1>

    @if ($errors->any())
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4">
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="bg-white shadow rounded-lg p-6">
        <!-- Inyectamos el HTML directamente. El Motor se encargó de TODO. -->
        {!! $formHtml !!}
    </div>
</div>
@endsection
```

### Entorno (`.env` en Laravel)

No olvides declarar las variables requeridas en el archivo `.env` de Laravel para que la capa de Infraestructura de `LemurAse` las detecte:

```env
# Clave AES-256 de 32-bytes en Base64 para cifrar las credenciales de Stripe/PayPal
GATEWAY_ENCRYPTION_KEY=your_base64_encryption_key

# Usado para el HMAC del Token CSRF (Si se omite usa GATEWAY_SERVICE_SECRET)
GATEWAY_CSRF_SECRET=your_csrf_secret
```
