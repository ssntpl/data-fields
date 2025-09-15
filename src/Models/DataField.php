<?php

namespace Ssntpl\DataFields\Models;

use Illuminate\Database\Eloquent\Model;
use Ssntpl\DataFields\Traits\HasDataFields;
class DataField extends Model
{
    use HasDataFields;

    protected $fillable = [
        'id',
        'owner_id',
        'owner_type',
        'description',
        'key',
        'value',
        'type',
        'validations',
        'sort_order',
        'meta_data',
    ];

    protected $casts = [
        'validations' => 'array',
    ];

    public function owner()
    {
        return $this->morphTo();
    }

    public function delete()
    {
        $dataFields = $this->fields()->get();
        foreach($dataFields as $dataField)
        {
            $dataFieldFiles = $dataField->files()->get();
            foreach($dataFieldFiles as $dataFieldFile)
            {
                $dataFieldFile->delete();
            }
            $dataField->delete();
        }
        return parent::delete();
    }

    public function duplicate()
    {
        $newDataSet = $this->replicate();
        $newDataSet->owner_id = 0;
        $newDataSet->owner_type = '';
        $newDataSet->save();

        foreach ($this->fields as $dataField) {
            $newDataSet->fields()->save($dataField->duplicate());
        }

        return $newDataSet;
    }
    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = false;
}
