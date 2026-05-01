<?php

namespace LemurAse\Tests\Domain\ValueObjects;

use PHPUnit\Framework\TestCase;
use LemurAse\Domain\ValueObjects\EntityId;

class EntityIdTest extends TestCase
{
    public function testGenerateCreatesValidUuidAndShortId()
    {
        $id = EntityId::generate();
        
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $id->uuid()
        );
        
        $this->assertEquals(12, strlen($id->short()));
        // Short ID should be the last 12 chars of the UUID
        $this->assertEquals(substr($id->uuid(), -12), $id->short());
    }

    public function testFromStringRestoresIdCorrectly()
    {
        $uuid = '123e4567-e89b-12d3-a456-426614174000';
        $id = EntityId::fromString($uuid);
        
        $this->assertEquals($uuid, $id->uuid());
        $this->assertEquals('426614174000', $id->short());
    }

    public function testToStringReturnsUuid()
    {
        $uuid = '123e4567-e89b-12d3-a456-426614174000';
        $id = EntityId::fromString($uuid);
        
        $this->assertEquals($uuid, (string)$id);
    }
}
