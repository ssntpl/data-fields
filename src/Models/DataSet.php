<?php

namespace Ssntpl\DataFields\Models;

use Illuminate\Database\Eloquent\Model;
use Ssntpl\DataFields\Traits\HasDataFields;

class DataSet extends Model
{
    use HasDataFields;

    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = false;

    protected $fillable = [
        'id',
        'owner_id',
        'owner_type',
        'parent_id',
        'name',
        'type',
        'sort_order',
        'meta_data',
    ];
    
    protected $casts = [
        'meta_data' => 'array',
    ];

    protected function getClass()
    {
        return config('data-fields.data_set_model', DataSet::class);
    }
    
    public function owner()
    {
        return $this->morphTo();
    }

    public function delete()
    {
        $dataFields = $this->fields()->get();
        foreach($dataFields as $dataField)
        {
            $dataField->delete();
            // DataField::destroy($dataField->id);
        }
        if ($this->children()->get()) {
            foreach ($this->children()->get() as $child) {
                $child->delete();
            }
        }
        return parent::delete();
    }

    public function duplicate()
    {
        $newDataSet = $this->replicate();
        $newDataSet->owner_id = 0;
        $newDataSet->owner_type = '';
        $newDataSet->save();

        foreach($this->fields as $dataField)
        {
            $newDataSet->fields()->save($dataField->duplicate());
        }

        return $newDataSet;
    }

    public function parent()
    {
        return $this->belongsTo($this->getClass(), 'parent_id');
    }

    public function children()
    {
        return $this->hasMany($this->getClass(), 'parent_id');
    }
}
