# Laravel Data Fields Package

A Laravel package for managing dynamic data fields and data sets with polymorphic relationships. This package allows you to attach custom fields and organized field sets to any Eloquent model.

## Features

- **Dynamic Data Fields**: Attach custom fields to any model
- **Data Sets**: Organize fields into logical groups
- **Polymorphic Relations**: Works with any Eloquent model
- **Field Validation**: Built-in validation support
- **Configurable Models**: Override default models
- **Migration Support**: Database migrations included

## Installation

### 1. Install via Composer

```bash
composer require ssntpl/data-fields
```

### 2. Publish Configuration (Optional)

```bash
php artisan vendor:publish --tag=data-fields-config
```

### 3. Register Service Provider (Laravel < 5.5)

For Laravel versions before 5.5, add the service provider to `config/app.php`:

```php
'providers' => [
    // Other providers...
    Ssntpl\DataFields\DataFieldsServiceProvider::class,
],
```

### 4. Publish and Run Migrations

```bash
php artisan vendor:publish --tag=data-fields-migrations
php artisan migrate
```

## Configuration

The package publishes a configuration file to `config/data-fields.php`:

```php
<?php
return [
    'data_set_model'   => \Ssntpl\DataFields\Models\DataSet::class,
    'data_field_model' => \Ssntpl\DataFields\Models\DataField::class,

    // Enable `created_at` / `updated_at` on the package tables. Off by default
    // because most consumers don't need per-row timestamps for derived data.
    'data_fields_timestamps' => false,
    'data_sets_timestamps'   => false,
];
```

## Usage

### 1. Add Traits to Your Models

#### For Data Fields Only
```php
use Ssntpl\DataFields\Traits\HasDataFields;

class User extends Model
{
    use HasDataFields;
}
```

#### For Data Sets (includes Data Fields)
```php
use Ssntpl\DataFields\Traits\HasDataSets;

class Product extends Model
{
    use HasDataSets;
}
```

### 2. Working with Data Fields

#### Create Data Fields
```php
$user = User::find(1);

// Create a simple field
$user->fields()->create([
    'key' => 'phone_number',
    'value' => '+1234567890',
    'type' => 'text',
    'description' => 'User phone number'
]);

// Create field with validation
$user->fields()->create([
    'key' => 'age',
    'value' => '25',
    'type' => 'number',
    'validations' => ['required', 'numeric', 'min:18'],
    'sort_order' => 1
]);
```

#### Retrieve Data Fields
```php
// Get all fields for a model
$fields = $user->fields;

// Get specific field by key
$phoneField = $user->fields()->where('key', 'phone_number')->first();

// Get field value
$phoneNumber = $phoneField->value;
```

### 3. Working with Data Sets

#### Create Data Sets
```php
$product = Product::find(1);

// Create a data set
$specifications = $product->dataSets()->create([
    'name' => 'Product Specifications',
    'type' => 'specifications',
    'sort_order' => 1
]);

// Add fields to the data set
$specifications->fields()->create([
    'key' => 'weight',
    'value' => '2.5kg',
    'type' => 'text',
    'description' => 'Product weight'
]);

$specifications->fields()->create([
    'key' => 'dimensions',
    'value' => '30x20x10cm',
    'type' => 'text',
    'description' => 'Product dimensions'
]);
```

#### Retrieve Data Sets
```php
// Get all data sets
$dataSets = $product->dataSets;

// Get specific data set by type
$specs = $product->dataSets()->where('type', 'specifications')->first();

// Get fields within a data set
$specFields = $specs->fields;
```

### 4. Field Types and Validation

The package supports various field types:

```php
// Text field
$field = $model->fields()->create([
    'key' => 'description',
    'value' => 'Sample description',
    'type' => 'text'
]);

// Number field with validation
$field = $model->fields()->create([
    'key' => 'price',
    'value' => '99.99',
    'type' => 'number',
    'validations' => ['required', 'numeric', 'min:0']
]);

// Date field
$field = $model->fields()->create([
    'key' => 'expiry_date',
    'value' => '2024-12-31',
    'type' => 'date',
    'validations' => ['required', 'date', 'after:today']
]);
```

### 5. Duplication

Both DataField and DataSet models support duplication:

```php
// Duplicate a data field
$originalField = DataField::find(1);
$duplicatedField = $originalField->duplicate();

// Duplicate a data set (includes all its fields)
$originalSet = DataSet::find(1);
$duplicatedSet = $originalSet->duplicate();
```

### 6. JSON Mode

Instead of storing each field as a row in `data_fields`, you can store an entire form's **schema** + **values** as JSON columns on the owner model. Same field types, same casting (including file references via `ssntpl/laravel-files`), same validation — only the storage layout changes. Useful when:

- You want one DB row per submission (log entry, step instance, form response) instead of N rows per field.
- The schema is defined once on a parent record and reused by many children.
- You're moving an existing JSON-blob column to a structured shape without dragging in two more tables.

#### 6.1 Add JSON columns to your table

```php
use Ssntpl\DataFields\Support\JsonModeMigration;

Schema::create('log_entries', function (Blueprint $table) {
    $table->id();
    JsonModeMigration::addColumns($table);  // adds data_fields_schema, data_fields_values
    $table->timestamps();
});
```

#### 6.2 Use `HasDataFieldsJson` (flat) or `HasDataSetsJson` (containers)

```php
use Ssntpl\DataFields\Traits\HasDataSetsJson;

class LogEntry extends Model
{
    use HasDataSetsJson;
}
```

#### 6.3 Read / write

