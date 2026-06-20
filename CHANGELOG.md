# Changelog

All notable changes to `ssntpl/data-fields` are recorded here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and
the package adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased] — 0.4.0

A ground-up rework of the JSON-mode storage. All 0.3.x development rolls into
this release. Pre-release package — no compatibility shim is provided.

### Added — cast-mode storage

A Laravel custom cast attached to any JSON column, replacing the
trait-based JSON mode. Each cast column holds **one self-describing
`DataField` document** that carries schema and values together — no
separate schema column, no envelope, no `clearDataFieldsCache`.

- `Ssntpl\DataFields\Support\DataField` value object. Implements
  `Castable`, `IteratorAggregate`, `JsonSerializable`, `ArrayAccess`,
  `Countable`. One class covers leaf and container shapes; `type` discriminates.
- `Ssntpl\DataFields\Casts\DataFieldCast` Laravel cast — never imported
  directly; consumers write `DataField::class` in `$casts` and the package
  resolves via `Castable::castUsing()`.
- Factories: `DataField::leaf()`, `DataField::section()`,
  `DataField::step()`, `DataField::group()`, `DataField::fromArray()`.
- Property proxy through containers — `$user->user_settings->dark_mode->value`
  reads/writes the leaf. Dotted-path lookup via `$df->dataField('a.b.c')`.
- Mutation: `addField()`, `removeField()`, `setValue()`, `__set`,
  `ArrayAccess` writes. Dirty tracking via re-serialise-on-save (Laravel's
  `AsArrayObject` pattern) — the cast object stays decoupled from its
  parent model.
- Structural validation runs at construction time — bad type, missing key,
  duplicate sibling key, malformed `options` / `visible_if` all throw
  `InvalidArgumentException` at the point of authorship.
- Rule validation explicit via `$df->validate()` — runs Laravel's validator
  against the tree with dotted error paths and `Rule::in()` auto-derivation
  for `select_*` leaves; hidden fields (`visible_if`) skipped.

### Added — `FieldType` enum

All 15 type strings (12 leaves + 3 containers) live in
`Ssntpl\DataFields\Support\FieldType` as a backed enum. Used in
`DataField`, `ValueCaster`, and consumer-facing signatures. JSON storage
and the row-mode `type` column stay as strings — the enum is a PHP-side
type-safety / exhaustiveness aid.

### Added — row-mode hygiene

- `HasDataFields::getFieldValue($key)` / `setFieldValue($key, $value, $type = null)`
  upsert-by-key helpers. `setFieldValue` accepts either a `FieldType` enum
  or a raw string.
- `data-fields.auto_load_migrations` config flag — when `true` the service
  provider registers the package migration directly, no `vendor:publish`
  needed.
- `data_fields` table now indexed on `(owner_id, owner_type, key)` instead
  of just `(owner_id, owner_type)` so `$owner->fields()->where('key', X)`
  lookup is index-covered. The composite serves the owner-only prefix too;
  the previous index is redundant. Folded into the create migration — no
  upgrade migration needed.

### Changed

- **`DataField` (Eloquent model) renamed to `DataRow`.** The old name
  belongs to the new cast value object. `DataRow` is in
  `Ssntpl\DataFields\Models\DataRow`. Type constants
  (`DataField::BOOL` etc.) are gone — use `FieldType::Bool` etc. Custom
  subclasses extend `DataRow` (not `DataField`).
