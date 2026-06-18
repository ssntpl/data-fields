<?php

namespace Ssntpl\DataFields\Support;

use Illuminate\Database\Schema\Blueprint;

/**
 * Migration helper for adding JSON-mode columns to a consuming application's
 * table. Saves consumers from repeating column names and `nullable()` calls.
 *
 *   Schema::create('log_entries', function (Blueprint $table) {
 *       $table->id();
 *       \Ssntpl\DataFields\Support\JsonModeMigration::addColumns($table);
 *       $table->timestamps();
 *   });
 *
 * Pass explicit names to override the configured defaults — useful when a
 * model already has a JSON column you want to reuse:
 *
 *   JsonModeMigration::addColumns($table, schemaColumn: 'log_format', valuesColumn: 'entries');
 */
class JsonModeMigration
{
    public static function addColumns(
        Blueprint $table,
        ?string $schemaColumn = null,
        ?string $valuesColumn = null,
    ): void {
        $schemaColumn ??= config('data-fields.json.default_schema_column', 'data_fields_schema');
        $valuesColumn ??= config('data-fields.json.default_values_column', 'data_fields_values');

        if ($schemaColumn !== null) {
            $table->json($schemaColumn)->nullable();
        }
        if ($valuesColumn !== null) {
            $table->json($valuesColumn)->nullable();
        }
    }
}
