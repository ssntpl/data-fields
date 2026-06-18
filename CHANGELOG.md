# Changelog

All notable changes to `ssntpl/data-fields` are recorded here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and
the package adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

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

Legacy state extracted from the taillog project. Single storage mode (row),
no test suite, no published spec. Not recommended for new projects — use 0.2.0
or later.
