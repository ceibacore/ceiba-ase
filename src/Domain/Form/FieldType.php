<?php

declare(strict_types=1);

namespace LemurAse\Domain\Form;

enum FieldType: string
{
    case TEXT     = 'text';
    case PASSWORD = 'password';
    case CHECKBOX = 'checkbox';
    case SELECT   = 'select';
    case TEXTAREA = 'textarea';
    case NUMBER   = 'number';
    case EMAIL    = 'email';
    case URL      = 'url';

    /** Whitelist for safe rendering — prevents injection via custom JSON definitions */
    public static function fromTrusted(string $value): self
    {
        return self::from($value); // throws ValueError on unknown type
    }
}
