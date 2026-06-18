<?php

namespace Ssntpl\DataFields\Models;

use Illuminate\Database\Eloquent\Model;
use Ssntpl\DataFields\Traits\HasDataFields;

class DataSet extends Model
{
    use HasDataFields;

    public $timestamps = false;

    protected $fillable = [
        'owner_id',
        'owner_type',
        'name',
        'type',
        'sort_order',
        'meta_data',
    ];

    protected $casts = [
        'meta_data' => 'array',
    ];

    public function owner()
    {
        return $this->morphTo();
    }

    public function delete()
    {
        foreach ($this->fields()->get() as $dataField) {
            $dataField->delete();
        }
        return parent::delete();
    }

    /**
     * Duplicate this set. Its DataFields are reparented to the new copy via a
     * single INSERT each — no double-save.
     */
    public function duplicate()
    {
        $newDataSet = $this->replicate();
        $newDataSet->owner_id = 0;
        $newDataSet->owner_type = '';
        $newDataSet->save();

        foreach ($this->fields as $dataField) {
            $dataField->duplicateInto($newDataSet);
        }

        return $newDataSet;
    }
}
