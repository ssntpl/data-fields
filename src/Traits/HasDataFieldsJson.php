<?php

namespace Ssntpl\DataFields\Traits;

use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Ssntpl\DataFields\Models\DataField;
use Ssntpl\DataFields\Support\FieldValue;
use Ssntpl\DataFields\Support\SchemaValidator;
use Ssntpl\DataFields\Support\ValueCaster;

/**
 * JSON-mode storage: schema + values live as JSON columns on the model itself.
 *
 * Mirrors row-mode (HasDataFields) for the value-reading API but reads/writes
 * a structured JSON document rather than rows in `data_fields`. The same set
 * of field types and the same casting logic (via Support\ValueCaster) apply,
 * so a `file` field in row mode and a `file` field in JSON mode round-trip
 * identically.
 *
 * See docs/JSON_MODE_SPEC.md for the full specification.
 *
 * Column defaults can be overridden per-model by overriding the getter
 * methods (PHP trait-property defaults clash with subclass overrides, so we
 * use methods, not properties):
 *
 *   protected function getDataFieldsSchemaColumn(): ?string { return 'log_format'; }
 *   protected function getDataFieldsValuesColumn(): ?string { return 'entries'; }
 *
 * To skip the schema column entirely (when the schema lives on a parent
 * record), return null and also override getDataFieldsSchema():
 *
 *   protected function getDataFieldsSchemaColumn(): ?string { return null; }
 *   public function getDataFieldsSchema(): array { return $this->schedule->log_format ?? []; }
 */
trait HasDataFieldsJson
{
    /**
     * Per-instance memoisation of unwrapped schema/values. Reads are O(1)
     * after the first call. Setters invalidate. Consumers who mutate the
     * underlying column attribute by hand (raw $model->attributes writes,
     * $model->refresh()) should call clearDataFieldsCache() to drop the cache.
     */
    private ?array $cachedDataFieldsSchema = null;
    private ?array $cachedDataFieldsValues = null;

    protected function getDataFieldsSchemaColumn(): ?string
    {
        return 'data_fields_schema';
    }

    protected function getDataFieldsValuesColumn(): ?string
    {
        return 'data_fields_values';
    }

    public function clearDataFieldsCache(): static
    {
        $this->cachedDataFieldsSchema = null;
        $this->cachedDataFieldsValues = null;
        return $this;
    }

    /**
     * Register `array` casts on the configured columns at model init time, so
     * consumers don't have to remember to add them to $casts themselves.
     */
    public function initializeHasDataFieldsJson(): void
    {
        $casts = [];
        if (($col = $this->getDataFieldsSchemaColumn()) !== null) {
            $casts[$col] = 'array';
        }
        if (($col = $this->getDataFieldsValuesColumn()) !== null) {
            $casts[$col] = 'array';
        }
        if ($casts !== []) {
            $this->mergeCasts($casts);
        }
    }

    // -----------------------------------------------------------------
    // Schema
    // -----------------------------------------------------------------

    public function getDataFieldsSchema(): array
    {
        if ($this->cachedDataFieldsSchema !== null) {
            return $this->cachedDataFieldsSchema;
        }
        $col = $this->getDataFieldsSchemaColumn();
        if ($col === null) {
            return [];
        }
        return $this->cachedDataFieldsSchema = $this->unwrapEnvelope($this->{$col} ?? [], 'schema');
    }

    public function setDataFieldsSchema(array $schema): static
    {
        $col = $this->getDataFieldsSchemaColumn();
        if ($col === null) {
            throw new \LogicException(
                static::class . ' has no schema column configured; override getDataFieldsSchema() to write a schema.'
            );
        }
        $this->{$col} = $this->wrapEnvelope($schema, 'schema');
        $this->cachedDataFieldsSchema = null;
        return $this;
    }

    // -----------------------------------------------------------------
    // Values
    // -----------------------------------------------------------------

    public function getDataFieldsValues(): array
    {
        if ($this->cachedDataFieldsValues !== null) {
            return $this->cachedDataFieldsValues;
        }
        $col = $this->getDataFieldsValuesColumn();
        if ($col === null) {
            return [];
        }
        return $this->cachedDataFieldsValues = $this->unwrapEnvelope($this->{$col} ?? [], 'values');
    }

    public function setDataFieldsValues(array $values): static
    {
        $col = $this->getDataFieldsValuesColumn();
        if ($col === null) {
            throw new \LogicException(
                static::class . ' has no values column configured.'
            );
        }
        if (config('data-fields.json.strict_writes', false)) {
            $values = $this->dropUnknownKeys($values, $this->getDataFieldsSchema());
        }
        $this->{$col} = $this->wrapEnvelope($values, 'values');
        $this->cachedDataFieldsValues = null;
        return $this;
    }

