<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('data_fields', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('owner_id');
            $table->string('owner_type');

            $table->string('label')->nullable();
            $table->text('description')->nullable();
            $table->string('key')->nullable();
            $table->text('value')->nullable();
            $table->string('type');
            $table->text('validations')->nullable();
            $table->integer('sort_order')->nullable();
            $table->text('meta')->nullable();
            if (config('data-fields.data_fields_timestamps', false)) {
                $table->timestamps();
            }

            // Composite covers the two hot queries: `$owner->fields()->get()`
            // (prefix lookup on owner_id, owner_type) AND
            // `$owner->fields()->where('key', 'X')->first()` (full-prefix).
            $table->index(['owner_id', 'owner_type', 'key'], 'data_fields_owner_key_index');
        });
    }

    public function down()
    {
        Schema::dropIfExists('data_fields');
    }
};
