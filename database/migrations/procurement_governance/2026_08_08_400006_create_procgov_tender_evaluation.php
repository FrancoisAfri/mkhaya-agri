<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tender Review & Evaluation (§1) — the complete digital evaluation lifecycle.
 *
 * The shape follows the sequence in the brief: a review is initiated (§1.1) against a committee
 * (§1.2) whose members must clear conflict of interest (§1.3); criteria are configured and then
 * frozen (§1.4); each evaluator scores privately in their own workspace (§1.5); the system does the
 * arithmetic (§1.6); the chairperson moderates (§1.7); a recommendation report is generated (§1.8).
 *
 * Three decisions in here are worth stating, because they are what make the trail defensible:
 *
 *  - **Submitted evaluations are immutable.** §1.5 says so, and §1.6 adds that original scores
 *    survive an authorised correction. So a correction does not UPDATE a score row: the evaluation
 *    revision number increments, the prior values are copied into
 *    `procgov_evaluation_score_history`, and the reopening itself is an approved act with a reason.
 *
 *  - **Criteria freeze on first submitted evaluation, not on a button.** A frozen-by-intent design
 *    lets someone change the weighting after the first evaluator has scored against the old one.
 *    `frozen_at` is set by the service the moment scoring begins, and any later change requires an
 *    approved `procgov_criteria_changes` row that records what moved and why.
 *
 *  - **Scores are stored raw and weighted.** Recomputing weighted scores on read means a later
 *    weight change silently rewrites history; storing the weighted value alongside the weight that
 *    produced it means a recommendation report always reproduces exactly.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── §1.2 Evaluation committees ───────────────────────────────────────────────────────────
        Schema::create('procgov_committees', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            $t->string('reference', 40);                  // EC-2026-0004
            $t->string('name');
            $t->text('terms_of_reference')->nullable();

            // Bid Specification / Bid Evaluation / Bid Adjudication are the common triad, but the
            // value is free text so a tenant under another framework names its own.
            $t->string('committee_type', 60)->nullable();

            $t->foreignId('chairperson_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('secretary_id')->nullable()->constrained('users')->nullOnDelete();

            $t->date('appointment_date')->nullable();
            $t->date('start_date')->nullable();
            $t->date('end_date')->nullable();

            // DRAFT | ACTIVE | SUSPENDED | DISSOLVED
            $t->string('status', 20)->default('DRAFT');

            $t->unsignedSmallInteger('quorum')->default(3);
            $t->boolean('requires_external_member')->default(false);

            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->softDeletes();

            $t->unique(['company_id', 'reference'], 'procgov_cttee_ref_unique');
            $t->index(['company_id', 'status'], 'procgov_cttee_status_idx');
        });

        Schema::create('procgov_committee_members', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $t->foreignId('committee_id')->constrained('procgov_committees')->cascadeOnDelete();

            // Null for an external/independent member, who has no platform account. §1.2 asks for
            // them explicitly, and a review is often invalid without one.
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->boolean('is_external')->default(false);
            $t->string('external_name', 190)->nullable();
            $t->string('external_email', 190)->nullable();
            $t->string('external_organisation', 190)->nullable();

            // CHAIRPERSON | MEMBER | SECRETARY | TECHNICAL_ADVISOR | OBSERVER
            $t->string('member_role', 30)->default('MEMBER');
            $t->boolean('is_voting')->default(true);

            $t->date('appointed_at')->nullable();
            $t->date('start_date')->nullable();
            $t->date('end_date')->nullable();

            // Standing declarations at committee level; a per-tender declaration still applies
            // (§1.3) because a conflict is specific to who is bidding.
            $t->timestamp('confidentiality_signed_at')->nullable();

            // ACTIVE | SUSPENDED | RESIGNED | ENDED
            $t->string('status', 20)->default('ACTIVE');

            $t->timestamps();
            $t->softDeletes();

            $t->index(['company_id', 'committee_id', 'status'], 'procgov_cttee_mem_idx');
            $t->index(['company_id', 'user_id'], 'procgov_cttee_mem_user_idx');
        });

        // ── §1.1 Tender review initiation ────────────────────────────────────────────────────────
        Schema::create('procgov_tender_reviews', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            $t->string('reference', 40);                  // the Tender Review ID, TR-2026-0011
            $t->string('tender_number', 80)->nullable();  // the tenant's own tender reference
            $t->string('title');
            $t->text('description')->nullable();

            $t->string('category', 120)->nullable();
            $t->unsignedBigInteger('company_unit_id')->nullable();
            $t->unsignedBigInteger('project_id')->nullable();
            $t->foreignId('officer_id')->nullable()->constrained('users')->nullOnDelete();

            $t->decimal('estimated_value', 18, 2)->nullable();
            $t->string('currency', 3)->default('ZAR');
            $t->unsignedBigInteger('budget_id')->nullable();

            $t->timestamp('tender_closing_at')->nullable();
            $t->date('evaluation_start_date')->nullable();
            $t->date('evaluation_deadline')->nullable();

            /*
             * §1.1 evaluation methodology. Determines how §1.6 combines the criteria groups:
             *   WEIGHTED         — every criterion weighted into one score
             *   TWO_ENVELOPE     — technical gate first, financial opened only for those who pass
             *   PRICE_ONLY       — lowest compliant price
             *   PRICE_PREFERENCE — price points + preference points (the 80/20 & 90/10 shape)
             */
            $t->string('methodology', 30)->default('WEIGHTED');
            $t->unsignedTinyInteger('price_weight')->nullable();       // e.g. 80 or 90
            $t->unsignedTinyInteger('preference_weight')->nullable();  // e.g. 20 or 10

            $t->unsignedBigInteger('committee_id')->nullable();
            $t->string('required_approval_level', 60)->nullable();     // §1.1, free text per tenant

            // Where this review sits in the wider chain (§11). All nullable — a review can be
            // initiated standalone on a tenant with nothing else installed.
            $t->unsignedBigInteger('record_id')->nullable();
            $t->unsignedBigInteger('plan_item_id')->nullable();
            $t->unsignedBigInteger('purchase_request_id')->nullable();
            $t->unsignedBigInteger('requisition_id')->nullable();
            $t->unsignedBigInteger('rfx_event_id')->nullable();
            $t->unsignedBigInteger('rule_set_id')->nullable();

            /*
             * DRAFT → COMMITTEE_SETUP → CRITERIA_SETUP → EVALUATION → MODERATION
             *       → RECOMMENDATION → APPROVAL → AWARDED | CANCELLED
             */
            $t->string('status', 24)->default('DRAFT');

            // §1.4: set when the first evaluation is submitted; changes after this need an
            // approved criteria change.
            $t->timestamp('criteria_frozen_at')->nullable();
            $t->timestamp('evaluation_opened_at')->nullable();
            $t->timestamp('evaluation_closed_at')->nullable();
            $t->timestamp('awarded_at')->nullable();

            $t->text('cancellation_reason')->nullable();

            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->softDeletes();

            $t->unique(['company_id', 'reference'], 'procgov_review_ref_unique');
            $t->index(['company_id', 'status'], 'procgov_review_status_idx');
            $t->index(['company_id', 'officer_id'], 'procgov_review_officer_idx');
            $t->index(['company_id', 'evaluation_deadline'], 'procgov_review_deadline_idx');
            $t->index(['company_id', 'record_id'], 'procgov_review_record_idx');
        });

        // ── §1.2/§1.3 Who evaluates this particular tender, and their declarations ───────────────
        Schema::create('procgov_review_evaluators', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $t->foreignId('review_id')->constrained('procgov_tender_reviews')->cascadeOnDelete();

            $t->unsignedBigInteger('committee_member_id')->nullable();
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->boolean('is_external')->default(false);
            $t->string('external_name', 190)->nullable();
            $t->string('external_email', 190)->nullable();

            $t->string('member_role', 30)->default('MEMBER');
            $t->boolean('is_voting')->default(true);

            /*
             * §1.3 conflict of interest. The five statuses are the brief's, verbatim:
             *   NOT_COMPLETED | DECLARED | CONFLICT_IDENTIFIED | RECUSED | APPROVED_TO_PARTICIPATE
             * Scoring is refused unless this is DECLARED or APPROVED_TO_PARTICIPATE — and while it
             * is CONFLICT_IDENTIFIED the evaluator is frozen out entirely until it is resolved.
             */
            $t->string('coi_status', 30)->default('NOT_COMPLETED');
            $t->timestamp('coi_declared_at')->nullable();
            $t->timestamp('confidentiality_accepted_at')->nullable();

            $t->timestamp('invited_at')->nullable();
            $t->timestamp('completed_at')->nullable();

            // ASSIGNED | IN_PROGRESS | SUBMITTED | RECUSED | REMOVED
            $t->string('status', 20)->default('ASSIGNED');

            $t->timestamps();

            $t->index(['company_id', 'review_id', 'status'], 'procgov_reveval_status_idx');
            $t->index(['company_id', 'user_id'], 'procgov_reveval_user_idx');
            $t->unique(['review_id', 'user_id'], 'procgov_reveval_user_unique');
        });

        // Declarations are append-only rows, not a status field that gets overwritten: "declared no
        // conflict, then declared a conflict once the bidder list was published" is the sequence an
        // investigation cares about.
        Schema::create('procgov_coi_declarations', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $t->foreignId('review_id')->constrained('procgov_tender_reviews')->cascadeOnDelete();
            $t->foreignId('evaluator_id')->constrained('procgov_review_evaluators')->cascadeOnDelete();

            $t->string('status', 30);                    // the same five values
            $t->boolean('has_conflict')->default(false);
            $t->text('nature_of_interest')->nullable();
            $t->unsignedBigInteger('related_bidder_id')->nullable();
            $t->string('related_supplier_name', 190)->nullable();

            $t->text('declaration_statement')->nullable();
            $t->timestamp('declared_at');
            $t->string('ip', 45)->nullable();

            // Resolution by the administrator notified in §1.3.
            $t->string('resolution', 30)->nullable();    // CLEARED | RECUSED | REMOVED | ESCALATED
            $t->text('resolution_note')->nullable();
            $t->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('resolved_at')->nullable();

            $t->timestamps();

            $t->index(['company_id', 'review_id', 'status'], 'procgov_coi_status_idx');
        });

        // ── §1.4 Evaluation criteria (weighted, nestable, thresholded) ───────────────────────────
        Schema::create('procgov_criteria', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $t->foreignId('review_id')->constrained('procgov_tender_reviews')->cascadeOnDelete();
            $t->foreignId('parent_id')->nullable()->constrained('procgov_criteria')->cascadeOnDelete();

            $t->string('code', 40)->nullable();
            $t->string('name');
            $t->text('description')->nullable();

            // TECHNICAL | FINANCIAL | EXPERIENCE | PREFERENCE | COMPLIANCE — groups the criterion
            // for the methodology (two-envelope opens FINANCIAL only after TECHNICAL passes).
            $t->string('criterion_group', 20)->default('TECHNICAL');

            // Weight is a percentage of the parent's weight for a sub-criterion, and of 100 for a
            // top-level one. Validated to sum to 100 per level before the criteria can freeze.
            $t->decimal('weight', 8, 4)->default(0);
            $t->decimal('max_score', 10, 2)->default(100);

            // POINTS | PERCENTAGE | PASS_FAIL | BAND | FORMULA
            $t->string('scoring_method', 20)->default('POINTS');
            $t->json('scoring_bands')->nullable();       // [{min,max,label,points}, …] for BAND
            $t->string('formula', 500)->nullable();      // e.g. lowest_price/bid_price*max_score

            $t->boolean('is_mandatory')->default(false); // failing it disqualifies the bid
            $t->boolean('is_pass_fail')->default(false);
            $t->decimal('minimum_threshold', 10, 2)->nullable();  // §1.6 threshold result

            // Which evaluators score this one. Null = all of them (§1.4 committee-specific scoring).
            $t->json('scored_by_roles')->nullable();

            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->timestamps();
            $t->softDeletes();

            $t->index(['company_id', 'review_id', 'parent_id'], 'procgov_crit_tree_idx');
            $t->index(['company_id', 'review_id', 'criterion_group'], 'procgov_crit_group_idx');
        });

        // §1.4: the "authorised change process" after freezing. One row per change, approved before
        // it applies, so a weighting that moved mid-evaluation is visible rather than inferred.
        Schema::create('procgov_criteria_changes', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $t->foreignId('review_id')->constrained('procgov_tender_reviews')->cascadeOnDelete();
            $t->unsignedBigInteger('criterion_id')->nullable();

            $t->string('change_type', 20);               // ADDED | AMENDED | REMOVED
            $t->json('before')->nullable();
            $t->json('after')->nullable();
            $t->text('reason');

            $t->string('status', 20)->default('PENDING'); // PENDING | APPROVED | REJECTED | APPLIED
            $t->unsignedBigInteger('approval_request_id')->nullable();
            $t->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('requested_at');
            $t->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('decided_at')->nullable();

            // Re-scoring obligation: an amended criterion invalidates submitted scores against it.
            $t->boolean('requires_rescoring')->default(true);
            $t->timestamps();

            $t->index(['company_id', 'review_id', 'status'], 'procgov_crit_change_idx');
        });

        // ── Participating bidders (§1.8 "participating suppliers") ───────────────────────────────
        Schema::create('procgov_bidders', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $t->foreignId('review_id')->constrained('procgov_tender_reviews')->cascadeOnDelete();

            // Links to Supplier Management when installed; the name is always stored so a bid from
            // an unregistered bidder is still evaluable.
            $t->unsignedBigInteger('supplier_id')->nullable();
            $t->string('supplier_name', 190);
            $t->string('registration_number', 60)->nullable();
            $t->string('tax_number', 60)->nullable();
            $t->string('contact_email', 190)->nullable();

            $t->string('bid_reference', 60)->nullable();
            $t->decimal('bid_amount', 18, 2)->nullable();
            $t->string('currency', 3)->default('ZAR');
            $t->timestamp('submitted_at')->nullable();
            $t->boolean('is_late')->default(false);

            $t->unsignedTinyInteger('bbbee_level')->nullable();
            $t->decimal('preference_points', 8, 2)->nullable();
            $t->boolean('is_local_content_compliant')->nullable();

            // Mandatory/administrative gate before scoring (§1.4 mandatory criteria).
            $t->json('mandatory_checks')->nullable();    // [{code,label,passed,note}]
            $t->boolean('mandatory_passed')->nullable();

            // §1.8 disqualifications and their reasons — a first-class field, not a note.
            $t->boolean('is_disqualified')->default(false);
            $t->string('disqualification_stage', 30)->nullable();  // ADMINISTRATIVE | TECHNICAL | FINANCIAL
            $t->text('disqualification_reason')->nullable();
            $t->foreignId('disqualified_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('disqualified_at')->nullable();

            // §1.6 computed results. Refreshed by the scoring service; never edited by hand.
            $t->decimal('technical_score', 10, 4)->nullable();
            $t->decimal('financial_score', 10, 4)->nullable();
            $t->decimal('preference_score', 10, 4)->nullable();
            $t->decimal('average_score', 10, 4)->nullable();
            $t->decimal('weighted_score', 10, 4)->nullable();
            $t->boolean('threshold_passed')->nullable();
            $t->unsignedSmallInteger('financial_rank')->nullable();
            $t->unsignedSmallInteger('overall_rank')->nullable();
            $t->timestamp('scores_computed_at')->nullable();

            // SUBMITTED | ADMIN_CHECK | EVALUATING | DISQUALIFIED | SHORTLISTED | RECOMMENDED | AWARDED | UNSUCCESSFUL
            $t->string('status', 24)->default('SUBMITTED');

            $t->timestamps();
            $t->softDeletes();

            $t->index(['company_id', 'review_id', 'status'], 'procgov_bidder_status_idx');
            $t->index(['company_id', 'review_id', 'overall_rank'], 'procgov_bidder_rank_idx');
            $t->index(['company_id', 'supplier_id'], 'procgov_bidder_supplier_idx');
        });

        // ── §1.5 One evaluator's evaluation of one bidder ────────────────────────────────────────
        Schema::create('procgov_evaluations', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $t->foreignId('review_id')->constrained('procgov_tender_reviews')->cascadeOnDelete();
            $t->foreignId('evaluator_id')->constrained('procgov_review_evaluators')->cascadeOnDelete();
            $t->foreignId('bidder_id')->constrained('procgov_bidders')->cascadeOnDelete();

            // NOT_STARTED | DRAFT | SUBMITTED | REOPENED | SUPERSEDED | WITHDRAWN
            $t->string('status', 20)->default('DRAFT');

            $t->decimal('total_raw_score', 12, 4)->nullable();
            $t->decimal('total_weighted_score', 12, 4)->nullable();
            $t->boolean('threshold_passed')->nullable();

            $t->text('overall_comment')->nullable();
            $t->boolean('has_concerns')->default(false);   // §1.5 "flag concerns"
            $t->text('concern_note')->nullable();

            $t->timestamp('submitted_at')->nullable();
            $t->string('submitted_ip', 45)->nullable();

            // §1.5/§1.6: submission makes it immutable; a correction bumps the revision and the
            // previous values are preserved in procgov_evaluation_score_history.
            $t->unsignedSmallInteger('revision')->default(1);
            $t->boolean('is_locked')->default(false);
            $t->timestamp('reopened_at')->nullable();
            $t->foreignId('reopened_by')->nullable()->constrained('users')->nullOnDelete();
            $t->text('reopen_reason')->nullable();
            $t->unsignedBigInteger('reopen_approval_request_id')->nullable();

            $t->timestamps();

            $t->unique(['evaluator_id', 'bidder_id'], 'procgov_eval_unique');
            $t->index(['company_id', 'review_id', 'status'], 'procgov_eval_status_idx');
        });

        Schema::create('procgov_evaluation_scores', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $t->foreignId('evaluation_id')->constrained('procgov_evaluations')->cascadeOnDelete();
            $t->foreignId('criterion_id')->constrained('procgov_criteria')->cascadeOnDelete();

            $t->decimal('score', 10, 2)->nullable();
            $t->decimal('max_score', 10, 2)->default(100);
            // The weight in force when this score was given, kept alongside the value it produced
            // so a later weight change cannot silently rewrite a past evaluation.
            $t->decimal('weight_applied', 8, 4)->default(0);
            $t->decimal('weighted_score', 12, 4)->nullable();

            $t->boolean('passed')->nullable();          // pass/fail and mandatory criteria
            $t->text('comment')->nullable();
            $t->boolean('flagged')->default(false);
            $t->unsignedBigInteger('evidence_document_id')->nullable();

            $t->timestamps();

            $t->unique(['evaluation_id', 'criterion_id'], 'procgov_score_unique');
            $t->index(['company_id', 'criterion_id'], 'procgov_score_crit_idx');
        });

        // §1.6: "the system must retain the original scores even if a correction is authorised".
        Schema::create('procgov_evaluation_score_history', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $t->foreignId('evaluation_id')->constrained('procgov_evaluations')->cascadeOnDelete();
            $t->unsignedBigInteger('criterion_id')->nullable();

            $t->unsignedSmallInteger('revision');
            $t->decimal('score', 10, 2)->nullable();
            $t->decimal('max_score', 10, 2)->nullable();
            $t->decimal('weight_applied', 8, 4)->nullable();
            $t->decimal('weighted_score', 12, 4)->nullable();
            $t->boolean('passed')->nullable();
            $t->text('comment')->nullable();

            $t->string('reason', 500)->nullable();      // why the correction was authorised
            $t->foreignId('archived_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('archived_at');
            $t->timestamps();

            $t->index(['company_id', 'evaluation_id', 'revision'], 'procgov_score_hist_idx');
        });

        // ── §1.7 Moderation ──────────────────────────────────────────────────────────────────────
        Schema::create('procgov_moderations', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $t->foreignId('review_id')->constrained('procgov_tender_reviews')->cascadeOnDelete();

            $t->foreignId('moderator_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('status', 20)->default('OPEN');   // OPEN | AWAITING_RESPONSE | RESOLVED | CLOSED
            $t->timestamp('opened_at');
            $t->timestamp('closed_at')->nullable();
            $t->text('summary')->nullable();

            // Set when the moderator approves the final scores — the gate into §1.8.
            $t->timestamp('scores_approved_at')->nullable();
            $t->foreignId('scores_approved_by')->nullable()->constrained('users')->nullOnDelete();

            $t->timestamps();

            $t->index(['company_id', 'review_id', 'status'], 'procgov_mod_status_idx');
        });

        Schema::create('procgov_moderation_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $t->foreignId('moderation_id')->constrained('procgov_moderations')->cascadeOnDelete();

            $t->unsignedBigInteger('bidder_id')->nullable();
            $t->unsignedBigInteger('criterion_id')->nullable();
            $t->unsignedBigInteger('evaluation_id')->nullable();
            $t->foreignId('target_evaluator_id')->nullable()->constrained('procgov_review_evaluators')->nullOnDelete();

            // VARIANCE | CONCERN | THRESHOLD | MISSING_SCORE | MANUAL — why it surfaced.
            $t->string('item_type', 20)->default('VARIANCE');
            $t->decimal('variance', 10, 4)->nullable();
            $t->decimal('lowest_score', 10, 2)->nullable();
            $t->decimal('highest_score', 10, 2)->nullable();

            // CLARIFY | RE_EVALUATE | ACCEPT | ADJUST | NOTED
            $t->string('action', 20)->nullable();
            $t->text('moderator_comment')->nullable();
            $t->text('evaluator_response')->nullable();
            $t->timestamp('responded_at')->nullable();

            $t->string('status', 20)->default('OPEN');   // OPEN | AWAITING_RESPONSE | RESOLVED
            $t->timestamp('resolved_at')->nullable();
            $t->timestamps();

            $t->index(['company_id', 'moderation_id', 'status'], 'procgov_mod_item_idx');
        });

        // ── §1.8 Recommendation report ───────────────────────────────────────────────────────────
        Schema::create('procgov_recommendations', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $t->foreignId('review_id')->constrained('procgov_tender_reviews')->cascadeOnDelete();

            $t->string('reference', 40);
            $t->unsignedSmallInteger('version')->default(1);

            $t->unsignedBigInteger('recommended_bidder_id')->nullable();
            $t->string('recommended_supplier_name', 190)->nullable();
            $t->decimal('recommended_amount', 18, 2)->nullable();
            $t->string('currency', 3)->default('ZAR');

            $t->text('committee_comments')->nullable();
            $t->text('recommendation')->nullable();
            $t->text('award_conditions')->nullable();

            /*
             * The report is a frozen snapshot, not a live query. §1.8 lists thirteen things it must
             * contain — scores, members, disqualifications, approval history — and every one of
             * them can legitimately change afterwards (a member leaves, a criterion is amended for
             * the next tender). A report that silently re-renders differently a year later is not a
             * report. The PDF is generated from this JSON.
             */
            $t->json('snapshot')->nullable();
            $t->char('snapshot_hash', 64)->nullable();

            // DRAFT | SUBMITTED | APPROVED | REJECTED | SUPERSEDED
            $t->string('status', 20)->default('DRAFT');
            $t->unsignedBigInteger('approval_request_id')->nullable();
            $t->unsignedBigInteger('report_document_id')->nullable();

            $t->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('generated_at')->nullable();
            $t->timestamp('submitted_at')->nullable();
            $t->timestamp('decided_at')->nullable();
            $t->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $t->text('decision_comment')->nullable();

            $t->timestamps();
            $t->softDeletes();

            $t->unique(['company_id', 'reference', 'version'], 'procgov_recom_ref_unique');
            $t->index(['company_id', 'review_id', 'status'], 'procgov_recom_status_idx');
        });

        $this->applyTenantRls([
            'procgov_committees',
            'procgov_committee_members',
            'procgov_tender_reviews',
            'procgov_review_evaluators',
            'procgov_coi_declarations',
            'procgov_criteria',
            'procgov_criteria_changes',
            'procgov_bidders',
            'procgov_evaluations',
            'procgov_evaluation_scores',
            'procgov_evaluation_score_history',
            'procgov_moderations',
            'procgov_moderation_items',
            'procgov_recommendations',
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('procgov_recommendations');
        Schema::dropIfExists('procgov_moderation_items');
        Schema::dropIfExists('procgov_moderations');
        Schema::dropIfExists('procgov_evaluation_score_history');
        Schema::dropIfExists('procgov_evaluation_scores');
        Schema::dropIfExists('procgov_evaluations');
        Schema::dropIfExists('procgov_bidders');
        Schema::dropIfExists('procgov_criteria_changes');
        Schema::dropIfExists('procgov_criteria');
        Schema::dropIfExists('procgov_coi_declarations');
        Schema::dropIfExists('procgov_review_evaluators');
        Schema::dropIfExists('procgov_tender_reviews');
        Schema::dropIfExists('procgov_committee_members');
        Schema::dropIfExists('procgov_committees');
    }

    private function applyTenantRls(array $tables): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $session = "NULLIF(current_setting('app.company_id', true), '')";
        $policy = "{$session} IS NULL OR company_id = {$session}::bigint";

        foreach ($tables as $table) {
            DB::statement("ALTER TABLE {$table} ALTER COLUMN company_id SET DEFAULT {$session}::bigint");
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            DB::statement("DROP POLICY IF EXISTS tenant_isolation ON {$table}");
            DB::statement("CREATE POLICY tenant_isolation ON {$table} USING ({$policy}) WITH CHECK ({$policy})");
        }
    }
};
