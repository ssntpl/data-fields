# Laravel Data Fields Package

Typed dynamic data fields for Eloquent models. Two parallel storage modes,
one shared casting layer:

- **Cast mode** — one JSON column on the owner model holds a self-describing
  `DataField` document (schema + values together). Multiple columns per
  model, ergonomic typed object access, atomic writes per column.
- **Row mode** — one row per field in a polymorphic `data_fields` table.
  Cross-row queries by key/value, per-field granular updates, indexable by
  field name.

Pick whichever fits the column you're working with — a model can use both
on different columns.

## Features

- Typed value casting (`bool`, `text`, `number`, `select_single`,
  `select_multiple`, `date`, `time`, `datetime`, `file`, `files`, `json`,
  `array`).
- Container types (`step`, `section`, `group`) with arbitrary nesting in
  cast mode.
- Laravel-validator integration via inline `validations` rules, with
  auto-derived `Rule::in(...)` for `select_*` leaves.
- `visible_if` equality-based field visibility.
- `file` / `files` types backed by `ssntpl/laravel-files`.
- PHP 8.1 backed `FieldType` enum.

## Installation

```bash
composer require ssntpl/data-fields
```

Publish migrations + config (optional):

```bash
php artisan vendor:publish --tag=data-fields-config
php artisan vendor:publish --tag=data-fields-migrations
php artisan migrate
```

If you'd rather skip the publish step, set
`data-fields.auto_load_migrations` to `true` and the service provider
registers the package migration directly.

## Cast mode — typed JSON columns

### 1. Add a JSON column

```php
Schema::table('users', function (Blueprint $table) {
    $table->json('user_settings')->nullable();
});
```

### 2. Attach the cast

```php
use Ssntpl\DataFields\Support\DataField;

class User extends Model
{
    protected $casts = [
        'user_settings'     => DataField::class,
        'email_preferences' => DataField::class,
        'dietary_habits'    => DataField::class,
    ];
}
```

`DataField::class` resolves to the package's cast via the `Castable`
interface — no need to import `DataFieldCast` directly.

### 3. Write

```php
use Ssntpl\DataFields\Support\DataField;

$user->user_settings = DataField::section(items: [
    ['key' => 'dark_mode', 'type' => 'bool', 'value' => true],
    ['key' => 'language',  'type' => 'text', 'value' => 'en'],
]);
$user->save();
```

Or assign an array — the cast coerces:

```php
$user->user_settings = [
    'type'  => 'section',
    'items' => [
        ['key' => 'dark_mode', 'type' => 'bool', 'value' => true],
    ],
];
$user->save();
```

### 4. Read & mutate

```php
$user->user_settings->dark_mode->value;       // bool true
$user->user_settings->language->value;        // 'en'

$user->user_settings->dark_mode->value = false;
$user->save();   // dirty-tracked + persisted
```

### 5. Add / remove fields at runtime

```php
$user->user_settings->addField(['key' => 'fontsize', 'type' => 'number', 'value' => 14]);
$user->user_settings->removeField('language');
$user->save();
```

### 6. Containers (step / section / group)

```php
$user->user_settings = DataField::section(items: [
    [
        'key' => 'appearance', 'type' => 'group',
        'items' => [
            ['key' => 'dark_mode', 'type' => 'bool', 'value' => true],
            ['key' => 'accent',    'type' => 'select_single', 'value' => 'blue',
             'options' => [['key'=>'blue'], ['key'=>'red']]],
        ],
    ],
]);

$user->user_settings->appearance->dark_mode->value;          // true
$user->user_settings->dataField('appearance.accent')->value; // 'blue'
```

Arbitrary nesting depth.

### 7. Validation

Inline `validations` rules per leaf are run with Laravel's validator on
demand:

```php
$user->user_settings = DataField::section(items: [
    ['key' => 'name', 'type' => 'text', 'validations' => ['required']],
]);

try {
    $user->user_settings->validate();
} catch (\Illuminate\Validation\ValidationException $e) {
    // $e->errors() — dotted paths like 'step_1.name' for nested
}
```

- `validate()` is a **guard**, not a filter — returns the input unchanged
  on success.
- `select_single` / `select_multiple` with `options` get an auto-derived
  `Rule::in(...)` rule appended.
- Fields hidden via `visible_if` are skipped during validation; their
  stored values are kept on read and never deleted on write.

### 8. Factories

```php
DataField::section(string $key = null, array $items = [], array $extra = []);
DataField::step   (string $key = null, array $items = [], array $extra = []);
DataField::group  (string $key = null, array $items = [], array $extra = []);
DataField::leaf   (FieldType|string $type, mixed $value = null, array $extra = []);
DataField::fromArray(array $node);
```

### 9. Field types

Provided as a PHP 8.1 backed enum at `Ssntpl\DataFields\Support\FieldType`.

Leaves:
`Bool`, `Text`, `Number`, `SelectSingle`, `SelectMultiple`, `Date`, `Time`,
`DateTime`, `File`, `Files`, `Json`, `Array_`.

