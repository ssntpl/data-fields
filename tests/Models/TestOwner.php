<?php

namespace Ssntpl\DataFields\Tests\Models;

use Illuminate\Database\Eloquent\Model;
use Ssntpl\DataFields\Traits\HasDataSets;

class TestOwner extends Model
{
    use HasDataSets;

    protected $table = 'test_owners';

    protected $fillable = ['name'];

    public $timestamps = false;
}
