<?php

namespace Ssntpl\DataFields\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Ssntpl\DataFields\Models\DataField;
use Ssntpl\LaravelFiles\Models\File;
use Carbon\Carbon;

class FieldValueCast implements CastsAttributes
{
    public function get($model, string $key, $value, array $attributes)
    {
        if (is_null($value)) return null;

        return match($attributes['type'] ?? DataField::TEXT) {
            DataField::NUMBER => (float) $value,
            DataField::BOOL => (bool) $value,
            DataField::SELECT_MULTIPLE, DataField::ARRAY => json_decode($value, true),
            DataField::JSON => json_decode($value, true),
            DataField::FILE, DataField::FILES => $this->getFileFromJson($value),
            DataField::DATE => Carbon::parse($value)->toDateString(),
            DataField::TIME => Carbon::parse($value)->toTimeString(),
            DataField::DATETIME => Carbon::parse($value),
            default => (string) $value
        };
    }

    public function set($model, string $key, $value, array $attributes)
    {
        if (is_null($value)) return null;

        return match($attributes['type'] ?? DataField::TEXT) {
            DataField::SELECT_MULTIPLE, DataField::ARRAY, DataField::JSON => json_encode($value),
            DataField::BOOL => in_array($value, ['1', 'true', 'yes', 'on'], true),
            DataField::FILE, DataField::FILES => $this->setFileAsJson($value),
            DataField::DATE => Carbon::parse($value)->toDateString(),
            DataField::TIME => Carbon::parse($value)->toTimeString(),
            DataField::DATETIME => Carbon::parse($value)->toDateTimeString(),
            default => (string) $value
        };
    }

    private function getFileFromJson($value)
    {
        $data = json_decode($value, true);
        
        if (!is_array($data)) {
            return $value;
        }
        
        try {
            // Handle multiple files
            if (isset($data[0]) && is_array($data[0])) {
                return collect($data)->map(function($item) {
                    if (isset($item['model_type'], $item['model_id'])) {
                        return $item['model_type']::find($item['model_id']);
                    }
                    return null;
                })->filter()->values()->all();
            }
            
            // Handle single file
            if (isset($data['model_type'], $data['model_id'])) {
                return $data['model_type']::find($data['model_id']);
            }
        } catch (\Exception $e) {
            // Return original value if database connection fails
            return $value;
        }
        
        return $value;
    }

    private function setFileAsJson($value)
    {
        // Handle array of files
        if (is_array($value)) {
            return json_encode(collect($value)->map(function($file) {
                if ($file instanceof File) {
                    return [
                        'model_type' => get_class($file),
                        'model_id' => $file->id
                    ];
                }
                if (is_numeric($file)) {
                    return [
                        'model_type' => File::class,
                        'model_id' => (int) $file
                    ];
                }
                return null;
            })->filter()->values()->all());
        }
        
        // Handle single file
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