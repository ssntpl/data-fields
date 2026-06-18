<?php

namespace Ssntpl\DataFields\Traits;

use Ssntpl\DataFields\Models\DataSet;

trait HasDataSets
{
    use HasDataFields;

    public function dataSets()
    {
        return $this->morphMany($this->getDataSetModel(), 'owner');
    }

    /**
     * @deprecated since 0.2.0, use dataSets() instead. Kept for backward
     * compatibility with consumers that call `$model->data_sets()` or access
     * the relation as `$model->data_sets`.
     */
    public function data_sets()
    {
        return $this->dataSets();
    }

    protected function getDataSetModel()
    {
        return config('data-fields.data_set_model', DataSet::class);
    }
}
