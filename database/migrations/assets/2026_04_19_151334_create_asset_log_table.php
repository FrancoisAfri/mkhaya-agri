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
        Schema::create('asset_log', function (Blueprint $table) {
            $table->id();

            $table->uuid('uuid')->index();

            $table->unsignedBigInteger('user_id')->nullable();

            $table->string('action_type');

            $table->unsignedBigInteger('asset_id');

            $table->unsignedBigInteger('checkedout_to')->nullable();
            $table->unsignedBigInteger('store_id')->nullable();

            $table->smallInteger('status')->default(1)->index();

            $table->timestamps();

            // Recommended foreign keys
            // $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            // $table->foreign('asset_id')->references('id')->on('assets')->cascadeOnDelete();
            // $table->foreign('checkedout_to')->references('id')->on('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('asset_log');
    }
};
