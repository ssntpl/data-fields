<?php

namespace Ssntpl\DataFields\Tests\Feature;

use Ssntpl\DataFields\Support\DataField;
use Ssntpl\DataFields\Support\FieldType;
use Ssntpl\DataFields\Tests\Models\TestOwner;
use Ssntpl\DataFields\Tests\TestCase;
use Ssntpl\LaravelFiles\Models\File;

class DataFieldCastTest extends TestCase
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

    public function test_null_column_returns_null(): void
    {
        $owner = TestOwner::create(['name' => 'Empty']);

        $this->assertNull($owner->user_settings);
    }

    public function test_assign_and_persist_a_section(): void
    {
        $owner                = TestOwner::create(['name' => 'P']);
        $owner->user_settings = DataField::section(items: [
            ['key' => 'dark_mode', 'type' => 'bool', 'value' => true],
            ['key' => 'language',  'type' => 'text', 'value' => 'en'],
        ]);
        $owner->save();

        $loaded = TestOwner::find($owner->id);
        $this->assertNotNull($loaded->user_settings);
        $this->assertTrue($loaded->user_settings->dark_mode->value);
        $this->assertSame('en', $loaded->user_settings->language->value);
    }

    public function test_factory_initialises_an_empty_section_and_add_field(): void
    {
        $owner                = TestOwner::create(['name' => 'P']);
        $owner->user_settings = DataField::section();
        $owner->user_settings->addField(['key' => 'phone', 'type' => 'text', 'value' => '+91-999']);
        $owner->save();

        $this->assertSame('+91-999', TestOwner::find($owner->id)->user_settings->phone->value);
    }

    public function test_mutation_through_value_then_save_persists(): void
    {
        $owner                = TestOwner::create(['name' => 'P']);
        $owner->user_settings = DataField::section(items: [
            ['key' => 'dark_mode', 'type' => 'bool', 'value' => false],
        ]);
        $owner->save();

        // Mutate in place.
        $reload                              = TestOwner::find($owner->id);
        $reload->user_settings->dark_mode->value = true;
        $reload->save();

        $this->assertTrue(TestOwner::find($owner->id)->user_settings->dark_mode->value);
    }

    public function test_is_dirty_detects_in_place_mutation(): void
    {
        $owner                = TestOwner::create(['name' => 'P']);
        $owner->user_settings = DataField::section(items: [
            ['key' => 'dark_mode', 'type' => 'bool', 'value' => false],
        ]);
        $owner->save();

        $reload                              = TestOwner::find($owner->id);
        $this->assertFalse($reload->isDirty('user_settings'));

        $reload->user_settings->dark_mode->value = true;
        $this->assertTrue($reload->isDirty('user_settings'));
    }

    public function test_array_assignment_is_coerced_to_DataField(): void
    {
        $owner                = TestOwner::create(['name' => 'P']);
        $owner->user_settings = [
            'type'  => 'section',
            'items' => [['key' => 'x', 'type' => 'text', 'value' => 'Y']],
        ];
        $owner->save();

        $loaded = TestOwner::find($owner->id);
        $this->assertSame('Y', $loaded->user_settings->x->value);
    }

    public function test_assigning_null_clears_the_column(): void
    {
        $owner                = TestOwner::create(['name' => 'P']);
        $owner->user_settings = DataField::section(items: [
            ['key' => 'x', 'type' => 'text', 'value' => 'Y'],
        ]);
        $owner->save();

        $owner->user_settings = null;
        $owner->save();

        $this->assertNull(TestOwner::find($owner->id)->user_settings);
    }

    public function test_round_trips_every_leaf_type(): void
    {
        $owner                = TestOwner::create(['name' => 'P']);
        $owner->user_settings = DataField::section(items: [
            ['key' => 'b',  'type' => 'bool',            'value' => 'yes'],
            ['key' => 't',  'type' => 'text',            'value' => 'hi'],
            ['key' => 'n',  'type' => 'number',          'value' => '3.5'],
            ['key' => 'ss', 'type' => 'select_single',   'value' => 'red'],
            ['key' => 'sm', 'type' => 'select_multiple', 'value' => ['a', 'b']],
            ['key' => 'j',  'type' => 'json',            'value' => ['k' => 'v']],
            ['key' => 'a',  'type' => 'array',           'value' => [1, 2, 3]],
            ['key' => 'd',  'type' => 'date',            'value' => '2026-06-15'],
            ['key' => 'tm', 'type' => 'time',            'value' => '10:35:00'],
            ['key' => 'dt', 'type' => 'datetime',        'value' => '2026-06-15 10:35:00'],
        ]);
        $owner->save();

        $loaded = TestOwner::find($owner->id);
        $this->assertTrue($loaded->user_settings->b->value);
        $this->assertSame('hi', $loaded->user_settings->t->value);
        $this->assertSame(3.5, $loaded->user_settings->n->value);
        $this->assertSame('red', $loaded->user_settings->ss->value);
        $this->assertSame(['a', 'b'], $loaded->user_settings->sm->value);
        $this->assertSame(['k' => 'v'], $loaded->user_settings->j->value);
        $this->assertSame([1, 2, 3], $loaded->user_settings->a->value);
        $this->assertSame('2026-06-15', $loaded->user_settings->d->value);
        $this->assertSame('10:35:00', $loaded->user_settings->tm->value);
        $this->assertInstanceOf(\Carbon\Carbon::class, $loaded->user_settings->dt->value);
    }

    public function test_arbitrary_nesting_depth(): void
    {
        $owner                = TestOwner::create(['name' => 'P']);
        $owner->user_settings = DataField::section(items: [
            [
                'type' => 'section', 'key' => 'outer',
                'items' => [
                    [
                        'type' => 'group', 'key' => 'inner',
                        'items' => [
                            ['key' => 'deep', 'type' => 'text', 'value' => 'hello'],
                        ],
                    ],
                ],
            ],
        ]);
        $owner->save();

        $this->assertSame(
            'hello',
            TestOwner::find($owner->id)->user_settings->outer->inner->deep->value
        );
    }

    public function test_castable_resolves_via_DataField_class_shorthand(): void
    {
        // TestOwner already uses `DataField::class` shorthand in its $casts.
        // This confirms Castable::castUsing() resolves to DataFieldCast.
        $owner                = TestOwner::create(['name' => 'P']);
        $owner->user_settings = DataField::section(items: [
            ['key' => 'foo', 'type' => 'text', 'value' => 'bar'],
        ]);
        $owner->save();

        $loaded = TestOwner::find($owner->id);
        $this->assertInstanceOf(DataField::class, $loaded->user_settings);
        $this->assertSame('bar', $loaded->user_settings->foo->value);
    }

    public function test_file_field_round_trips_to_File_instance(): void
    {
        $file  = $this->makeFile();
        $owner = TestOwner::create(['name' => 'P']);

        $owner->user_settings = DataField::section(items: [
            ['key' => 'avatar', 'type' => 'file', 'value' => $file],
        ]);
        $owner->save();

        $loaded = TestOwner::find($owner->id);
        $this->assertInstanceOf(File::class, $loaded->user_settings->avatar->value);
        $this->assertSame($file->id, $loaded->user_settings->avatar->value->id);
    }

    public function test_files_field_round_trips_to_File_list(): void
    {
        $f1 = $this->makeFile();
        $f2 = $this->makeFile();
        $f3 = $this->makeFile();
        $owner = TestOwner::create(['name' => 'P']);

        $owner->user_settings = DataField::section();
        $owner->user_settings->addField([
            'key' => 'attachments', 'type' => 'files', 'value' => [$f1, $f2, $f3],
        ]);
        $owner->save();

        $loaded = TestOwner::find($owner->id);
        $value  = $loaded->user_settings->attachments->value;

        $this->assertIsArray($value);
        $this->assertCount(3, $value);
        $this->assertContainsOnlyInstancesOf(File::class, $value);
        $this->assertSame([$f1->id, $f2->id, $f3->id], array_map(fn ($f) => $f->id, $value));
    }

    public function test_single_file_assigned_to_files_field_wraps_to_list(): void
    {
        $file  = $this->makeFile();
        $owner = TestOwner::create(['name' => 'P']);

        $owner->user_settings = DataField::section(items: [
            ['key' => 'docs', 'type' => 'files', 'value' => $file],
        ]);
        $owner->save();

        $value = TestOwner::find($owner->id)->user_settings->docs->value;
        $this->assertIsArray($value);
        $this->assertCount(1, $value);
        $this->assertSame($file->id, $value[0]->id);
    }

    public function test_empty_files_field_round_trips_as_empty_list(): void
    {
        $owner = TestOwner::create(['name' => 'P']);

        $owner->user_settings = DataField::section(items: [
            ['key' => 'attachments', 'type' => 'files', 'value' => []],
        ]);
        $owner->save();

        $value = TestOwner::find($owner->id)->user_settings->attachments->value;
        $this->assertSame([], $value);
    }
}
