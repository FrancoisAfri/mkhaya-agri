<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_misuse_detection_rules', function (Blueprint $table) {
            $table->increments('id');
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('flag_type', 40)->index();
            $table->boolean('is_active')->default(true)->index();
            $table->json('parameters');
            $table->unsignedInteger('applies_to_category')->nullable();
            $table->unsignedInteger('created_by');
            $table->timestamps();

            $table->foreign('applies_to_category')->references('id')->on('asset_categories')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_misuse_detection_rules');
    }
};
