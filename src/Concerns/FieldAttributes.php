<?php

namespace Ssntpl\DataFields\Concerns;

use Ssntpl\DataFields\Support\FieldType;

/**
 * Shared shape-level behaviour between the cast value object
 * (`Support\DataField`) and the row-mode Eloquent model (`Models\DataRow`).
 *
 * Intentionally narrow — helper methods that read `$this->type` only. Data
 * shape (declared properties vs Eloquent attributes) is each class's own
 * concern, since declaring public properties on the trait would shadow
 * Eloquent's magic attribute access on `DataRow`.
 *
 * Expectation: the consuming class exposes `$this->type` as either a
 * `FieldType` enum or a raw string. `fieldType()` coerces it.
 */
trait FieldAttributes
{
    protected function fieldType(): FieldType
    {
        return FieldType::coerce($this->type);
    }

    public function isLeaf(): bool
    {
        return $this->fieldType()->isLeaf();
    }

    public function isContainer(): bool
    {
        return $this->fieldType()->isContainer();
    }
}
