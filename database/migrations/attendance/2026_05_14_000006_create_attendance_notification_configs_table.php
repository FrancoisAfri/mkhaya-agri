<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_notification_configs', function (Blueprint $table) {
            $table->id();
            $table->string('event', 60)->unique()->comment('late_arrival,absent,overtime,early_departure,missing_clockout,weekly_summary');
            $table->boolean('enabled')->default(true);
            $table->boolean('notify_employee')->default(true);
            $table->boolean('notify_manager')->default(true);
            $table->boolean('notify_hr')->default(false);
            $table->string('channel', 30)->default('email')->comment('email,sms,both');
            $table->unsignedSmallInteger('delay_minutes')->default(0)->comment('How long after the event to send');
            $table->text('custom_message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_notification_configs');
    }
};
