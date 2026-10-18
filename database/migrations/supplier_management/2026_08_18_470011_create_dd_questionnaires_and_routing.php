<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The tail of the plan: questionnaires, alert routing, and the health score's inputs.
 *
 * Questionnaires are how the checks that no registry can answer get asked. Nobody sells an API for
 * "do you have a whistleblower policy" or "who would your subcontractors be" — those come from the
 * supplier, in writing, and the answers have to be as durable and as attributable as an automated
 * check or they are worth nothing in an audit.
 *
 *  dd_questionnaires           a pack sent to a supplier: which template, who asked, what came back
 *  dd_questionnaire_answers    one answer per question, kept separately so a single "yes" can be
 *                              re-read years later without unpacking a blob
 *
 * Routing goes on the settings row rather than in its own table: it is four lists of role slots
 * keyed by severity, it changes rarely, and a table would make the common read a join.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dd_questionnaires', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->unsignedBigInteger('supplier_id')->index();
            $t->unsignedBigInteger('case_id')->nullable()->index();

            $t->string('pack', 40);                        // esg | cyber | governance | bee | sector
            $t->string('title', 190);
            $t->string('status', 20)->default('sent');     // draft | sent | in_progress | submitted | reviewed
            $t->unsignedTinyInteger('completeness')->default(0);   // 0-100

            // Scored 0-100 where higher is worse, to match every other component in the model.
            $t->decimal('risk_score', 6, 2)->nullable();
            $t->json('flags')->nullable();                 // answers that are themselves findings

            $t->unsignedBigInteger('sent_by')->nullable();
            $t->timestamp('sent_at')->nullable();
            $t->timestamp('submitted_at')->nullable();
            $t->unsignedBigInteger('reviewed_by')->nullable();
            $t->timestamp('reviewed_at')->nullable();
            $t->text('review_note')->nullable();
            $t->date('due_on')->nullable();

            $t->timestamps();
            $t->softDeletes();

            $t->index(['company_id', 'supplier_id', 'pack'], 'dd_questionnaires_supplier_pack_idx');
        });

        Schema::create('dd_questionnaire_answers', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->unsignedBigInteger('questionnaire_id')->index();

            $t->string('code', 60);
            $t->string('question', 500);
            $t->string('type', 20)->default('boolean');    // boolean | choice | text | number | date
            $t->text('answer')->nullable();
            $t->text('note')->nullable();
            $t->unsignedBigInteger('document_id')->nullable();

            // What this answer means for risk — set from the template rather than judged per answer,
            // so two reviewers reading the same "no" reach the same number.
            $t->boolean('is_flag')->default(false);
            $t->string('flag_severity', 20)->nullable();
            $t->timestamps();

            $t->unique(['questionnaire_id', 'code'], 'dd_questionnaire_answers_unique');
        });

        Schema::table('supplier_settings', function (Blueprint $t) {
            // Which role slots hear about what. Four lists keyed by severity.
            $t->json('alert_routing')->nullable()->after('case_sla_days');
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (['dd_questionnaires', 'dd_questionnaire_answers'] as $table) {
            $session = "NULLIF(current_setting('app.company_id', true), '')";
            $policy = "{$session} IS NULL OR company_id = {$session}::bigint";

            DB::statement("ALTER TABLE {$table} ALTER COLUMN company_id SET DEFAULT {$session}::bigint");
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            DB::statement("DROP POLICY IF EXISTS tenant_isolation ON {$table}");
            DB::statement("CREATE POLICY tenant_isolation ON {$table} USING ({$policy}) WITH CHECK ({$policy})");
        }
    }

    public function down(): void
    {
        Schema::table('supplier_settings', function (Blueprint $t) {
            $t->dropColumn('alert_routing');
        });

        Schema::dropIfExists('dd_questionnaire_answers');
        Schema::dropIfExists('dd_questionnaires');
    }
};
