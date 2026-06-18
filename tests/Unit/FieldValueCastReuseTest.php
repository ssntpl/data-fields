<?php

namespace Ssntpl\DataFields\Tests\Unit;

use Ssntpl\DataFields\Models\DataField;
use Ssntpl\DataFields\Support\ValueCaster;
use Ssntpl\DataFields\Tests\Models\JsonOwner;
use Ssntpl\DataFields\Tests\Models\TestOwner;
use Ssntpl\DataFields\Tests\TestCase;
use Ssntpl\LaravelFiles\Models\File;

class FieldValueCastReuseTest extends TestCase
{
    private function makeFile(): File
    {
        $file             = new File();
        $file->owner_id   = 0;
        $file->owner_type = '';
        $file->type       = 'test';
        $file->key        = 'tmp/' . bin2hex(random_bytes(4));
        $file->disk       = 'local';
        $file->save();
        return $file;
    }

    public function test_file_field_in_row_mode_resolves_to_File_instance(): void
    {
        $file  = $this->makeFile();
        $owner = TestOwner::create(['name' => 'RowFile']);

        $field = $owner->fields()->create([
            'key'   => 'doc',
            'type'  => DataField::FILE,
            'value' => $file,
        ]);

        $loaded = DataField::find($field->id);
        $this->assertInstanceOf(File::class, $loaded->value);
        $this->assertSame($file->id, $loaded->value->id);
    }

    public function test_file_field_in_json_mode_resolves_to_File_instance(): void
    {
        $file = $this->makeFile();

        $owner = new JsonOwner();
        $owner->setDataFieldsSchema([
            ['key' => 'doc', 'type' => DataField::FILE],
        ]);
        $owner->setFieldValue('doc', $file);
        $owner->save();

        $resolved = JsonOwner::find($owner->id)->getFieldValue('doc');
        $this->assertInstanceOf(File::class, $resolved);
        $this->assertSame($file->id, $resolved->id);
    }

    public function test_files_field_in_json_mode_resolves_to_File_collection(): void
    {
        $f1 = $this->makeFile();
        $f2 = $this->makeFile();

        $owner = new JsonOwner();
        $owner->setDataFieldsSchema([
            ['key' => 'docs', 'type' => DataField::FILES],
        ]);
        $owner->setFieldValue('docs', [$f1, $f2]);
        $owner->save();

        $resolved = JsonOwner::find($owner->id)->getFieldValue('docs');
        $this->assertCount(2, $resolved);
        $this->assertContainsOnlyInstancesOf(File::class, $resolved);
        $this->assertSame([$f1->id, $f2->id], array_map(fn ($f) => $f->id, $resolved));
    }

    public function test_value_caster_rejects_non_file_classes(): void
    {
        // Pretend a malicious value was stored with a non-file class string.
        $resolved = ValueCaster::castForRead(
            DataField::FILE,
            json_encode(['model_type' => self::class, 'model_id' => 1])
        );

        // Should return null (rejected), not autoload self::class.
        $this->assertNull($resolved);
    }

    public function test_file_subclass_is_accepted_by_resolver(): void
    {
        // A consumer-defined subclass of File should pass the base-class check.
        $resolved = ValueCaster::resolveModelClass(SubclassedFile::class);
        $this->assertSame(SubclassedFile::class, $resolved);
    }
}

class SubclassedFile extends File
{
    // Empty subclass — exists only to prove the base-class check accepts it.
}
