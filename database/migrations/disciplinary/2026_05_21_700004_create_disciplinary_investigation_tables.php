<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── Formal investigations linked to a disciplinary case ───────────────
        Schema::create('disciplinary_investigations', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->string('investigation_number', 30)->unique();
            $table->unsignedBigInteger('case_id');
            $table->unsignedBigInteger('investigator_id')->nullable();
            // pending | in_progress | completed | suspended
            $table->string('status', 30)->default('pending');
            $table->text('objective')->nullable();
            $table->text('scope')->nullable();
            $table->json('checklist')->nullable();                      // [{item, done, done_at}]
            $table->date('start_date')->nullable();
            $table->date('due_date')->nullable();
            $table->date('completed_date')->nullable();
            $table->text('findings')->nullable();
            $table->text('recommended_action')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index('case_id');
            $table->index('investigator_id');
            $table->index('status');

            $table->foreign('case_id')->references('id')->on('disciplinary_cases')->cascadeOnDelete();
        });

        // ── Investigation notes (witness interviews, evidence reviews, findings) ─
        Schema::create('disciplinary_investigation_notes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('investigation_id');
            $table->unsignedBigInteger('author_id');
            // general | witness_interview | evidence_review | finding | recommendation
            $table->string('note_type', 40)->default('general');
            $table->string('title', 200)->nullable();
            $table->text('content');
            $table->boolean('is_confidential')->default(false);
            $table->timestamps();

            $table->index('investigation_id');
            $table->index('note_type');

            $table->foreign('investigation_id')
                ->references('id')->on('disciplinary_investigations')->cascadeOnDelete();
        });

        // ── AI-generated or manual investigation reports ───────────────────────
        Schema::create('disciplinary_investigation_reports', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('investigation_id');
            $table->string('report_type', 40)->default('final');       // interim | final | ai_generated
            $table->string('title', 200);
            $table->text('executive_summary')->nullable();
            $table->text('background')->nullable();
            $table->text('methodology')->nullable();
            $table->text('findings');
            $table->text('conclusion')->nullable();
            $table->text('recommendations')->nullable();
            $table->json('ai_metadata')->nullable();
            $table->boolean('is_ai_generated')->default(false);
            $table->unsignedBigInteger('generated_by');
            $table->timestamps();

            $table->index('investigation_id');
            $table->index('report_type');

            $table->foreign('investigation_id')
                ->references('id')->on('disciplinary_investigations')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('disciplinary_investigation_reports');
        Schema::dropIfExists('disciplinary_investigation_notes');
        Schema::dropIfExists('disciplinary_investigations');
    }
};
