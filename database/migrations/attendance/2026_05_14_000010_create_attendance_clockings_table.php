<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_clockings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('hr_people')->cascadeOnDelete();
            $table->foreignId('attendance_record_id')->nullable()->constrained('attendance_records')->nullOnDelete();
            $table->string('type', 20)->comment('clock_in,clock_out,break_start,break_end');
            $table->string('method', 30)->default('manual')->comment('manual,biometric,facial,gps,qr_code,mobile');
            $table->string('work_type', 20)->default('office')->comment('office,remote,hybrid');
            $table->dateTime('clocked_at');
            $table->string('location')->nullable()->comment('Office name or WFH address');
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('device_id')->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_verified')->default(false);
            $table->boolean('is_missed')->default(false)->comment('System-detected missed clocking');
            $table->boolean('is_corrected')->default(false)->comment('Manually corrected by HR');
            $table->foreignId('corrected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('corrected_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['employee_id', 'clocked_at']);
            $table->index(['attendance_record_id']);
            $table->index(['method', 'clocked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_clockings');
    }
};
