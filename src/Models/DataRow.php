<?php

namespace Ssntpl\DataFields\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Ssntpl\DataFields\Casts\RowValueCast;
use Ssntpl\DataFields\Concerns\FieldAttributes;
use Ssntpl\DataFields\Concerns\HasDataFields;
use Ssntpl\LaravelFiles\Traits\HasFiles;

/**
 * Row-mode storage: one field per row in the `data_fields` table, attached
 * polymorphically to an owner model.
 *
 * The cast-mode counterpart is `Ssntpl\DataFields\Support\DataField`. Both
 * carry the same conceptual shape (key, type, value, ...) and share the
 * `FieldAttributes` trait for type-related helpers; everything else is
 * Eloquent-specific (polymorphic owner, transactional delete cascade, etc.).
 */
class DataRow extends Model
{
    use FieldAttributes;
    use HasDataFields;
    use HasFiles;

    protected $table = 'data_fields';

    public $timestamps = false;

    protected $fillable = [
        'owner_id',
        'owner_type',
        'label',
        'description',
        'key',
        'value',
        'type',
        'validations',
        'sort_order',
        'meta',
    ];

    protected $casts = [
        'validations' => 'array',
        'meta'        => 'array',
        'value'       => RowValueCast::class,
    ];

    public function owner()
    {
        return $this->morphTo();
    }

    public function delete()
    {
        return DB::connection($this->getConnectionName())->transaction(function () {
            foreach ($this->files()->get() as $file) {
                $file->delete();
            }
            foreach ($this->fields()->get() as $child) {
                $child->delete();
            }
            return parent::delete();
        });
    }

    /**
     * Duplicate this row. Children (sub-rows via self-polymorphism) are
     * reparented to the new copy via a single INSERT each — no double-save.
     */
    public function duplicate()
    {
        $copy = $this->replicate();
        $copy->owner_id   = 0;
        $copy->owner_type = '';
        $copy->save();

        foreach ($this->fields as $child) {
            $child->duplicateInto($copy);
        }

        return $copy;
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
