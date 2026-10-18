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
        Schema::create('asset_license_dates', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->index();
            $table->date('purchase_date')->nullable();
            $table->date('expiration_date')->nullable();
            $table->date('renewal_date')->nullable();
            $table->unsignedBigInteger('license_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->smallInteger('status')->default(1);
            $table->timestamps();

            // Recommended relationships
            // $table->foreign('license_id')->references('id')->on('asset_license')->cascadeOnDelete();
            // $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('asset_license_dates');
    }
};
