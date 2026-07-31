<?php

declare(strict_types=1);

namespace LemurAse\Migration\Dialect;

use LemurAse\Migration\AseColumnDef;

interface AseDialectInterface
{
    // ── Type resolution ──────────────────────────────────────────────────────
    public function jsonType(): string;
    public function booleanType(): string;
    public function textType(): string;
    public function autoIncrementPkDefinition(): string;
    public function currentTimestamp(): string;
    public function onUpdateTimestamp(): string;

    // ── DDL generation ───────────────────────────────────────────────────────
    public function createTableSql(string $table, string $columnsSql, string $indexesSql): string;
    public function addColumnSql(string $table, AseColumnDef $col): string;
    public function modifyColumnSql(string $table, AseColumnDef $col): string;
    public function dropColumnSql(string $table, string $column): string;
    public function renameColumnSql(string $table, string $oldName, string $newName, string $columnDef): string;
    public function renameTableSql(string $from, string $to): string;
    public function dropTableSql(string $table): string;
    public function dropTableIfExistsSql(string $table): string;
    public function addIndexSql(string $table, array $columns, string $name, bool $unique): string;
    public function dropIndexSql(string $table, string $name): string;
    public function addForeignKeySql(string $table, string $column, string $refTable, string $refColumn, string $onDelete, string $name): string;
    public function dropForeignKeySql(string $table, string $name): string;
    public function trackingTableDdl(string $tableName): string;

    // ── Identifier quoting ───────────────────────────────────────────────────
    public function quoteIdentifier(string $name): string;

    // ── Dialect name ─────────────────────────────────────────────────────────
    public function getName(): string;
}
