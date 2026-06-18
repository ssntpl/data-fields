# JSON-Mode Storage for `ssntpl/data-fields`

**Status:** spec for implementation. Owner: TBD.
**Effort estimate:** ~5–8 working days including tests and docs.

This document describes a parallel storage mode for the `ssntpl/data-fields` package. The existing row-based mode (current `HasDataFields` / `HasDataSets` traits) stays exactly as it is — this work adds a new mode without changing the old one.

---

## 1. Goal

Let consuming applications store a form's **schema** and **values** as JSON columns on an owner model, instead of as rows in `data_fields` / `data_sets`. Reuse everything that exists today (field types, `FieldValueCast`, file references via `ssntpl/laravel-files`, validation rules) — the only thing that changes is *where* the data is persisted.

Two early consumers drive this:

- **digilims** — log entries on instrument schedules. A schedule defines the form once; many log entries store only values. Today, both schema and values are stored together in one JSON column. JSON-mode formalises this pattern.
- **ProcessFlow** — `template_nodes.fields_schema` (schema only) and `step_instances.outputs` (values only). The same canonical schema shape and the same value casting should work for both columns.

---

## 2. Non-goals

The following stay out of scope of this work:

- **Row-mode changes.** `data_fields` / `data_sets` tables, the `DataField` / `DataSet` models, and the existing `HasDataFields` / `HasDataSets` traits are untouched. **taillog must continue to work with zero code changes.**
- **Workflow / step orchestration.** No step lifecycle, no dependency enforcement, no assignment semantics, no completion tracking. The trait knows how to *walk* a schema that contains `step` / `section` / `group` containers (so leaf-field access and validation work), but it does not enforce step ordering or assign work. Those live in a future `ssntpl/data-fields-flow` companion or in each application.
- **Visual form builder.** This is a backend-only spec. UI is the consumer's concern.
- **Schema versioning.** Optional `version` envelope is mentioned below as a forward-compat hook but no migration tooling is part of this work.

---

## 3. Constraints

- Backward compatibility: any model currently using `HasDataFields` or `HasDataSets` must behave identically after this change ships.
- File fields keep using `ssntpl/laravel-files` via `FieldValueCast`. The same `{model_type, model_id}` (single) or array-of-those (multi) encoding applies.
- Validations use Laravel's built-in validator with rule arrays (e.g., `["required", "numeric", "min:0"]`). No JSON Schema. (A JSON Schema interop helper may come later.)
- Per-field timestamps and per-set timestamps remain configurable as in the current `config/data-fields.php`.

---

## 4. Canonical schema shape

The schema is an **ordered array of nodes**. Each node is either a **leaf** (captures a value) or a **container** (groups child nodes).

### 4.1 Common to all nodes

```jsonc
{
  "type": "text",          // required
  "key": "performed_by",   // required, snake_case, unique within the form
  "label": "Performed by", // human-readable
  "description": "...",    // optional helper text
  "meta": { ... }          // optional, project-specific extensions (package ignores)
}
```

### 4.2 Leaf node — extra properties

```jsonc
{
  "type": "select_single",
  "key": "verdict",
  "label": "Verification",
  "validations": ["required"],          // Laravel validation rules
  "default": null,                      // optional default
  "options": [                          // required for select_single / select_multiple
    { "key": "ok",   "label": "Approved" },
    { "key": "redo", "label": "Needs redo" }
  ],
  "visible_if": { "field_key": "value" } // optional — equality match only (v1)
}
```

