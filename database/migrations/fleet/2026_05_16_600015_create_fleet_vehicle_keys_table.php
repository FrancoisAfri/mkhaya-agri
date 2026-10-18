<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fleet_vehicle_keys', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('vehicle_id');
            $table->string('key_number', 40)->nullable();
            $table->string('key_type', 40)->default('spare');   // master, spare, valet
            $table->string('key_status', 40)->default('in_safe'); // in_safe, with_employee, lost, damaged
            $table->text('description')->nullable();
            $table->string('issued_to_type', 20)->default('safe'); // employee | safe
            $table->unsignedBigInteger('issued_to_employee_id')->nullable(); // hr_people.id
            $table->unsignedBigInteger('safe_id')->nullable();     // fleet_key_safes.id
            $table->date('date_issued')->nullable();
            $table->unsignedBigInteger('issued_by_id')->nullable(); // users.id
            $table->string('reason_for_loss')->nullable();
            $table->unsignedBigInteger('captured_by')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->foreign('vehicle_id')->references('id')->on('fleet_vehicles')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fleet_vehicle_keys');
    }
};
