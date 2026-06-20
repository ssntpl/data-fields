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
     * Clone this row onto a new owner, recursively re-parenting any
     * children (sub-rows via self-polymorphism). One INSERT per copy.
     *
     * If you want an in-memory copy without persisting, use Laravel's
     * built-in `replicate()` directly and save when you're ready.
     */
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