- **`HasDataFields` trait moved from `Traits\` to `Concerns\`.**
  Full path: `Ssntpl\DataFields\Concerns\HasDataFields`.
- **`ValueCaster` accepts `FieldType|string`** at every entry point
  (`castForRead`, `castForWrite`, `castNativeRead`, `castNativeWrite`).
  String input is coerced via `FieldType::coerce()`. Backward compatible
  for callers passing raw type strings.
- **`FieldValueCast` renamed to `RowValueCast`** (`Ssntpl\DataFields\Casts\RowValueCast`).
  The old name was ambiguous between row-mode and the new `DataFieldCast`;
  the new name makes it explicit that this cast is for the `value` column
  on a `DataRow`. It now uses `FieldType::Text->value` as the fallback
  string when the `type` attribute is missing.
- **`data_fields` table schema aligned with the cast-mode `DataField` shape:**
  - Added `label` (string, nullable) — the short display string. Previously
    the `description` column was overloaded for this; now `label` and
    `description` carry the two distinct concerns from cast mode
    (`label` = short, `description` = long-form helper text).
  - Renamed `meta_data` column → `meta` to match the cast-mode `meta` key.
  - `DataRow::$fillable` and `$casts` updated accordingly. Migration is
    a fresh create — no upgrade migration needed.
- `DataRow::delete()` cascades wrapped in `DB::transaction()` — a parent-
  delete failure no longer leaves orphaned child rows or detached files.
- `ValueCaster` no longer infers single-vs-multi file shape from the
  decoded structure. The declared `$type` (FILE vs FILES) drives the
  branch. FILES always hydrates as a list (empty list preserved instead
  of returning null), and a single `File` passed to a FILES field is
  wrapped as a list on write.
- `ServiceProvider::boot()` gates `publishes()` behind `runningInConsole()`.
- Config key renamed: `data_field_model` → `data_row_model` (default now
  `DataRow::class`).
- Config keys removed: `json.default_schema_column`,
  `json.default_values_column`, `json.envelope_version`,
  `json.write_envelope`, `json.strict_writes` (no longer needed in cast
  mode).

### Removed — JSON-mode trait surface

- `Ssntpl\DataFields\Traits\HasDataFieldsJson` trait — replaced by the
  `DataField` cast.
- `Ssntpl\DataFields\Support\JsonModeMigration` helper — consumer just
  writes `$table->json('column_name')->nullable()`.
- `Ssntpl\DataFields\Support\SchemaValidator` — structural validation
  folded into `DataField::__construct`.
- `Ssntpl\DataFields\Support\FieldValue` and
  `Ssntpl\DataFields\Support\DataSetValue` — superseded by `DataField`.
- `Ssntpl\DataFields\Contracts\FieldLike` — row mode and cast mode now
  have distinct, storage-specific call sites; no more storage-agnostic
  iteration use case.
- `Ssntpl\DataFields\Traits\Traits\` and `Contracts\` namespaces removed
  (now empty after deletions).
- `clearDataFieldsCache()` method — cast object holds no internal
  memoisation.
- Envelope handling (`{"version": "1.0", "schema": ...}` wrapping) —
  not needed in the merged-document shape.
- `strict_writes` config — cast is strict by construction (unknown keys
  in input throw).

### Removed — other

- `\Throwable` catch-all in `ValueCaster::resolveFiles()` — real DB errors
  now propagate instead of being silently swallowed. Missing rows still
  return `null` (Eloquent `find()` semantics).
- `data_sets` table, `DataSet` model, `HasDataSets` trait,
  `HasDataSetsJson` trait, `DataSetValue` value object,
  `data_set_model` / `data_sets_timestamps` config keys.
  Grouping is a UX/domain concern — see container types in cast mode.

### Migration paths

#### From `HasDataFieldsJson` (0.2.x) to cast mode

Before:

```php
class LogEntry extends Model
{
    use HasDataFieldsJson;
}

$entry->setDataFieldsSchema([
    ['key' => 'performed_by', 'type' => 'text'],
]);
$entry->setFieldValue('performed_by', 'Rahul');
$entry->save();
```

After:

```php
use Ssntpl\DataFields\Support\DataField;

class LogEntry extends Model
{
    protected $casts = [
        'entry_data' => DataField::class,
    ];
}

