<?php

declare(strict_types=1);

namespace LemurAse\Migration\Dialect;

use LemurAse\Migration\AseColumnDef;

final class MySQLDialect implements AseDialectInterface
{
    public function getName(): string { return 'mysql'; }

    public function quoteIdentifier(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }

    public function jsonType(): string        { return 'JSON'; }
    public function booleanType(): string     { return 'TINYINT(1)'; }
    public function textType(): string        { return 'TEXT'; }
    public function currentTimestamp(): string { return 'CURRENT_TIMESTAMP'; }
    public function onUpdateTimestamp(): string { return 'ON UPDATE CURRENT_TIMESTAMP'; }

    public function autoIncrementPkDefinition(): string
    {
        return 'INT UNSIGNED NOT NULL AUTO_INCREMENT';
    }

    public function createTableSql(string $table, string $columnsSql, string $indexesSql): string
    {
        $body = rtrim($columnsSql);
        if (!empty($indexesSql)) {
            $body .= ",\n" . $indexesSql;
        }
        return "CREATE TABLE IF NOT EXISTS {$this->quoteIdentifier($table)} (\n{$body}\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    }

    public function addColumnSql(string $table, AseColumnDef $col): string
    {
        $after = $col->after ? ' AFTER ' . $this->quoteIdentifier($col->after) : '';
        return "ALTER TABLE {$this->quoteIdentifier($table)} ADD COLUMN " . $col->toInlineSql($this) . $after;
    }

    public function modifyColumnSql(string $table, AseColumnDef $col): string
    {
        return "ALTER TABLE {$this->quoteIdentifier($table)} MODIFY COLUMN " . $col->toInlineSql($this);
    }

    public function dropColumnSql(string $table, string $column): string
    {
        return "ALTER TABLE {$this->quoteIdentifier($table)} DROP COLUMN {$this->quoteIdentifier($column)}";
    }

    public function renameColumnSql(string $table, string $oldName, string $newName, string $columnDef): string
    {
        // MySQL 8.0+ supports RENAME COLUMN
        return "ALTER TABLE {$this->quoteIdentifier($table)} RENAME COLUMN {$this->quoteIdentifier($oldName)} TO {$this->quoteIdentifier($newName)}";
    }

    public function renameTableSql(string $from, string $to): string
    {
        return "RENAME TABLE {$this->quoteIdentifier($from)} TO {$this->quoteIdentifier($to)}";
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
        return "ALTER TABLE {$this->quoteIdentifier($table)} ADD {$type} {$this->quoteIdentifier($idxName)} ({$cols})";
    }

    public function dropIndexSql(string $table, string $name): string
    {
        return "ALTER TABLE {$this->quoteIdentifier($table)} DROP INDEX {$this->quoteIdentifier($name)}";
    }

    public function addForeignKeySql(
        string $table, string $column,
        string $refTable, string $refColumn,
        string $onDelete, string $name
    ): string {
        $constraintName = $name ?: "fk_{$table}_{$column}";
        $q = [$this, 'quoteIdentifier'];
        return sprintf(
            'ALTER TABLE %s ADD CONSTRAINT %s FOREIGN KEY (%s) REFERENCES %s(%s) ON DELETE %s',
            $q($table), $q($constraintName), $q($column), $q($refTable), $q($refColumn), $onDelete
        );
    }

    public function dropForeignKeySql(string $table, string $name): string
    {
        return "ALTER TABLE {$this->quoteIdentifier($table)} DROP FOREIGN KEY {$this->quoteIdentifier($name)}";
    }

    public function trackingTableDdl(string $tableName): string
    {
        $t = $this->quoteIdentifier($tableName);
        return <<<SQL
CREATE TABLE IF NOT EXISTS {$t} (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `version`    CHAR(14)     NOT NULL,
    `name`       VARCHAR(255) NOT NULL,
    `applied_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `checksum`   CHAR(64)     NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_version` (`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;
    }
}
