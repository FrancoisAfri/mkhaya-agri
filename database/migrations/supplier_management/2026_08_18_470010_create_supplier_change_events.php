<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5 — monitor: what changed since we approved them.
 *
 * The module can already say what is true about a supplier today and what was true at every earlier
 * check. What it cannot say is that something MOVED, and that is the whole product: a new director
 * appointed last Tuesday, a credit score that fell forty points, a tax status that lapsed, a
 * registered address that is now a different city.
 *
 * A change is not the same thing as an observation, which is why it gets its own table. An
 * observation is "here is what the registry said"; a change is "the registry says something
 * different from last time, here is the difference, and nobody has looked at it yet". The second is
 * the one somebody has to act on, and burying it inside a hundred identical-looking observations is
 * how monitoring becomes a log nobody reads.
 *
 * Changes are detected by comparing consecutive observations of the same check, so nothing has to
 * be scheduled to notice — the comparison happens when the newer answer arrives.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_change_events', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->unsignedBigInteger('supplier_id')->index();

            $t->string('category', 40);            // directors | company_status | banking | ownership | credit | tax | address
            $t->string('check_type', 60)->nullable();
            $t->string('field', 120)->nullable();

            // The difference itself, kept in both directions so the timeline can say what it WAS as
            // well as what it is. Values are text: a registered address and a credit score both have
            // to fit, and neither is worth a polymorphic column.
            $t->text('previous_value')->nullable();
            $t->text('current_value')->nullable();

            $t->string('severity', 20)->default('medium');   // low | medium | high | critical
            $t->text('summary');
            $t->string('detected_by', 30)->default('monitoring');  // monitoring | edit | provider | manual
            $t->unsignedBigInteger('verification_id')->nullable();
            $t->unsignedBigInteger('monitoring_run_id')->nullable();

            // Whether the change caused a reassessment, and what it did to the score. Recorded rather
            // than inferred, because "the score went up that week" is not proof of a cause.
            $t->boolean('triggered_reassessment')->default(false);
            $t->decimal('risk_before', 6, 2)->nullable();
            $t->decimal('risk_after', 6, 2)->nullable();

            $t->string('status', 20)->default('open');       // open | acknowledged | actioned | dismissed
            $t->unsignedBigInteger('reviewed_by')->nullable();
            $t->timestamp('reviewed_at')->nullable();
            $t->text('review_note')->nullable();

            $t->timestamp('detected_at');
            $t->timestamps();

            $t->index(['company_id', 'supplier_id', 'detected_at'], 'supplier_change_events_timeline_idx');
            $t->index(['company_id', 'status', 'severity'], 'supplier_change_events_triage_idx');
        });

        // When each check was last attempted, so the scheduler can ask "what is due" without walking
        // every observation ever made. Derived data, rebuilt from observations if it is ever lost.
        Schema::table('suppliers', function (Blueprint $t) {
            $t->timestamp('last_monitored_at')->nullable()->after('risk_calculated_at');
            $t->timestamp('monitoring_due_at')->nullable()->after('last_monitored_at');
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $session = "NULLIF(current_setting('app.company_id', true), '')";
        $policy = "{$session} IS NULL OR company_id = {$session}::bigint";

        DB::statement("ALTER TABLE supplier_change_events ALTER COLUMN company_id SET DEFAULT {$session}::bigint");
        DB::statement('ALTER TABLE supplier_change_events ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE supplier_change_events FORCE ROW LEVEL SECURITY');
        DB::statement('DROP POLICY IF EXISTS tenant_isolation ON supplier_change_events');
        DB::statement("CREATE POLICY tenant_isolation ON supplier_change_events USING ({$policy}) WITH CHECK ({$policy})");
    }

    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $t) {
            $t->dropColumn(['last_monitored_at', 'monitoring_due_at']);
        });

        Schema::dropIfExists('supplier_change_events');
    }
};
