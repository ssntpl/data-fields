<?php

namespace Ssntpl\DataFields\Models;

use Illuminate\Database\Eloquent\Model;
use Ssntpl\DataFields\Casts\FieldValueCast;
use Ssntpl\DataFields\Contracts\FieldLike;
use Ssntpl\DataFields\Traits\HasDataFields;
use Ssntpl\LaravelFiles\Traits\HasFiles;

class DataField extends Model implements FieldLike
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

    /**
     * FieldLike alias for the row-mode `meta_data` column.
     */
    public function getMetaAttribute(): array
    {
        return $this->meta_data ?? [];
    }

    public function isVisible(): bool
    {
        return true;
    }

    public function delete()
    {
        foreach ($this->files()->get() as $file) {
            $file->delete();
        }
        foreach ($this->fields()->get() as $child) {
            $child->delete();
        }
        return parent::delete();
    }

    /**
     * Duplicate this field. Children (sub-fields) are reparented to the new
     * copy via a single INSERT each — no double-save.
     */
    public function duplicate()
    {
        $newDataField = $this->replicate();
        $newDataField->owner_id = 0;
        $newDataField->owner_type = '';
        $newDataField->save();

        foreach ($this->fields as $child) {
            $child->duplicateInto($newDataField);
        }

        return $newDataField;
    }

    public function duplicateInto(Model $owner): self
    {
        $copy = $this->replicate();
        $owner->fields()->save($copy);

        foreach ($this->fields as $child) {
            $child->duplicateInto($copy);
        }

        return $copy;
    }
}
