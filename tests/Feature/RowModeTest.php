<?php

namespace Ssntpl\DataFields\Tests\Feature;

use Carbon\Carbon;
use Ssntpl\DataFields\Models\DataField;
use Ssntpl\DataFields\Models\DataSet;
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
            'type'  => DataField::TEXT,
        ]);

        $this->assertCount(1, $owner->fields()->get());
        $this->assertSame('+91-99999-00000', $owner->fields()->first()->value);
    }

    public function test_owner_can_attach_data_sets(): void
    {
        $owner = TestOwner::create(['name' => 'Product']);

        $set = $owner->dataSets()->create([
            'name' => 'Specs',
            'type' => 'specifications',
        ]);

        $set->fields()->create([
            'key'   => 'weight',
            'value' => '2.5',
            'type'  => DataField::NUMBER,
        ]);

        $this->assertCount(1, $owner->dataSets()->get());
        $this->assertSame(2.5, $set->fields()->first()->value);
    }

    public function test_data_sets_snake_case_alias_still_works(): void
    {
        $owner = TestOwner::create(['name' => 'Legacy']);

        $owner->data_sets()->create([
            'name' => 'Specs',
            'type' => 'specifications',
        ]);

        $this->assertCount(1, $owner->data_sets()->get());
        $this->assertCount(1, $owner->dataSets()->get());
    }

    public function test_field_value_cast_for_each_type(): void
    {
        $owner = TestOwner::create(['name' => 'Caster']);

        $cases = [
            ['type' => DataField::BOOL,            'set' => 'yes',                          'expect' => true],
            ['type' => DataField::TEXT,            'set' => 'hello',                        'expect' => 'hello'],
            ['type' => DataField::NUMBER,          'set' => '3.5',                          'expect' => 3.5],
            ['type' => DataField::SELECT_SINGLE,   'set' => 'red',                          'expect' => 'red'],
            ['type' => DataField::SELECT_MULTIPLE, 'set' => ['a', 'b'],                     'expect' => ['a', 'b']],
            ['type' => DataField::JSON,            'set' => ['k' => 'v'],                   'expect' => ['k' => 'v']],
            ['type' => DataField::ARRAY,           'set' => [1, 2, 3],                      'expect' => [1, 2, 3]],
            ['type' => DataField::DATE,            'set' => '2026-06-15',                   'expect' => '2026-06-15'],
            ['type' => DataField::TIME,            'set' => '10:35:00',                     'expect' => '10:35:00'],
        ];

        foreach ($cases as $i => $case) {
            $field = $owner->fields()->create([
                'key'   => "case_$i",
                'type'  => $case['type'],
                'value' => $case['set'],
            ]);

            $reloaded = DataField::find($field->id);
            $this->assertEquals($case['expect'], $reloaded->value, "type={$case['type']}");
        }
    }

    public function test_datetime_cast_returns_carbon(): void
    {
        $owner = TestOwner::create(['name' => 'DT']);

        $field = $owner->fields()->create([
            'key'   => 'when',
            'type'  => DataField::DATETIME,
            'value' => '2026-06-15 10:35:00',
        ]);

        $reloaded = DataField::find($field->id);
        $this->assertInstanceOf(Carbon::class, $reloaded->value);
        $this->assertSame('2026-06-15 10:35:00', $reloaded->value->toDateTimeString());
    }

    public function test_bool_cast_accepts_common_truthy_values(): void
    {
        $owner = TestOwner::create(['name' => 'BoolCheck']);

        foreach (['1', 'true', 'yes', 'on'] as $truthy) {
            $field = $owner->fields()->create([
                'key'   => "t_$truthy",
                'type'  => DataField::BOOL,
                'value' => $truthy,
            ]);
            $this->assertTrue(DataField::find($field->id)->value, "truthy=$truthy");
        }

        foreach (['0', 'false', 'no', 'off', ''] as $falsy) {
            $field = $owner->fields()->create([
                'key'   => "f_$falsy",
                'type'  => DataField::BOOL,
                'value' => $falsy,
            ]);
            $this->assertFalse(DataField::find($field->id)->value, "falsy=$falsy");
        }
    }

    public function test_delete_cascades_to_child_fields(): void
    {
        $owner = TestOwner::create(['name' => 'Parent']);

        $parent = $owner->fields()->create([
            'key'   => 'group',
            'type'  => DataField::TEXT,
            'value' => 'g',
        ]);

        $parent->fields()->create([
            'key'   => 'child',
            'type'  => DataField::TEXT,
            'value' => 'c',
        ]);

        $this->assertSame(2, DataField::count());

        $parent->delete();

        $this->assertSame(0, DataField::count());
    }

    public function test_data_set_delete_cascades_to_fields(): void
    {
        $owner = TestOwner::create(['name' => 'P']);

        $set = $owner->dataSets()->create([
            'name' => 'Specs',
            'type' => 'specifications',
        ]);
        $set->fields()->create(['key' => 'k', 'type' => DataField::TEXT, 'value' => 'v']);

        $this->assertSame(1, DataField::count());

        $set->delete();

        $this->assertSame(0, DataField::count());
        $this->assertSame(0, DataSet::count());
    }

    public function test_data_field_duplicate_clones_with_children_reparented(): void
    {
        $owner = TestOwner::create(['name' => 'P']);

        $parent = $owner->fields()->create([
            'key'   => 'parent',
            'type'  => DataField::TEXT,
            'value' => 'p',
        ]);
        $parent->fields()->create(['key' => 'c1', 'type' => DataField::TEXT, 'value' => '1']);
        $parent->fields()->create(['key' => 'c2', 'type' => DataField::TEXT, 'value' => '2']);

        $copy = $parent->duplicate();

        $this->assertNotSame($parent->id, $copy->id);
        $this->assertSame(0, (int) $copy->owner_id);
        $this->assertSame('', $copy->owner_type);
        $this->assertCount(2, $copy->fields()->get());
        $this->assertCount(2, $parent->fields()->get());

        $childKeys = $copy->fields()->orderBy('key')->pluck('key')->all();
        $this->assertSame(['c1', 'c2'], $childKeys);
    }

    public function test_data_set_duplicate_clones_with_fields_reparented(): void
    {
        $owner = TestOwner::create(['name' => 'P']);

        $set = $owner->dataSets()->create(['name' => 'Specs', 'type' => 'specifications']);
        $set->fields()->create(['key' => 'a', 'type' => DataField::TEXT, 'value' => 'A']);
        $set->fields()->create(['key' => 'b', 'type' => DataField::TEXT, 'value' => 'B']);

        $copy = $set->duplicate();

        $this->assertNotSame($set->id, $copy->id);
        $this->assertSame(0, (int) $copy->owner_id);
        $this->assertSame('', $copy->owner_type);
        $this->assertCount(2, $copy->fields()->get());
        $this->assertCount(2, $set->fields()->get());
    }

    public function test_data_set_does_not_allow_mass_assigning_id(): void
    {
        $owner = TestOwner::create(['name' => 'P']);

        $set = $owner->dataSets()->create([
            'id'   => 9999,
            'name' => 'Specs',
            'type' => 'specifications',
        ]);

        $this->assertNotSame(9999, $set->id);
    }
}
