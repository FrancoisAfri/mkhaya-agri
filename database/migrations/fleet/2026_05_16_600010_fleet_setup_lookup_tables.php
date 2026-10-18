<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fleet_filling_stations', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('location', 200)->nullable();
            $table->string('fuel_types', 100)->nullable();
            $table->string('contact_number', 30)->nullable();
            $table->boolean('active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('fleet_card_types', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            $table->string('description', 200)->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('fleet_licence_types', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('description', 200)->nullable();
            $table->integer('validity_months')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fleet_licence_types');
        Schema::dropIfExists('fleet_card_types');
        Schema::dropIfExists('fleet_filling_stations');
    }
};
