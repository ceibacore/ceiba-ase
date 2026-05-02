<?php

declare(strict_types=1);

namespace LemurAse\Migration;

use LemurAse\Migration\Dialect\AseDialectInterface;

/**
 * Blueprint for defining a table structure inside createTable() closures.
 */
final class AseColumnBlueprint
{
    /** @var AseColumnDef[] */
    private array $columns = [];

    /** @var array[] Each: ['type'=>'index'|'unique'|'fk', 'columns'=>[], 'name'=>'', 'refTable'=>'', 'refColumn'=>'', 'onDelete'=>''] */
    private array $indexes = [];

    public function __construct(private readonly string $tableName = '')
    {
    }

    // ── Shorthand column types ────────────────────────────────────────────────

    public function char(string $name, int $length = 36): AseColumnDef
    {
        return $this->addColumn($name, "CHAR({$length})");
    }

    public function string(string $name, int $length = 255): AseColumnDef
    {
        return $this->addColumn($name, "VARCHAR({$length})");
    }

    public function text(string $name): AseColumnDef
    {
        return $this->addColumn($name, 'TEXT');
    }

    public function integer(string $name): AseColumnDef
    {
        return $this->addColumn($name, 'INT');
    }

    public function bigInteger(string $name): AseColumnDef
    {
        return $this->addColumn($name, 'BIGINT');
    }

    public function unsignedInteger(string $name): AseColumnDef
    {
        return $this->addColumn($name, 'INT')->unsigned();
    }

    public function decimal(string $name, int $precision = 10, int $scale = 2): AseColumnDef
    {
        return $this->addColumn($name, "DECIMAL({$precision},{$scale})");
    }

    public function boolean(string $name): AseColumnDef
    {
        return $this->addColumn($name, 'BOOLEAN');
    }

    public function json(string $name): AseColumnDef
    {
        return $this->addColumn($name, 'JSON');
    }

    public function datetime(string $name): AseColumnDef
    {
        return $this->addColumn($name, 'DATETIME');
    }

    // ── Composite helpers ─────────────────────────────────────────────────────

    public function timestamps(): void
    {
        $this->addColumn('created_at', 'DATETIME')
             ->default('CURRENT_TIMESTAMP');

        $this->addColumn('updated_at', 'DATETIME')
             ->default('CURRENT_TIMESTAMP')
             ->onUpdateCurrentTimestamp();
    }

    public function softDeletes(): void
    {
        $this->addColumn('deleted_at', 'DATETIME')->nullable();
    }

    // ── Index declarations ────────────────────────────────────────────────────

    public function index(string|array $columns, string $name = ''): void
    {
        $cols = is_array($columns) ? $columns : [$columns];
        $this->indexes[] = ['type' => 'index', 'columns' => $cols, 'name' => $name];
    }

    public function uniqueIndex(string|array $columns, string $name = ''): void
    {
        $cols = is_array($columns) ? $columns : [$columns];
        $this->indexes[] = ['type' => 'unique', 'columns' => $cols, 'name' => $name];
    }

    public function foreignKey(string $col, string $refTable, string $refColumn = 'id', string $onDelete = 'CASCADE', string $name = ''): void
    {
        if ($name) {
            $constraintName = $name;
        } else {
            $constraintName = $this->tableName ? "fk_{$this->tableName}_{$col}" : "fk_{$col}";
        }
        $this->indexes[] = [
            'type'      => 'fk',
            'columns'   => [$col],
            'name'      => $constraintName,
            'refTable'  => $refTable,
            'refColumn' => $refColumn,
            'onDelete'  => $onDelete,
        ];
    }

    // ── Rendering ─────────────────────────────────────────────────────────────

    /**
     * Returns the full CREATE TABLE SQL for the given (already-prefixed) table name.
     */
    public function toCreateSql(string $prefixedTable, AseDialectInterface $dialect, string $prefix = ''): string
    {
        $columnLines = [];
        foreach ($this->columns as $col) {
            $columnLines[] = '    ' . $col->toInlineSql($dialect);
        }

        $indexLines = [];
        foreach ($this->indexes as $idx) {
            $q    = [$dialect, 'quoteIdentifier'];
            $cols = implode(', ', array_map($q, $idx['columns']));
            $name = $idx['name'] ?: 'idx_' . implode('_', $idx['columns']);

            switch ($idx['type']) {
                case 'index':
                    $indexLines[] = "    INDEX {$q($name)} ({$cols})";
                    break;
                case 'unique':
                    $indexLines[] = "    UNIQUE INDEX {$q($name)} ({$cols})";
                    break;
                case 'fk':
                    $fkName = $idx['name'] ?? "fk_{$idx['columns'][0]}";
                    $indexLines[] = sprintf(
                        '    CONSTRAINT %s FOREIGN KEY (%s) REFERENCES %s(%s) ON DELETE %s',
                        $q($fkName),
                        $cols,
                        $q($prefix . $idx['refTable']),
                        $q($idx['refColumn']),
                        $idx['onDelete']
                    );
                    break;
            }
        }

        $columnsSql = implode(",\n", $columnLines);
        $indexesSql = implode(",\n", $indexLines);

        return $dialect->createTableSql($prefixedTable, $columnsSql, $indexesSql);
    }

    // ── Internal ──────────────────────────────────────────────────────────────

    private function addColumn(string $name, string $type): AseColumnDef
    {
        $col = new AseColumnDef($name, $type);
        $this->columns[] = $col;
        return $col;
    }
}