Supported `type` values for leaves (same set as `DataField`'s constants):
`bool`, `text`, `number`, `select_single`, `select_multiple`, `date`, `time`, `datetime`, `file`, `files`, `json`, `array`.

`required` is expressed inside `validations` (`["required", ...]`) — not duplicated as a separate flag.

### 4.3 Container node — extra properties

```jsonc
{
  "type": "step",             // step | section | group
  "key": "step_2",
  "label": "Manager Verification",
  "items": [ /* child nodes */ ],
  "requires": ["step_1"],     // optional — keys of containers that must be done first
  "assigned_to": {            // optional — single-target assignment hint
    "type": "role",           // user | role | team | email
    "key":  "manager"
  }
}
```

This trait **walks** containers for leaf access and validation. It does **not** enforce `requires` or `assigned_to` — those are hints for a future flow companion.

### 4.4 Values shape

Values are a JSON object keyed by leaf `key`. When containers are present, values are scoped by container key. Each container scope has two reserved keys, `values` and `meta`. The trait owns `values`; `meta` is opaque to this package and reserved for higher-layer code (flow companion, app-specific bookkeeping):

```jsonc
// no containers (flat)
{ "performed_by": "Rahul", "verdict": "ok" }

// with containers (scoped)
{
  "step_1": {
    "values": {
      "performed_by": "Rahul",
      "evidence_photos": [1234, 1235]
    },
    "meta": {
      "completed_at": "2026-06-15T10:35:00+05:30",
      "completed_by_id": 42
    }
  }
}
```

The trait reads/writes the `values` field inside each container scope. It preserves `meta` on read and on write without inspecting its contents. Keys like `completed_at` / `completed_by_id` belong inside `meta`, not as siblings of `values` — so the future flow companion can claim its own keys inside `meta` without breaking the canonical layout.

### 4.5 Optional envelope (recommended, forward-compat)

When writing JSON, wrap with a `version` envelope:

```jsonc
{
  "version": "1.0",
  "schema": [ /* nodes */ ]
}
```

Same for values. The trait should accept both wrapped and unwrapped and write wrapped form by default.

`version` is a **reserved top-level key** in both schema and values JSON. A schema node cannot have `"key": "version"` at the top level, and a flat values object cannot use `"version"` as a leaf key. This keeps envelope auto-detection unambiguous: the trait detects wrapped form when both `version` (string) **and** `schema`/`values` (array/object) are present at the top level.

---

## 5. The new trait: `Ssntpl\DataFields\Traits\HasDataFieldsJson`

A model gets JSON-mode storage by adding this trait:

```php
use Ssntpl\DataFields\Traits\HasDataFieldsJson;

class LogEntry extends Model
{
    use HasDataFieldsJson;
}
```

By default the trait reads/writes two columns:

- `data_fields_schema` (JSON, nullable) — the schema array (with envelope).
- `data_fields_values` (JSON, nullable) — the values object (with envelope).

A model overrides column names by overriding the getter methods (PHP trait-property defaults clash with subclass overrides, so we use methods, not properties):

```php
class LogEntry extends Model
{
    use HasDataFieldsJson;

    protected function getDataFieldsSchemaColumn(): ?string { return 'log_format'; }
    protected function getDataFieldsValuesColumn(): ?string { return 'entries'; }
}
```

Or skip the schema column entirely (when the schema lives on a parent record) by returning `null` and overriding `getDataFieldsSchema()`:

```php
class LogEntry extends Model
{
    use HasDataFieldsJson;

    protected function getDataFieldsSchemaColumn(): ?string { return null; }

    public function getDataFieldsSchema(): array
    {
        return $this->schedule->log_format ?? [];
    }
}
```

### 5.1 Public API on the trait

```php
public function getDataFieldsSchema(): array;
public function setDataFieldsSchema(array $schema): static;

public function getDataFieldsValues(): array;
public function setDataFieldsValues(array $values): static;

public function getFieldValue(string $key): mixed;          // dotted path supported
public function setFieldValue(string $key, mixed $value): static;

public function dataFields(): \Illuminate\Support\Collection;  // hydrated DataField instances
public function dataField(string $key): ?DataField;            // single leaf by key (dotted path)

public function validateDataFields(?array $values = null): array;  // throws ValidationException on failure
```

### 5.2 Dotted-path access

`getFieldValue('step_1.performed_by')` returns the value of the `performed_by` leaf inside the `step_1` container. For flat schemas (no containers), `getFieldValue('performed_by')` works without a prefix.

### 5.3 Hydrated field instances

`dataFields()` returns a `Collection<FieldValue>`. `FieldValue` is a plain value object in `Ssntpl\DataFields\Support` — **not** an Eloquent `Model`. This is a deliberate change from earlier drafts of this spec: subclassing `DataField` and overriding `save()` to throw is leaky (Eloquent has too many persistence side-channels — `update()`, `push()`, `saveQuietly()`, observers, global scopes), so we use a non-Eloquent object instead.

```php
namespace Ssntpl\DataFields\Support;

final class FieldValue implements FieldLike
{
    public function __construct(
        public readonly string $key,
        public readonly string $type,
        public readonly mixed $value,
        public readonly ?string $label = null,
        public readonly ?string $description = null,
        public readonly array $validations = [],
        public readonly array $meta = [],
        public readonly bool $visible = true,
    ) {}

    // Same API surface that row-mode DataField exposes:
    // ->key, ->type, ->value, ->validations, ->meta, ->isVisible()
}
```

`FieldLike` is an interface that both `Ssntpl\DataFields\Models\DataField` (row mode) and `FieldValue` (JSON mode) implement, so consumer code can be storage-agnostic:

```php
foreach ($logEntry->dataFields() as $field) {  // FieldLike[]
    echo $field->key . ' = ' . $field->value . PHP_EOL;  // cast handles bool / dates / files
}
```

The same value-casting service that `FieldValueCast` calls is reused to produce the cast `$value` on `FieldValue`. No persistence path exists, so there is nothing to lock down.

`dataField(string $key)` returns a single `FieldValue` or `null`.

### 5.4 Validation

`validateDataFields()` walks the schema, collects each leaf's `validations` rules, and runs them through Laravel's `Validator`. Returns the validated, flat (`step_1.performed_by => value`) array on success; throws `Illuminate\Validation\ValidationException` on failure.

Implementation: the trait builds a **flat working copy** of values (keys joined with `.` — `step_1.performed_by`) and matching flat rules, runs Laravel's validator against that, and remaps error keys back to the same dotted form so callers can render them straight from `ValidationException::errors()`. The on-disk canonical shape (§4.4) is **not** changed for validation — flattening is internal and only spans the depth required.

**Hidden fields** (resolved via §4.2 `visible_if`) are skipped during validation. Their stored value is **kept on read** and **not deleted on write** — toggling a parent field must not destroy downstream data. Consumers explicitly call `prune()` (planned, not in v1) to drop hidden values.

### 5.5 Re-using cast logic

Values must round-trip through the same logic `FieldValueCast` uses, so `bool`, `date`, `datetime`, `file`, `files`, `json`, `array` are handled identically to row mode.

Implementation: extract the cast body into a service (e.g. `Ssntpl\DataFields\Support\ValueCaster::get($type, $raw)` / `::set($type, $value)`). `FieldValueCast` becomes a thin wrapper that forwards to the service. The JSON trait calls the service directly per leaf. No duplication, no transient Eloquent models, guaranteed parity.

---

## 6. Containers and `HasDataSetsJson`

Most JSON-mode forms will be flat (no containers). For consumers that need containers (digilims log entries with `step` containers), a companion trait `HasDataSetsJson` extends `HasDataFieldsJson` with container-aware helpers:

```php
public function dataSets(): \Illuminate\Support\Collection;  // hydrated DataSetValue instances
public function dataSet(string $key): ?DataSetValue;
```

`DataSetValue` is the container counterpart to `FieldValue` — a plain value object in `Ssntpl\DataFields\Support` exposing `key`, `type` (`step`/`section`/`group`), `label`, `items` (a `Collection<FieldLike>` of children), `requires`, `assigned_to`, and `meta` (the opaque per-container bag from §4.4). Same non-Eloquent rationale as `FieldValue`: no persistence path means nothing to lock down.

The trait does not enforce `requires`, `assigned_to`, or anything inside `meta`. It exposes them as readable properties on `DataSetValue` and walks past them; logic that uses them belongs to a higher layer (the future flow companion).

**Implementation note:** `HasDataSetsJson` is intentionally a thin trait — its only real responsibility is exposing the container view. In v1 we may collapse it into `HasDataFieldsJson` (where `dataSets()` returns an empty collection when no containers exist) to avoid forcing consumers to choose between two traits at compose time. Open for the implementer to decide; either way the public API surface above is contractual.

---

## 7. Configuration additions

Extend `config/data-fields.php`:

```php
return [
    'data_set_model'   => \Ssntpl\DataFields\Models\DataSet::class,
    'data_field_model' => \Ssntpl\DataFields\Models\DataField::class,
    'data_sets_timestamps'   => false,
    'data_fields_timestamps' => false,

    'json' => [
        'default_schema_column' => 'data_fields_schema',
        'default_values_column' => 'data_fields_values',
        'envelope_version'      => '1.0',
        'write_envelope'        => true,  // wrap with {version, schema/values} on write
        'strict_writes'         => false, // when true, setDataFieldsValues() drops keys not present in schema
    ],
];
```

---

## 8. Migrations

This work does not add or modify any package-managed tables. The consuming application is responsible for adding the JSON columns to its own tables:

```php
$table->json('data_fields_schema')->nullable();
$table->json('data_fields_values')->nullable();
```

Provide one helper:

```php
// In a new file: src/Support/JsonModeBlueprint.php (or similar)
\Ssntpl\DataFields\Support\JsonModeMigration::addColumns($table);
```

so consumers can write `JsonModeMigration::addColumns($table)` in their migrations without typing the column names by hand.

---

## 9. Tests to write

Add `phpunit.xml` and a `tests/` directory if not present. Use **Orchestra Testbench** for Laravel package testing.

Required test files:

1. `tests/Feature/HasDataFieldsJsonTest.php`
   - Persists and reads a flat schema + values.
   - Round-trips every supported field type, including `file`, `files`, `bool`, `date`, `datetime`, `json`, `array`.
   - Respects overridden column names.
   - Works when schema column is null and `getDataFieldsSchema()` is overridden to pull from a relation.
   - Handles both wrapped and unwrapped envelope formats on read.

2. `tests/Feature/HasDataSetsJsonTest.php`
   - Walks a schema with `step` containers.
   - `getFieldValue('step_1.performed_by')` works with dotted paths.
   - Preserves `completed_at` / `completed_by_id` on write.

3. `tests/Feature/ValidationTest.php`
   - `validateDataFields()` honours `["required", "min:1", "date"]` style rules.
   - Throws `ValidationException` with field-keyed errors.
   - Container-scoped validation produces `step_1.performed_by` style error keys.

4. `tests/Feature/BackwardCompatTest.php`
   - A model using `HasDataFields` (row mode) behaves identically before and after the change.
   - A model can use both `HasDataFields` and `HasDataFieldsJson` simultaneously on different attributes (verify no conflict).

5. `tests/Unit/FieldValueCastReuseTest.php`
   - The cast applied in JSON-mode round-trips a `File` model reference identically to row-mode.

Run with `vendor/bin/phpunit`. Aim for >90% line coverage on the new traits.

---

## 10. Acceptance criteria (definition of done)

- [ ] `HasDataFieldsJson` trait added to `src/Traits/`.
- [ ] `HasDataSetsJson` trait added to `src/Traits/` (or collapsed into `HasDataFieldsJson` per §6 — pick one and document).
- [ ] `FieldValue` and `DataSetValue` value objects added to `src/Support/`.
- [ ] `FieldLike` interface added to `src/Contracts/`, implemented by both row-mode `DataField` and `FieldValue`.
- [ ] `ValueCaster` service extracted; `FieldValueCast` refactored to forward to it.
- [ ] `config/data-fields.php` extended with the `json` block (including `strict_writes`).
- [ ] `JsonModeMigration::addColumns()` helper available.
- [ ] All tests in §9 pass under PHP 8.2+ and Laravel 11 and 12.
- [ ] `README.md` updated with a JSON-Mode section linking to this spec.
- [ ] No behaviour changes to `HasDataFields`, `HasDataSets`, `DataField`, `DataSet`, or the existing migrations beyond the additive contract noted above (FieldLike implementation on `DataField` is purely additive).
- [ ] A taillog smoke test is performed: install the new version, run taillog locally, verify zero regressions.
- [ ] `composer.json` version bump noted in CHANGELOG/README (suggest `0.2.0`).

**Not part of this work:**

- PHPStan baseline — no baseline exists in the repo today. Add it in the follow-up hardening PR, not here.
- Decoupling `ssntpl/laravel-files` — explicitly kept coupled in v1 per current direction. Revisit later if a consumer without file fields wants the package.

---

## 11. Decisions (locked) and remaining open questions

### Locked

1. **Hydrated field type.** `FieldValue` (plain value object), not a `DataField` subclass. See §5.3. Both `DataField` (row mode) and `FieldValue` (JSON mode) implement the `FieldLike` interface so consumer code is storage-agnostic.
2. **`visible_if` semantics.** v1 supports equality only (`{ field_key: value }`). All fields are returned by `dataFields()`; hidden ones expose `isVisible() === false`. Validation skips hidden fields; their stored values are kept on read and not deleted on write.
3. **Container nesting depth.** Arbitrary depth. Recursion is required anyway.
4. **Unknown values keys.** Lenient by default — keys present in values but absent from the schema are kept on read **and** on write. Strict-write mode is opt-in via `data-fields.json.strict_writes = true`; in strict mode, `setDataFieldsValues()` drops unknown keys when a schema is loaded.
5. **Container `meta` ownership.** This package never inspects per-container `meta` (§4.4). It is preserved verbatim on read and write. Flow-companion keys like `completed_at` / `completed_by_id` live inside `meta`.
6. **Reserved keys.** `version` is reserved at the top level of schema and values JSON.

### Still open

1. **Schema cache.** For large schemas read many times per request, memoise via `protected ?array $cachedSchema`. Trivial; pick during implementation.

### Decided after first implementation pass

- **`validateDataFields()` is a guard, not a filter.** On success it returns the input `$values` unchanged. Callers wanting the Laravel-filtered "only validated keys" shape call `Validator::make(...)->validated()` directly. Reason: returning a filtered subset surprises callers who pipe the result back into `setDataFieldsValues()` — they'd silently drop unknown values.
- **`dataField($key)` / `dataSet($key)` match by `path` only**, not by `key`. Eliminates the ambiguity when the same key exists in multiple containers.
- **`getFieldValue($key)` honours `default`** when the path isn't present in values, matching `dataFields()` hydration.
- **Lenient `setFieldValue` refuses dotted unknown keys.** A dotted path with no schema match writes literally — silently meaningless — so we no-op instead. Flat unknown keys still pass through under `strict_writes=false`.
- **Container detection requires `items`.** A leaf typo'd as `type: 'group'` without an `items` array is treated as a leaf, not a silent container.
- **Envelope half-present is an error.** Reading `{"version": "1.0"}` (no payload key) throws `UnexpectedValueException` rather than silently treating it as data.
- **Bool storage canonical.** Row mode persists `'1'`/`'0'` strings; JSON mode persists native PHP booleans. Both writers accept the usual truthy strings case-insensitively.
- **Date/time/datetime read paths are defensive.** Malformed DB values return `null` instead of crashing the cast (write paths still throw — bad input doesn't reach the column).
- **Select options auto-derive `in:` rules.** `select_single` with `options` adds `in:opt1,opt2,...`; `select_multiple` adds `array` plus a `.*` rule.

---

## 12. Out of scope — handed to follow-up work

- `ssntpl/data-fields-flow` companion package — adds step lifecycle enforcement (`requires`, `assigned_to`, `completed_at`).
- JSON Schema interop helper (convert canonical schema ↔ JSON Schema).
- Form builder UI components.
- ProcessFlow adoption — separate work to switch `fields_schema` / `outputs` columns to this canonical shape (tracked in the ProcessFlow project docs).

---

## 13. References

- `README.md` — package overview and current row-mode API.
- `src/Casts/FieldValueCast.php` — the cast you must reuse for value round-tripping.
- `src/Models/DataField.php` — the field types and existing API surface.
- `ssntpl/laravel-files` — the file storage package used by `FILE` / `FILES` types.

For wider context (why this work exists, how it relates to ProcessFlow and digilims), see the `bpm/SHARED_SCHEMA.md` design notes in the ProcessFlow workspace.
