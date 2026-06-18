<?php

namespace Ssntpl\DataFields\Tests\Models;

use Illuminate\Database\Eloquent\Model;
use Ssntpl\DataFields\Traits\HasDataFields;
use Ssntpl\DataFields\Traits\HasDataFieldsJson;

/**
 * Exercises spec §9.4: a model can use HasDataFields (row) and
 * HasDataFieldsJson (json) simultaneously on different attributes.
 */
class MixedOwner extends Model
{
    use HasDataFields;
    use HasDataFieldsJson;

    protected $table = 'test_mixed_owners';

    protected $fillable = ['name', 'data_fields_schema', 'data_fields_values'];

    public $timestamps = false;
}
