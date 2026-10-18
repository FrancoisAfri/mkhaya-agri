<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('claim_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('code', 40)->unique();
            $table->text('description')->nullable();
            $table->boolean('requires_receipt')->default(true);
            $table->decimal('max_amount', 12, 2)->nullable();
            $table->decimal('approval_threshold', 12, 2)->nullable();
            $table->unsignedSmallInteger('max_age_days')->default(30);
            $table->string('icon', 60)->nullable();
            $table->boolean('active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('claim_categories');
    }
};