    // -----------------------------------------------------------------
    // Per-field read/write — supports dotted paths for container schemas
    // -----------------------------------------------------------------

    public function getFieldValue(string $key): mixed
    {
        $schema = $this->getDataFieldsSchema();
        $values = $this->getDataFieldsValues();

        $node = $this->findLeafNode($schema, $key);
        if ($node === null) {
            return null;
        }

        $parts = $this->splitPath($key);
        $hasValue = $this->pathExists($values, $parts, $schema);
        $raw = $hasValue
            ? $this->readValueAt($values, $parts, $schema)
            : ($node['default'] ?? null);

        return ValueCaster::castNativeRead($node['type'] ?? DataField::TEXT, $raw);
    }

    public function setFieldValue(string $key, mixed $value): static
    {
        $schema = $this->getDataFieldsSchema();
        $values = $this->getDataFieldsValues();

        $node = $this->findLeafNode($schema, $key);
        if ($node === null) {
            if (config('data-fields.json.strict_writes', false)) {
                return $this;
            }
            // Dotted unknown paths can't be written meaningfully (the schema
            // hasn't declared the container chain) — refuse rather than store
            // a literal dotted top-level key. Flat unknown keys are still
            // allowed in lenient mode.
            if (str_contains($key, '.')) {
                return $this;
            }
            $values[$key] = $value;
        } else {
            $native = ValueCaster::castNativeWrite($node['type'] ?? DataField::TEXT, $value);
            $this->writeValueAt($values, $this->splitPath($key), $native, $schema);
        }

        return $this->setDataFieldsValues($values);
    }

    // -----------------------------------------------------------------
    // Hydrated FieldValue collection
    // -----------------------------------------------------------------

    /**
     * Recursively walk the schema and return every leaf as a FieldValue.
     * Containers are walked through; only leaves are emitted.
     *
     * @return Collection<int, FieldValue>
     */
    public function dataFields(): Collection
    {
        $schema = $this->getDataFieldsSchema();
        $values = $this->getDataFieldsValues();

        return collect($this->hydrateLeaves($schema, $values, ''));
    }

    public function dataField(string $key): ?FieldValue
    {
        return $this->dataFields()->first(fn (FieldValue $f) => $f->path === $key);
    }

    /**
     * Assert the current schema is well-formed. Throws InvalidArgumentException
     * with a descriptive `path: reason` message on the first issue found.
     * Cheap to call (no DB access); useful in tests, in artisan validate
     * commands, or just before persisting a schema you've built by hand.
     */
    public function validateDataFieldsSchema(): void
    {
        SchemaValidator::validate($this->getDataFieldsSchema());
    }

    // -----------------------------------------------------------------
    // Validation
    // -----------------------------------------------------------------

    /**
     * Run Laravel's validator against the values using rules collected from
     * the schema. Hidden fields (visible_if=false) are skipped. select_*
     * leaves with `options` get an auto-derived `in:` rule.
     *
     * Contract: this is a GUARD, not a filter.
     *   - On success: returns `$values` unchanged (the same array you passed,
     *     or the current canonical values if you didn't pass any).
     *   - On failure: throws ValidationException with dotted error keys
     *     (e.g. "step_1.performed_by") that match the schema paths.
     *
     * If you want Laravel's filtered "only validated keys" shape, call
     * `Validator::make($entry->getDataFieldsValues(), $rules)->validated()`
     * directly — the trait keeps the call site shape stable.
     */
    public function validateDataFields(?array $values = null): array
    {
        $schema = $this->getDataFieldsSchema();
        $values ??= $this->getDataFieldsValues();

        $rules = $this->collectValidationRules($schema, '', $values);
        if ($rules === []) {
            return $values;
        }

        $input     = $this->buildValidationInput($schema, $values);
        $validator = Validator::make($input, $rules);
        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return $values;
    }

    /**
     * @return array<string, array<int, mixed>>  dotted-path => rule list
     */
    protected function collectValidationRules(array $nodes, string $pathPrefix, array $valuesScope): array
    {
        $rules = [];
        foreach ($nodes as $node) {
            if (!is_array($node) || !isset($node['key'], $node['type'])) {
                continue;
            }
            $key  = $node['key'];
            $path = $pathPrefix === '' ? $key : "$pathPrefix.$key";

            if ($this->isContainerNode($node)) {
                $childScope = $valuesScope[$key]['values'] ?? [];
                $rules     += $this->collectValidationRules($node['items'] ?? [], $path, $childScope);
                continue;
            }

            if (!$this->resolveVisibility($node, $valuesScope)) {
                continue;
            }

            $nodeRules = $node['validations'] ?? [];
            $optionKeys = $this->extractOptionKeys($node);

            // Auto-derive `in:` rule from `options` for select_* leaves.
            if ($optionKeys !== null && $node['type'] === DataField::SELECT_SINGLE) {
                $nodeRules[] = 'in:' . implode(',', $optionKeys);
            }
            if ($optionKeys !== null && $node['type'] === DataField::SELECT_MULTIPLE) {
                $nodeRules[] = 'array';
                $rules["$path.*"] = ['in:' . implode(',', $optionKeys)];
            }

            if ($nodeRules === []) {
                continue;
            }
            $rules[$path] = $nodeRules;
        }
        return $rules;
    }

