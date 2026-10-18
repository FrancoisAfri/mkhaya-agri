<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {

            Schema::create('asset_components', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->index();
                $table->string('name')->nullable();
                $table->string('description')->nullable();
                $table->integer('size')->default(0);
                $table->integer('asset_id')->nullable(); // ideally FK
                $table->smallInteger('status')->default(1)->index();
                $table->timestamps();
            });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('asset_components');
    }
};
