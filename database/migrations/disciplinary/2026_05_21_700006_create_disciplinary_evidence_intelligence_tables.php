<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── AI analysis of individual evidence items or case bundles ──────────
        Schema::create('disciplinary_evidence_analysis', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('evidence_id')->nullable();
            $table->unsignedBigInteger('case_id')->nullable();
            $table->unsignedBigInteger('incident_id')->nullable();
            // text_extraction | statement_analysis | witness_consistency | conflict_detection
            $table->string('analysis_type', 60);
            $table->longText('extracted_text')->nullable();
            $table->text('analysis_result');
            $table->json('key_findings')->nullable();                   // [{finding, confidence, severity}]
            $table->string('consistency_score', 10)->nullable();        // e.g. "78%" for witness checks
            $table->json('conflicts_detected')->nullable();             // [{description, source_a, source_b}]
            $table->string('ai_provider', 40)->nullable();
            $table->string('ai_model', 80)->nullable();
            $table->json('ai_metadata')->nullable();
            $table->unsignedBigInteger('generated_by')->nullable();
            $table->timestamps();

            $table->index('case_id');
            $table->index('incident_id');
            $table->index('evidence_id');
            $table->index('analysis_type');
        });

        // ── Chronological timeline of events for a case or incident ──────────
        Schema::create('disciplinary_case_timelines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('case_id')->nullable();
            $table->unsignedBigInteger('incident_id')->nullable();
            // incident | report | case_opened | notice_issued | investigator_assigned |
            // hearing_scheduled | hearing_held | outcome | closed | evidence_added | witness_added
            $table->string('event_type', 60);
            $table->string('event_label', 200);
            $table->text('event_detail')->nullable();
            $table->dateTime('event_date');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_name', 150)->nullable();             // stored for history
            $table->boolean('is_ai_inferred')->default(false);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('case_id');
            $table->index('incident_id');
            $table->index('event_date');
        });

        // ── Full AI evidence & case intelligence reports ───────────────────────
        Schema::create('disciplinary_ai_evidence_reports', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('case_id')->nullable();
            $table->unsignedBigInteger('incident_id')->nullable();
            // evidence_summary | hearing_pack | misconduct_analysis | full_case_intelligence
            $table->string('report_type', 60);
            $table->string('title', 200);
            $table->text('executive_summary');
            $table->json('sections')->nullable();                       // [{heading, content}]
            $table->text('recommendations')->nullable();
            $table->string('ai_provider', 40)->nullable();
            $table->string('ai_model', 80)->nullable();
            $table->json('ai_metadata')->nullable();
            $table->unsignedBigInteger('generated_by');
            $table->timestamps();

            $table->index('case_id');
            $table->index('incident_id');
            $table->index('report_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('disciplinary_ai_evidence_reports');
        Schema::dropIfExists('disciplinary_case_timelines');
        Schema::dropIfExists('disciplinary_evidence_analysis');
    }
};