$entry->entry_data = DataField::section();
$entry->entry_data->addField(['key' => 'performed_by', 'type' => 'text', 'value' => 'Rahul']);
$entry->save();
```

For the two-column shape (`data_fields_schema` + `data_fields_values`),
write a one-off script that merges them per row:

```php
foreach (LogEntry::cursor() as $entry) {
    $schema = (array) ($entry->data_fields_schema['schema'] ?? $entry->data_fields_schema ?? []);
    $values = (array) ($entry->data_fields_values['values'] ?? $entry->data_fields_values ?? []);

    $entry->entry_data = DataField::section(items: array_map(
        fn ($leaf) => array_merge($leaf, ['value' => $values[$leaf['key']] ?? null]),
        $schema,
    ));
    $entry->save();
}
```

#### Row-mode rename

Replace `Ssntpl\DataFields\Models\DataField` → `Ssntpl\DataFields\Models\DataRow`
and `Ssntpl\DataFields\Traits\HasDataFields` →
`Ssntpl\DataFields\Concerns\HasDataFields`. Replace `DataField::BOOL` etc.
with `FieldType::Bool->value` (or just the literal strings — they haven't
changed).

## [0.2.0] — 2026-06-18

First release after the package was extracted from the taillog project to be
generic and reusable.

### Added — JSON-mode storage

A parallel storage mode that keeps a form's schema and values as JSON columns on
the owner model itself, rather than as rows in `data_fields` / `data_sets`. Row
mode is unchanged.

- `HasDataFieldsJson` trait — schema + values get/set with envelope handling,
  dotted-path `getFieldValue` / `setFieldValue`, hydrated `dataFields()` /
  `dataField()`, `visible_if` equality resolution with hidden-field-kept-on-read
  semantics, lenient and strict write modes via config flag, and
  `validateDataFields()` driving Laravel's validator from rules collected from
  the schema (with auto-derived `in:` rules for `select_*` leaves).
- `HasDataSetsJson` trait — `dataSets()` / `dataSet()` walking arbitrary-depth
  `step` / `section` / `group` containers, preserving schema order.
- `FieldLike` interface implemented by both row-mode `DataField` and JSON-mode
  `FieldValue` so consumer code is storage-agnostic.
- `FieldValue` and `DataSetValue` readonly value objects returned by hydration
  (plain PHP objects, not Eloquent subclasses — no leaky `save()` path).
- `ValueCaster` service — single source of truth for casting in both modes.
  Honours `config('files.model')` so consumers who subclass or replace the
  laravel-files `File` model can store and resolve their own class.
- `SchemaValidator` — validates a schema array is well-formed (duplicate keys,
  container shape, reserved `version` key, malformed `options` / `visible_if`,
  unknown leaf types). Exposed via `validateDataFieldsSchema()` on the trait.
- `JsonModeMigration::addColumns($table)` migration helper.
- `config('data-fields.json')` block — column defaults, envelope version,
  envelope-on-write flag, `strict_writes` flag.
- Per-instance memoisation of schema and values reads. Setters invalidate; raw
  attribute writers should call `clearDataFieldsCache()`.

### Added — package hygiene

- Pinned `php ^8.2` and `laravel/framework ^11.0|^12.0` (was wildcard).
- Test suite: PHPUnit 11 + Orchestra Testbench on sqlite in-memory. 71 tests,
  184 assertions covering both storage modes.
- `docs/JSON_MODE_SPEC.md` — full canonical specification of the JSON-mode
  schema and values shape, locked decisions, and remaining open items.
- README section 6 documenting the JSON-mode API end-to-end.

### Changed

- `DataField::duplicate()` — fixes the misnamed `$newDataSet` variable and
  rewrites the recursion to a single-INSERT `duplicateInto()` per child (was
  double-saving each duplicated child).
- `DataSet` — drops `id` from `$fillable` so the primary key can no longer be
  mass-assigned.
- `HasDataSets` — `dataSets()` is now the canonical morphMany method;
  `data_sets()` is kept as a deprecated alias delegating to it.
- `FieldValueCast` — refactored to forward to `ValueCaster`. Bool values now
  store as canonical `'1'` / `'0'` strings (was a PHP bool, which platform PDO
  drivers were free to round-trip as `'f'` / `'t'` etc — `(bool) 'false' === true`
  in PHP made the round-trip silently corrupt). Date / time / datetime read
  paths now catch `Carbon\Exceptions\InvalidFormatException` and return `null`
  so a malformed DB row can't crash a Builder query.
- Migrations converted to anonymous-class form so the test suite can re-run
  them without class-redeclaration errors. Existing consumers' published
  migrations are unaffected.

### Security

- `FieldValueCast` file resolution previously called
  `$data['model_type']::find(...)` on a class string read from stored data —
  an autoload-arbitrary-class vector. Resolution now routes through Laravel's
  morph map and rejects any class that is not the configured file model (or a
  subclass of it).

## [0.1.x]

Legacy state extracted from existing projects. Single storage mode (row),
no test suite, no published spec. Not recommended for new projects — use 0.2.0
or later.
