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
    }
}
