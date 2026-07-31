<?php

namespace Tests\Integration;

use LemurAse\Domain\Repositories\PlanRepositoryInterface;
use LemurAse\Domain\ValueObjects\EntityId;
use LemurAse\Infrastructure\Persistence\LemurPlanRepository;
use LemurAse\Shared\LemurInstance;
use PHPUnit\Framework\TestCase;

/**
 * Integration test for plan metadata migration and persistence.
 * 
 * This test verifies:
 * 1. The metadata column can store JSON data
 * 2. Repository properly encodes/decodes metadata
 * 3. Metadata survives round-trip save/load
 */
final class PlanMetadataMigrationTest extends TestCase
{
    private PlanRepositoryInterface $planRepo;
    private \PDO $pdo;

    protected function setUp(): void
    {
        // Use test database connection (defined in phpunit.xml)
        $this->pdo = $this->getTestPdo();
        
        // Initialize LemurInstance with test PDO
        LemurInstance::boot([
            'db' => [
                'pdo' => $this->pdo,
                'prefix' => 'ase_'
            ]
        ]);

        $this->planRepo = new LemurPlanRepository();
    }

    /**
     * Test that metadata column exists and accepts JSON.
     */
    public function testMetadataColumnExists(): void
    {
        // Check if metadata column exists in ase_plans table
        $columns = $this->getTableColumns('ase_plans');
        $this->assertArrayHasKey('metadata', $columns);
        $this->assertStringContainsString('json', strtolower($columns['metadata'] ?? ''));
    }

    /**
     * Test saving and retrieving plan with metadata.
     */
    public function testPlanMetadataRoundTrip(): void
    {
        // Create test metadata
        $metadata = [
            'is_recommended' => true,
            'billing_type' => 'annual',
            'max_users' => 100,
            'features' => ['export', 'api'],
            'support_level' => 'premium'
        ];

        // Create and save plan with metadata
        $plan = new \LemurAse\Domain\Entities\Plan(
            EntityId::generate(),
            'test-metadata-plan',
            'Test Metadata Plan',
            'Plan with metadata attributes',
            true,
            $metadata
        );

        $this->planRepo->save($plan);

        // Retrieve plan and verify metadata
        $retrieved = $this->planRepo->findBySlug('test-metadata-plan');
        
        $this->assertNotNull($retrieved);
        $this->assertEquals($metadata, $retrieved->metadata());
        $this->assertTrue($retrieved->getMetadata('is_recommended'));
        $this->assertEquals('annual', $retrieved->getMetadata('billing_type'));
        $this->assertEquals(100, $retrieved->getMetadata('max_users'));
    }

    /**
     * Test plan with null metadata.
     */
    public function testPlanWithNullMetadata(): void
    {
        $plan = new \LemurAse\Domain\Entities\Plan(
            EntityId::generate(),
            'test-no-metadata',
            'Test No Metadata',
            null,
            true,
            null
        );

        $this->planRepo->save($plan);

        $retrieved = $this->planRepo->findBySlug('test-no-metadata');
        $this->assertNotNull($retrieved);
        $this->assertNull($retrieved->metadata());
    }

    /**
     * Test metadata with nested structures persists correctly.
     */
    public function testNestedMetadataPersistence(): void
    {
        $complexMetadata = [
            'tiers' => [
                'basic' => ['users' => 5, 'storage' => 10],
                'pro' => ['users' => 50, 'storage' => 100],
                'enterprise' => ['users' => null, 'storage' => null]
            ],
            'pricing' => [
                'monthly' => 99.99,
                'annual' => 999.99
            ]
        ];

        $plan = new \LemurAse\Domain\Entities\Plan(
            EntityId::generate(),
            'test-nested',
            'Test Nested Metadata',
            null,
            true,
            $complexMetadata
        );

        $this->planRepo->save($plan);

        $retrieved = $this->planRepo->findBySlug('test-nested');
        $this->assertEquals($complexMetadata, $retrieved->metadata());
        $this->assertEquals(5, $retrieved->getMetadata('tiers')['basic']['users']);
    }

    // Helper methods

    private function getTestPdo(): \PDO
    {
        // Attempt to connect using test environment variables or defaults
        $host = getenv('TEST_DB_HOST') ?: 'localhost';
        $port = getenv('TEST_DB_PORT') ?: 3306;
        $name = getenv('TEST_DB_NAME') ?: 'lemur_ase_test';
        $user = getenv('TEST_DB_USER') ?: 'root';
        $pass = getenv('TEST_DB_PASS') ?: '';

        try {
            return new \PDO(
                "mysql:host=$host;port=$port;dbname=$name",
                $user,
                $pass,
                [
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                    \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC
                ]
            );
        } catch (\PDOException $e) {
            $this->markTestSkipped("Test database not available: " . $e->getMessage());
        }
    }

    private function getTableColumns(string $tableName): array
    {
        $stmt = $this->pdo->query("DESC $tableName");
        if (!$stmt) {
            return [];
        }

        $columns = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $columns[$row['Field']] = $row['Type'];
        }
        return $columns;
    }
}
