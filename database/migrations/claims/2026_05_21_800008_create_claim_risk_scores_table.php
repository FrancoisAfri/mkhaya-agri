<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('claim_risk_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('claim_id')->unique()->constrained('claims')->cascadeOnDelete();
            $table->decimal('risk_score', 5, 2)->default(0);
            $table->enum('risk_level', ['low', 'medium', 'high', 'critical'])->default('low');
            $table->json('flags')->nullable();
            $table->text('explanation')->nullable();
            $table->boolean('is_duplicate')->default(false);
            $table->boolean('is_inflated')->default(false);
            $table->boolean('is_splitting')->default(false);
            $table->boolean('missing_receipt')->default(false);
            $table->boolean('out_of_policy')->default(false);
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('claim_risk_scores');
    }
};
