<?php
// config for Ssntpl/DataFields
return [
    'data_set_model'   => \Ssntpl\DataFields\Models\DataSet::class,
    'data_field_model' => \Ssntpl\DataFields\Models\DataField::class,

    'data_fields_timestamps' => false,
    'data_sets_timestamps'   => false,

    'json' => [
        // Default column names for HasDataFieldsJson when the consuming model
        // does not override $dataFieldsSchemaColumn / $dataFieldsValuesColumn.
        'default_schema_column' => 'data_fields_schema',
        'default_values_column' => 'data_fields_values',

        // Wrap-on-write envelope. Reading is always envelope-tolerant
        // (auto-detected by presence of `version` + `schema`/`values`).
        'envelope_version' => '1.0',
        'write_envelope'   => true,

        // When true, setDataFieldsValues() drops keys not present in the
        // schema. Default lenient — preserves unknown keys (useful during
        // schema-evolution migrations).
        'strict_writes' => false,
    ],
];
