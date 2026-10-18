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
        Schema::create('module_ribbon', function (Blueprint $table) {
            $table->id();
            $table->boolean('active')->default(1);
            $table->integer('sort_order')->nullable();
            $table->string('ribbon_name');
            $table->string('description')->nullable();
            $table->string('ribbon_path');
            $table->string('font_awesome')->nullable();
            $table->integer('access_level')->default(1);
            $table->foreignId('module_id')->constrained()->onDelete('cascade');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('module_ribbon');
    }
};
