<?php

namespace Ssntpl\DataFields\Tests\Models;

use Illuminate\Database\Eloquent\Model;
use Ssntpl\DataFields\Traits\HasDataFieldsJson;

/**
 * Exercises spec §5: a model can override the schema/values column names.
 */
class LogEntry extends Model
{
    use HasDataFieldsJson;

    protected $table = 'test_log_entries';

    protected $fillable = ['name', 'log_format', 'entries'];

    public $timestamps = false;

    protected function getDataFieldsSchemaColumn(): ?string
    {
        return 'log_format';
    }

    protected function getDataFieldsValuesColumn(): ?string
    {
        return 'entries';
    }
}
