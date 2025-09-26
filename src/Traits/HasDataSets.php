<?php

namespace Ssntpl\DataFields\Traits;

use Ssntpl\DataFields\Models\DataSet;

trait HasDataSets
{
    use HasDataFields;

    public function data_sets()
    {
        return $this->morphMany($this->getDataSetModel(), 'owner');
    }

    protected function getDataSetModel()
    {
        return config('data-fields.data_set_model', DataSet::class);
    }

}
