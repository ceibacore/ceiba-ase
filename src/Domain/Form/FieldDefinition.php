<?php

declare(strict_types=1);

namespace LemurAse\Domain\Form;

/**
 * Immutable value object describing a single form field.
 *
 * Validation rules (optional):
 *   pattern   => ECMA regex string (server-side applied via preg_match)
 *   minLength => int
 *   maxLength => int
 *   min       => numeric (for NUMBER type)
 *   max       => numeric (for NUMBER type)
 *   enum      => string[] (for SELECT type — valid values)
 *
 * Condition (optional — field is visible only when):
 *   ['field' => 'other_field_name', 'operator' => 'eq', 'value' => true]
 *   Supported operators: eq, neq, in, nin
 */
final class FieldDefinition
{
    public function __construct(
        private readonly string    $name,
        private readonly FieldType $type,
        private readonly string    $label,
        private readonly bool      $sensitive   = false,
        private readonly bool      $required    = false,
        private readonly ?string   $placeholder = null,
        private readonly ?string   $helpText    = null,
        private readonly mixed     $default     = null,
        private readonly array     $validation  = [],
        private readonly ?array    $condition   = null,
        private readonly ?string   $group       = null,
        private readonly array     $options     = [],  // SELECT: [['value'=>'x','label'=>'X']]
    ) {}

    public function name(): string       { return $this->name; }
    public function type(): FieldType    { return $this->type; }
    public function label(): string      { return $this->label; }
    public function isSensitive(): bool  { return $this->sensitive; }
    public function isRequired(): bool   { return $this->required; }
    public function placeholder(): ?string { return $this->placeholder; }
    public function helpText(): ?string  { return $this->helpText; }
    public function default(): mixed     { return $this->default; }
    public function validation(): array  { return $this->validation; }
    public function condition(): ?array  { return $this->condition; }
    public function group(): ?string     { return $this->group; }
    public function options(): array     { return $this->options; }

    /**
     * Serialize to array — safe for JSON output to frontend.
     * Validation patterns and help text included; no internal state leaked.
     */
    public function toArray(): array
    {
        return [
            'name'        => $this->name,
            'type'        => $this->type->value,
            'label'       => $this->label,
            'sensitive'   => $this->sensitive,
            'required'    => $this->required,
            'placeholder' => $this->placeholder,
            'help_text'   => $this->helpText,
            'default'     => $this->default,
            'validation'  => $this->validation,
            'condition'   => $this->condition,
            'group'       => $this->group,
            'options'     => $this->options,
        ];
    }

    /**
     * Build from an array (e.g., loaded from a JSON definition file).
     * Validates the type against the FieldType whitelist.
     *
     * @throws \ValueError  if 'type' is not a valid FieldType value
     * @throws \InvalidArgumentException if 'name' or 'label' are missing
     */
    public static function fromArray(array $data): self
    {
        if (empty($data['name']) || empty($data['label'])) {
            throw new \InvalidArgumentException("FieldDefinition requires 'name' and 'label'.");
        }

        return new self(
            name:        (string) $data['name'],
            type:        FieldType::fromTrusted((string) ($data['type'] ?? 'text')),
            label:       (string) $data['label'],
            sensitive:   (bool) ($data['sensitive'] ?? false),
            required:    (bool) ($data['required'] ?? false),
            placeholder: isset($data['placeholder']) ? (string) $data['placeholder'] : null,
            helpText:    isset($data['help_text']) ? (string) $data['help_text'] : null,
            default:     $data['default'] ?? null,
            validation:  (array) ($data['validation'] ?? []),
            condition:   isset($data['condition']) ? (array) $data['condition'] : null,
            group:       isset($data['group']) ? (string) $data['group'] : null,
            options:     (array) ($data['options'] ?? []),
        );
    }
}
