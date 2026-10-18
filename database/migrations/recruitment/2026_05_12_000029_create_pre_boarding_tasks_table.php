<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pre_boarding_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pre_boarding_plan_id')->constrained('pre_boarding_plans')->cascadeOnDelete();
            $table->string('type', 40); // document_submission|form_completion|system_access|background_check|welcome_pack
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status', 20)->default('pending'); // pending|completed|skipped
            $table->date('due_date')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['pre_boarding_plan_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pre_boarding_tasks');
    }
};
