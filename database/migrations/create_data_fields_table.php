<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateDataFieldsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('data_fields', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('owner_id');
            $table->string('owner_type');
            $table->index(['owner_id', 'owner_type'], 'data_fields_owner_id_owner_type_index');

            $table->unsignedBigInteger('parent_id')->nullable();
            $table->text('description')->nullable();
            $table->string('key')->nullable();
            $table->text('value')->nullable();
            $table->string('type');
            $table->text('validations')->nullable();
            $table->integer('sort_order')->nullable();
            $table->text('meta_data')->nullable();
            if (config('data-fields.data_fields_timestamps', false)) {
                $table->timestamps();
            }
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('data_fields');
    }
}
