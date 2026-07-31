<?php

declare(strict_types=1);

namespace LemurAse\FormManagement\Domain;

/**
 * Contract for a gateway form definition.
 *
 * Implement this interface (or load from JSON via GatewayFormRegistry)
 * to add support for a new payment gateway without touching the core engine.
 */
interface GatewayFormDefinitionInterface
{
    /** Unique lowercase slug: 'stripe', 'paypal', 'mercadopago', etc. */
    public function provider(): string;

    /** Human-readable name shown in the UI. */
    public function label(): string;

    /** Schema version (semver). Bump when credential fields change. */
    public function version(): string;

    /**
     * Ordered list of field definitions that compose this gateway's form.
     *
     * @return FieldDefinition[]
     */
    public function fields(): array;

    /**
     * Optional field groups for visual sectioning.
     * Each entry: ['id' => string, 'title' => string, 'order' => int]
     *
     * @return array[]
     */
    public function groups(): array;
}