```php
$entry->setDataFieldsSchema([
    ['key' => 'performed_by', 'type' => 'text',  'validations' => ['required']],
    ['key' => 'verdict',      'type' => 'select_single'],
]);

$entry->setFieldValue('performed_by', 'Rahul');
$entry->setFieldValue('verdict', 'ok');
$entry->save();

$entry->getFieldValue('performed_by');   // "Rahul"
$entry->dataFields();                    // Collection<FieldValue>
$entry->validateDataFields();            // throws ValidationException on failure
```

#### 6.4 Containers (steps / sections / groups)

```php
$entry->setDataFieldsSchema([
    [
        'type'  => 'step', 'key' => 'step_1', 'label' => 'Perform',
        'items' => [
            ['key' => 'performed_by', 'type' => 'text'],
        ],
    ],
]);

$entry->setFieldValue('step_1.performed_by', 'Rahul');
$entry->dataSets();             // Collection<DataSetValue>
$entry->dataSet('step_1');      // single container
```

#### 6.5 Override column names

```php
class LogEntry extends Model
{
    use HasDataFieldsJson;

    protected function getDataFieldsSchemaColumn(): ?string { return 'log_format'; }
    protected function getDataFieldsValuesColumn(): ?string { return 'entries'; }
}
```

Return `null` from `getDataFieldsSchemaColumn()` and override `getDataFieldsSchema()` to pull the schema from a parent record instead.

#### 6.6 Notable behaviours

- **`select_single` / `select_multiple` with `options`** automatically get a Laravel `in:...` rule appended at validation time — out-of-options values fail validation without you having to list them again in `validations`.
- **`getFieldValue($key)` honours the leaf's `default`** when the path isn't set in values. Explicit `null` overrides the default.
- **`dataField($key)` / `dataSet($key)` match by full dotted path only.** For nested schemas, pass `step_1.phone`, not bare `phone` — otherwise the call returns `null` (no ambiguity).
- **Lenient `setFieldValue` refuses dotted unknown paths.** Flat unknown keys still pass through under `strict_writes=false`; dotted ones don't (they'd corrupt the shape).
- **`version` is a reserved top-level key.** Don't name a leaf `version`. A column holding `{"version": "..."}` without the matching payload throws — it's almost always corruption.

For the full canonical schema/values shape, envelope handling, `visible_if` semantics, and strict-write behaviour, see [`docs/JSON_MODE_SPEC.md`](docs/JSON_MODE_SPEC.md).

### 7. Custom Models

You can extend the base models to add custom functionality:

```php
// Custom DataField model
class CustomDataField extends \Ssntpl\DataFields\Models\DataField
{
    protected $extraFillable = ['custom_attribute'];

    public function getFillable()
    {
        return array_merge(parent::getFillable(), $this->extraFillable ?? []);
    }

    public function getFormattedValue()
    {
        return strtoupper($this->value);
    }
}

// Custom DataSet model
class CustomDataSet extends \Ssntpl\DataFields\Models\DataSet
{
    protected $extraFillable = ['custom_field'];

    public function getFillable()
    {
        return array_merge(parent::getFillable(), $this->extraFillable ?? []);
    }
}
```

Update your configuration:

```php
// config/data-fields.php
return [
    'data_set_model' => App\Models\CustomDataSet::class,
    'data_field_model' => App\Models\CustomDataField::class,
];
```

## Database Schema

### Data Fields Table
- `id` - Primary key
- `owner_id` - Polymorphic relation ID
- `owner_type` - Polymorphic relation type
- `description` - Field description
- `key` - Field identifier
- `value` - Field value
- `type` - Field type (text, number, date, etc.)
- `validations` - JSON validation rules
- `sort_order` - Display order
- `meta_data` - Additional metadata

### Data Sets Table
- `id` - Primary key
- `owner_id` - Polymorphic relation ID
- `owner_type` - Polymorphic relation type
- `name` - Data set name
- `type` - Data set type/category
- `sort_order` - Display order
- `meta_data` - Additional metadata

## API Reference

### HasDataFields Trait
- `fields()` - Morphed relationship to data fields

### HasDataSets Trait
- `dataSets()` - Morphed relationship to data sets (canonical)
- `data_sets()` - **Deprecated** snake_case alias, kept for backward compatibility. New code should use `dataSets()`.
- Includes `HasDataFields` trait

### DataField Model
- `owner()` - Polymorphic relationship to owner model
- `duplicate()` - Create a copy of the field
- `delete()` - Delete field and related data

### DataSet Model
- `owner()` - Polymorphic relationship to owner model
- `fields()` - Relationship to associated fields
- `duplicate()` - Create a copy of the set and all fields
- `delete()` - Delete set and all associated fields

## Requirements

- PHP 8.2+
- Laravel 11.0+ or 12.0+
- `ssntpl/laravel-files` ^0.1 (required — used by the `file` / `files` field types)

## Security

The `file` / `files` field types store a reference to a row in `ssntpl/laravel-files`'s `files` table as `{model_type, model_id}` JSON. On read, the cast resolves `model_type` through Laravel's morph map (`Illuminate\Database\Eloquent\Relations\Relation::morphMap()`) and rejects any class that is not `Ssntpl\LaravelFiles\Models\File` or a subclass — so a tampered value cannot autoload arbitrary classes. If you have subclassed the File model, ensure your subclass extends `Ssntpl\LaravelFiles\Models\File`.

## Support

- **Issues**: [GitHub Issues](https://github.com/ssntpl/data-fields/issues)
- **Source**: [GitHub Repository](https://github.com/ssntpl/data-fields)

## Author

**Abhishek Sharma**
- Email: abhishek.sharma@ssntpl.in
- Website: [https://ssntpl.com](https://ssntpl.com)