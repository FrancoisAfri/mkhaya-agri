<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 9-box talent grid placements. Performance (x) × Potential (y), each low/med/high.
 * Placements are auto-derived from snapshot + talent-profile scores, but can be
 * manually overridden in a calibration session (drag-drop), so we persist them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pa_nine_box_placements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cycle_id')->constrained('pa_cycles')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('users')->cascadeOnDelete();
            $table->string('performance_band'); // low | medium | high
            $table->string('potential_band');   // low | medium | high
            $table->unsignedTinyInteger('box');  // 1..9
            $table->decimal('performance_score', 6, 2)->nullable();
            $table->decimal('potential_score', 6, 2)->nullable();
            $table->boolean('is_manual')->default(false);
            $table->text('notes')->nullable();
            $table->foreignId('placed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['cycle_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pa_nine_box_placements');
    }
};
