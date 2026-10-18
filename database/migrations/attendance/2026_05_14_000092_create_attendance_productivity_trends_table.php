<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('attendance_productivity_trends', function (Blueprint $table) {
            $table->id();
            $table->string('scope');          // 'company' | 'department' | 'employee'
            $table->string('scope_key')->nullable();  // department name or employee_id
            $table->string('metric');         // attendance_rate | punctuality | overtime | productivity_score
            $table->date('period_date');      // week/month start
            $table->string('period_type');    // weekly | monthly
            $table->decimal('value', 8, 2);
            $table->decimal('prev_value', 8, 2)->nullable();
            $table->decimal('change_pct', 6, 2)->nullable();
            $table->string('trend_direction')->nullable(); // up | down | stable
            $table->timestamps();

            $table->index(['scope', 'scope_key', 'metric', 'period_date']);
        });
    }

    public function down(): void { Schema::dropIfExists('attendance_productivity_trends'); }
};
