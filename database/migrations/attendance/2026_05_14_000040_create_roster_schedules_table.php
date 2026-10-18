<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roster_schedules', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('department', 100)->nullable();
            $table->date('period_start');
            $table->date('period_end');
            $table->string('status', 20)->default('draft')->comment('draft,published,archived');
            $table->string('generated_by', 20)->default('manual')->comment('manual,ai');
            $table->text('ai_optimization_notes')->nullable();
            $table->unsignedTinyInteger('coverage_score')->nullable()->comment('0-100 AI score');
            $table->unsignedTinyInteger('balance_score')->nullable()->comment('0-100 fairness score');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['department', 'period_start']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roster_schedules');
    }
};
