<?php

namespace Ssntpl\DataFields\Casts;

use Carbon\Carbon;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Relations\Relation;
use Ssntpl\DataFields\Models\DataField;
use Ssntpl\LaravelFiles\Models\File;

class FieldValueCast implements CastsAttributes
{
    public function get($model, string $key, $value, array $attributes)
    {
        if (is_null($value)) {
            return null;
        }

        return match ($attributes['type'] ?? DataField::TEXT) {
            DataField::NUMBER => (float) $value,
            DataField::BOOL => (bool) $value,
            DataField::SELECT_MULTIPLE, DataField::ARRAY => json_decode($value, true),
            DataField::JSON => json_decode($value, true),
            DataField::FILE, DataField::FILES => $this->getFileFromJson($value),
            DataField::DATE => Carbon::parse($value)->toDateString(),
            DataField::TIME => Carbon::parse($value)->toTimeString(),
            DataField::DATETIME => Carbon::parse($value),
            default => (string) $value,
        };
    }

    public function set($model, string $key, $value, array $attributes)
    {
        if (is_null($value)) {
            return null;
        }

        return match ($attributes['type'] ?? DataField::TEXT) {
            DataField::SELECT_MULTIPLE, DataField::ARRAY, DataField::JSON => json_encode($value),
            DataField::BOOL => in_array($value, ['1', 'true', 'yes', 'on', 1, true], true),
            DataField::FILE, DataField::FILES => $this->setFileAsJson($value),
            DataField::DATE => Carbon::parse($value)->toDateString(),
            DataField::TIME => Carbon::parse($value)->toTimeString(),
            DataField::DATETIME => Carbon::parse($value)->toDateTimeString(),
            default => (string) $value,
        };
    }

    private function getFileFromJson($value)
    {
        $data = json_decode($value, true);

        if (!is_array($data)) {
            return $value;
        }

        try {
            // Multiple files
            if (isset($data[0]) && is_array($data[0])) {
                return collect($data)
                    ->map(fn ($item) => $this->resolveFileReference($item))
                    ->filter()
                    ->values()
                    ->all();
            }

            // Single file
            return $this->resolveFileReference($data);
        } catch (\Throwable $e) {
            return $value;
        }
    }

    private function resolveFileReference(array $ref): ?File
    {
        if (!isset($ref['model_type'], $ref['model_id'])) {
            return null;
        }

        $class = $this->resolveModelClass($ref['model_type']);
        if ($class === null) {
            return null;
        }

        $found = $class::find($ref['model_id']);
        return $found instanceof File ? $found : null;
    }

    /**
     * Resolve a stored model_type (morph alias or FQCN) to a class string,
     * but only return it if the class is a File (or subclass). Anything else
     * is rejected — we will not autoload arbitrary classes from stored data.
     */
    private function resolveModelClass(string $stored): ?string
    {
        $class = Relation::getMorphedModel($stored) ?? $stored;

        if (!is_string($class) || $class === '') {
            return null;
        }
        if (!class_exists($class)) {
            return null;
        }
        if (!is_a($class, File::class, true)) {
            return null;
        }
        return $class;
    }

    private function setFileAsJson($value)
    {
        if (is_array($value)) {
            return json_encode(
                collect($value)
                    ->map(fn ($file) => $this->fileReference($file))
                    ->filter()
                    ->values()
                    ->all()
            );
        }

        $ref = $this->fileReference($value);
        if ($ref !== null) {
            return json_encode($ref);
        }

        return (string) $value;
    }

    private function fileReference($file): ?array
    {
        if ($file instanceof File) {
            return [
                'model_type' => $this->morphTypeFor($file),
                'model_id' => $file->getKey(),
            ];
        }
        if (is_numeric($file)) {
            return [
                'model_type' => $this->morphTypeFor(File::class),
                'model_id' => (int) $file,
            ];
        }
        return null;
    }

    /**
     * Return the morph alias if one is registered for this class/instance,
     * otherwise the FQCN. Storing the alias makes the value resilient to
     * namespace renames.
     */
    private function morphTypeFor($fileOrClass): string
    {
        $class = is_object($fileOrClass) ? get_class($fileOrClass) : $fileOrClass;
        $alias = array_search($class, Relation::morphMap() ?: [], true);
        return $alias !== false ? $alias : $class;
    }
}
