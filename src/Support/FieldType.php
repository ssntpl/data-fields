<?php

namespace Ssntpl\DataFields\Support;

/**
 * Single source of truth for the field-type vocabulary used by both the
 * cast (`DataField` value object) and the row-mode model (`DataRow`).
 *
 * Backed by strings because JSON storage and the row-mode `type` column
 * both hold the literal type string — the enum is purely a PHP-side type
 * safety / exhaustiveness aid.
 */
enum FieldType: string
{
    // --- leaves ----------------------------------------------------------
    case Bool           = 'bool';
    case Text           = 'text';
    case Number         = 'number';
    case SelectSingle   = 'select_single';
    case SelectMultiple = 'select_multiple';
    case Date           = 'date';
    case Time           = 'time';
    case DateTime       = 'datetime';
    case File           = 'file';
    case Files          = 'files';
    case Json           = 'json';
    case Array_         = 'array';

    // --- containers ------------------------------------------------------
    case Step    = 'step';
    case Section = 'section';
    case Group   = 'group';

    public function isLeaf(): bool
    {
        return !$this->isContainer();
    }

    public function isContainer(): bool
    {
        return match ($this) {
            self::Step, self::Section, self::Group => true,
            default                                => false,
        };
    }

    /**
     * Accepts a `FieldType` instance OR a raw string (e.g. from stored JSON).
     * Returns the enum or throws `\ValueError` on unknown input — same shape
     * `FieldType::from()` already gives us, but transparent to enum input.
     */
    public static function coerce(self|string $value): self
    {
        return $value instanceof self ? $value : self::from($value);
    }

    /**
     * Convenience: enumerate just the leaves or just the containers.
     *
     * @return list<self>
     */
    public static function leaves(): array
    {
        return array_values(array_filter(self::cases(), fn (self $t) => $t->isLeaf()));
    }

    /**
     * @return list<self>
     */
    public static function containers(): array
    {
        return array_values(array_filter(self::cases(), fn (self $t) => $t->isContainer()));
    }
}
