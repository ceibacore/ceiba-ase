<?php

declare(strict_types=1);

namespace LemurAse\Migration;

use LemurAse\Migration\Dialect\AseDialectInterface;

/**
 * Fluent column definition builder.
 * Used standalone in addColumn/modifyColumn, and built by AseColumnBlueprint inside createTable().
 */
final class AseColumnDef
{
    public string  $name;
    public string  $type;
    public bool    $nullable      = false;
    public bool    $notNull       = false;
    public bool    $isPrimary     = false;
    public bool    $isUnique      = false;
    public bool    $unsigned      = false;
    public bool    $autoIncrement = false;
    public mixed   $default       = null;
    public bool    $hasDefault    = false;
    public ?string $after         = null;
    public bool    $onUpdateCurrentTimestamp = false;

    public function __construct(string $name, string $type)
    {
        $this->name = $name;
        $this->type = $type;
    }

    public function nullable(): self
    {
        $this->nullable = true;
        $this->notNull  = false;
        return $this;
    }

    public function notNull(): self
    {
        $this->notNull  = true;
        $this->nullable = false;
        return $this;
    }

    public function default(mixed $value): self
    {
        $this->default    = $value;
        $this->hasDefault = true;
        return $this;
    }

    public function unsigned(): self
    {
        $this->unsigned = true;
        return $this;
    }

    public function primary(): self
    {
        $this->isPrimary = true;
        $this->notNull   = true;
        return $this;
    }

    public function unique(): self
    {
        $this->isUnique = true;
        return $this;
    }

    public function autoIncrement(): self
    {
        $this->autoIncrement = true;
        return $this;
    }

    public function onUpdateCurrentTimestamp(): self
    {
        $this->onUpdateCurrentTimestamp = true;
        return $this;
    }

    public function after(string $column): self
    {
        $this->after = $column;
        return $this;
    }

    /**
     * Generate the inline column SQL fragment: `name` TYPE [UNSIGNED] [NOT NULL] [DEFAULT x] [AUTO_INCREMENT] [PRIMARY KEY] [UNIQUE]
     */
    public function toInlineSql(AseDialectInterface $dialect): string
    {
        $q    = [$dialect, 'quoteIdentifier'];
        $sql  = $q($this->name) . ' ' . $this->resolveType($dialect);

        if ($this->unsigned) {
            $sql .= ' UNSIGNED';
        }

        if ($this->notNull && !$this->nullable) {
            $sql .= ' NOT NULL';
        } elseif ($this->nullable) {
            $sql .= ' NULL';
        }

        if ($this->hasDefault) {
            $sql .= ' DEFAULT ' . $this->formatDefault($this->default);
        }

        if ($this->autoIncrement) {
            $sql .= ' AUTO_INCREMENT';
        }

        if ($this->onUpdateCurrentTimestamp) {
            $oup = $dialect->onUpdateTimestamp();
            if ($oup !== '') {
                $sql .= ' ' . $oup;
            }
        }

        if ($this->isPrimary) {
            $sql .= ' PRIMARY KEY';
        }

        if ($this->isUnique) {
            $sql .= ' UNIQUE';
        }

        return $sql;
    }

    private function resolveType(AseDialectInterface $dialect): string
    {
        return match (strtoupper($this->type)) {
            'JSON'    => $dialect->jsonType(),
            'BOOLEAN', 'BOOL' => $dialect->booleanType(),
            'TEXT'    => $dialect->textType(),
            default   => $this->type,
        };
    }

    private function formatDefault(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        // Raw SQL keywords (CURRENT_TIMESTAMP, etc.)
        if (is_string($value) && preg_match('/^[A-Z_()]+$/', $value)) {
            return $value;
        }
        return "'" . addslashes((string) $value) . "'";
    }
}
