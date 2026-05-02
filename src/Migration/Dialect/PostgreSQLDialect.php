<?php

declare(strict_types=1);

namespace LemurAse\Migration\Dialect;

use LemurAse\Migration\AseColumnDef;

/**
 * PostgreSQL dialect — scaffolded for future implementation.
 * All methods throw \LogicException until implemented.
 */
final class PostgreSQLDialect implements AseDialectInterface
{
    public function getName(): string { return 'pgsql'; }

    public function quoteIdentifier(string $name): string
    {
        return '"' . str_replace('"', '""', $name) . '"';
    }

    public function jsonType(): string        { return 'JSONB'; }
    public function booleanType(): string     { return 'BOOLEAN'; }
    public function textType(): string        { return 'TEXT'; }
    public function currentTimestamp(): string { return 'NOW()'; }
    public function onUpdateTimestamp(): string { return ''; } // Requires trigger in PG

    public function autoIncrementPkDefinition(): string
    {
        return 'INT GENERATED ALWAYS AS IDENTITY';
    }

    private function notImplemented(string $method): never
    {
        throw new \LogicException("PostgreSQLDialect::{$method}() is not yet implemented.");
    }

    public function createTableSql(string $table, string $columnsSql, string $indexesSql): string { $this->notImplemented(__FUNCTION__); }
    public function addColumnSql(string $table, AseColumnDef $col): string                         { $this->notImplemented(__FUNCTION__); }
    public function modifyColumnSql(string $table, AseColumnDef $col): string                      { $this->notImplemented(__FUNCTION__); }
    public function dropColumnSql(string $table, string $column): string                           { $this->notImplemented(__FUNCTION__); }
    public function renameColumnSql(string $table, string $oldName, string $newName, string $columnDef): string { $this->notImplemented(__FUNCTION__); }
    public function renameTableSql(string $from, string $to): string                               { $this->notImplemented(__FUNCTION__); }
    public function dropTableSql(string $table): string                                            { $this->notImplemented(__FUNCTION__); }
    public function dropTableIfExistsSql(string $table): string                                    { $this->notImplemented(__FUNCTION__); }
    public function addIndexSql(string $table, array $columns, string $name, bool $unique): string { $this->notImplemented(__FUNCTION__); }
    public function dropIndexSql(string $table, string $name): string                              { $this->notImplemented(__FUNCTION__); }
    public function addForeignKeySql(string $table, string $column, string $refTable, string $refColumn, string $onDelete, string $name): string { $this->notImplemented(__FUNCTION__); }
    public function dropForeignKeySql(string $table, string $name): string                         { $this->notImplemented(__FUNCTION__); }
    public function trackingTableDdl(string $tableName): string                                    { $this->notImplemented(__FUNCTION__); }
}
