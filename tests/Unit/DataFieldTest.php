<?php

namespace Ssntpl\DataFields\Tests\Unit;

use Ssntpl\DataFields\Support\DataField;
use Ssntpl\DataFields\Support\FieldType;
use Ssntpl\DataFields\Tests\TestCase;

class DataFieldTest extends TestCase
{
    // -----------------------------------------------------------------
    // Construction & shape validation
    // -----------------------------------------------------------------

    public function test_construct_a_simple_leaf(): void
    {
        $df = new DataField(['key' => 'phone', 'type' => 'text', 'value' => '+91-9999']);

        $this->assertSame('phone', $df->key);
        $this->assertSame(FieldType::Text, $df->type);
        $this->assertSame('+91-9999', $df->value);
        $this->assertTrue($df->isLeaf());
        $this->assertFalse($df->isContainer());
    }

    public function test_construct_a_container(): void
    {
        $df = new DataField([
            'key'   => 'settings',
            'type'  => 'section',
            'items' => [
                ['key' => 'dark_mode', 'type' => 'bool', 'value' => true],
            ],
        ]);

        $this->assertSame('settings', $df->key);
        $this->assertTrue($df->isContainer());
        $this->assertCount(1, $df->items);
        $this->assertSame('dark_mode', $df->items[0]->key);
    }

    public function test_root_key_is_optional(): void
    {
        $df = new DataField(['type' => 'section', 'items' => []]);
        $this->assertNull($df->key);
    }

