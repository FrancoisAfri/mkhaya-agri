<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rejected evidence (PM-1003): "rejected events land in a dead-letter table with the validation
 * error, never silently dropped."
 *
 * This matters more than it looks. A device syncs a day's work from a trench with no signal; if one
 * record in the batch is malformed and we drop it, the person who captured it has no way to know
 * and the work is simply gone. Here it is kept, with what was wrong and the payload exactly as it
 * arrived, so it can be corrected and replayed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pm_evidence_dead_letters', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();

            // Kept even when invalid, so a batch can be traced back to what sent it.
            $t->string('source_module', 30)->nullable();
            $t->string('source_ref', 190)->nullable();
            $t->string('event_type', 60)->nullable();
            $t->unsignedBigInteger('project_id')->nullable();
            $t->string('batch_id', 64)->nullable();

            // The submission exactly as received — not a normalised version of it, which would
            // hide the very malformation being recorded.
            $t->json('payload');
            $t->json('errors');

            $t->unsignedBigInteger('submitted_by')->nullable();
            $t->timestamp('replayed_at')->nullable();
            $t->uuid('replayed_as')->nullable();     // the evidence uuid it finally became
            $t->timestamps();

            $t->index(['company_id', 'replayed_at']);
            $t->index(['company_id', 'batch_id']);
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $session = "NULLIF(current_setting('app.company_id', true), '')";
        $policy = "{$session} IS NULL OR company_id = {$session}::bigint";
        DB::statement("ALTER TABLE pm_evidence_dead_letters ALTER COLUMN company_id SET DEFAULT {$session}::bigint");
        DB::statement('ALTER TABLE pm_evidence_dead_letters ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE pm_evidence_dead_letters FORCE ROW LEVEL SECURITY');
        DB::statement('DROP POLICY IF EXISTS tenant_isolation ON pm_evidence_dead_letters');
        DB::statement("CREATE POLICY tenant_isolation ON pm_evidence_dead_letters USING ({$policy}) WITH CHECK ({$policy})");
    }

    public function down(): void
    {
        Schema::dropIfExists('pm_evidence_dead_letters');
    }
};
