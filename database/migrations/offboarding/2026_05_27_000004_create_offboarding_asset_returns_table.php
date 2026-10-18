<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offboarding_asset_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained('offboarding_plans')->cascadeOnDelete();
            $table->unsignedInteger('asset_id')->constrained('assets');
            $table->enum('status', ['pending', 'returned'])->default('pending');
            $table->date('returned_at')->nullable();
            $table->enum('condition', ['good', 'fair', 'poor', 'damaged'])->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['plan_id', 'asset_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offboarding_asset_returns');
    }
};