    public function test_missing_type_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DataField(['key' => 'foo']);
    }

    public function test_unknown_type_throws(): void
    {
        $this->expectExceptionMessageMatches('/unknown field type/');
        new DataField(['key' => 'foo', 'type' => 'bogus']);
    }

    public function test_empty_key_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DataField(['key' => '', 'type' => 'text']);
    }

    public function test_container_without_items_throws(): void
    {
        $this->expectExceptionMessageMatches('/items/');
        new DataField(['key' => 'g', 'type' => 'group']);
    }

    public function test_duplicate_sibling_keys_throw(): void
    {
        $this->expectExceptionMessageMatches('/duplicate sibling key/');
        new DataField([
            'type'  => 'section',
            'items' => [
                ['key' => 'dup', 'type' => 'text'],
                ['key' => 'dup', 'type' => 'text'],
            ],
        ]);
    }

    public function test_options_with_comma_in_key_rejected(): void
    {
        $this->expectExceptionMessageMatches('/must not contain `,` or `\|`/');
        new DataField([
            'key' => 'v', 'type' => 'select_single',
            'options' => [['key' => 'a,b']],
        ]);
    }

    public function test_visible_if_as_list_rejected(): void
    {
        $this->expectExceptionMessageMatches('/visible_if/');
        new DataField([
            'key' => 'v', 'type' => 'text',
            'visible_if' => ['a'],  // list, not assoc map
        ]);
    }

    // -----------------------------------------------------------------
    // Factories
    // -----------------------------------------------------------------

    public function test_leaf_factory(): void
    {
        $df = DataField::leaf(FieldType::Number, '3.5', ['key' => 'price']);
        $this->assertSame(3.5, $df->value);   // cast via ValueCaster
        $this->assertSame(FieldType::Number, $df->type);
    }

    public function test_section_factory(): void
    {
        $df = DataField::section('user_settings', [
            ['key' => 'dark_mode', 'type' => 'bool', 'value' => true],
        ]);
        $this->assertSame(FieldType::Section, $df->type);
        $this->assertSame('user_settings', $df->key);
        $this->assertCount(1, $df->items);
    }

    public function test_step_and_group_factories(): void
    {
        $this->assertSame(FieldType::Step, DataField::step('s')->type);
        $this->assertSame(FieldType::Group, DataField::group('g')->type);
    }

    // -----------------------------------------------------------------
    // Value access (leaf) — magic property, casting, defaults
    // -----------------------------------------------------------------

    public function test_bool_value_round_trips_through_setter(): void
    {
        $df = DataField::leaf(FieldType::Bool, 'yes', ['key' => 'on']);
        $this->assertTrue($df->value);

        $df->value = 'no';
        $this->assertFalse($df->value);
    }

    public function test_number_value_casts_to_float(): void
    {
        $df = DataField::leaf(FieldType::Number, '3.5', ['key' => 'n']);
        $this->assertSame(3.5, $df->value);
    }

    public function test_value_falls_back_to_default_when_unset(): void
    {
        $df = new DataField(['key' => 'verdict', 'type' => 'text', 'default' => 'pending']);
        $this->assertSame('pending', $df->value);

        $df->value = null;
        $this->assertNull($df->value);   // explicit null overrides default
    }

    public function test_set_value_on_container_throws(): void
    {
        $df = DataField::section('s');
        $this->expectException(\LogicException::class);
        $df->setValue('x');
    }

    // -----------------------------------------------------------------
    // Property proxy through containers
    // -----------------------------------------------------------------

    public function test_property_access_returns_child_by_key(): void
    {
        $df = new DataField([
            'type'  => 'section',
            'items' => [
                ['key' => 'dark_mode', 'type' => 'bool', 'value' => true],
                ['key' => 'language',  'type' => 'text', 'value' => 'en'],
            ],
        ]);

        $this->assertSame(true, $df->dark_mode->value);
        $this->assertSame('en', $df->language->value);
    }

    public function test_property_access_chains_through_nested_containers(): void
    {
        $df = new DataField([
            'type'  => 'section',
            'items' => [
                [
                    'key' => 'appearance', 'type' => 'group',
                    'items' => [
                        ['key' => 'dark_mode', 'type' => 'bool', 'value' => true],
                    ],
                ],
            ],
        ]);

        $this->assertSame(true, $df->appearance->dark_mode->value);
    }

    public function test_property_access_on_unknown_key_returns_null(): void
    {
        $df = DataField::section('s', [['key' => 'x', 'type' => 'text', 'value' => 'y']]);
        $this->assertNull($df->no_such_key);
    }

    public function test_data_field_dotted_path_lookup(): void
    {
        $df = new DataField([
            'type'  => 'section',
            'items' => [
                [
                    'key' => 'appearance', 'type' => 'group',
                    'items' => [['key' => 'dark_mode', 'type' => 'bool', 'value' => true]],
                ],
            ],
        ]);

        $this->assertSame(true, $df->dataField('appearance.dark_mode')->value);
        $this->assertNull($df->dataField('appearance.missing'));
        $this->assertNull($df->dataField('missing.path'));
    }

    // -----------------------------------------------------------------
    // Mutation — addField, removeField, replace
    // -----------------------------------------------------------------

    public function test_add_field_appends_to_container(): void
    {
        $df = DataField::section('s', [['key' => 'a', 'type' => 'text', 'value' => 'A']]);
        $df->addField(['key' => 'b', 'type' => 'text', 'value' => 'B']);

        $this->assertCount(2, $df->items);
        $this->assertSame('B', $df->b->value);
    }

    public function test_add_field_with_duplicate_key_throws(): void
    {
        $df = DataField::section('s', [['key' => 'a', 'type' => 'text']]);
        $this->expectExceptionMessageMatches('/duplicate sibling key/');
        $df->addField(['key' => 'a', 'type' => 'text']);
    }

    public function test_add_field_on_leaf_throws(): void
    {
        $df = DataField::leaf(FieldType::Text, 'x', ['key' => 'leaf']);
        $this->expectException(\LogicException::class);
        $df->addField(['key' => 'y', 'type' => 'text']);
    }

    public function test_remove_field(): void
    {
        $df = DataField::section('s', [
            ['key' => 'a', 'type' => 'text'],
            ['key' => 'b', 'type' => 'text'],
        ]);
        $df->removeField('a');
        $this->assertCount(1, $df->items);
        $this->assertSame('b', $df->items[0]->key);
    }

    public function test_setting_existing_child_via_assignment_replaces(): void
    {
        $df = DataField::section('s', [['key' => 'a', 'type' => 'text', 'value' => 'old']]);
        $df->a = ['key' => 'a', 'type' => 'text', 'value' => 'new'];

        $this->assertSame('new', $df->a->value);
        $this->assertCount(1, $df->items);
    }

    public function test_setting_unknown_child_via_assignment_throws(): void
    {
        $df = DataField::section('s');
        $this->expectException(\LogicException::class);
        $df->unknown = ['key' => 'unknown', 'type' => 'text'];
    }

    // -----------------------------------------------------------------
    // Iteration & ArrayAccess
    // -----------------------------------------------------------------

    public function test_iterates_container_items(): void
    {
        $df = DataField::section('s', [
            ['key' => 'a', 'type' => 'text', 'value' => 'A'],
            ['key' => 'b', 'type' => 'text', 'value' => 'B'],
        ]);
        $keys = [];
        foreach ($df as $child) {
            $keys[] = $child->key;
        }
        $this->assertSame(['a', 'b'], $keys);
    }

    public function test_array_access_by_index_and_key(): void
    {
        $df = DataField::section('s', [
            ['key' => 'a', 'type' => 'text', 'value' => 'A'],
        ]);
        $this->assertSame('A', $df[0]->value);
        $this->assertSame('A', $df['a']->value);
        $this->assertTrue(isset($df['a']));
        $this->assertFalse(isset($df['z']));
    }

    public function test_array_access_unset_removes_child(): void
    {
        $df = DataField::section('s', [
            ['key' => 'a', 'type' => 'text'],
            ['key' => 'b', 'type' => 'text'],
        ]);
        unset($df['a']);
        $this->assertCount(1, $df->items);
    }

    public function test_count_returns_items_count(): void
    {
        $df = DataField::section('s', [
            ['key' => 'a', 'type' => 'text'],
            ['key' => 'b', 'type' => 'text'],
        ]);
        $this->assertCount(2, $df);
    }

    // -----------------------------------------------------------------
    // Serialisation round-trip
    // -----------------------------------------------------------------

    public function test_to_array_round_trips_through_constructor(): void
    {
        $original = new DataField([
            'type'  => 'section',
            'key'   => 'settings',
            'items' => [
                ['key' => 'dark_mode', 'type' => 'bool', 'value' => true],
                ['key' => 'language',  'type' => 'text', 'value' => 'en'],
            ],
        ]);

        $reconstructed = DataField::fromArray($original->toArray());

        $this->assertSame($original->toArray(), $reconstructed->toArray());
        $this->assertSame(true, $reconstructed->dark_mode->value);
    }

    public function test_to_array_writes_storage_form_for_datetime(): void
    {
        $df = DataField::leaf(FieldType::DateTime, '2026-06-15 10:35:00', ['key' => 'when']);
        $arr = $df->toArray();
        // Storage form is a date string.
        $this->assertSame('2026-06-15 10:35:00', $arr['value']);
    }

    public function test_json_encode_uses_to_array(): void
    {
        $df = DataField::leaf(FieldType::Text, 'hi', ['key' => 'g']);
        $this->assertJson(json_encode($df));
        $this->assertStringContainsString('"value":"hi"', json_encode($df));
    }

    // -----------------------------------------------------------------
    // Validation — rule running, visible_if, select auto-in
    // -----------------------------------------------------------------

    public function test_validate_passes_for_valid_input(): void
    {
        $df = new DataField([
            'type'  => 'section',
            'items' => [
                ['key' => 'name', 'type' => 'text', 'value' => 'Sam', 'validations' => ['required']],
            ],
        ]);
        $out = $df->validate();
        $this->assertSame(['name' => 'Sam'], $out);
    }

    public function test_validate_throws_on_failure_with_dotted_path(): void
    {
        $df = new DataField([
            'type'  => 'section',
            'items' => [
                [
                    'key' => 'step_1', 'type' => 'step',
                    'items' => [
                        ['key' => 'performed_by', 'type' => 'text', 'validations' => ['required']],
                    ],
                ],
            ],
        ]);

        try {
            $df->validate();
            $this->fail('Expected ValidationException');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('step_1.performed_by', $e->errors());
        }
    }

    public function test_select_single_auto_in_rule(): void
    {
        $df = new DataField([
            'type'  => 'section',
            'items' => [
                [
                    'key' => 'verdict', 'type' => 'select_single', 'value' => 'xyz',
                    'options' => [['key' => 'ok'], ['key' => 'redo']],
                ],
            ],
        ]);

        try {
            $df->validate();
            $this->fail('Expected ValidationException for out-of-options value');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('verdict', $e->errors());
        }
    }

    public function test_hidden_fields_skip_validation(): void
    {
        $df = new DataField([
            'type'  => 'section',
            'items' => [
                ['key' => 'has_phone', 'type' => 'bool', 'value' => false],
                [
                    'key' => 'phone', 'type' => 'text',
                    'validations' => ['required'],
                    'visible_if'  => ['has_phone' => true],
                ],
            ],
        ]);

        // Should NOT throw — phone is hidden.
        $df->validate();
        $this->addToAssertionCount(1);
    }
}
