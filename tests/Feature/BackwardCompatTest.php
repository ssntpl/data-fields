<?php

namespace Ssntpl\DataFields\Tests\Feature;

use Ssntpl\DataFields\Models\DataField;
use Ssntpl\DataFields\Tests\Models\MixedOwner;
use Ssntpl\DataFields\Tests\Models\TestOwner;
use Ssntpl\DataFields\Tests\TestCase;

class BackwardCompatTest extends TestCase
{
    public function test_row_mode_only_behaviour_is_unchanged(): void
    {
        $owner = TestOwner::create(['name' => 'Legacy']);
        $owner->fields()->create([
            'key'   => 'phone',
            'type'  => DataField::TEXT,
            'value' => '99999',
        ]);
        $owner->dataSets()->create(['name' => 'Specs', 'type' => 'specifications']);

        $this->assertCount(1, $owner->fields()->get());
        $this->assertCount(1, $owner->dataSets()->get());
    }

    public function test_model_can_use_both_row_and_json_traits_simultaneously(): void
    {
        $owner = new MixedOwner(['name' => 'Mixed']);
        $owner->save();

        // Row-mode field via HasDataFields
        $owner->fields()->create([
            'key'   => 'row_field',
            'type'  => DataField::TEXT,
            'value' => 'A',
        ]);

        // JSON-mode field via HasDataFieldsJson
        $owner->setDataFieldsSchema([
            ['key' => 'json_field', 'type' => DataField::TEXT],
        ]);
        $owner->setFieldValue('json_field', 'B');
        $owner->save();

        $loaded = MixedOwner::find($owner->id);
        $this->assertSame('A', $loaded->fields()->first()->value);
        $this->assertSame('B', $loaded->getFieldValue('json_field'));
    }
}
