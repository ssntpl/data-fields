<?php

namespace Ssntpl\DataFields\Tests\Models;

use Illuminate\Database\Eloquent\Model;
use Ssntpl\DataFields\Traits\HasDataSetsJson;

class JsonOwner extends Model
{
    use HasDataSetsJson;

    protected $table = 'test_json_owners';

    protected $fillable = ['name', 'data_fields_schema', 'data_fields_values'];

    public $timestamps = false;
}
