<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offboarding_clearances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained('offboarding_plans')->cascadeOnDelete();
            $table->enum('department', ['hr', 'it', 'finance', 'facilities', 'manager']);
            $table->enum('status', ['pending', 'cleared'])->default('pending');
            $table->foreignId('signed_off_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('signed_off_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['plan_id', 'department']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offboarding_clearances');
    }
};
