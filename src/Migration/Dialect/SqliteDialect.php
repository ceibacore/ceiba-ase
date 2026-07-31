<?php

declare(strict_types=1);

namespace LemurAse\Migration\Dialect;

use LemurAse\Migration\AseColumnDef;

final class SqliteDialect implements AseDialectInterface
{
    public function getName(): string { return 'sqlite'; }

    public function quoteIdentifier(string $name): string
    {
        return '"' . str_replace('"', '""', $name) . '"';
    }

    public function jsonType(): string        { return 'TEXT'; }
    public function booleanType(): string     { return 'INTEGER'; }
    public function textType(): string        { return 'TEXT'; }
    public function currentTimestamp(): string { return 'CURRENT_TIMESTAMP'; }
    public function onUpdateTimestamp(): string { return ''; }

    public function autoIncrementPkDefinition(): string
    {
        return 'INTEGER PRIMARY KEY AUTOINCREMENT';
    }

    public function createTableSql(string $table, string $columnsSql, string $indexesSql): string
    {
        $inlineParts = [];
        $extraStatements = [];

        // Add columns
        $inlineParts[] = rtrim($columnsSql);

        if (!empty($indexesSql)) {
            $lines = explode("\n", $indexesSql);
            foreach ($lines as $line) {
                $trimmed = trim($line, " \t\r\n,");
                if ($trimmed === '') {
                    continue;
                }

                // Match UNIQUE INDEX "name" (columns)
                if (preg_match('/^UNIQUE\s+INDEX\s+("[^"]+"|`[^`]+`|\w+)\s*\((.+)\)$/i', $trimmed, $m)) {
                    $idxName = trim($m[1], '"` ');
                    $cols = $m[2];
                    $uniqueIdxName = $table . '_' . $idxName;
                    $extraStatements[] = "CREATE UNIQUE INDEX {$this->quoteIdentifier($uniqueIdxName)} ON {$this->quoteIdentifier($table)} ({$cols})";
                }
                // Match INDEX "name" (columns)
                elseif (preg_match('/^INDEX\s+("[^"]+"|`[^`]+`|\w+)\s*\((.+)\)$/i', $trimmed, $m)) {
                    $idxName = trim($m[1], '"` ');
                    $cols = $m[2];
                    $uniqueIdxName = $table . '_' . $idxName;
                    $extraStatements[] = "CREATE INDEX {$this->quoteIdentifier($uniqueIdxName)} ON {$this->quoteIdentifier($table)} ({$cols})";
                }
                else {
                    // Keep CONSTRAINT / FOREIGN KEY / UNIQUE constraints inline
                    $inlineParts[] = '    ' . $trimmed;
                }
            }
        }

        $body = implode(",\n", $inlineParts);
        $sql = "CREATE TABLE IF NOT EXISTS {$this->quoteIdentifier($table)} (\n{$body}\n)";

        if (!empty($extraStatements)) {
            $sql .= ";\n" . implode(";\n", $extraStatements);
        }

        return $sql;
    }

    public function addColumnSql(string $table, AseColumnDef $col): string
    {
        return "ALTER TABLE {$this->quoteIdentifier($table)} ADD COLUMN " . $col->toInlineSql($this);
    }

    public function modifyColumnSql(string $table, AseColumnDef $col): string
    {
        return "ALTER TABLE {$this->quoteIdentifier($table)} ALTER COLUMN " . $col->toInlineSql($this);
    }

    public function dropColumnSql(string $table, string $column): string
    {
        return "ALTER TABLE {$this->quoteIdentifier($table)} DROP COLUMN {$this->quoteIdentifier($column)}";
    }

    public function renameColumnSql(string $table, string $oldName, string $newName, string $columnDef): string
    {
        return "ALTER TABLE {$this->quoteIdentifier($table)} RENAME COLUMN {$this->quoteIdentifier($oldName)} TO {$this->quoteIdentifier($newName)}";
    }

    public function renameTableSql(string $from, string $to): string
    {
        return "ALTER TABLE {$this->quoteIdentifier($from)} RENAME TO {$this->quoteIdentifier($to)}";
    }

    public function dropTableSql(string $table): string
    {
        return "DROP TABLE {$this->quoteIdentifier($table)}";
    }

    public function dropTableIfExistsSql(string $table): string
    {
        return "DROP TABLE IF EXISTS {$this->quoteIdentifier($table)}";
    }

    public function addIndexSql(string $table, array $columns, string $name, bool $unique): string
    {
        $type    = $unique ? 'UNIQUE INDEX' : 'INDEX';
        $cols    = implode(', ', array_map([$this, 'quoteIdentifier'], $columns));
        $idxName = $name ?: 'idx_' . implode('_', $columns);
        return "CREATE {$type} {$this->quoteIdentifier($idxName)} ON {$this->quoteIdentifier($table)} ({$cols})";
    }

    public function dropIndexSql(string $table, string $name): string
    {
        return "DROP INDEX {$this->quoteIdentifier($name)}";
    }

    public function addForeignKeySql(
        string $table, string $column,
        string $refTable, string $refColumn,
        string $onDelete, string $name
    ): string {
        return "";
    }

    public function dropForeignKeySql(string $table, string $name): string
    {
        return "";
    }

    public function trackingTableDdl(string $tableName): string
    {
        $t = $this->quoteIdentifier($tableName);
        return <<<SQL
CREATE TABLE IF NOT EXISTS {$t} (
    "id"         INTEGER PRIMARY KEY AUTOINCREMENT,
    "version"    TEXT     NOT NULL,
    "name"       TEXT     NOT NULL,
    "applied_at" DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "checksum"   TEXT     NOT NULL,
    UNIQUE("version")
)
SQL;
    }
}
