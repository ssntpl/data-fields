<?php

namespace Ssntpl\DataFields\Tests\Feature;

use Carbon\Carbon;
use Ssntpl\DataFields\Models\DataField;
use Ssntpl\DataFields\Support\FieldValue;
use Ssntpl\DataFields\Tests\Models\ChildEntry;
use Ssntpl\DataFields\Tests\Models\JsonOwner;
use Ssntpl\DataFields\Tests\Models\LogEntry;
use Ssntpl\DataFields\Tests\TestCase;

class HasDataFieldsJsonTest extends TestCase
{
    public function test_persists_and_reads_a_flat_schema_and_values(): void
    {
        $owner = new JsonOwner(['name' => 'Form']);
        $owner->setDataFieldsSchema([
            ['key' => 'performed_by', 'type' => DataField::TEXT, 'label' => 'Performed by'],
            ['key' => 'verdict',      'type' => DataField::SELECT_SINGLE],
        ]);
        $owner->setDataFieldsValues([
            'performed_by' => 'Rahul',
            'verdict'      => 'ok',
        ]);
        $owner->save();

        $loaded = JsonOwner::find($owner->id);
        $this->assertSame('Rahul', $loaded->getFieldValue('performed_by'));
        $this->assertSame('ok', $loaded->getFieldValue('verdict'));
    }

    public function test_set_field_value_updates_a_single_leaf(): void
    {
        $owner = new JsonOwner(['name' => 'F']);
        $owner->setDataFieldsSchema([
            ['key' => 'phone', 'type' => DataField::TEXT],
        ]);
        $owner->setFieldValue('phone', '+91-9999900000');
        $owner->save();

        $this->assertSame('+91-9999900000', JsonOwner::find($owner->id)->getFieldValue('phone'));
    }

    public function test_round_trips_every_field_type(): void
    {
        $owner = new JsonOwner(['name' => 'AllTypes']);

        $owner->setDataFieldsSchema([
            ['key' => 'b',  'type' => DataField::BOOL],
            ['key' => 't',  'type' => DataField::TEXT],
            ['key' => 'n',  'type' => DataField::NUMBER],
            ['key' => 'ss', 'type' => DataField::SELECT_SINGLE],
            ['key' => 'sm', 'type' => DataField::SELECT_MULTIPLE],
            ['key' => 'j',  'type' => DataField::JSON],
            ['key' => 'a',  'type' => DataField::ARRAY],
            ['key' => 'd',  'type' => DataField::DATE],
            ['key' => 'tm', 'type' => DataField::TIME],
            ['key' => 'dt', 'type' => DataField::DATETIME],
        ]);
        $owner->setDataFieldsValues([
            'b' => 'yes', 't' => 'hi', 'n' => '3.5', 'ss' => 'red',
            'sm' => ['a', 'b'], 'j' => ['k' => 'v'], 'a' => [1, 2, 3],
            'd' => '2026-06-15', 'tm' => '10:35:00', 'dt' => '2026-06-15 10:35:00',
        ]);
        $owner->save();

        $loaded = JsonOwner::find($owner->id);
        $this->assertTrue($loaded->getFieldValue('b'));
        $this->assertSame('hi', $loaded->getFieldValue('t'));
        $this->assertSame(3.5, $loaded->getFieldValue('n'));
        $this->assertSame('red', $loaded->getFieldValue('ss'));
        $this->assertSame(['a', 'b'], $loaded->getFieldValue('sm'));
        $this->assertSame(['k' => 'v'], $loaded->getFieldValue('j'));
        $this->assertSame([1, 2, 3], $loaded->getFieldValue('a'));
        $this->assertSame('2026-06-15', $loaded->getFieldValue('d'));
        $this->assertSame('10:35:00', $loaded->getFieldValue('tm'));
        $this->assertInstanceOf(Carbon::class, $loaded->getFieldValue('dt'));
        $this->assertSame('2026-06-15 10:35:00', $loaded->getFieldValue('dt')->toDateTimeString());
    }

    public function test_data_fields_returns_a_field_value_per_leaf(): void
    {
        $owner = new JsonOwner();
        $owner->setDataFieldsSchema([
            ['key' => 'phone', 'type' => DataField::TEXT, 'label' => 'Phone'],
            ['key' => 'age',   'type' => DataField::NUMBER],
        ]);
        $owner->setDataFieldsValues(['phone' => '999', 'age' => '25']);

        $fields = $owner->dataFields();

        $this->assertCount(2, $fields);
        $this->assertContainsOnlyInstancesOf(FieldValue::class, $fields);

        $phone = $fields->firstWhere('key', 'phone');
        $this->assertSame('Phone', $phone->label);
        $this->assertSame('999', $phone->value);
        $this->assertTrue($phone->isVisible());

        $age = $fields->firstWhere('key', 'age');
        $this->assertSame(25.0, $age->value);
    }

