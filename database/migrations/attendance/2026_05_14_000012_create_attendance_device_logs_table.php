<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_device_logs', function (Blueprint $table) {
            $table->id();
            $table->string('device_id', 100)->comment('External device identifier');
            $table->string('device_type', 30)->comment('biometric,facial_recognition,qr_scanner,mobile');
            $table->string('device_name')->nullable();
            $table->foreignId('employee_id')->nullable()->constrained('hr_people')->nullOnDelete();
            $table->foreignId('clocking_id')->nullable()->constrained('attendance_clockings')->nullOnDelete();
            $table->json('raw_data')->nullable()->comment('Raw payload from device');
            $table->string('status', 20)->default('pending')->comment('pending,processed,failed');
            $table->text('error_message')->nullable();
            $table->dateTime('captured_at');
            $table->dateTime('processed_at')->nullable();
            $table->timestamps();

            $table->index(['device_id', 'captured_at']);
            $table->index(['status', 'captured_at']);
            $table->index('employee_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_device_logs');
    }
};
