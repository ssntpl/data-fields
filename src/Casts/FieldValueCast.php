<?php

namespace Ssntpl\DataFields\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Ssntpl\DataFields\Enums\FieldType;
use Ssntpl\LaravelFiles\Models\File;

class FieldValueCast implements CastsAttributes
{
    public function get($model, string $key, $value, array $attributes)
    {
        if (is_null($value)) return null;

        return match($attributes['type'] ?? FieldType::TEXT) {
            FieldType::NUMBER => (float) $value,
            FieldType::BOOLEAN, FieldType::CHECK => (bool) $value,
            FieldType::MULTIPLE, FieldType::ARRAY => json_decode($value, true),
            FieldType::JSON => json_decode($value, true),
            FieldType::FILE => $this->getFileFromJson($value),
            default => (string) $value
        };
    }

    public function set($model, string $key, $value, array $attributes)
    {
        if (is_null($value)) return null;

        return match($attributes['type'] ?? FieldType::TEXT) {
            FieldType::MULTIPLE, FieldType::ARRAY, FieldType::JSON => json_encode($value),
            FieldType::BOOLEAN, FieldType::CHECK => $value ? '1' : '0',
            FieldType::FILE => $this->setFileAsJson($value),
            default => (string) $value
        };
    }

    private function getFileFromJson($value)
    {
        $data = json_decode($value, true);
        
        if (!is_array($data) || !isset($data['model_type'], $data['model_id'])) {
            return $value;
        }
        
        return $data['model_type']::find($data['model_id']);
    }

    private function setFileAsJson($value)
    {
        if ($value instanceof File) {
            return json_encode([
                'model_type' => get_class($value),
                'model_id' => $value->id
            ]);
        }
        
        if (is_numeric($value)) {
            return json_encode([
                'model_type' => File::class,
                'model_id' => (int) $value
            ]);
        }
        
        return (string) $value;
    }
}