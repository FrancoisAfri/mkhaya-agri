<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('pa_cycles', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->enum('cycle_type', ['annual', 'semi_annual', 'quarterly', 'monthly', 'probation', 'custom'])
                ->default('annual');
            $table->date('start_date');
            $table->date('end_date');
            $table->date('self_review_deadline')->nullable();
            $table->date('manager_review_deadline')->nullable();
            $table->enum('status', ['draft', 'active', 'closed', 'archived'])->default('draft');
            $table->foreignId('rating_scale_id')->nullable()->constrained('pa_rating_scales')->nullOnDelete();
            $table->foreignId('template_id')->nullable()->constrained('pa_templates')->nullOnDelete();
            $table->boolean('allow_self_review')->default(true);
            $table->boolean('allow_peer_review')->default(false);
            $table->boolean('require_manager_approval')->default(true);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pa_cycles');
    }
};
