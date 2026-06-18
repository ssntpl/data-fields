<?php

namespace Ssntpl\DataFields\Tests\Models;

use Illuminate\Database\Eloquent\Model;
use Ssntpl\DataFields\Traits\HasDataFieldsJson;

/**
 * Exercises spec §5: a model with no own schema column, pulling the schema
 * from a parent record.
 */
class ChildEntry extends Model
{
    use HasDataFieldsJson;

    protected $table = 'test_child_entries';

    protected $fillable = ['name', 'entries'];

    public $timestamps = false;

    public array $stubSchema = [];

    protected function getDataFieldsSchemaColumn(): ?string
    {
        return null;
    }

    protected function getDataFieldsValuesColumn(): ?string
    {
        return 'entries';
    }

    public function getDataFieldsSchema(): array
    {
        return $this->stubSchema;
    }
}
