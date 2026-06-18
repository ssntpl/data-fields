<?php

namespace Ssntpl\DataFields\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;
use Ssntpl\DataFields\DataFieldsServiceProvider;
use Ssntpl\LaravelFiles\LaravelFilesServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->loadDataFieldsMigrations();
        $this->createTestOwnersTable();
    }

    protected function getPackageProviders($app)
    {
        return [
            LaravelFilesServiceProvider::class,
            DataFieldsServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app)
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);
    }

    protected function loadDataFieldsMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        $this->loadMigrationsFrom(__DIR__ . '/../vendor/ssntpl/laravel-files/database/migrations');
    }

    protected function createTestOwnersTable(): void
    {
        Schema::create('test_owners', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
        });

        Schema::create('test_json_owners', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            \Ssntpl\DataFields\Support\JsonModeMigration::addColumns($table);
        });

        Schema::create('test_mixed_owners', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            \Ssntpl\DataFields\Support\JsonModeMigration::addColumns($table);
        });

        Schema::create('test_log_entries', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->json('log_format')->nullable();
            $table->json('entries')->nullable();
        });

        Schema::create('test_child_entries', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->json('entries')->nullable();
        });
    }
}
