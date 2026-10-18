<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_shifts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code', 30)->unique();
            $table->time('start_time');
            $table->time('end_time');
            $table->unsignedSmallInteger('break_duration_minutes')->default(60);
            $table->boolean('is_overnight')->default(false);
            $table->boolean('is_flexible')->default(false);
            $table->unsignedSmallInteger('flexible_window_minutes')->default(0)->comment('Grace window for flexible shifts');
            $table->unsignedSmallInteger('grace_period_minutes')->nullable()->comment('Overrides global setting when set');
            $table->text('description')->nullable();
            $table->tinyInteger('status')->default(1)->comment('1=active,0=inactive');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_shifts');
    }
};
