<?php

namespace Ssntpl\DataFields\Tests\Feature;

use Ssntpl\DataFields\Models\DataField;
use Ssntpl\DataFields\Support\DataSetValue;
use Ssntpl\DataFields\Support\FieldValue;
use Ssntpl\DataFields\Tests\Models\JsonOwner;
use Ssntpl\DataFields\Tests\TestCase;

class HasDataSetsJsonTest extends TestCase
{
    private function stepSchema(): array
    {
        return [
            [
                'type'  => 'step',
                'key'   => 'step_1',
                'label' => 'Perform',
                'items' => [
                    ['key' => 'performed_by',    'type' => DataField::TEXT, 'validations' => ['required']],
                    ['key' => 'evidence_photos', 'type' => DataField::FILES],
                ],
            ],
            [
                'type'  => 'step',
                'key'   => 'step_2',
                'label' => 'Verify',
                'requires' => ['step_1'],
                'items' => [
                    ['key' => 'verdict', 'type' => DataField::SELECT_SINGLE],
                ],
            ],
        ];
    }

    public function test_dotted_path_get_and_set(): void
    {
        $owner = new JsonOwner(['name' => 'Schedule']);
        $owner->setDataFieldsSchema($this->stepSchema());
        $owner->setFieldValue('step_1.performed_by', 'Rahul');
        $owner->setFieldValue('step_2.verdict', 'ok');
        $owner->save();

        $loaded = JsonOwner::find($owner->id);
        $this->assertSame('Rahul', $loaded->getFieldValue('step_1.performed_by'));
        $this->assertSame('ok', $loaded->getFieldValue('step_2.verdict'));
    }

    public function test_data_sets_walks_container_nodes(): void
    {
        $owner = new JsonOwner(['name' => 'S']);
        $owner->setDataFieldsSchema($this->stepSchema());
        $owner->setFieldValue('step_1.performed_by', 'Rahul');

        $sets = $owner->dataSets();
        $this->assertCount(2, $sets);
        $this->assertContainsOnlyInstancesOf(DataSetValue::class, $sets);

        $step1 = $sets->firstWhere('key', 'step_1');
        $this->assertSame('Perform', $step1->label);
        $this->assertSame([], $step1->requires);
        $this->assertGreaterThan(0, $step1->items->count());

        $step2 = $sets->firstWhere('key', 'step_2');
        $this->assertSame(['step_1'], $step2->requires);
    }

    public function test_data_set_returns_single_container_by_key(): void
    {
        $owner = new JsonOwner();
        $owner->setDataFieldsSchema($this->stepSchema());

        $step = $owner->dataSet('step_1');
        $this->assertNotNull($step);
        $this->assertSame('step_1', $step->key);
        $this->assertSame('step', $step->type);
    }

    public function test_data_fields_flattens_leaves_across_containers(): void
    {
        $owner = new JsonOwner();
        $owner->setDataFieldsSchema($this->stepSchema());
        $owner->setFieldValue('step_1.performed_by', 'Rahul');

        $fields = $owner->dataFields();
        $this->assertCount(3, $fields); // performed_by, evidence_photos, verdict

        $performedBy = $fields->firstWhere('path', 'step_1.performed_by');
        $this->assertInstanceOf(FieldValue::class, $performedBy);
        $this->assertSame('performed_by', $performedBy->key);
        $this->assertSame('Rahul', $performedBy->value);
    }

    public function test_meta_at_container_level_is_preserved(): void
    {
        $owner = new JsonOwner();
        $owner->setDataFieldsSchema($this->stepSchema());
        $owner->setDataFieldsValues([
            'step_1' => [
                'values' => ['performed_by' => 'Rahul'],
                'meta'   => [
                    'completed_at'    => '2026-06-15T10:35:00+05:30',
                    'completed_by_id' => 42,
                ],
            ],
        ]);
        $owner->save();

        $loaded = JsonOwner::find($owner->id);
        $step1  = $loaded->dataSet('step_1');

        $this->assertSame('2026-06-15T10:35:00+05:30', $step1->meta['completed_at']);
        $this->assertSame(42, $step1->meta['completed_by_id']);
    }

    public function test_arbitrary_nesting_depth(): void
    {
        $owner = new JsonOwner();
        $owner->setDataFieldsSchema([
            [
                'type' => 'section',
                'key'  => 'outer',
                'items' => [
                    [
                        'type'  => 'group',
                        'key'   => 'inner',
                        'items' => [
                            ['key' => 'deep', 'type' => DataField::TEXT],
                        ],
                    ],
                ],
            ],
        ]);
        $owner->setFieldValue('outer.inner.deep', 'hello');

        $this->assertSame('hello', $owner->getFieldValue('outer.inner.deep'));
    }
}
