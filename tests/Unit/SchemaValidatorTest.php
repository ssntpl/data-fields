<?php

namespace Ssntpl\DataFields\Tests\Unit;

use InvalidArgumentException;
use Ssntpl\DataFields\Models\DataField;
use Ssntpl\DataFields\Support\SchemaValidator;
use Ssntpl\DataFields\Tests\TestCase;

class SchemaValidatorTest extends TestCase
{
    public function test_valid_flat_schema_passes(): void
    {
        SchemaValidator::validate([
            ['key' => 'phone', 'type' => DataField::TEXT],
            ['key' => 'age',   'type' => DataField::NUMBER, 'validations' => ['required', 'numeric']],
        ]);
        $this->addToAssertionCount(1);
    }

    public function test_valid_container_schema_passes(): void
    {
        SchemaValidator::validate([
            [
                'type'  => 'step',
                'key'   => 'step_1',
                'items' => [
                    ['key' => 'performed_by', 'type' => DataField::TEXT],
                ],
            ],
        ]);
        $this->addToAssertionCount(1);
    }

    public function test_missing_key_rejected(): void
    {
        $this->expectExceptionMessageMatches('/`key` must be a non-empty string/');
        SchemaValidator::validate([['type' => DataField::TEXT]]);
    }

    public function test_missing_type_rejected(): void
    {
        $this->expectExceptionMessageMatches('/`type` must be a non-empty string/');
        SchemaValidator::validate([['key' => 'x']]);
    }

    public function test_duplicate_keys_rejected(): void
    {
        $this->expectExceptionMessageMatches('/duplicate key `phone`/');
        SchemaValidator::validate([
            ['key' => 'phone', 'type' => DataField::TEXT],
            ['key' => 'phone', 'type' => DataField::TEXT],
        ]);
    }

    public function test_duplicate_keys_inside_container_rejected(): void
    {
        $this->expectExceptionMessageMatches('/duplicate key `dup`/');
        SchemaValidator::validate([
            [
                'type'  => 'section',
                'key'   => 'sec',
                'items' => [
                    ['key' => 'dup', 'type' => DataField::TEXT],
                    ['key' => 'dup', 'type' => DataField::TEXT],
                ],
            ],
        ]);
    }

    public function test_reserved_version_key_at_top_level_rejected(): void
    {
        $this->expectExceptionMessageMatches('/`version` is a reserved top-level key/');
        SchemaValidator::validate([['key' => 'version', 'type' => DataField::TEXT]]);
    }

    public function test_unknown_leaf_type_rejected(): void
    {
        $this->expectExceptionMessageMatches('/unknown leaf type `mystery`/');
        SchemaValidator::validate([['key' => 'x', 'type' => 'mystery']]);
    }

    public function test_container_missing_items_rejected(): void
    {
        $this->expectExceptionMessageMatches('/missing required `items`/');
        SchemaValidator::validate([['type' => 'step', 'key' => 'step_1']]);
    }

    public function test_options_missing_key_rejected(): void
    {
        $this->expectExceptionMessageMatches('/option `key` must be/');
        SchemaValidator::validate([
            [
                'key'     => 'v',
                'type'    => DataField::SELECT_SINGLE,
                'options' => [['label' => 'Approved']],
            ],
        ]);
    }

    public function test_select_options_can_be_omitted(): void
    {
        SchemaValidator::validate([
            ['key' => 'v', 'type' => DataField::SELECT_SINGLE],
        ]);
        $this->addToAssertionCount(1);
    }

    public function test_visible_if_must_be_object_map(): void
    {
        $this->expectExceptionMessageMatches('/`visible_if` must be an object map/');
        SchemaValidator::validate([
            ['key' => 'a', 'type' => DataField::BOOL],
            ['key' => 'b', 'type' => DataField::TEXT, 'visible_if' => ['a']],
        ]);
    }

    public function test_validations_must_be_list(): void
    {
        $this->expectExceptionMessageMatches('/`validations` must be a list/');
        SchemaValidator::validate([
            ['key' => 'x', 'type' => DataField::TEXT, 'validations' => 'required'],
        ]);
    }

    public function test_top_level_associative_array_rejected(): void
    {
        $this->expectExceptionMessageMatches('/expected a list of nodes/');
        SchemaValidator::validate(['phone' => ['type' => DataField::TEXT]]);
    }
}
