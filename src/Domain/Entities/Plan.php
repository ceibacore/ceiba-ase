<?php

namespace LemurAse\Domain\Entities;

use LemurAse\Domain\ValueObjects\EntityId;

final class Plan extends AggregateRoot
{
    public function __construct(
        EntityId $id,
        private string $slug,
        private string $name,
        private ?string $description = null,
        private bool $isActive = true,
        private ?array $metadata = null
    ) {
        parent::__construct($id);
    }

    public function slug(): string { return $this->slug; }
    public function name(): string { return $this->name; }
    public function description(): ?string { return $this->description; }
    public function isActive(): bool { return $this->isActive; }
    
    /**
     * Get custom metadata attributes (JSON decoded).
     * 
     * @return array|null Flexible attributes: is_recommended, billing_type, features, etc.
     */
    public function metadata(): ?array { return $this->metadata; }
    
    /**
     * Get a specific metadata attribute by key.
     * 
     * @param string $key   The attribute key
     * @param mixed  $default Default value if key not found
     * @return mixed The attribute value or default
     */
    public function getMetadata(string $key, mixed $default = null): mixed
    {
        return $this->metadata[$key] ?? $default;
    }
    
    /**
     * Check if plan has a metadata attribute.
     * 
     * @param string $key The attribute key
     * @return bool True if key exists and is not null
     */
    public function hasMetadata(string $key): bool
    {
        return isset($this->metadata[$key]);
    }
}
