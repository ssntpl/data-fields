<?php

namespace Ssntpl\DataFields\Contracts;

/**
 * Storage-agnostic contract for a single field.
 *
 * Implemented by:
 *  - Ssntpl\DataFields\Models\DataField   (row mode — Eloquent attributes)
 *  - Ssntpl\DataFields\Support\FieldValue (json mode — readonly value object)
 *
 * Consumers can iterate `$model->dataFields()` / `$model->fields` and read
 * properties without caring which storage backend produced the value.
 *
 * @property-read string      $key          field identifier
 * @property-read string      $type         one of DataField::* constants
 * @property-read mixed       $value        cast value (bool/float/Carbon/File/...)
 * @property-read ?string     $label        optional human label (json mode only; null in row mode)
 * @property-read ?string     $description  optional helper text
 * @property-read array       $validations  Laravel validation rules
 * @property-read array       $meta         arbitrary meta bag (maps to `meta_data` column in row mode)
 */
interface FieldLike
{
    /**
     * Whether this field is currently visible (resolved through `visible_if`).
     * Row-mode fields are always visible — visibility only meaningfully applies
     * to JSON-mode fields that reference a schema with conditional display.
     */
    public function isVisible(): bool;
}
