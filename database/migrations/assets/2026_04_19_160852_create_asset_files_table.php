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
        Schema::create('asset_files', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->index();
            $table->string('name')->nullable()->index();
            $table->string('description')->nullable()->index();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('asset_id'); // NOT NULL
            $table->string('document')->nullable(); // file path
            $table->date('date_added')->nullable();
            $table->smallInteger('status')->default(1)->index();
            $table->timestamps();

            // Recommended foreign keys
            // $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            // $table->foreign('asset_id')->references('id')->on('assets')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('asset_files');
    }
};
