<?php

namespace Ssntpl\DataFields\Tests\Models;

use Illuminate\Database\Eloquent\Model;
use Ssntpl\DataFields\Concerns\HasDataFields;
use Ssntpl\DataFields\Support\DataField;

class TestOwner extends Model
{
    use HasDataFields;

    protected $table = 'test_owners';

    protected $fillable = ['name', 'user_settings'];

    public $timestamps = false;

    protected $casts = [
        'user_settings' => DataField::class,
    ];
}
