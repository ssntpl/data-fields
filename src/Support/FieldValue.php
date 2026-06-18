<?php

namespace Ssntpl\DataFields\Support;

use Ssntpl\DataFields\Contracts\FieldLike;

/**
 * Non-persistent value object representing a single hydrated field in JSON
 * mode. Returned by HasDataFieldsJson::dataFields() / dataField().
 *
 * Both this class and Ssntpl\DataFields\Models\DataField implement FieldLike,
 * so consumer code can read `$field->key`, `$field->value`, etc. without
 * knowing which storage mode produced the value.
 */
final class FieldValue implements FieldLike
{
    /**
     * @param string  $key          schema leaf key (e.g. "performed_by")
     * @param string  $type         one of DataField::* constants
     * @param mixed   $value        cast value (bool/float/Carbon/File/...)
     * @param mixed   $rawValue     uncast value as stored in the JSON document
     * @param ?string $label        from schema; null if omitted
     * @param ?string $description  from schema; null if omitted
     * @param array   $validations  Laravel rule array from schema
     * @param array   $meta         schema-level `meta` bag (ignored by package)
     * @param array   $options      options for select_single / select_multiple
     * @param bool    $visible      resolved via visible_if; true when no rule
     * @param string  $path         dotted path including container ancestors
     */
    public function __construct(
        public readonly string $key,
        public readonly string $type,
        public readonly mixed $value,
        public readonly mixed $rawValue = null,
        public readonly ?string $label = null,
        public readonly ?string $description = null,
        public readonly array $validations = [],
        public readonly array $meta = [],
        public readonly array $options = [],
        public readonly bool $visible = true,
        public readonly string $path = '',
    ) {}

    public function isVisible(): bool
    {
        return $this->visible;
    }
}
