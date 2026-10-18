<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who moved a SARS return, from what, to what, and when.
 *
 * The submission itself records only where it ended up: a status, and a submitted_at that is set
 * when it reaches `submitted`. So "who filed this, and did anybody review it first?" — the two
 * questions actually asked about a statutory return, by an auditor and by whoever has to defend a
 * penalty — had no answer at all. Worse, nothing was written down when a return was moved
 * backwards, which until now was allowed.
 *
 * A separate table rather than a JSON column on the submission, because these rows are read
 * across returns ("everything filed in March, by whom") and because appending to a JSON blob under
 * concurrency loses events.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sars_submission_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('submission_id')->constrained('sars_submissions')->cascadeOnDelete();

            // Denormalised so the trail survives being read without the parent, and so a company's
            // events can be scoped without a join — this table is covered by the same RLS as the
            // rest of the schema.
            $table->unsignedBigInteger('company_id')->nullable();

            $table->string('from_status', 20)->nullable();   // null on the first event: it was created
            $table->string('to_status', 20);
            $table->string('event', 40)->default('status_changed');
            $table->text('note')->nullable();

            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_name')->nullable();        // kept verbatim: a user can be deleted
            $table->string('ip', 45)->nullable();

            $table->timestamps();

            $table->index(['submission_id', 'id']);
            $table->index(['company_id', 'created_at']);
        });

        $this->applyTenantIsolation('sars_submission_events');
    }

    /** The same row-level isolation every other tenant table carries. */
    private function applyTenantIsolation(string $table): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $session = "NULLIF(current_setting('app.company_id', true), '')";
        $policy = "{$session} IS NULL OR company_id = {$session}::bigint";

        DB::statement("ALTER TABLE {$table} ALTER COLUMN company_id SET DEFAULT {$session}::bigint");
        DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
        DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
        DB::statement("DROP POLICY IF EXISTS tenant_isolation ON {$table}");
        DB::statement("CREATE POLICY tenant_isolation ON {$table} USING ({$policy}) WITH CHECK ({$policy})");
    }

    public function down(): void
    {
        Schema::dropIfExists('sars_submission_events');
    }
};
