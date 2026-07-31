<?php

declare(strict_types=1);

namespace LemurAse\Migration;

/**
 * Immutable result object returned by AseMigrationRunner methods.
 */
final class MigrationResult
{
    /**
     * @param bool     $success   Whether all operations completed without error
     * @param string[] $applied   Version strings of migrations that were applied
     * @param string[] $reverted  Version strings of migrations that were reverted
     * @param string[] $sqlLog    SQL statements collected during dry-run
     * @param array[]  $errors    Each: ['version' => string, 'message' => string]
     */
    public function __construct(
        public readonly bool  $success,
        public readonly array $applied,
        public readonly array $reverted,
        public readonly array $sqlLog,
        public readonly array $errors,
    ) {}

    /**
     * True if the runner had work to do (applied migrations or collected SQL in dry-run).
     */
    public function hasPending(): bool
    {
        return !empty($this->applied) || !empty($this->sqlLog);
    }

    /**
     * True if any errors occurred.
     */
    public function hasErrors(): bool
    {
        return !empty($this->errors);
    }
}
