<?php

namespace Ssntpl\DataFields\Concerns;

use Ssntpl\DataFields\Models\DataRow;
use Ssntpl\DataFields\Support\FieldType;

/**
 * Row-mode trait: data fields live as rows in the `data_fields` table,
 * indexed by `(owner_id, owner_type, key)`. The Eloquent model returned by
 * `fields()` is `Ssntpl\DataFields\Models\DataRow` (configurable via
 * `config('data-fields.data_row_model')`).
 *
 * For the cast-mode shape (one self-describing JSON document per column),
 * attach the `Ssntpl\DataFields\Casts\DataFieldCast` cast to the column or
 * write `DataField::class` in `$casts` — the two modes are independent and
 * don't share an API surface.
 */
trait HasDataFields
{
    public function fields()
    {
        return $this->morphMany($this->getDataRowModel(), 'owner');
    }

    /**
     * Read the cast value of a single field by key. Returns null when the
     * key isn't attached to this owner.
     */
    public function getFieldValue(string $key): mixed
    {
        return $this->fields()->where('key', $key)->first()?->value;
    }

    /**
     * Upsert a single field by key. Creates the row if absent, updates the
     * value (and type, if provided) if present. Returns the row.
     *
     * Type is required when creating because the column is NOT NULL; on
     * update, the existing type is preserved unless overridden.
     */
    public function setFieldValue(string $key, mixed $value, FieldType|string|null $type = null): DataRow
    {
        $row = $this->fields()->where('key', $key)->first();

        if ($row === null) {
            $type ??= FieldType::Text;
            return $this->fields()->create([
                'key'   => $key,
                'value' => $value,
                'type'  => $type instanceof FieldType ? $type->value : $type,
            ]);
        }

        if ($type !== null) {
            $row->type = $type instanceof FieldType ? $type->value : $type;
        }
        $row->value = $value;
        $row->save();
        return $row;
    }

    protected function getDataRowModel(): string
    {
        return config('data-fields.data_row_model', DataRow::class);
    }
}
