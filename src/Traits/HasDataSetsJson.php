<?php

namespace Ssntpl\DataFields\Traits;

use Illuminate\Support\Collection;
use Ssntpl\DataFields\Support\DataSetValue;
use Ssntpl\DataFields\Support\FieldValue;
use Ssntpl\DataFields\Support\ValueCaster;

/**
 * Container-aware companion to HasDataFieldsJson. Adds dataSets() / dataSet()
 * for walking step / section / group containers in the schema.
 *
 * IMPORTANT: dataSets() collides with the row-mode HasDataSets trait's
 * morphMany. Don't compose both on the same model — pick one storage mode.
 *
 * @see HasDataFieldsJson
 */
trait HasDataSetsJson
{
    use HasDataFieldsJson;

    /**
     * Hydrate every top-level container node in the schema (and its nested
     * containers recursively) as a DataSetValue.
     *
     * @return Collection<int, DataSetValue>
     */
    public function dataSets(): Collection
    {
        $schema = $this->getDataFieldsSchema();
        $values = $this->getDataFieldsValues();

        return collect($this->hydrateContainers($schema, $values, ''));
    }

    public function dataSet(string $key): ?DataSetValue
    {
        return $this->dataSets()->first(fn (DataSetValue $s) => $s->path === $key);
    }

    /**
     * @return list<DataSetValue>
     */
    protected function hydrateContainers(array $nodes, array $valuesScope, string $pathPrefix): array
    {
        $out = [];
        foreach ($nodes as $node) {
            if (!is_array($node) || !$this->isContainerNode($node)) {
                continue;
            }
            $key  = $node['key'] ?? null;
            if ($key === null) {
                continue;
            }
            $path        = $pathPrefix === '' ? $key : "$pathPrefix.$key";
            $childScope  = $valuesScope[$key]['values'] ?? [];
            $meta        = $valuesScope[$key]['meta'] ?? [];

            $children = [];
            foreach ($node['items'] ?? [] as $item) {
                if (!is_array($item)) {
                    continue;
                }
                if ($this->isContainerNode($item)) {
                    array_push($children, ...$this->hydrateContainers([$item], $childScope, $path));
                } elseif (isset($item['key'], $item['type'])) {
                    array_push($children, ...$this->hydrateChildItems([$item], $childScope, $path));
                }
            }

            $out[] = new DataSetValue(
                key:         $key,
                type:        $node['type'],
                items:       collect($children),
                label:       $node['label'] ?? null,
                description: $node['description'] ?? null,
                requires:    $node['requires'] ?? [],
                assignedTo:  $node['assigned_to'] ?? null,
                meta:        is_array($meta) ? $meta : [],
                path:        $path,
            );
        }
        return $out;
    }

    /**
     * Hydrate just the LEAF children of a container (containers are added
     * separately by hydrateContainers, so a DataSetValue's items collection
     * holds both leaves and nested DataSetValues in schema order).
     *
     * @return list<FieldValue>
     */
    protected function hydrateChildItems(array $nodes, array $valuesScope, string $pathPrefix): array
    {
        $out = [];
        foreach ($nodes as $node) {
            if (!is_array($node) || $this->isContainerNode($node) || !isset($node['key'], $node['type'])) {
                continue;
            }
            $key  = $node['key'];
            $path = $pathPrefix === '' ? $key : "$pathPrefix.$key";
            $raw  = $valuesScope[$key] ?? ($node['default'] ?? null);

            $out[] = new FieldValue(
                key:         $key,
                type:        $node['type'],
                value:       ValueCaster::castNativeRead($node['type'], $raw),
                rawValue:    $raw,
                label:       $node['label'] ?? null,
                description: $node['description'] ?? null,
                validations: $node['validations'] ?? [],
                meta:        $node['meta'] ?? [],
                options:     $node['options'] ?? [],
                visible:     $this->resolveVisibility($node, $valuesScope),
                path:        $path,
            );
        }
        return $out;
    }
}
