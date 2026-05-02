<?php

declare(strict_types=1);

namespace LemurAse\Migration;

use LemurAse\Migration\Dialect\AseDialectInterface;
use LemurAse\Migration\Dialect\MySQLDialect;

/**
 * Orchestrates discovery, execution, rollback, and status of ASE migrations.
 *
 * Migrations are discovered from $migrationsPath as PHP files matching
 * the pattern: YYYYMMDDHHMMSS_snake_case_description.php
 *
 * Tracking table: _ase_migrations (never prefixed — it is a system table).
 */
final class AseMigrationRunner
{
    private const TRACKING_TABLE   = '_ase_migrations';
    private const FILE_PATTERN     = '/^(\d{14})_([a-z0-9_]+)\.php$/';
    private const CLASS_PREFIX     = 'Migration_';

    public function __construct(
        private readonly \PDO              $pdo,
        private readonly string            $migrationsPath,
        private readonly string            $prefix         = '',
        private readonly bool              $dryRun         = false,
        private readonly AseDialectInterface $dialect      = new MySQLDialect(),
        private readonly bool              $debug          = false
    ) {}

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Run all pending migrations (or at most $steps).
     */
    public function migrate(?int $steps = null): MigrationResult
    {
        $this->ensureTrackingTable();

        $discovered = $this->discoverMigrations();
        $applied    = $this->getAppliedVersions();
        
        if ($this->debug) {
            echo "[DEBUG] Discovered migrations: " . count($discovered) . "\n";
            echo "[DEBUG] Already applied: " . count($applied) . "\n";
        }

        $pending    = array_filter($discovered, fn($m) => !isset($applied[$m['version']]));
        $pending    = array_values($pending);

        if ($this->debug) {
            echo "[DEBUG] Pending migrations: " . count($pending) . "\n";
            foreach ($pending as $p) {
                echo "  - {$p['version']} ({$p['name']})\n";
            }
        }

        if ($steps !== null) {
            $pending = array_slice($pending, 0, $steps);
        }

        if (empty($pending)) {
            if ($this->debug) echo "[DEBUG] Nothing to migrate\n";
            return new MigrationResult(success: true, applied: [], reverted: [], sqlLog: [], errors: []);
        }

        $appliedVersions = [];
        $sqlLog          = [];
        $errors          = [];

        foreach ($pending as $meta) {
            try {
                if ($this->debug) echo "[DEBUG] Executing migration {$meta['version']}...\n";
                
                $migration = $this->loadMigration($meta);
                $builder   = new AseSchemaBuilder($this->pdo, $this->prefix, $this->dryRun, $this->dialect);

                $this->injectSchema($migration, $builder);
                $migration->up();

                if ($this->dryRun) {
                    $sqlLog = array_merge($sqlLog, $builder->getSqlLog());
                    if ($this->debug) echo "[DEBUG DRY-RUN] {$meta['version']} would be applied\n";
                } else {
                    $this->recordApplied($meta['version'], $meta['name'], $this->computeChecksum($meta['file']));
                    if ($this->debug) echo "[DEBUG] ✓ {$meta['version']} applied successfully\n";
                }

                $appliedVersions[] = $meta['version'];
            } catch (\Throwable $e) {
                if ($this->debug) {
                    echo "[DEBUG ERROR] {$meta['version']} failed:\n";
                    echo "[DEBUG ERROR] " . $e->getMessage() . "\n";
                    echo "[DEBUG ERROR] Previous code: " . $e->getCode() . "\n";
                }
                $errors[] = ['version' => $meta['version'], 'message' => $e->getMessage()];
                break; // Stop on first error
            }
        }

        return new MigrationResult(
            success:  empty($errors),
            applied:  $appliedVersions,
            reverted: [],
            sqlLog:   $sqlLog,
            errors:   $errors,
        );
    }

    /**
     * Revert the last $steps applied migrations.
     */
    public function rollback(int $steps = 1): MigrationResult
    {
        $this->ensureTrackingTable();

        $appliedRows = $this->getAppliedVersionsOrdered(descending: true);
        $toRevert    = array_slice($appliedRows, 0, $steps);

        $discovered = $this->discoverMigrations();
        $byVersion  = array_column($discovered, null, 'version');

        $revertedVersions = [];
        $sqlLog           = [];
        $errors           = [];

        foreach ($toRevert as $row) {
            $version = $row['version'];

            if (!isset($byVersion[$version])) {
                $errors[] = ['version' => $version, 'message' => "Migration file for version {$version} not found."];
                continue;
            }

            try {
                $meta      = $byVersion[$version];
                $migration = $this->loadMigration($meta);
                $builder   = new AseSchemaBuilder($this->pdo, $this->prefix, $this->dryRun, $this->dialect);

                $this->injectSchema($migration, $builder);
                $migration->down();

                if ($this->dryRun) {
                    $sqlLog = array_merge($sqlLog, $builder->getSqlLog());
                } else {
                    $this->removeRecord($version);
                }

                $revertedVersions[] = $version;
            } catch (\Throwable $e) {
                $errors[] = ['version' => $version, 'message' => $e->getMessage()];
                break;
            }
        }

        return new MigrationResult(
            success:  empty($errors),
            applied:  [],
            reverted: $revertedVersions,
            sqlLog:   $sqlLog,
            errors:   $errors,
        );
    }

