<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateDataSetsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('data_sets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('owner_id');
            $table->string('owner_type');
            $table->index(['owner_id', 'owner_type'], 'data_sets_owner_id_owner_type_index');

            $table->string('name')->nullable();
            $table->string('type');
            $table->integer('sort_order')->nullable();
            $table->text('meta_data')->nullable();
            if (config('data-fields.data_sets_timestamps', false)) {
                $table->timestamps();
            }
            $table->index('type');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('data_sets');
    }
}
