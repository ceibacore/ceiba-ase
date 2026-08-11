<?php

declare(strict_types=1);

namespace LemurAse\FormManagement\Infrastructure;

use LemurAse\FormManagement\Domain\FieldDefinition;
use LemurAse\FormManagement\Domain\GatewayFormDefinitionInterface;
use LemurAse\FormManagement\Infrastructure\Definitions\MockFormDefinition;
use LemurAse\FormManagement\Infrastructure\Definitions\MercadoPagoFormDefinition;
use LemurAse\FormManagement\Infrastructure\Definitions\PayPalFormDefinition;
use LemurAse\FormManagement\Infrastructure\Definitions\StripeFormDefinition;

/**
 * Registry of all gateway form definitions.
 *
 * Built-in providers (Stripe, PayPal) are registered automatically.
 * Add custom gateways at boot time:
 *
 *   GatewayFormRegistry::register(new MercadoPagoFormDefinition());
 *
 * Or load from a JSON definition file:
 *
 *   GatewayFormRegistry::registerFromJson('/path/to/mercadopago.json');
 */
final class GatewayFormRegistry
{
    /** @var array<string, GatewayFormDefinitionInterface> */
    private static array $definitions = [];
    private static bool  $booted      = false;

    private function __construct() {}

    // ── Boot ─────────────────────────────────────────────────────────────────

    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }

        self::register(new StripeFormDefinition());
        self::register(new PayPalFormDefinition());
        self::register(new MockFormDefinition());
        self::register(new MercadoPagoFormDefinition());

        self::$booted = true;
    }

    /** Reset for testing purposes. */
    public static function reset(): void
    {
        self::$definitions = [];
        self::$booted      = false;
    }

    // ── Registration ─────────────────────────────────────────────────────────

    public static function register(GatewayFormDefinitionInterface $definition): void
    {
        self::$definitions[$definition->provider()] = $definition;
    }

    /**
     * Register a gateway definition from a JSON file.
     *
     * JSON format: { "provider": "...", "label": "...", "version": "...",
     *                "groups": [...], "fields": [...] }
     *
     * Each field follows the FieldDefinition::fromArray() schema.
     */
    public static function registerFromJson(string $filePath): void
    {
        if (!is_file($filePath) || !is_readable($filePath)) {
            throw new \InvalidArgumentException("Gateway JSON definition not found: {$filePath}");
        }

        $data = json_decode(file_get_contents($filePath), associative: true, flags: JSON_THROW_ON_ERROR);

        self::register(new class ($data) implements GatewayFormDefinitionInterface {
            private readonly string $provider;
            private readonly string $label;
            private readonly string $version;
            private readonly array  $groups;
            /** @var FieldDefinition[] */
            private readonly array  $fields;

            public function __construct(array $data)
            {
                $this->provider = (string) ($data['provider'] ?? throw new \InvalidArgumentException("Missing 'provider'"));
                $this->label    = (string) ($data['label']    ?? throw new \InvalidArgumentException("Missing 'label'"));
                $this->version  = (string) ($data['version']  ?? '1.0.0');
                $this->groups   = (array)  ($data['groups']   ?? []);
                $this->fields   = array_map(
                    fn(array $f) => FieldDefinition::fromArray($f),
                    (array) ($data['fields'] ?? [])
                );
            }

            public function provider(): string { return $this->provider; }
            public function label(): string    { return $this->label; }
            public function version(): string  { return $this->version; }
            public function groups(): array    { return $this->groups; }
            public function fields(): array    { return $this->fields; }
        });
    }

    // ── Lookups ──────────────────────────────────────────────────────────────

    public static function get(string $provider): GatewayFormDefinitionInterface
    {
        self::boot();

        return self::$definitions[$provider]
            ?? throw new \InvalidArgumentException(
                "No form definition for provider '{$provider}'. " .
                "Registered: " . implode(', ', self::providers())
            );
    }

    public static function has(string $provider): bool
    {
        self::boot();
        return isset(self::$definitions[$provider]);
    }

    /** @return string[] */
    public static function providers(): array
    {
        self::boot();
        return array_keys(self::$definitions);
    }

    /** @return array<string, GatewayFormDefinitionInterface> */
    public static function all(): array
    {
        self::boot();
        return self::$definitions;
    }
}
