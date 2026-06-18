<?php

namespace Ssntpl\DataFields\Tests\Feature;

use Illuminate\Validation\ValidationException;
use Ssntpl\DataFields\Models\DataField;
use Ssntpl\DataFields\Tests\Models\JsonOwner;
use Ssntpl\DataFields\Tests\TestCase;

class ValidationTest extends TestCase
{
    public function test_passing_values_return_canonical_shape(): void
    {
        $owner = new JsonOwner();
        $owner->setDataFieldsSchema([
            ['key' => 'phone', 'type' => DataField::TEXT, 'validations' => ['required']],
        ]);
        $owner->setDataFieldsValues(['phone' => '99999']);

        $out = $owner->validateDataFields();
        $this->assertSame(['phone' => '99999'], $out);
    }

    public function test_failing_required_rule_throws_validation_exception(): void
    {
        $owner = new JsonOwner();
        $owner->setDataFieldsSchema([
            ['key' => 'phone', 'type' => DataField::TEXT, 'validations' => ['required']],
        ]);
        $owner->setDataFieldsValues(['phone' => null]);

        try {
            $owner->validateDataFields();
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('phone', $e->errors());
        }
    }

    public function test_container_scoped_error_keys_use_dotted_paths(): void
    {
        $owner = new JsonOwner();
        $owner->setDataFieldsSchema([
            [
                'type'  => 'step',
                'key'   => 'step_1',
                'items' => [
                    ['key' => 'performed_by', 'type' => DataField::TEXT, 'validations' => ['required']],
                ],
            ],
        ]);
        $owner->setDataFieldsValues(['step_1' => ['values' => ['performed_by' => null]]]);

        try {
            $owner->validateDataFields();
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('step_1.performed_by', $e->errors());
        }
    }

    public function test_hidden_fields_skip_validation(): void
    {
        $owner = new JsonOwner();
        $owner->setDataFieldsSchema([
            ['key' => 'has_phone', 'type' => DataField::BOOL],
            [
                'key'         => 'phone',
                'type'        => DataField::TEXT,
                'validations' => ['required'],
                'visible_if'  => ['has_phone' => true],
            ],
        ]);
        $owner->setDataFieldsValues([
            'has_phone' => false,
            'phone'     => null,
        ]);

        // Should NOT throw — phone is hidden because has_phone=false.
        $out = $owner->validateDataFields();
        $this->assertSame(false, $out['has_phone']);
    }

    public function test_numeric_validation_rule_honoured(): void
    {
        $owner = new JsonOwner();
        $owner->setDataFieldsSchema([
            ['key' => 'age', 'type' => DataField::NUMBER, 'validations' => ['required', 'numeric', 'min:0']],
        ]);
        $owner->setDataFieldsValues(['age' => 'not-a-number']);

        $this->expectException(ValidationException::class);
        $owner->validateDataFields();
    }

    public function test_select_single_options_auto_derive_in_rule(): void
    {
        $owner = new JsonOwner();
        $owner->setDataFieldsSchema([
            [
                'key'     => 'verdict',
                'type'    => DataField::SELECT_SINGLE,
                'options' => [
                    ['key' => 'ok',   'label' => 'Approved'],
                    ['key' => 'redo', 'label' => 'Needs redo'],
                ],
            ],
        ]);

        // Value not in options → fails.
        $owner->setDataFieldsValues(['verdict' => 'xyz']);
        try {
            $owner->validateDataFields();
            $this->fail('Expected ValidationException for out-of-options value');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('verdict', $e->errors());
        }

        // Valid option → passes.
        $owner->setDataFieldsValues(['verdict' => 'ok']);
        $this->assertSame(['verdict' => 'ok'], $owner->validateDataFields());
    }

    public function test_select_multiple_options_auto_derive_in_rule(): void
    {
        $owner = new JsonOwner();
        $owner->setDataFieldsSchema([
            [
                'key'     => 'tags',
                'type'    => DataField::SELECT_MULTIPLE,
                'options' => [
                    ['key' => 'red',   'label' => 'Red'],
                    ['key' => 'green', 'label' => 'Green'],
                    ['key' => 'blue',  'label' => 'Blue'],
                ],
            ],
        ]);

        $owner->setDataFieldsValues(['tags' => ['red', 'pink']]);
        try {
            $owner->validateDataFields();
            $this->fail('Expected ValidationException for out-of-options member');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('tags.1', $e->errors());
        }

        $owner->setDataFieldsValues(['tags' => ['red', 'blue']]);
        $owner->validateDataFields(); // should not throw
        $this->addToAssertionCount(1);
    }

    public function test_validate_returns_input_unchanged_on_success(): void
    {
        $owner = new JsonOwner();
        $owner->setDataFieldsSchema([
            ['key' => 'name', 'type' => DataField::TEXT, 'validations' => ['required']],
        ]);

        $input = ['name' => 'X', 'extra' => 'kept'];  // 'extra' is unknown
        $out   = $owner->validateDataFields($input);

        // Guard contract: returns input unchanged (no filtering of unknown keys).
        $this->assertSame($input, $out);
    }
}
