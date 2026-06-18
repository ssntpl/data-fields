<?php

namespace Ssntpl\DataFields\Support;

use Carbon\Carbon;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Database\Eloquent\Relations\Relation;
use Ssntpl\DataFields\Models\DataField;
use Ssntpl\LaravelFiles\Models\File;

/**
 * Single source of truth for value casting across row-mode and JSON-mode storage.
 *
 * Row mode (FieldValueCast): values live in a TEXT column. The cast must
 * round-trip strings ↔ PHP types and JSON-encode the structured types
 * (json/array/select_multiple/file/files).
 *
 * JSON mode (HasDataFieldsJson): values live inside a larger JSON document
 * that the surrounding column cast has already decoded into a native PHP
 * structure. The cast only needs to normalise types — no inner JSON encoding.
 *
 * Bool handling:
 *  - Row mode stores '1' / '0' as canonical strings (portable across SQLite,
 *    MySQL, PostgreSQL — PHP (bool)'false' === true would break a naive read).
 *  - JSON mode stores PHP true / false natively (JSON has booleans).
 *  - Both write paths accept the usual truthy strings (case-insensitive).
 *
 * Date handling:
 *  - Read paths defensively catch Carbon parse failures and return null, so a
 *    malformed DB row can't crash a Builder::get() call.
 *  - Write paths throw on bad input (data integrity at the write boundary).
 */
class ValueCaster
{
    private const BOOL_TRUTHY = ['1', 'true', 'yes', 'on'];

    /**
     * Cast a raw row-mode string from the DB column to a PHP value.
     */
    public static function castForRead(string $type, ?string $raw): mixed
    {
        if (is_null($raw)) {
            return null;
        }

        return match ($type) {
            DataField::NUMBER                                            => (float) $raw,
            DataField::BOOL                                              => self::toBool($raw),
            DataField::SELECT_MULTIPLE, DataField::ARRAY, DataField::JSON => json_decode($raw, true),
            DataField::FILE, DataField::FILES                            => self::resolveFiles(json_decode($raw, true)),
            DataField::DATE                                              => self::tryParse($raw, fn (Carbon $c) => $c->toDateString()),
            DataField::TIME                                              => self::tryParse($raw, fn (Carbon $c) => $c->toTimeString()),
            DataField::DATETIME                                          => self::tryParse($raw, fn (Carbon $c) => $c),
            default                                                      => (string) $raw,
        };
    }

    /**
     * Cast a PHP value to the raw string stored in a row-mode DB column.
     */
    public static function castForWrite(string $type, mixed $value): mixed
    {
        if (is_null($value)) {
            return null;
        }

        return match ($type) {
            DataField::SELECT_MULTIPLE, DataField::ARRAY, DataField::JSON => json_encode($value),
            DataField::BOOL                                              => self::toBool($value) ? '1' : '0',
            DataField::FILE, DataField::FILES                            => self::encodeFiles($value, asJsonString: true),
            DataField::DATE                                              => Carbon::parse($value)->toDateString(),
            DataField::TIME                                              => Carbon::parse($value)->toTimeString(),
            DataField::DATETIME                                          => Carbon::parse($value)->toDateTimeString(),
            default                                                      => (string) $value,
        };
    }

    /**
     * Cast a JSON-mode value (already JSON-decoded by the surrounding column
     * cast) to its PHP form. Structured types pass through without re-decoding.
     */
    public static function castNativeRead(string $type, mixed $value): mixed
    {
        if (is_null($value)) {
            return null;
        }

        return match ($type) {
            DataField::NUMBER                                            => (float) $value,
            DataField::BOOL                                              => self::toBool($value),
            DataField::SELECT_MULTIPLE, DataField::ARRAY, DataField::JSON => is_string($value) ? json_decode($value, true) : $value,
            DataField::FILE, DataField::FILES                            => self::resolveFiles(is_string($value) ? json_decode($value, true) : $value),
            DataField::DATE                                              => self::tryParse($value, fn (Carbon $c) => $c->toDateString()),
            DataField::TIME                                              => self::tryParse($value, fn (Carbon $c) => $c->toTimeString()),
            DataField::DATETIME                                          => self::tryParse($value, fn (Carbon $c) => $c),
            default                                                      => (string) $value,
        };
    }