    public function test_overridden_column_names_are_honoured(): void
    {
        $entry = new LogEntry(['name' => 'L']);
        $entry->setDataFieldsSchema([
            ['key' => 'temperature', 'type' => DataField::NUMBER],
        ]);
        $entry->setDataFieldsValues(['temperature' => '36.6']);
        $entry->save();

        $loaded = LogEntry::find($entry->id);
        $this->assertSame(36.6, $loaded->getFieldValue('temperature'));
        // Schema and values landed in the overridden columns, not the defaults.
        $this->assertNotNull($loaded->getAttributes()['log_format']);
        $this->assertNotNull($loaded->getAttributes()['entries']);
    }

    public function test_works_when_schema_column_is_null_and_method_overrides_loading(): void
    {
        $entry = new ChildEntry(['name' => 'C']);
        $entry->stubSchema = [
            ['key' => 'temp', 'type' => DataField::NUMBER],
        ];
        $entry->setDataFieldsValues(['temp' => '37']);
        $entry->save();

        // Reload — stubSchema is per-instance so set it again.
        $loaded = ChildEntry::find($entry->id);
        $loaded->stubSchema = [
            ['key' => 'temp', 'type' => DataField::NUMBER],
        ];

        $this->assertSame(37.0, $loaded->getFieldValue('temp'));

        // Writing to the schema must throw — there's no column to store it in.
        $this->expectException(\LogicException::class);
        $loaded->setDataFieldsSchema([['key' => 'x', 'type' => DataField::TEXT]]);
    }

    public function test_reads_both_wrapped_and_unwrapped_envelope_forms(): void
    {
        $owner = JsonOwner::create([
            'name' => 'EnvTest',
            // Unwrapped on read should still be honoured even though we write wrapped.
            'data_fields_schema' => [
                ['key' => 'foo', 'type' => DataField::TEXT],
            ],
            'data_fields_values' => [
                'foo' => 'bar',
            ],
        ]);

        $loaded = JsonOwner::find($owner->id);
        $this->assertSame('bar', $loaded->getFieldValue('foo'));

        // Wrapped form manually written:
        $owner2 = JsonOwner::create([
            'name' => 'EnvTest2',
            'data_fields_schema' => [
                'version' => '1.0',
                'schema'  => [
                    ['key' => 'foo', 'type' => DataField::TEXT],
                ],
            ],
            'data_fields_values' => [
                'version' => '1.0',
                'values'  => ['foo' => 'baz'],
            ],
        ]);
        $loaded2 = JsonOwner::find($owner2->id);
        $this->assertSame('baz', $loaded2->getFieldValue('foo'));
    }

    public function test_writes_wrapped_envelope_by_default(): void
    {
        $owner = new JsonOwner(['name' => 'Wrap']);
        $owner->setDataFieldsSchema([['key' => 'foo', 'type' => DataField::TEXT]]);
        $owner->setDataFieldsValues(['foo' => 'x']);
        $owner->save();

        $rawSchema = JsonOwner::find($owner->id)->getAttributes()['data_fields_schema'];
        $rawValues = JsonOwner::find($owner->id)->getAttributes()['data_fields_values'];

        $this->assertStringContainsString('"version"', $rawSchema);
        $this->assertStringContainsString('"schema"', $rawSchema);
        $this->assertStringContainsString('"version"', $rawValues);
        $this->assertStringContainsString('"values"', $rawValues);
    }

    public function test_visible_if_marks_field_as_hidden_when_dependency_unmet(): void
    {
        $owner = new JsonOwner();
        $owner->setDataFieldsSchema([
            ['key' => 'has_phone', 'type' => DataField::BOOL],
            [
                'key'        => 'phone',
                'type'       => DataField::TEXT,
                'visible_if' => ['has_phone' => true],
            ],
        ]);
        $owner->setDataFieldsValues(['has_phone' => false]);

        $phone = $owner->dataField('phone');
        $this->assertFalse($phone->isVisible());

        // Hidden value is kept even though hidden.
        $owner->setFieldValue('phone', '+919999999999');
        $owner->setDataFieldsValues(array_merge($owner->getDataFieldsValues(), ['has_phone' => false]));
        $this->assertSame('+919999999999', $owner->getFieldValue('phone'));
    }

