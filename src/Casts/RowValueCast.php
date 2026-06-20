<?php

namespace Ssntpl\DataFields\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Ssntpl\DataFields\Support\FieldType;
use Ssntpl\DataFields\Support\ValueCaster;

/**
 * Cast for the `value` column on a `DataRow`. Discriminates by the row's
 * `type` attribute and delegates to `ValueCaster::castForRead` /
 * `castForWrite` (the string-encoded boundary used by row mode).
 *
 * Not used by cast-mode storage — `Support\DataField` calls `ValueCaster`
 * directly via the native (no-string) entry points.
 */
class RowValueCast implements CastsAttributes
{
    public function get($model, string $key, $value, array $attributes)
    {
        return ValueCaster::castForRead($attributes['type'] ?? FieldType::Text->value, $value);
    }

    public function set($model, string $key, $value, array $attributes)
    {
        return ValueCaster::castForWrite($attributes['type'] ?? FieldType::Text->value, $value);
    }
}
