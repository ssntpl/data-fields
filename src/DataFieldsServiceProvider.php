<?php

namespace Ssntpl\DataFields;

use Illuminate\Support\ServiceProvider;

class DataFieldsServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/data-fields.php', 'data-fields');
    }

    /**
     * Bootstrap any package services.
     *
     * @return void
     */
    public function boot()
    {
        $this->publishes([
            __DIR__ . "/../config/data-fields.php" => config_path("data-fields.php"),
        ], 'data-fields-config');

        $this->publishesMigrations([
            __DIR__.'/../database/migrations/create_data_fields_table.php' => database_path('migrations/2025_01_01_000001_create_data_fields_table.php'),
            __DIR__.'/../database/migrations/create_data_sets_table.php' => database_path('migrations/2025_01_01_000002_create_data_sets_table.php'),
        ], 'data-fields-migrations');
    }
}
