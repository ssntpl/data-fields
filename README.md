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
    'data_set_model' => \Ssntpl\DataFields\Models\DataSet::class,
    'data_field_model' => \Ssntpl\DataFields\Models\DataField::class,
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
$specifications = $product->data_sets()->create([
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
$dataSets = $product->data_sets;

// Get specific data set by type
$specs = $product->data_sets()->where('type', 'specifications')->first();

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

### 6. Custom Models

You can extend the base models to add custom functionality:

```php
// Custom DataField model
class CustomDataField extends \Ssntpl\DataFields\Models\DataField
{
    protected $fillable = ['custom_attribute'];
    
    // Add custom methods
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
- `data_sets()` - Morphed relationship to data sets
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

- PHP 7.4+
- Laravel 8.0+

## Support

- **Issues**: [GitHub Issues](https://github.com/ssntpl/data-fields/issues)
- **Source**: [GitHub Repository](https://github.com/ssntpl/data-fields)

## Author

**Abhishek Sharma**
- Email: abhishek.sharma@ssntpl.in
- Website: [https://ssntpl.com](https://ssntpl.com)