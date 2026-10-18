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
        Schema::create('assets_files', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('title')->index()->nullable();
            $table->json('image')->nullable();
            $table->string('video')->nullable();
            $table->json('document')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('assets_files');
    }
};