    /**
     * Cast a PHP value to its JSON-mode native form (to be embedded directly
     * in the larger JSON document — no inner string encoding).
     */
    public static function castNativeWrite(string $type, mixed $value): mixed
    {
        if (is_null($value)) {
            return null;
        }

        return match ($type) {
            DataField::SELECT_MULTIPLE, DataField::ARRAY, DataField::JSON => $value,
            DataField::BOOL                                              => self::toBool($value),
            DataField::FILE, DataField::FILES                            => self::encodeFiles($value, asJsonString: false),
            DataField::DATE                                              => Carbon::parse($value)->toDateString(),
            DataField::TIME                                              => Carbon::parse($value)->toTimeString(),
            DataField::DATETIME                                          => Carbon::parse($value)->toDateTimeString(),
            default                                                      => (string) $value,
        };
    }

    /**
     * Normalise any reasonable truthy/falsy representation to a PHP bool.
     * Accepts: PHP bool, 0/1 int, '1'/'0'/'true'/'false'/'yes'/'no'/'on'/'off'
     * (case-insensitive). Anything else returns false.
     */
    private static function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value === 1;
        }
        if (is_string($value)) {
            return in_array(strtolower($value), self::BOOL_TRUTHY, true);
        }
        return false;
    }

    /**
     * Try Carbon::parse and apply a transform. Returns null if parsing fails —
     * a malformed DB value must not crash a Builder::get() call. Write paths
     * still throw on bad input (Carbon::parse directly).
     */
    private static function tryParse(mixed $value, \Closure $transform): mixed
    {
        if ($value instanceof Carbon) {
            return $transform($value);
        }
        try {
            return $transform(Carbon::parse($value));
        } catch (InvalidFormatException|\InvalidArgumentException $e) {
            return null;
        }
    }

    /**
     * Decode an already-decoded array of file references into File models.
     * `$decoded` is either a single ref `{model_type, model_id}`, an array of
     * refs, or null/garbage (in which case we return the input unchanged).
     */
    private static function resolveFiles(mixed $decoded): mixed
    {
        if (!is_array($decoded)) {
            return $decoded;
        }

        try {
            // Multi-file: numerically indexed array of refs.
            if (isset($decoded[0]) && is_array($decoded[0])) {
                return collect($decoded)
                    ->map(fn ($item) => self::resolveOneFile($item))
                    ->filter()
                    ->values()
                    ->all();
            }

            return self::resolveOneFile($decoded);
        } catch (\Throwable $e) {
            return $decoded;
        }
    }

    private static function resolveOneFile(array $ref): ?File
    {
        if (!isset($ref['model_type'], $ref['model_id'])) {
            return null;
        }

        $class = self::resolveModelClass($ref['model_type']);
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
    public static function resolveModelClass(string $stored): ?string
    {
        $class = Relation::getMorphedModel($stored) ?? $stored;

        if (!is_string($class) || $class === '' || !class_exists($class)) {
            return null;
        }
        if (!is_a($class, File::class, true)) {
            return null;
        }
        return $class;
    }

    /**
     * Convert a file/files PHP value to either a JSON string (row mode) or a
     * native PHP array (JSON mode).
     */
    private static function encodeFiles(mixed $value, bool $asJsonString): mixed
    {
        $ref = self::toFileReference($value);
        if ($ref === null) {
            return $asJsonString ? (string) $value : $value;
        }
        return $asJsonString ? json_encode($ref) : $ref;
    }

    private static function toFileReference(mixed $value): mixed
    {
        if (is_array($value)) {
            return collect($value)
                ->map(fn ($file) => self::oneFileReference($file))
                ->filter()
                ->values()
                ->all();
        }
        return self::oneFileReference($value);
    }

    private static function oneFileReference(mixed $file): ?array
    {
        if ($file instanceof File) {
            return [
                'model_type' => self::morphTypeFor($file),
                'model_id'   => $file->getKey(),
            ];
        }
        if (is_numeric($file)) {
            return [
                'model_type' => self::morphTypeFor(File::class),
                'model_id'   => (int) $file,
            ];
        }
        return null;
    }

    /**
     * Return the morph alias if one is registered for this class/instance,
     * otherwise the FQCN. Storing the alias makes the value resilient to
     * namespace renames.
     */
    public static function morphTypeFor(mixed $fileOrClass): string
    {
        $class = is_object($fileOrClass) ? get_class($fileOrClass) : $fileOrClass;
        $alias = array_search($class, Relation::morphMap() ?: [], true);
        return $alias !== false ? $alias : $class;
    }
}
