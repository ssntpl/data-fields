<?php

namespace Ssntpl\DataFields\Models;

use Illuminate\Database\Eloquent\Model;
use Ssntpl\DataFields\Casts\FieldValueCast;
use Ssntpl\DataFields\Traits\HasDataFields;
use Ssntpl\LaravelFiles\Traits\HasFiles;

class DataField extends Model
{
    use HasDataFields;
    use HasFiles;
    
    public const BOOL = 'bool';
    public const TEXT = 'text';
    public const NUMBER = 'number';
    public const SELECT_SINGLE = 'select_single';
    public const SELECT_MULTIPLE = 'select_multiple';
    public const DATE = 'date';
    public const TIME = 'time';
    public const DATETIME = 'datetime';
    public const FILE = 'file';
    public const FILES = 'files';
    public const JSON = 'json';
    public const ARRAY = 'array';

    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
    */
    public $timestamps = false;
    
    protected $fillable = [
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
        'meta_data' => 'array',
        'value' => FieldValueCast::class,
    ];

    public static function getAllTypes()
    {
        return [
            self::BOOL,
            self::TEXT,
            self::NUMBER,
            self::SELECT_SINGLE,
            self::SELECT_MULTIPLE,
            self::DATE,
            self::TIME,
            self::DATETIME,
            self::FILE,
            self::FILES,
            self::JSON,
            self::ARRAY,
        ];
    }

    public function owner()
    {
        return $this->morphTo();
    }

    public function delete()
    {
        $dataFieldFiles = $this->files()->get();
        foreach($dataFieldFiles as $file) {
            $file->delete();
        }
        $dataFields = $this->fields()->get();
        foreach($dataFields as $dataField)
        {
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
}
