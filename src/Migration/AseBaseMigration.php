<?php

declare(strict_types=1);

namespace LemurAse\Migration;

/**
 * Abstract base class for all ASE migrations.
 *
 * Each migration must declare VERSION and DESCRIPTION constants
 * and implement up() and down().
 *
 * Version format: YYYYMMDDHHmmSS (14 digits)
 * Example: 20260501120000
 */
abstract class AseBaseMigration
{
    public const VERSION     = '';
    public const DESCRIPTION = '';

    protected AseSchemaBuilder $schema;

    final public function __construct(AseSchemaBuilder $schema)
    {
        $this->schema = $schema;
    }

    /**
     * Apply the migration — add tables, columns, indexes.
     */
    abstract public function up(): void;

    /**
     * Revert the migration — must undo exactly what up() did.
     */
    abstract public function down(): void;

    final public function getVersion(): string     { return static::VERSION; }
    final public function getDescription(): string { return static::DESCRIPTION; }
}
