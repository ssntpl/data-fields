<?php

namespace Ssntpl\DataFields\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Ssntpl\DataFields\Models\DataField;
use Ssntpl\DataFields\Support\ValueCaster;

class FieldValueCast implements CastsAttributes
{
    public function get($model, string $key, $value, array $attributes)
    {
        return ValueCaster::castForRead($attributes['type'] ?? DataField::TEXT, $value);
    }

    public function set($model, string $key, $value, array $attributes)
    {
        return ValueCaster::castForWrite($attributes['type'] ?? DataField::TEXT, $value);
    }
}
