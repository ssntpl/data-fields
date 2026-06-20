<?php

namespace Ssntpl\DataFields\Tests\Feature;

use Carbon\Carbon;
use Ssntpl\DataFields\Models\DataRow;
use Ssntpl\DataFields\Support\FieldType;
use Ssntpl\DataFields\Tests\Models\TestOwner;
use Ssntpl\DataFields\Tests\TestCase;

class RowModeTest extends TestCase
{
    public function test_owner_can_attach_data_fields(): void
    {
        $owner = TestOwner::create(['name' => 'Widget']);

        $owner->fields()->create([
            'key'   => 'phone',
            'value' => '+91-99999-00000',
            'type'  => FieldType::Text->value,
        ]);

        $this->assertCount(1, $owner->fields()->get());
        $this->assertSame('+91-99999-00000', $owner->fields()->first()->value);
    }

    public function test_row_carries_label_and_meta_in_alignment_with_cast_mode(): void
    {
        $owner = TestOwner::create(['name' => 'L']);

        $row = $owner->fields()->create([
            'key'         => 'phone',
            'type'        => FieldType::Text->value,
            'value'       => '+91-9999',
            'label'       => 'Phone number',
            'description' => 'Primary contact number, including country code',
            'meta'        => ['ui_group' => 'contact', 'hint' => 'use international format'],
        ]);

        $reloaded = DataRow::find($row->id);
        $this->assertSame('Phone number', $reloaded->label);
        $this->assertSame('Primary contact number, including country code', $reloaded->description);
        $this->assertSame(['ui_group' => 'contact', 'hint' => 'use international format'], $reloaded->meta);
    }

    public function test_field_value_cast_for_each_type(): void
    {
        $owner = TestOwner::create(['name' => 'Caster']);

        $cases = [
            ['type' => FieldType::Bool,           'set' => 'yes',            'expect' => true],
            ['type' => FieldType::Text,           'set' => 'hello',          'expect' => 'hello'],
            ['type' => FieldType::Number,         'set' => '3.5',            'expect' => 3.5],
            ['type' => FieldType::SelectSingle,   'set' => 'red',            'expect' => 'red'],
            ['type' => FieldType::SelectMultiple, 'set' => ['a', 'b'],       'expect' => ['a', 'b']],
            ['type' => FieldType::Json,           'set' => ['k' => 'v'],     'expect' => ['k' => 'v']],
            ['type' => FieldType::Array_,         'set' => [1, 2, 3],        'expect' => [1, 2, 3]],
            ['type' => FieldType::Date,           'set' => '2026-06-15',     'expect' => '2026-06-15'],
            ['type' => FieldType::Time,           'set' => '10:35:00',       'expect' => '10:35:00'],
        ];

        foreach ($cases as $i => $case) {
            $field = $owner->fields()->create([
                'key'   => "case_$i",
                'type'  => $case['type']->value,
                'value' => $case['set'],
            ]);

            $reloaded = DataRow::find($field->id);
            $this->assertEquals($case['expect'], $reloaded->value, "type={$case['type']->value}");
        }
    }

    public function test_datetime_cast_returns_carbon(): void
    {
        $owner = TestOwner::create(['name' => 'DT']);

        $field = $owner->fields()->create([
            'key'   => 'when',
            'type'  => FieldType::DateTime->value,
            'value' => '2026-06-15 10:35:00',
        ]);

        $reloaded = DataRow::find($field->id);
        $this->assertInstanceOf(Carbon::class, $reloaded->value);
        $this->assertSame('2026-06-15 10:35:00', $reloaded->value->toDateTimeString());
    }

    public function test_bool_cast_accepts_common_truthy_values(): void
    {
        $owner = TestOwner::create(['name' => 'BoolCheck']);

        foreach (['1', 'true', 'yes', 'on'] as $truthy) {
            $field = $owner->fields()->create([
                'key'   => "t_$truthy",
                'type'  => FieldType::Bool->value,
                'value' => $truthy,
            ]);
            $this->assertTrue(DataRow::find($field->id)->value, "truthy=$truthy");
        }

        foreach (['0', 'false', 'no', 'off', ''] as $falsy) {
            $field = $owner->fields()->create([
                'key'   => "f_$falsy",
                'type'  => FieldType::Bool->value,
                'value' => $falsy,
            ]);
            $this->assertFalse(DataRow::find($field->id)->value, "falsy=$falsy");
        }
    }

    public function test_delete_cascades_to_child_fields(): void
    {
        $owner = TestOwner::create(['name' => 'Parent']);

        $parent = $owner->fields()->create([
            'key'   => 'group',
            'type'  => FieldType::Text->value,
            'value' => 'g',
        ]);

        $parent->fields()->create([
            'key'   => 'child',
            'type'  => FieldType::Text->value,
            'value' => 'c',
        ]);

        $this->assertSame(2, DataRow::count());

        $parent->delete();

        $this->assertSame(0, DataRow::count());
    }

    public function test_data_row_duplicate_clones_with_children_reparented(): void
    {
        $owner = TestOwner::create(['name' => 'P']);

        $parent = $owner->fields()->create([
            'key'   => 'parent',
            'type'  => FieldType::Text->value,
            'value' => 'p',
        ]);
        $parent->fields()->create(['key' => 'c1', 'type' => FieldType::Text->value, 'value' => '1']);
        $parent->fields()->create(['key' => 'c2', 'type' => FieldType::Text->value, 'value' => '2']);

        $copy = $parent->duplicate();

        $this->assertNotSame($parent->id, $copy->id);
        $this->assertSame(0, (int) $copy->owner_id);
        $this->assertSame('', $copy->owner_type);
        $this->assertCount(2, $copy->fields()->get());
        $this->assertCount(2, $parent->fields()->get());

        $childKeys = $copy->fields()->orderBy('key')->pluck('key')->all();
        $this->assertSame(['c1', 'c2'], $childKeys);
    }

    public function test_get_field_value_returns_cast_value_by_key(): void
    {
        $owner = TestOwner::create(['name' => 'Lookup']);
        $owner->fields()->create([
            'key'   => 'age',
            'type'  => FieldType::Number->value,
            'value' => '42',
        ]);

        $this->assertSame(42.0, $owner->getFieldValue('age'));
        $this->assertNull($owner->getFieldValue('does_not_exist'));
    }

    public function test_set_field_value_creates_row_when_absent(): void
    {
        $owner = TestOwner::create(['name' => 'Upsert']);
        $owner->setFieldValue('phone', '+91-9999900000');

        $this->assertSame('+91-9999900000', $owner->getFieldValue('phone'));
        $this->assertSame(1, $owner->fields()->count());
    }

    public function test_set_field_value_updates_existing_row(): void
    {
        $owner = TestOwner::create(['name' => 'Upsert']);
        $owner->setFieldValue('phone', 'old');
        $owner->setFieldValue('phone', 'new');

        $this->assertSame('new', $owner->getFieldValue('phone'));
        $this->assertSame(1, $owner->fields()->count());
    }

    public function test_set_field_value_honours_type_on_create_and_update(): void
    {
        $owner = TestOwner::create(['name' => 'Typed']);

        // Accept FieldType enum.
        $owner->setFieldValue('age', '25', FieldType::Number);
        $this->assertSame(25.0, $owner->getFieldValue('age'));

        // Update preserves type when not overridden.
        $owner->setFieldValue('age', '30');
        $this->assertSame(30.0, $owner->getFieldValue('age'));

        // Update overrides type when explicitly provided (string form still works).
        $owner->setFieldValue('age', 'thirty', 'text');
        $this->assertSame('thirty', $owner->getFieldValue('age'));
    }
}
