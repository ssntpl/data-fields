<?php

namespace Ssntpl\DataFields\Traits;

use Ssntpl\DataFields\Models\DataSet;

trait HasDataSets
{
    use HasDataFields;

    public function data_sets()
    {
        return $this->morphMany(config('data-fields.data_set_model', DataSet::class), 'owner');
    }

}
