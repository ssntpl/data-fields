# Laravel Data Fields

[![Latest Version on Packagist](https://img.shields.io/packagist/v/ssntpl/data-fields.svg?style=flat-square)](https://packagist.org/packages/ssntpl/data-fields)
[![Total Downloads](https://img.shields.io/packagist/dt/ssntpl/data-fields.svg?style=flat-square)](https://packagist.org/packages/ssntpl/data-fields)
[![License](https://img.shields.io/packagist/l/ssntpl/data-fields.svg?style=flat-square)](LICENSE.md)

Typed, dynamic data fields for Eloquent models. Attach admin-defined fields
to any model and read them as typed PHP values — booleans cast to `bool`,
numbers to `float`, dates to `Carbon`, files to your `File` model, and so on.

Two parallel storage modes:

- **Cast mode** — one JSON column on the owner model holds a self-describing
  `DataField` document (schema + values together). Ergonomic typed object
  access, atomic per-column writes, multiple "forms" per model.
- **Row mode** — one row per field in a polymorphic `data_fields` table.
  Cross-row queries by key/value, per-field granular updates, indexable.

Pick whichever fits the column you're working with — a single model can use
both modes on different attributes.

```php
use Ssntpl\DataFields\Support\DataField;

class User extends Model
{
    protected $casts = [
        'preferences' => DataField::class,    // cast mode
    ];
    use \Ssntpl\DataFields\Concerns\HasDataFields;   // row mode
}

// Cast mode — work with the column as a typed document
$user->preferences = DataField::section(items: [
    ['key' => 'dark_mode', 'type' => 'bool', 'value' => true],
    ['key' => 'language',  'type' => 'text', 'value' => 'en'],
]);
$user->preferences->dark_mode->value;   // bool true
$user->preferences->dark_mode->value = false;
$user->save();

// Row mode — store fields polymorphically
$user->fields()->create([
    'key' => 'phone', 'type' => 'text', 'value' => '+91-9999900000',
]);
$user->getFieldValue('phone');          // '+91-9999900000'
```

## Table of contents

- [Installation](#installation)
- [Choosing a mode](#choosing-a-mode)
- [Cast mode](#cast-mode)
  - [Setup](#setup)
  - [Defining and writing](#defining-and-writing)
  - [Reading values](#reading-values)
  - [Mutating values](#mutating-values)
  - [Adding and removing fields at runtime](#adding-and-removing-fields-at-runtime)
  - [Containers and nesting](#containers-and-nesting)
  - [Validation](#validation)
  - [Default values](#default-values)
  - [Conditional visibility (`visible_if`)](#conditional-visibility-visible_if)
  - [Factories](#factories)
  - [Iteration and array access](#iteration-and-array-access)
- [Row mode](#row-mode)
  - [Setup](#row-mode-setup)
  - [Working with rows](#working-with-rows)
  - [Single-key helpers](#single-key-helpers)
  - [Custom row model](#custom-row-model)
- [Field types reference](#field-types-reference)
- [File and files types](#file-and-files-types)
- [Configuration](#configuration)
- [API reference](#api-reference)
- [Common patterns](#common-patterns)
- [Migrating from 0.2.x](#migrating-from-02x)
- [Testing](#testing)
- [Security](#security)
- [Changelog](#changelog)
- [Contributing](#contributing)
- [Credits](#credits)
- [License](#license)

## Installation

Install via Composer:

```bash
composer require ssntpl/data-fields
```

Publish the config (optional — only needed if you want to override defaults):

```bash
php artisan vendor:publish --tag=data-fields-config
```

### Row mode

If you're using row mode (the `data_fields` table), publish and run the
migration:

```bash
php artisan vendor:publish --tag=data-fields-migrations
php artisan migrate
```

If you'd rather skip the publish step, set `data-fields.auto_load_migrations`
to `true` in the published config — the service provider will load the
package's migration directly.

### Cast mode

Cast mode doesn't ship a table — the consumer adds a JSON column to whichever
model they want:

```php
Schema::table('users', function (Blueprint $table) {
    $table->json('preferences')->nullable();
});
```

### Requirements

- PHP 8.2+
- Laravel 11.0 or 12.0
- `ssntpl/laravel-files` ^0.1 (required — used by the `file` / `files` field types)

## Choosing a mode

Both modes share the same conceptual shape (key, type, value, label,
description, validations, meta) and the same casting layer. They differ in
where the data lives and which read/write patterns they optimise for.

| Concern | Cast mode | Row mode |
|---|---|---|
| Storage | One JSON column on the owner model | One row per field in `data_fields` |
| Read one field | Single column read | One DB query (or via eager load) |
| Read all fields | Single column read | One query (eager-loadable) |
| Update one field | Whole column rewrite | One row update |
| Query across rows by field | Hard (DB-specific JSON path queries) | Native SQL |
| Multiple distinct "forms" per record | Natural (one column per form) | Needs a discriminator column |
| Containers (`step` / `section` / `group`) | First-class | Not supported |
| Concurrent writes to different fields | Last-write-wins on the column | Field-independent |
| DB-level constraints (FK, unique) | None inside JSON | Native SQL |
| External (non-PHP) consumers | Must understand JSON shape | Trivial — normalised rows |
| Best fit | Settings, preferences, structured submissions | EAV, searchable attributes, sparse data |

Rule of thumb: **start with cast mode**. Reach for row mode when you have a
real need for cross-row queries by field value, BI/reporting tooling, or
field-level DB constraints.

---

## Cast mode

A single JSON column on the owner model holds the entire field document.
The Laravel cast hydrates that JSON into a `DataField` object you can read,
mutate, and persist with ordinary `$model->save()` semantics.

### Setup

1. Add a `json` column to your model's table:

   ```php
   Schema::table('users', function (Blueprint $table) {
       $table->json('preferences')->nullable();
   });
   ```

2. Add the cast to your model — write `DataField::class` directly; the
   package resolves to its internal cast via Laravel's `Castable` interface:

   ```php
   use Ssntpl\DataFields\Support\DataField;

   class User extends Model
   {
       protected $casts = [
           'preferences' => DataField::class,
       ];

       protected $fillable = ['preferences', /* ... */];
   }
   ```

That's it. Each cast column can hold an entire form's worth of fields.
Attach as many as you need:

```php
protected $casts = [
    'preferences'       => DataField::class,
    'email_settings'    => DataField::class,
    'shipping_defaults' => DataField::class,
];
```

### Defining and writing

A column casts to a single `DataField` object. The simplest form is a
container holding leaf fields:

```php
use Ssntpl\DataFields\Support\DataField;

$user->preferences = DataField::section(items: [
    ['key' => 'dark_mode', 'type' => 'bool', 'value' => true],
    ['key' => 'language',  'type' => 'text', 'value' => 'en'],
]);
$user->save();
```

You can also assign a plain array — the cast coerces it to a `DataField` for
you:

```php
$user->preferences = [
    'type'  => 'section',
    'items' => [
        ['key' => 'dark_mode', 'type' => 'bool', 'value' => true],
    ],
];
$user->save();
```

A `null` column casts to `null`. Assigning `null` clears the column:

```php
$user->preferences = null;
$user->save();
```

There's no implicit default — initialise the document explicitly via a
factory or by assignment. This matches Laravel's nullable-cast contract.

### Reading values

Property access returns the matching `DataField`; chain `->value` to read
the typed value:

```php
$user->preferences->dark_mode;          // DataField (leaf)
$user->preferences->dark_mode->value;   // bool true (cast via the field's type)
$user->preferences->language->value;    // 'en'
```

For nested structures, chain through container children:

```php
$user->preferences->appearance->dark_mode->value;
```

Or use the explicit dotted-path lookup:

```php
$user->preferences->dataField('appearance.dark_mode')->value;
```

If a key doesn't exist, property access returns `null`:

```php
$user->preferences->missing_key;        // null
```

### Mutating values

The `DataField` object is mutable. Mutations persist when you call
`$model->save()`:

```php
$user->preferences->dark_mode->value = false;
$user->save();
```

Dirty tracking works through Laravel's standard cast-re-serialisation:
`isDirty('preferences')` returns `true` after any in-memory change, and
`save()` writes the new JSON when it differs from the original.

You can also replace an entire field by assignment:

```php
$user->preferences->dark_mode = DataField::leaf('bool', false, ['key' => 'dark_mode']);
// or, equivalently, with a plain array:
$user->preferences->dark_mode = ['key' => 'dark_mode', 'type' => 'bool', 'value' => false];
$user->save();
```

### Adding and removing fields at runtime

Containers support `addField` and `removeField`:

```php
$user->preferences->addField([
    'key' => 'fontsize', 'type' => 'number', 'value' => 14,
]);

$user->preferences->removeField('language');

$user->save();
```

Adding a duplicate sibling key throws `\InvalidArgumentException` immediately
— structural validation runs at the point of authorship, not at save.

### Containers and nesting

Three container types are available — they're semantically equivalent
inside the package; pick whichever your UI vocabulary prefers:

| Type | Typical use |
|---|---|
| `section` | Logical grouping of related fields |
| `step` | A wizard/multi-step form pane |
| `group` | An inline cluster, smaller than a section |

Containers nest arbitrarily:

```php
$user->preferences = DataField::section(items: [
    [
        'type' => 'group', 'key' => 'appearance', 'label' => 'Appearance',
        'items' => [
            ['key' => 'dark_mode', 'type' => 'bool', 'value' => true],
            ['key' => 'accent', 'type' => 'select_single', 'value' => 'blue',
             'options' => [['key' => 'blue'], ['key' => 'red']]],
        ],
    ],
    [
        'type' => 'group', 'key' => 'notifications', 'label' => 'Notifications',
        'items' => [
            ['key' => 'frequency', 'type' => 'select_single', 'value' => 'daily',
             'options' => [['key' => 'daily'], ['key' => 'weekly']]],
        ],
    ],
]);
```

Access nested leaves via property chains or dotted-path lookup:

```php
$user->preferences->appearance->dark_mode->value;
$user->preferences->dataField('appearance.accent')->value;
$user->preferences->notifications->frequency->value;
```

### Validation

Each leaf can carry inline Laravel validation rules. Run them with
`validate()`:

```php
$user->preferences = DataField::section(items: [
    ['key' => 'name', 'type' => 'text', 'validations' => ['required', 'min:2']],
    ['key' => 'age',  'type' => 'number', 'validations' => ['required', 'numeric', 'min:18']],
]);

try {
    $user->preferences->validate();
} catch (\Illuminate\Validation\ValidationException $e) {
    // $e->errors() — dotted paths, e.g. 'step_1.age' for nested
}
```

Notes:

- `validate()` is a **guard, not a filter** — on success it returns the
  values unchanged. If you want Laravel's "only validated keys" shape, call
  `Validator::make(...)->validated()` directly.
- For `select_single` / `select_multiple` with an `options` list, an
  `Rule::in(...)` rule is auto-derived so out-of-options values fail
  validation without you having to repeat the option keys in
  `validations`.
- Hidden fields (resolved via `visible_if`) are skipped — their stored
  values are preserved on read and not deleted on write.

To validate on save, call `validate()` inside your own `saving` observer:

```php
static::saving(function ($model) {
    if ($model->preferences) {
        $model->preferences->validate();
    }
});
```

### Default values

A leaf's `default` is returned by `->value` when no `value` has been set.
Explicit `null` overrides the default — callers chose to clear it.

```php
$df = new DataField([
    'key' => 'plan', 'type' => 'text', 'default' => 'free',
]);
$df->value;                  // 'free' (from default)

$df->value = 'pro';
$df->value;                  // 'pro'

$df->value = null;
$df->value;                  // null (explicit override)
```

### Conditional visibility (`visible_if`)

Mark a field as visible only when a sibling has a specific value. Currently
equality-based; multiple keys mean AND.

```php
DataField::section(items: [
    ['key' => 'has_phone', 'type' => 'bool', 'value' => false],
    [
        'key' => 'phone', 'type' => 'text',
        'visible_if' => ['has_phone' => true],
        'validations' => ['required'],
    ],
]);
```

Hidden fields skip validation; their stored values are preserved.

### Factories

Three container shortcuts plus a generic leaf and a recursive `fromArray`:

```php
DataField::section(?string $key = null, array $items = [], array $extra = []): self
DataField::step(?string $key = null, array $items = [], array $extra = []): self
DataField::group(?string $key = null, array $items = [], array $extra = []): self
DataField::leaf(FieldType|string $type, mixed $value = null, array $extra = []): self
DataField::fromArray(array $node): self
```

Examples:

```php
use Ssntpl\DataFields\Support\DataField;
use Ssntpl\DataFields\Support\FieldType;

DataField::section('preferences', items: [...]);
DataField::leaf(FieldType::Date, '2026-06-15', ['key' => 'expires_at']);
DataField::leaf('number', 14, ['key' => 'fontsize', 'label' => 'Font size']);
```

### Iteration and array access

Containers iterate over their `items`:

```php
foreach ($user->preferences as $field) {
    echo $field->key . ' = ' . $field->value . PHP_EOL;
}

count($user->preferences);          // count of items
```

ArrayAccess works by both index and key:

```php
$user->preferences[0];              // first DataField
$user->preferences['dark_mode'];    // DataField with key 'dark_mode'
unset($user->preferences['dark_mode']);
```

---

## Row mode

Use row mode when you need cross-row queries by field key/value (e.g.
"find all users with `plan = 'pro'`"), per-field granular updates, BI/
reporting tools that expect normalised data, or DB-level constraints on
individual fields.

### Row mode setup

Add the trait to your model:

```php
use Ssntpl\DataFields\Concerns\HasDataFields;

class Product extends Model
{
    use HasDataFields;
}
```

That's it — the trait wires up the polymorphic `fields()` relationship
against the package's `data_fields` table.

### Working with rows

```php
$product->fields()->create([
    'key'   => 'sku',
    'type'  => 'text',
    'value' => 'WIDGET-001',
    'label' => 'Stock keeping unit',
]);

// Read
$product->fields;                                      // Collection<DataRow>
$product->fields()->where('key', 'sku')->first()->value;

// Cast across all rows
foreach ($product->fields as $row) {
    echo $row->label . ' = ' . $row->value . PHP_EOL;
}
```

Rows store typed values: `$row->value` returns the cast PHP type
(bool, float, Carbon, File, etc.) based on the row's `type`.

### Single-key helpers

The trait provides two convenience methods for working with a single field
by key:

```php
$product->getFieldValue('sku');                        // cast value or null
$product->setFieldValue('sku', 'NEW-001');             // upsert by key
$product->setFieldValue('weight', 2.5, 'number');      // type on first set
```

`setFieldValue` creates the row if absent and updates if present. The third
argument accepts a `FieldType` enum or a raw string; it defaults to `text`
on create and preserves the existing type on update.

### Custom row model

Subclass `DataRow` to add custom attributes, accessors, or methods:

```php
use Ssntpl\DataFields\Models\DataRow;

class CustomDataRow extends DataRow
{
    protected $extraFillable = ['source_system'];

    public function getFillable()
    {
        return array_merge(parent::getFillable(), $this->extraFillable ?? []);
    }

    public function isFromExternalSystem(): bool
    {
        return $this->source_system !== null;
    }
}
```

Point the config at your subclass:

```php
// config/data-fields.php
return [
    'data_row_model' => App\Models\CustomDataRow::class,
];
```

The `fields()` relationship will now hydrate as `CustomDataRow` instances.

### Validation rules in row mode

Storing `validations` rules alongside the field works — but note that **row
mode does not auto-run those rules**. The rules are persisted as field
metadata; running them is the consuming application's job (typically before
calling `create()` / `update()`):

```php
$product->fields()->create([
    'key'         => 'price',
    'value'       => '99.99',
    'type'        => 'number',
    'validations' => ['required', 'numeric', 'min:0'],   // stored only
]);
```

If you want auto-running rules, use cast mode — `$df->validate()` runs them.

---

## Field types reference

The package supports 12 leaf types and 3 container types. All available as
both literal strings and as cases on the `FieldType` PHP enum.

### Leaves

| Type | Stored as | Read returns |
|---|---|---|
| `bool` | `'1'` / `'0'` (row), native bool (cast) | `bool` |
| `text` | `string` | `string` |
| `number` | `string` (row), `float` (cast) | `float` |
| `select_single` | `string` | `string` |
| `select_multiple` | JSON array of strings | `array<string>` |
| `date` | `'YYYY-MM-DD'` string | `string` |
| `time` | `'HH:MM:SS'` string | `string` |
| `datetime` | `'YYYY-MM-DD HH:MM:SS'` string | `\Carbon\Carbon` |
| `file` | `{model_type, model_id}` JSON | `\Ssntpl\LaravelFiles\Models\File` or `null` |
| `files` | array of `{model_type, model_id}` | `array<File>` (always a list) |
| `json` | JSON | decoded `array` |
| `array` | JSON list | `array` |

### Containers (cast mode only)

| Type | Notes |
|---|---|
| `step` | A step/page in a wizard form |
| `section` | A logical grouping of related fields |
| `group` | An inline cluster, smaller than a section |

All three are semantically equivalent inside the package — the choice is
a hint to your UI layer.

### The `FieldType` enum

For type safety in your code, use `Ssntpl\DataFields\Support\FieldType`:

```php
use Ssntpl\DataFields\Support\FieldType;

FieldType::Bool->value;              // 'bool'
FieldType::SelectSingle->isLeaf();   // true
FieldType::Section->isContainer();   // true

FieldType::leaves();                 // list of leaf cases
FieldType::containers();             // list of container cases

DataField::leaf(FieldType::DateTime, now(), ['key' => 'last_seen']);
```

The enum is the in-memory type; JSON storage and the row-mode `type` column
stay as strings.

---

## File and files types

The `file` and `files` types store a reference to a `File` model from the
[`ssntpl/laravel-files`](https://github.com/ssntpl/laravel-files) package.

Pass a `File` instance, the package handles the rest:

```php
$file = File::find(123);

$user->preferences = DataField::section(items: [
    ['key' => 'avatar', 'type' => 'file', 'value' => $file],
]);
$user->save();

$user->preferences->avatar->value;          // File instance
$user->preferences->avatar->value->url;     // works as any File
```

For `files` (multiple), pass an array — even a single `File` is wrapped to a
list:

```php
$user->preferences->addField([
    'key' => 'attachments', 'type' => 'files',
    'value' => [$f1, $f2, $f3],
]);

$user->preferences->attachments->value;     // array<File>
```

An empty `files` field round-trips as `[]`, not `null`.

---

## Configuration

The published config (`config/data-fields.php`) is small:

```php
return [
    // Row-mode Eloquent model. Subclass DataRow and point at it to add
    // custom attributes/behaviour.
    'data_row_model' => \Ssntpl\DataFields\Models\DataRow::class,

    // Enable created_at / updated_at on the `data_fields` table.
    // Off by default — most consumers don't need per-row timestamps.
    'data_fields_timestamps' => false,

    // When true, the service provider loads the package's migration
    // directly — no `vendor:publish` needed.
    'auto_load_migrations' => false,
];
```

---

## API reference

### `Ssntpl\DataFields\Support\DataField` (cast value object)

| Method | Notes |
|---|---|
| `new DataField($node)` / `fromArray($node)` | Construct from a node array; throws on malformed input |
| `static leaf(FieldType\|string $type, $value, array $extra = [])` | Leaf factory |
| `static section(?string $key, array $items, array $extra = [])` | Section container factory |
| `static step(?string $key, array $items, array $extra = [])` | Step container factory |
| `static group(?string $key, array $items, array $extra = [])` | Group container factory |
| `isLeaf()` / `isContainer()` | Type-based predicates |
| `isVisible(?array $siblingValues = null)` | Resolves `visible_if` |
| `getValue()` / `setValue($v)` | Read/write the leaf value (honours default) |
| `$df->{$childKey}` | Property access — returns child `DataField` or `null` |
| `$df->dataField($dottedPath)` | Explicit path lookup, deep |
| `$df->addField($node)` / `$df->removeField($key)` | Container-only mutation |
| Iterable, `ArrayAccess`, `Countable` | Walk and index children |
| `validate()` | Runs Laravel rules; throws `ValidationException` |
| `toArray()` / `jsonSerialize()` | Storage-form serialisation |

### `Ssntpl\DataFields\Models\DataRow` (row-mode Eloquent model)

| Method | Notes |
|---|---|
| `owner()` | Polymorphic morphTo |
| `fields()` | Children via self-polymorphism (rare in practice) |
| `duplicate()` / `duplicateInto($owner)` | Clone with re-parented children |
| `delete()` | Transactional cascade to files + children |

### `Ssntpl\DataFields\Concerns\HasDataFields` (row-mode trait)

| Method | Notes |
|---|---|
| `fields()` | `morphMany` to `DataRow` |
| `getFieldValue($key)` | Cast value or `null` |
| `setFieldValue($key, $value, $type = null)` | Upsert by key |

### `Ssntpl\DataFields\Support\FieldType` (enum)

| Method | Notes |
|---|---|
| `isLeaf()` / `isContainer()` | Per-case predicates |
| `static coerce($value)` | Accept enum or string; throws on unknown |
| `static leaves()` / `static containers()` | Enumerate by kind |

---

## Common patterns

### Per-environment defaults

Use the model's `creating` event to seed a default document:

```php
static::creating(function (User $user) {
    if ($user->preferences === null) {
        $user->preferences = DataField::section(items: [
            ['key' => 'language', 'type' => 'text', 'value' => 'en'],
            ['key' => 'theme',    'type' => 'select_single', 'value' => 'system',
             'options' => [['key'=>'system'],['key'=>'light'],['key'=>'dark']]],
        ]);
    }
});
```

### Validating on save

Hook into `saving`:

```php
static::saving(function (User $user) {
    if ($user->preferences) {
        $user->preferences->validate();
    }
});
```

### Schema defined on a parent, values stored per-child

When many child records share one schema (e.g., template + responses), keep
the schema on the parent and store only the merged document on the child.
The cast handles both shapes identically — the schema lives wherever you
choose.

### Iterating leaves across containers

`dataField('a.b.c')` looks up by full path. To walk every leaf:

```php
$walker = function (DataField $node) use (&$walker, &$leaves) {
    if ($node->isLeaf()) {
        $leaves[] = $node;
        return;
    }
    foreach ($node->items as $child) {
        $walker($child);
    }
};
$leaves = [];
$walker($user->preferences);
```

---

## Migrating from 0.2.x

The 0.4.x release is a breaking redesign. If you were on 0.2.x with the
`HasDataFieldsJson` trait:

**Before (0.2.x):**

```php
use Ssntpl\DataFields\Traits\HasDataFieldsJson;

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

**After (0.4.x):**

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

For row-mode consumers, the rename `DataField` → `DataRow` and trait
namespace `Traits\` → `Concerns\` are the main changes:

```diff
- use Ssntpl\DataFields\Traits\HasDataFields;
+ use Ssntpl\DataFields\Concerns\HasDataFields;

- use Ssntpl\DataFields\Models\DataField;
+ use Ssntpl\DataFields\Models\DataRow;
```

Type-string constants are gone — use either the raw string (`'bool'`,
`'text'`, …) or the `FieldType` enum cases (`FieldType::Bool->value`, …).

See `CHANGELOG.md` for the complete list of changes and rationales.

---

## Testing

```bash
composer install
composer test          # or: vendor/bin/phpunit
```

The test suite runs against SQLite in-memory using Orchestra Testbench.

## Security

The `file` / `files` types store a reference to a row in
`ssntpl/laravel-files`'s `files` table as `{model_type, model_id}` JSON. On
read, the cast resolves `model_type` through Laravel's morph map
(`Illuminate\Database\Eloquent\Relations\Relation::morphMap()`) and rejects
any class that is not `Ssntpl\LaravelFiles\Models\File` or a subclass — so
a tampered value cannot autoload arbitrary classes. If you have subclassed
the File model, ensure your subclass extends
`Ssntpl\LaravelFiles\Models\File`.

If you discover a security vulnerability, please email
`abhishek.sharma@ssntpl.in` instead of opening a public issue.

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for a detailed record of changes per
release.

## Contributing

Issues and pull requests are welcome at
[github.com/ssntpl/data-fields](https://github.com/ssntpl/data-fields).

When sending a PR:

1. Fork the repo and create a feature branch.
2. Add tests covering the change.
3. Run `composer test` and make sure everything is green.
4. Update `CHANGELOG.md` under the `[Unreleased]` section.

## Credits

- **Abhishek Sharma** — [abhishek.sharma@ssntpl.in](mailto:abhishek.sharma@ssntpl.in) — [https://ssntpl.com](https://ssntpl.com)
- All [contributors](https://github.com/ssntpl/data-fields/graphs/contributors)

## License

The MIT License (MIT). See [LICENSE.md](LICENSE.md).
