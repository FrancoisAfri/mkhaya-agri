<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_biometric_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->nullable()->constrained('hr_people')->nullOnDelete();
            $table->foreignId('clocking_id')->nullable()->constrained('attendance_clockings')->nullOnDelete();
            $table->string('biometric_type', 30)->comment('fingerprint,facial,iris,qr_code');
            $table->string('device_id', 100)->nullable();
            $table->decimal('confidence_score', 5, 2)->nullable()->comment('0-100 match confidence');
            $table->string('template_hash', 128)->nullable()->comment('Hashed biometric template identifier');
            $table->json('raw_response')->nullable()->comment('Device/API raw response');
            $table->boolean('is_matched')->default(false);
            $table->boolean('is_liveness_verified')->default(false)->comment('Anti-spoofing check for facial');
            $table->string('failure_reason')->nullable();
            $table->dateTime('captured_at');
            $table->timestamps();

            $table->index(['employee_id', 'captured_at']);
            $table->index(['biometric_type', 'is_matched', 'captured_at']);
            $table->index('device_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_biometric_logs');
    }
};