    public function test_lenient_writes_keep_unknown_keys(): void
    {
        config()->set('data-fields.json.strict_writes', false);

        $owner = new JsonOwner();
        $owner->setDataFieldsSchema([['key' => 'known', 'type' => DataField::TEXT]]);
        $owner->setDataFieldsValues(['known' => 'k', 'unknown' => 'u']);

        $this->assertSame('u', $owner->getDataFieldsValues()['unknown'] ?? null);
    }

    public function test_strict_writes_drop_unknown_keys(): void
    {
        config()->set('data-fields.json.strict_writes', true);

        $owner = new JsonOwner();
        $owner->setDataFieldsSchema([['key' => 'known', 'type' => DataField::TEXT]]);
        $owner->setDataFieldsValues(['known' => 'k', 'unknown' => 'u']);

        $this->assertArrayNotHasKey('unknown', $owner->getDataFieldsValues());
        $this->assertSame('k', $owner->getDataFieldsValues()['known']);
    }

    public function test_get_field_value_falls_back_to_default_when_unset(): void
    {
        $owner = new JsonOwner();
        $owner->setDataFieldsSchema([
            ['key' => 'verdict', 'type' => DataField::TEXT, 'default' => 'pending'],
        ]);
        // No values set yet.
        $this->assertSame('pending', $owner->getFieldValue('verdict'));

        // Explicit null overrides the default — callers chose to clear it.
        $owner->setDataFieldsValues(['verdict' => null]);
        $this->assertNull($owner->getFieldValue('verdict'));
    }

    public function test_data_field_matches_by_path_not_by_key(): void
    {
        $owner = new JsonOwner();
        $owner->setDataFieldsSchema([
            [
                'type' => 'step', 'key' => 'step_1',
                'items' => [['key' => 'phone', 'type' => DataField::TEXT]],
            ],
            [
                'type' => 'step', 'key' => 'step_2',
                'items' => [['key' => 'phone', 'type' => DataField::TEXT]],
            ],
        ]);

        // Bare 'phone' is ambiguous → no match.
        $this->assertNull($owner->dataField('phone'));

        // Full dotted paths resolve unambiguously.
        $this->assertNotNull($owner->dataField('step_1.phone'));
        $this->assertNotNull($owner->dataField('step_2.phone'));
    }

    public function test_set_field_value_refuses_dotted_unknown_paths(): void
    {
        $owner = new JsonOwner();
        $owner->setDataFieldsSchema([['key' => 'known', 'type' => DataField::TEXT]]);
        $owner->setFieldValue('unknown.path', 'x');

        $this->assertArrayNotHasKey('unknown.path', $owner->getDataFieldsValues());
        $this->assertArrayNotHasKey('unknown', $owner->getDataFieldsValues());
    }

    public function test_leaf_typed_group_without_items_is_treated_as_leaf(): void
    {
        $owner = new JsonOwner();
        $owner->setDataFieldsSchema([
            // 'group' is a reserved container type, but without `items` this
            // must be treated as a leaf — not silently swallowed.
            ['key' => 'opts', 'type' => 'group'],
        ]);
        $owner->setFieldValue('opts', 'something');

        $this->assertSame('something', $owner->getFieldValue('opts'));
    }

    public function test_envelope_half_present_throws(): void
    {
        $owner = new JsonOwner([
            'name'               => 'Bad',
            'data_fields_schema' => ['version' => '1.0'],  // payload missing
        ]);

        $this->expectException(\UnexpectedValueException::class);
        $owner->getDataFieldsSchema();
    }

    public function test_bool_truthy_match_is_case_insensitive(): void
    {
        $owner = new JsonOwner();
        $owner->setDataFieldsSchema([['key' => 'b', 'type' => DataField::BOOL]]);

        foreach (['YES', 'Yes', 'TRUE', 'On', '1'] as $truthy) {
            $owner->setFieldValue('b', $truthy);
            $this->assertTrue($owner->getFieldValue('b'), "truthy=$truthy");
        }
        foreach (['no', 'NO', 'False', 'OFF', '0'] as $falsy) {
            $owner->setFieldValue('b', $falsy);
            $this->assertFalse($owner->getFieldValue('b'), "falsy=$falsy");
        }
    }
}
