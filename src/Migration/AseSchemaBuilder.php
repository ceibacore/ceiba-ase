<?php

declare(strict_types=1);

namespace LemurAse\Migration;

use LemurAse\Migration\Dialect\AseDialectInterface;
use LemurAse\Migration\Dialect\MySQLDialect;

/**
 * DDL builder and executor.
 * Wraps PDO directly — DDL statements are not parameterized queries.
 */
final class AseSchemaBuilder
{
    /** @var string[] */
    private array $sqlLog = [];

    public function __construct(
        private readonly \PDO              $pdo,
        private readonly string            $prefix  = '',
        private readonly bool              $dryRun  = false,
        private readonly AseDialectInterface $dialect = new MySQLDialect(),
        private readonly bool              $debug = false
    ) {}

    // ── Table-level ──────────────────────────────────────────────────────────

    public function createTable(string $table, callable $definition): void
    {
        $blueprint = new AseColumnBlueprint($table);
        $definition($blueprint);
        $sql = $blueprint->toCreateSql($this->prefix($table), $this->dialect, $this->prefix);
        $this->execute($sql);
    }

    public function dropTable(string $table): void
    {
        $this->execute($this->dialect->dropTableSql($this->prefix($table)));
    }

    public function dropTableIfExists(string $table): void
    {
        $this->execute($this->dialect->dropTableIfExistsSql($this->prefix($table)));
    }

    public function renameTable(string $from, string $to): void
    {
        $this->execute($this->dialect->renameTableSql($this->prefix($from), $this->prefix($to)));
    }

    // ── Column-level ─────────────────────────────────────────────────────────

    /**
     * @param array{nullable?:bool, default?:mixed, after?:string, unsigned?:bool, auto_increment?:bool, on_update?:string} $options
     */
    public function addColumn(string $table, string $column, string $type, array $options = []): void
    {
        $col = $this->buildColumnDef($column, $type, $options);
        $this->execute($this->dialect->addColumnSql($this->prefix($table), $col));
    }

    public function dropColumn(string $table, string $column): void
    {
        $this->execute($this->dialect->dropColumnSql($this->prefix($table), $column));
    }

    /**
     * @param array{nullable?:bool, default?:mixed, after?:string, unsigned?:bool, on_update?:string} $options
     */
    public function modifyColumn(string $table, string $column, string $type, array $options = []): void
    {
        $col = $this->buildColumnDef($column, $type, $options);
        $this->execute($this->dialect->modifyColumnSql($this->prefix($table), $col));
    }

    public function renameColumn(string $table, string $oldName, string $newName): void
    {
        $this->execute($this->dialect->renameColumnSql($this->prefix($table), $oldName, $newName, ''));
    }

    // ── Index-level ───────────────────────────────────────────────────────────

    public function addIndex(string $table, string|array $columns, string $name = '', bool $unique = false): void
    {
        $cols = is_array($columns) ? $columns : [$columns];
        $this->execute($this->dialect->addIndexSql($this->prefix($table), $cols, $name, $unique));
    }

    public function dropIndex(string $table, string $name): void
    {
        $this->execute($this->dialect->dropIndexSql($this->prefix($table), $name));
    }

    public function addForeignKey(
        string $table,
        string $column,
        string $refTable,
        string $refColumn = 'id',
        string $onDelete  = 'RESTRICT',
        string $name      = ''
    ): void {
        $this->execute($this->dialect->addForeignKeySql(
            $this->prefix($table), $column,
            $this->prefix($refTable), $refColumn,
            $onDelete, $name
        ));
    }

    public function dropForeignKey(string $table, string $name): void
    {
        $this->execute($this->dialect->dropForeignKeySql($this->prefix($table), $name));
    }

    // ── Raw SQL ───────────────────────────────────────────────────────────────

    public function statement(string $sql): void
    {
        $this->execute($sql);
    }

    // ── Introspection ────────────────────────────────────────────────────────

    public function getSqlLog(): array  { return $this->sqlLog; }
    public function isDryRun(): bool    { return $this->dryRun; }
    public function getDialect(): AseDialectInterface { return $this->dialect; }

    // ── Internal ─────────────────────────────────────────────────────────────

    private function prefix(string $table): string
    {
        return $this->prefix . $table;
    }

    private function execute(string $sql): void
    {
        $this->sqlLog[] = $sql;
        if (!$this->dryRun) {
            try {
                $this->pdo->exec($sql);
            } catch (\PDOException $e) {
                // Extract detailed error info from PDO
                $errorInfo = $this->pdo->errorInfo();
                $sqlState = $errorInfo[0] ?? 'HY000';
                $errno = $errorInfo[1] ?? $e->getCode();
                $errMsg = $errorInfo[2] ?? $e->getMessage();
                
                $details = "MySQL errno {$errno} [SQLSTATE: {$sqlState}]";
                throw new \RuntimeException(
                    "Migration SQL error: {$details}\n" .
                    "Message: {$errMsg}\n" .
                    "SQL: " . $sql,
                    (int)$errno,
                    $e
                );
            }
        }
    }

    private function buildColumnDef(string $name, string $type, array $options): AseColumnDef
    {
        $col = new AseColumnDef($name, $type);

        if (!empty($options['nullable'])) {
            $col->nullable();
        } else {
            $col->notNull();
        }

        if (array_key_exists('default', $options)) {
            $col->default($options['default']);
        }

        if (!empty($options['unsigned'])) {
            $col->unsigned();
        }

        if (!empty($options['auto_increment'])) {
            $col->autoIncrement();
        }

        if (!empty($options['after'])) {
            $col->after($options['after']);
        }

        if (!empty($options['on_update']) && strtoupper($options['on_update']) === 'CURRENT_TIMESTAMP') {
            $col->onUpdateCurrentTimestamp();
        }

        return $col;
    }
}
