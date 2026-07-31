<?php

namespace Tests\Unit\Domain\Entities;

use LemurAse\Domain\Entities\Plan;
use LemurAse\Domain\ValueObjects\EntityId;
use PHPUnit\Framework\TestCase;

final class PlanMetadataTest extends TestCase
{
    /**
     * Test storing and retrieving metadata from a plan.
     */
    public function testStoreAndRetrieveMetadata(): void
    {
        $metadata = [
            'is_recommended' => true,
            'billing_type' => 'annual',
            'max_users' => 100,
            'support_level' => 'premium'
        ];

        $plan = new Plan(
            EntityId::generate(),
            'pro-annual',
            'Pro Annual',
            'Professional plan billed annually',
            true,
            $metadata
        );

        $this->assertSame($metadata, $plan->metadata());
        $this->assertTrue($plan->getMetadata('is_recommended'));
        $this->assertEquals('annual', $plan->getMetadata('billing_type'));
        $this->assertEquals(100, $plan->getMetadata('max_users'));
    }

    /**
     * Test retrieving metadata with default value.
     */
    public function testGetMetadataWithDefault(): void
    {
        $plan = new Plan(
            EntityId::generate(),
            'basic',
            'Basic Plan',
            null,
            true,
            ['max_users' => 10]
        );

        $this->assertEquals(10, $plan->getMetadata('max_users'));
        $this->assertEquals('default_value', $plan->getMetadata('missing_key', 'default_value'));
        $this->assertNull($plan->getMetadata('missing_key'));
    }

    /**
     * Test checking if metadata key exists.
     */
    public function testHasMetadata(): void
    {
        $plan = new Plan(
            EntityId::generate(),
            'starter',
            'Starter Plan',
            null,
            true,
            ['features' => ['export', 'api'], 'support_email' => null]
        );

        $this->assertTrue($plan->hasMetadata('features'));
        $this->assertFalse($plan->hasMetadata('missing_key'));
        $this->assertFalse($plan->hasMetadata('support_email')); // null is not "has"
    }

    /**
     * Test plan with null metadata.
     */
    public function testPlanWithNullMetadata(): void
    {
        $plan = new Plan(
            EntityId::generate(),
            'free',
            'Free Plan',
            'No features',
            true,
            null
        );

        $this->assertNull($plan->metadata());
        $this->assertNull($plan->getMetadata('any_key'));
        $this->assertFalse($plan->hasMetadata('any_key'));
    }

    /**
     * Test plan with empty metadata array.
     */
    public function testPlanWithEmptyMetadata(): void
    {
        $plan = new Plan(
            EntityId::generate(),
            'minimal',
            'Minimal Plan',
            null,
            true,
            []
        );

        $this->assertEquals([], $plan->metadata());
        $this->assertNull($plan->getMetadata('any_key'));
        $this->assertFalse($plan->hasMetadata('any_key'));
    }

    /**
     * Test metadata with nested structures.
     */
    public function testMetadataWithNestedStructures(): void
    {
        $metadata = [
            'features' => [
                'basic' => ['users', 'storage'],
                'advanced' => ['api', 'webhooks', 'sso']
            ],
            'pricing' => [
                'monthly' => 99,
                'annual' => 999
            ]
        ];

        $plan = new Plan(
            EntityId::generate(),
            'enterprise',
            'Enterprise Plan',
            null,
            true,
            $metadata
        );

        $this->assertEquals($metadata, $plan->metadata());
        $this->assertEquals(['users', 'storage'], $plan->getMetadata('features')['basic']);
        $this->assertEquals(999, $plan->getMetadata('pricing')['annual']);
    }
}