    /**
     * Extract `key` values from a leaf's `options` list. Returns null if the
     * leaf has no options or the shape isn't a list of {key} objects, so the
     * caller knows to skip the auto-derived `in:` rule.
     *
     * @return list<string>|null
     */
    protected function extractOptionKeys(array $node): ?array
    {
        $options = $node['options'] ?? null;
        if (!is_array($options) || $options === []) {
            return null;
        }
        $keys = [];
        foreach ($options as $option) {
            if (!is_array($option) || !isset($option['key'])) {
                return null;
            }
            $keys[] = (string) $option['key'];
        }
        return $keys;
    }

    /**
     * Build the nested input array Laravel's validator expects. Drops the
     * container wrapper (`{values, meta}`) so dotted rule paths like
     * `step_1.performed_by` resolve naturally.
     */
    protected function buildValidationInput(array $nodes, array $valuesScope): array
    {
        $out = [];
        foreach ($nodes as $node) {
            if (!is_array($node) || !isset($node['key'])) {
                continue;
            }
            $key = $node['key'];
            if ($this->isContainerNode($node)) {
                $childScope = $valuesScope[$key]['values'] ?? [];
                $out[$key]  = $this->buildValidationInput($node['items'] ?? [], $childScope);
            } else {
                $out[$key] = $valuesScope[$key] ?? null;
            }
        }
        return $out;
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    /**
     * @return list<FieldValue>
     */
    protected function hydrateLeaves(array $nodes, array $valuesScope, string $pathPrefix): array
    {
        $out = [];
        foreach ($nodes as $node) {
            if (!is_array($node) || !isset($node['key'], $node['type'])) {
                continue;
            }
            $key = $node['key'];
            $path = $pathPrefix === '' ? $key : "$pathPrefix.$key";

            if ($this->isContainerNode($node)) {
                $childScope = $valuesScope[$key]['values'] ?? [];
                array_push($out, ...$this->hydrateLeaves($node['items'] ?? [], $childScope, $path));
                continue;
            }

            $raw     = $valuesScope[$key] ?? ($node['default'] ?? null);
            $visible = $this->resolveVisibility($node, $valuesScope);

            $out[] = new FieldValue(
                key:         $key,
                type:        $node['type'],
                value:       ValueCaster::castNativeRead($node['type'], $raw),
                rawValue:    $raw,
                label:       $node['label'] ?? null,
                description: $node['description'] ?? null,
                validations: $node['validations'] ?? [],
                meta:        $node['meta'] ?? [],
                options:     $node['options'] ?? [],
                visible:     $visible,
                path:        $path,
            );
        }
        return $out;
    }

    /**
     * Container types are reserved: step / section / group. A node is treated
     * as a container only when its type is one of those AND `items` is an
     * array — otherwise a typo'd leaf typed 'group' would be silently swallowed.
     */
    protected function isContainerNode(array $node): bool
    {
        return in_array($node['type'] ?? null, ['step', 'section', 'group'], true)
            && isset($node['items'])
            && is_array($node['items']);
    }

    /**
     * @param list<string> $parts
     */
    protected function pathExists(array $values, array $parts, array $schemaNodes): bool
    {
        $key = array_shift($parts);
        if ($key === null || !array_key_exists($key, $values)) {
            return false;
        }
        if ($parts === []) {
            return true;
        }
        $node = $this->findNodeByKey($schemaNodes, $key);
        if ($node === null || !$this->isContainerNode($node)) {
            return false;
        }
        $scope = is_array($values[$key]) ? ($values[$key]['values'] ?? []) : [];
        return $this->pathExists($scope, $parts, $node['items'] ?? []);
    }

    /**
     * visible_if v1 — equality match against keys in the same container scope.
     * `{ "field_key": "expected" }` — multiple keys mean AND.
     */
    protected function resolveVisibility(array $node, array $scope): bool
    {
        if (!isset($node['visible_if']) || !is_array($node['visible_if'])) {
            return true;
        }
        foreach ($node['visible_if'] as $key => $expected) {
            $actual = Arr::get($scope, $key);
            if ($actual !== $expected) {
                return false;
            }
        }
        return true;
    }

    protected function findLeafNode(array $schema, string $dottedKey): ?array
    {
        $parts = $this->splitPath($dottedKey);
        $nodes = $schema;
        $node  = null;
        while ($parts !== []) {
            $part = array_shift($parts);
            $node = $this->findNodeByKey($nodes, $part);
            if ($node === null) {
                return null;
            }
            if ($parts === []) {
                return $this->isContainerNode($node) ? null : $node;
            }
            if (!$this->isContainerNode($node)) {
                return null;
            }
            $nodes = $node['items'] ?? [];
        }
        return null;
    }

    protected function findNodeByKey(array $nodes, string $key): ?array
    {
        foreach ($nodes as $node) {
            if (is_array($node) && ($node['key'] ?? null) === $key) {
                return $node;
            }
        }
        return null;
    }

    /**
     * @param list<string> $parts
     */
    protected function readValueAt(array $values, array $parts, array $schemaNodes): mixed
    {
        $key = array_shift($parts);
        if ($key === null || !array_key_exists($key, $values)) {
            return null;
        }
        $node = $this->findNodeByKey($schemaNodes, $key);

        if ($parts === []) {
            return $values[$key];
        }

        if ($node === null || !$this->isContainerNode($node)) {
            return null;
        }
        return $this->readValueAt($values[$key]['values'] ?? [], $parts, $node['items'] ?? []);
    }

    /**
     * @param list<string> $parts
     */
    protected function writeValueAt(array &$values, array $parts, mixed $native, array $schemaNodes): void
    {
        $key  = array_shift($parts);
        $node = $this->findNodeByKey($schemaNodes, $key);

        if ($parts === []) {
            $values[$key] = $native;
            return;
        }

        if ($node === null || !$this->isContainerNode($node)) {
            // Schema says this isn't a container — refuse to write a path through it.
            return;
        }

        if (!isset($values[$key]) || !is_array($values[$key])) {
            $values[$key] = ['values' => [], 'meta' => []];
        }
        if (!isset($values[$key]['values']) || !is_array($values[$key]['values'])) {
            $values[$key]['values'] = [];
        }
        $this->writeValueAt($values[$key]['values'], $parts, $native, $node['items'] ?? []);
    }

    /**
     * @return list<string>
     */
    protected function splitPath(string $key): array
    {
        return $key === '' ? [] : explode('.', $key);
    }

    /**
     * Strict-write helper: drop keys in $values that don't correspond to any
     * schema node. Recurses through containers.
     */
    protected function dropUnknownKeys(array $values, array $schemaNodes): array
    {
        $out = [];
        foreach ($values as $key => $val) {
            $node = $this->findNodeByKey($schemaNodes, $key);
            if ($node === null) {
                continue;
            }
            if ($this->isContainerNode($node) && is_array($val)) {
                $scope = $val['values'] ?? [];
                $out[$key] = [
                    'values' => $this->dropUnknownKeys($scope, $node['items'] ?? []),
                    'meta'   => $val['meta'] ?? [],
                ];
                continue;
            }
            $out[$key] = $val;
        }
        return $out;
    }

    /**
     * Wrap a payload with the `{version, schema|values}` envelope per config.
     */
    protected function wrapEnvelope(array $payload, string $payloadKey): array
    {
        if (!config('data-fields.json.write_envelope', true)) {
            return $payload;
        }
        return [
            'version'   => config('data-fields.json.envelope_version', '1.0'),
            $payloadKey => $payload,
        ];
    }

    /**
     * Auto-detect envelope by checking for both `version` and the payload key.
     * Accepts both wrapped and unwrapped input transparently.
     *
     * Throws when only one half of the envelope is present — that's almost
     * certainly column corruption, and silently treating it as unwrapped data
     * would mask the bug.
     */
    protected function unwrapEnvelope(mixed $data, string $payloadKey): array
    {
        if (!is_array($data)) {
            return [];
        }
        $hasVersion = array_key_exists('version', $data);
        $hasPayload = array_key_exists($payloadKey, $data);

        if ($hasVersion && $hasPayload) {
            if (!is_string($data['version']) || !is_array($data[$payloadKey])) {
                throw new \UnexpectedValueException(
                    sprintf(
                        'Malformed data-fields envelope: expected string `version` and array `%s`.',
                        $payloadKey
                    )
                );
            }
            return $data[$payloadKey];
        }
        if ($hasVersion !== $hasPayload) {
            throw new \UnexpectedValueException(
                sprintf(
                    'Malformed data-fields envelope: one of `version`/`%s` is present without the other.',
                    $payloadKey
                )
            );
        }
        return $data;
    }
}
