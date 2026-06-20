<?php

namespace Ssntpl\DataFields\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Ssntpl\DataFields\Support\DataField;

/**
 * Laravel custom cast bridging a JSON column to a `DataField` value object.
 *
 * Consumers never reference this class directly — they write `DataField::class`
 * in `$casts` and Laravel resolves to here via `DataField::castUsing()`.
 *
 * Mutability is handled by re-serialise-on-save: Laravel calls `set()` at
 * persist time, comparing the resulting JSON string against `getOriginal()`
 * to decide dirtiness. The DataField object stays decoupled from its parent
 * model — no back-references, no observer wiring.
 */
class DataFieldCast implements CastsAttributes
{
    public function get($model, string $key, $value, array $attributes): ?DataField
    {
        if ($value === null || $value === '') {
            return null;
        }
        $decoded = is_string($value) ? json_decode($value, true) : $value;
        if (!is_array($decoded) || $decoded === []) {
            return null;
        }
        return DataField::fromArray($decoded);
    }

    public function set($model, string $key, $value, array $attributes): array
    {
        if ($value === null) {
            return [$key => null];
        }
        if (is_array($value)) {
            $value = DataField::fromArray($value);
        }
        if (!$value instanceof DataField) {
            throw new \InvalidArgumentException(
                "Attribute `$key` must be a DataField, array, or null"
            );
        }
        return [$key => json_encode($value->toArray())];
    }
}
