<?php

namespace Ssntpl\DataFields\Support;

use Illuminate\Support\Collection;

/**
 * Non-persistent value object representing a container node (step / section /
 * group) in a JSON-mode schema. Returned by HasDataSetsJson::dataSets() /
 * dataSet().
 *
 * `$items` is a Collection whose elements are either FieldValue (leaves) or
 * nested DataSetValue (deeper containers). Nesting is arbitrary depth.
 *
 * The package never inspects `$meta`, `$requires`, or `$assignedTo` — they are
 * surfaced as readable properties for higher-layer flow code to consume.
 */
final class DataSetValue
{
    /**
     * @param string                                         $key          container key (e.g. "step_1")
     * @param string                                         $type         step | section | group
     * @param Collection<int, \Ssntpl\DataFields\Contracts\FieldLike|DataSetValue> $items
     * @param ?string                                        $label
     * @param ?string                                        $description
     * @param array<int, string>                             $requires     keys of containers required before this one
     * @param ?array{type: string, key: string}              $assignedTo   single-target assignment hint
     * @param array                                          $meta         opaque per-container bag from values JSON
     * @param string                                         $path         dotted path (matches key for top-level containers)
     */
    public function __construct(
        public readonly string $key,
        public readonly string $type,
        public readonly Collection $items,
        public readonly ?string $label = null,
        public readonly ?string $description = null,
        public readonly array $requires = [],
        public readonly ?array $assignedTo = null,
        public readonly array $meta = [],
        public readonly string $path = '',
    ) {}
}
