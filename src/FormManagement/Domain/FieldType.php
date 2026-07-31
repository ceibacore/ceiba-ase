<?php

declare(strict_types=1);

namespace LemurAse\FormManagement\Domain;

enum FieldType: string
{
    case TEXT = 'text';
    case DATE = 'date';
    case DATETIME = 'datetime';
    case TIME = 'time';
    case PASSWORD = 'password';
    case CHECKBOX = 'checkbox';
    case SELECT = 'select';
    case TEXTAREA = 'textarea';
    case NUMBER = 'number';
    case PHONE = 'phone';
    case EMAIL = 'email';
    case URL = 'url';

    /** Whitelist for safe rendering — prevents injection via custom JSON definitions */
    public static function fromTrusted(string $value): self
    {
        return self::from($value); // throws ValueError on unknown type
    }
}
