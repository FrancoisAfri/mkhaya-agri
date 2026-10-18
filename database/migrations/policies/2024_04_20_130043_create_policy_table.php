<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('policy', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->text('description')->nullable();
            $table->string('document')->nullable();
            $table->enum('status', ['draft', 'active', 'under_review', 'archived'])->default('draft');
            $table->bigInteger('date')->nullable()->index();
            $table->unsignedBigInteger('category_id')->nullable()->index();
            $table->bigInteger('refresh_month')->nullable()->index();
            $table->string('slug')->nullable()->unique();
            $table->unsignedBigInteger('current_version_id')->nullable();
            $table->unsignedBigInteger('owner_id')->nullable()->index();
            $table->unsignedBigInteger('department_id')->nullable()->index();
            $table->date('effective_date')->nullable()->index();
            $table->date('review_date')->nullable()->index();
            $table->boolean('requires_attestation')->default(false);
            $table->json('tags')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('policy');
    }
};
