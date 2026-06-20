<?php
// config for Ssntpl/DataFields
return [
    // Row-mode Eloquent model. Subclass DataRow if you want extra fillables
    // or behaviour and point this key at your subclass.
    'data_row_model' => \Ssntpl\DataFields\Models\DataRow::class,

    // Enable `created_at` / `updated_at` on the `data_fields` table. Off by
    // default because most consumers don't need per-row timestamps for
    // derived data.
    'data_fields_timestamps' => false,

    // When true, the service provider registers the package's migrations
    // directly so consumers don't need to `vendor:publish` them. Leave
    // false if you want to publish + customise the migration timestamps
    // yourself.
    'auto_load_migrations' => false,
];