Containers:
`Step`, `Section`, `Group`.

```php
use Ssntpl\DataFields\Support\FieldType;

DataField::leaf(FieldType::Date, '2026-06-15', ['key' => 'expires_at']);
```

Field-type strings in stored JSON match the enum's `value` (lowercase /
snake_case).

## Row mode — polymorphic `data_fields` table

Useful when you need cross-row queries by field key/value, per-field
granular updates, or external (non-PHP) consumers reading normalized data.

### 1. Add the trait

```php
use Ssntpl\DataFields\Concerns\HasDataFields;

class Product extends Model
{
    use HasDataFields;
}
```

### 2. Create

```php
$product->fields()->create([
    'key'   => 'sku',
    'type'  => 'text',
    'value' => 'WIDGET-001',
]);

// Storing validation rules alongside the field — note that `validations` is
// merely *persisted* in row mode, not executed. Row mode does not auto-run
// these rules; that's the consuming application's job (typically before
// calling `create()`). Cast mode runs them via `$df->validate()`.
$product->fields()->create([
    'key'         => 'price',
    'value'       => '99.99',
    'type'        => 'number',
    'validations' => ['required', 'numeric', 'min:0'],
]);
```

### 3. Retrieve

```php
$product->fields;                              // Collection<DataRow>
$product->fields()->where('key', 'sku')->first()->value;

// Single-key helpers:
$product->getFieldValue('sku');                // 'WIDGET-001'
$product->setFieldValue('price', '79.99');     // upsert by key
$product->setFieldValue('weight', 2.5, 'number');
```

### 4. Custom row model

```php
class CustomDataRow extends \Ssntpl\DataFields\Models\DataRow
{
    protected $extraFillable = ['custom_attribute'];

    public function getFillable()
    {
        return array_merge(parent::getFillable(), $this->extraFillable ?? []);
    }
}
```

Point the config at it:

```php
// config/data-fields.php
return [
    'data_row_model' => App\Models\CustomDataRow::class,
];
```

## Database schema

### `data_fields` table (row mode)

- `id` – primary key
- `owner_id`, `owner_type` – polymorphic owner
- `label`, `description`, `key`, `value`, `type`, `validations`, `sort_order`, `meta`
- Composite index on `(owner_id, owner_type, key)`

Cast mode ships no tables — the consumer adds a JSON column to whichever
model they want.

## API summary

### `Ssntpl\DataFields\Support\DataField` (cast value object)

- Construction: `new DataField($node)`, `fromArray($node)`, `leaf()`,
  `section()`, `step()`, `group()`
- Predicates: `isLeaf()`, `isContainer()`, `isVisible($scope = null)`
- Read: `$df->value`, `$df->{$childKey}`, `dataField($dottedPath)`,
  iteration, `ArrayAccess`, `count()`
- Mutate: `$df->value = ...`, `$df->{$childKey} = ...`, `setValue()`,
  `addField()`, `removeField()`
- Validate: `validate()` (throws `ValidationException` on failure)
- Serialise: `toArray()`, `jsonSerialize()`

### `Ssntpl\DataFields\Models\DataRow` (row-mode Eloquent model)

- `owner()` – polymorphic morphTo
- `fields()` – children via self-polymorphism
- `duplicate()`, `duplicateInto($owner)` – clone with re-parented children
- `delete()` – transactional cascade to files + children

### `Ssntpl\DataFields\Concerns\HasDataFields` (row-mode trait)

- `fields()` – morphMany to `DataRow`
- `getFieldValue($key)` – cast value or null
- `setFieldValue($key, $value, $type = null)` – upsert by key

### `Ssntpl\DataFields\Support\FieldType` (enum)

- All 12 leaf + 3 container cases
- `isLeaf()`, `isContainer()`, `coerce($value)`, `leaves()`, `containers()`

## Requirements

- PHP 8.2+
- Laravel 11.0+ or 12.0+
- `ssntpl/laravel-files` ^0.1 (for `file` / `files` field types)

## Security

The `file` / `files` field types store a reference to a row in
`ssntpl/laravel-files`'s `files` table as `{model_type, model_id}` JSON.
On read, the cast resolves `model_type` through Laravel's morph map
(`Illuminate\Database\Eloquent\Relations\Relation::morphMap()`) and rejects
any class that is not `Ssntpl\LaravelFiles\Models\File` or a subclass — a
tampered value cannot autoload arbitrary classes. If you have subclassed
the File model, ensure your subclass extends
`Ssntpl\LaravelFiles\Models\File`.

## Support

- **Issues**: [GitHub Issues](https://github.com/ssntpl/data-fields/issues)
- **Source**: [GitHub Repository](https://github.com/ssntpl/data-fields)

## Author

**Abhishek Sharma**
- Email: abhishek.sharma@ssntpl.in
- Website: [https://ssntpl.com](https://ssntpl.com)
