<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Closes the remaining scope gaps: toolbox talk delivery, contractor performance, scannable
 * inspection subjects, and AI analytics output.
 *
 * Each of these existed as half a feature. The AI could *draft* a toolbox talk but nothing recorded
 * that it was delivered; contractors were assessed on paperwork but never on how they actually
 * behaved on site; equipment could be scanned but the things inspections are performed *on* — a
 * warehouse, a fire panel, a vehicle bay — could not.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Toolbox talks: the record that it was actually delivered ──────────────────────────
        Schema::create('sheq_toolbox_talks', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id')->index();
            $table->unsignedBigInteger('site_id')->nullable()->index();

            $table->string('topic', 200);
            $table->text('content')->nullable();

            /** Where the content came from: AI draft, a document, or written by the presenter. */
            $table->string('source', 20)->default('MANUAL');
            $table->unsignedBigInteger('ai_suggestion_id')->nullable();

            $table->date('held_on')->index();
            $table->unsignedInteger('duration_minutes')->nullable();
            $table->unsignedBigInteger('presented_by')->nullable();

            /**
             * Who was there.
             *
             * The attendance is the point. A toolbox talk with no attendance record proves the
             * employer prepared something, not that anybody was told — and the question after an
             * incident is always whether *this person* was briefed on *this hazard*.
             */
            $table->unsignedInteger('attendee_count')->default(0);
            $table->jsonb('attendees')->nullable();

            /** Raised from the floor during the talk, which is where the useful ones come from. */
            $table->text('concerns_raised')->nullable();
            $table->unsignedBigInteger('action_id')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });

        // ── Contractor performance, observed rather than declared ────────────────────────────
        Schema::create('sheq_contractor_scores', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id')->index();
            $table->unsignedBigInteger('contractor_id')->index();

            /** The month being scored. Performance is a trend, not a snapshot. */
            $table->string('period', 7)->index();

            /**
             * Counted from what actually happened, never entered by hand.
             *
             * A contractor rating somebody types in is a rating of the relationship. These come
             * from the incident, inspection and access registers, so the number a procurement
             * manager sees is the one the site produced.
             */
            $table->unsignedInteger('incidents')->default(0);
            $table->unsignedInteger('high_potential_incidents')->default(0);
            $table->unsignedInteger('inspection_failures')->default(0);
            $table->unsignedInteger('access_refusals')->default(0);
            $table->unsignedInteger('overdue_actions')->default(0);
            $table->unsignedInteger('workers_on_site')->default(0);

            $table->decimal('score', 5, 2)->nullable();
            $table->string('rating', 20)->nullable();

            $table->timestamp('computed_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'contractor_id', 'period'], 'sheq_contractor_score_period');
        });

        // ── AI analytics output ──────────────────────────────────────────────────────────────
        Schema::create('sheq_ai_insights', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id')->index();

            /** TREND, HOTSPOT, RECURRENCE, FORECAST, ENVIRONMENTAL. */
            $table->string('insight_type', 30)->index();

            $table->string('title', 255);
            $table->text('finding');
            $table->text('recommendation')->nullable();

            /**
             * The rows this was computed from.
             *
             * An analytic assertion nobody can drill into is an opinion with a chart. A safety
             * manager challenged on "grinding injuries are up 40%" must be able to open the four
             * incidents it counted.
             */
            $table->jsonb('evidence')->nullable();
            $table->unsignedInteger('sample_size')->default(0);

            $table->string('severity', 20)->default('INFO');
            $table->unsignedBigInteger('site_id')->nullable()->index();

            $table->date('period_from')->nullable();
            $table->date('period_to')->nullable();

            /** Dismissed by a human, so the same finding stops resurfacing every night. */
            $table->timestamp('dismissed_at')->nullable();
            $table->unsignedBigInteger('dismissed_by')->nullable();
            $table->text('dismiss_note')->nullable();

            $table->timestamps();

            $table->index(['company_id', 'insight_type', 'dismissed_at']);
        });

        // ── Scannable inspection subjects ────────────────────────────────────────────────────
        Schema::table('sheq_inspection_schedules', function (Blueprint $table) {
            // Lets a person scan the thing in front of them and open its checklist, rather than
            // finding it in a list at a desk afterwards from memory.
            $table->string('qr_code', 80)->nullable()->unique()->after('subject_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            $session = "NULLIF(current_setting('app.company_id', true), '')";
            $policy = "{$session} IS NULL OR company_id = {$session}::bigint";

            foreach (['sheq_toolbox_talks', 'sheq_contractor_scores', 'sheq_ai_insights'] as $table) {
                DB::statement("ALTER TABLE {$table} ALTER COLUMN company_id SET DEFAULT {$session}::bigint");
                DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
                DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
                DB::statement("DROP POLICY IF EXISTS tenant_isolation ON {$table}");
                DB::statement("CREATE POLICY tenant_isolation ON {$table} USING ({$policy}) WITH CHECK ({$policy})");
            }
        }
    }

    public function down(): void
    {
        Schema::table('sheq_inspection_schedules', fn (Blueprint $t) => $t->dropColumn('qr_code'));
        Schema::dropIfExists('sheq_ai_insights');
        Schema::dropIfExists('sheq_contractor_scores');
        Schema::dropIfExists('sheq_toolbox_talks');
    }
};
