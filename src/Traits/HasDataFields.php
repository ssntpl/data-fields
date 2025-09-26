<?php

namespace Ssntpl\DataFields\Traits;

use Ssntpl\DataFields\Models\DataField;

trait HasDataFields
{
    public function fields()
    {
        return $this->morphMany($this->getDataFieldModel(), 'owner');
    }

    protected function getDataFieldModel()
    {
        return config('data-fields.data_field_model', DataField::class);
    }

}
