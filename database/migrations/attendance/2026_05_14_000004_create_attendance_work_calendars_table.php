<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_work_calendars', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedSmallInteger('year');
            $table->boolean('monday')->default(true);
            $table->boolean('tuesday')->default(true);
            $table->boolean('wednesday')->default(true);
            $table->boolean('thursday')->default(true);
            $table->boolean('friday')->default(true);
            $table->boolean('saturday')->default(false);
            $table->boolean('sunday')->default(false);
            $table->boolean('include_public_holidays')->default(true);
            $table->text('description')->nullable();
            $table->boolean('is_default')->default(false);
            $table->tinyInteger('status')->default(1)->comment('1=active,0=inactive');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('attendance_calendar_shift', function (Blueprint $table) {
            $table->id();
            $table->foreignId('calendar_id')->constrained('attendance_work_calendars')->cascadeOnDelete();
            $table->foreignId('shift_id')->constrained('attendance_shifts')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['calendar_id', 'shift_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_calendar_shift');
        Schema::dropIfExists('attendance_work_calendars');
    }
};
