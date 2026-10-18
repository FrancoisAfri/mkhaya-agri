<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_models', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('make_id')->constrained('asset_makes')->cascadeOnDelete();
            $table->string('name', 120);
            $table->boolean('status')->default(true);
            $table->timestamps();

            $table->index('make_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_models');
    }
};