    /**
     * Drop all tracked tables (run down() in reverse), then re-run all.
     * Requires $confirm = true to prevent accidental data loss.
     */
    public function fresh(bool $confirm = false): MigrationResult
    {
        if (!$confirm) {
            throw new \LogicException("fresh() requires confirm=true. This is a destructive operation.");
        }

        // Roll back everything
        $this->ensureTrackingTable();
        $appliedRows = $this->getAppliedVersionsOrdered(descending: true);

        if (!empty($appliedRows)) {
            $this->rollback(count($appliedRows));
        }

        // Drop tracking table itself
        if (!$this->dryRun) {
            $this->pdo->exec("DROP TABLE IF EXISTS " . $this->dialect->quoteIdentifier(self::TRACKING_TABLE));
        }

        // Re-run all
        return $this->migrate();
    }

    /**
     * Return status of every discovered migration.
     * Each row: ['version', 'name', 'status', 'applied_at', 'checksum_match']
     */
    public function status(): array
    {
        $this->ensureTrackingTable();

        $discovered = $this->discoverMigrations();
        $applied    = $this->getAppliedVersionsOrdered();

        $appliedMap = [];
        foreach ($applied as $row) {
            $appliedMap[$row['version']] = $row;
        }

        $result = [];
        foreach ($discovered as $meta) {
            $isApplied = isset($appliedMap[$meta['version']]);
            $row = [
                'version'       => $meta['version'],
                'name'          => $meta['name'],
                'status'        => $isApplied ? 'applied' : 'pending',
                'applied_at'    => $isApplied ? $appliedMap[$meta['version']]['applied_at'] : null,
                'checksum_match'=> null,
            ];

            if ($isApplied) {
                $currentChecksum = $this->computeChecksum($meta['file']);
                $row['checksum_match'] = ($currentChecksum === $appliedMap[$meta['version']]['checksum']);
            }

            $result[] = $row;
        }

        return $result;
    }

    // ── Internal ──────────────────────────────────────────────────────────────

    private function ensureTrackingTable(): void
    {
        $ddl = $this->dialect->trackingTableDdl(self::TRACKING_TABLE);
        $this->pdo->exec($ddl);
    }

    /**
     * Scans $migrationsPath for PHP files matching the naming convention.
     * Returns array sorted by version (ascending).
     *
     * @return array[] Each: ['version' => string, 'name' => string, 'file' => string, 'class' => string]
     */
    private function discoverMigrations(): array
    {
        if (!is_dir($this->migrationsPath)) {
            return [];
        }

        $files = scandir($this->migrationsPath);
        if ($files === false) {
            return [];
        }

        $migrations = [];
        foreach ($files as $file) {
            if (!preg_match(self::FILE_PATTERN, $file, $m)) {
                continue;
            }

            $version   = $m[1];
            $rawName   = $m[2];
            $className = self::CLASS_PREFIX . $version . '_' . $this->snakeToStudly($rawName);

            $migrations[] = [
                'version' => $version,
                'name'    => $rawName,
                'file'    => $this->migrationsPath . DIRECTORY_SEPARATOR . $file,
                'class'   => $className,
            ];
        }

        usort($migrations, fn($a, $b) => strcmp($a['version'], $b['version']));

        return $migrations;
    }

    /**
     * @return array<string, string>  version => checksum
     */
    private function getAppliedVersions(): array
    {
        try {
            $stmt = $this->pdo->query(
                "SELECT version, checksum FROM " . $this->dialect->quoteIdentifier(self::TRACKING_TABLE)
            );
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            return array_column($rows, 'checksum', 'version');
        } catch (\PDOException $e) {
            return [];
        }
    }

    /**
     * @return array[] Full rows ordered by version
     */
    private function getAppliedVersionsOrdered(bool $descending = false): array
    {
        try {
            $order = $descending ? 'DESC' : 'ASC';
            $stmt  = $this->pdo->query(
                "SELECT version, name, applied_at, checksum FROM "
                . $this->dialect->quoteIdentifier(self::TRACKING_TABLE)
                . " ORDER BY version {$order}"
            );
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            return [];
        }
    }

    private function loadMigration(array $meta): AseBaseMigration
    {
        if (!file_exists($meta['file'])) {
            throw new \RuntimeException("Migration file not found: {$meta['file']}");
        }

        require_once $meta['file'];

        $class = $meta['class'];
        if (!class_exists($class)) {
            // Try without namespace (migrations/php files may declare global namespace)
            if (!class_exists('LemurAse\\Migrations\\' . $class)) {
                throw new \RuntimeException("Migration class '{$class}' not found in {$meta['file']}");
            }
            $class = 'LemurAse\\Migrations\\' . $class;
        }

        $builder = new AseSchemaBuilder($this->pdo, $this->prefix, $this->dryRun, $this->dialect);
        return new $class($builder);
    }

    private function injectSchema(AseBaseMigration $migration, AseSchemaBuilder $builder): void
    {
        $ref  = new \ReflectionProperty(AseBaseMigration::class, 'schema');
        $ref->setAccessible(true);
        $ref->setValue($migration, $builder);
    }

    private function recordApplied(string $version, string $name, string $checksum): void
    {
        $t    = $this->dialect->quoteIdentifier(self::TRACKING_TABLE);
        $stmt = $this->pdo->prepare(
            "INSERT INTO {$t} (version, name, checksum) VALUES (?, ?, ?)"
        );
        $stmt->execute([$version, $name, $checksum]);
    }

    private function removeRecord(string $version): void
    {
        $t    = $this->dialect->quoteIdentifier(self::TRACKING_TABLE);
        $stmt = $this->pdo->prepare("DELETE FROM {$t} WHERE version = ?");
        $stmt->execute([$version]);
    }

    private function computeChecksum(string $filePath): string
    {
        return hash_file('sha256', $filePath);
    }

    private function snakeToStudly(string $snake): string
    {
        return implode('', array_map('ucfirst', explode('_', $snake)));
    }
}
