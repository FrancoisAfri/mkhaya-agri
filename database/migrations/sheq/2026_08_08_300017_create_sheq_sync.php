<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Offline sync (§6.1, SHEQ-OFF-2 / OFF-3 / OFF-4 / OFF-5).
 *
 * Three tables, one for each half of the contract and one for what goes wrong:
 *
 * - `sheq_sync_operations` is the server's record of every operation a client pushed, keyed by the
 *   client-generated UUIDv7 (SHEQ-OFF-2). Replaying an operation returns the stored result rather
 *   than applying it twice — a device that loses signal mid-push retries its whole queue, and the
 *   second attempt must change nothing.
 *
 * - `sheq_sync_changes` is a monotonic per-tenant change feed (SHEQ-OFF-3). A client sends the
 *   highest sequence it has seen and gets everything after it. This is a table rather than a
 *   `sequence` column on each entity because a cursor has to order changes *across* entities: a
 *   client that pulled incidents at 14:00 and hazards at 14:05 has no single position otherwise.
 *
 * - `sheq_sync_conflicts` is the queue behind SHEQ-OFF-5. Both values are stored, not just the
 *   loser, because the whole point is that a field worker can see what they typed next to what the
 *   server holds. The spec calls silent loss of their data a P0 defect, so nothing here overwrites:
 *   a conflict row is written before the server value is kept.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sheq_sync_operations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            /**
             * The client's idempotency key (SHEQ-OFF-2). UUIDv7 so it sorts by creation time, which
             * lets the server apply a device's queue in the order the user actually did the work.
             */
            $table->uuid('client_id');

            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('device_id', 100)->nullable();

            $table->string('entity_type', 40);              // INCIDENT|HAZARD|ACTION|INSPECTION
            $table->uuid('entity_uuid')->nullable();         // null for a create that has not landed
            $table->string('operation', 20);                 // CREATE|UPDATE|TRANSITION|DELETE

            // What the client last saw. The merge is meaningless without it: "changed on server"
            // can only be decided against the value the client started from (SHEQ-OFF-4).
            $table->unsignedInteger('base_version')->nullable();
            $table->jsonb('base')->nullable();
            $table->jsonb('payload');

            // APPLIED|MERGED|CONFLICT|REJECTED|SUPERSEDED
            $table->string('status', 20)->default('APPLIED');
            $table->jsonb('result')->nullable();             // what the client gets back on replay
            $table->text('rejection_reason')->nullable();    // SHEQ-OFF-6 explains itself

            $table->timestampTz('received_at');
            $table->timestampTz('applied_at')->nullable();
            $table->timestamps();

            // The idempotency guarantee, enforced by the database rather than by a check-then-act
            // race between two retries arriving on different workers.
            $table->unique(['company_id', 'client_id'], 'sheq_sync_op_idem_uniq');
            $table->index(['company_id', 'entity_type', 'entity_uuid']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('sheq_sync_changes', function (Blueprint $table) {
            // The cursor itself. bigIncrements, so the database hands out the ordering.
            $table->bigIncrements('sequence');
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            $table->string('entity_type', 40);
            $table->unsignedBigInteger('entity_id');
            $table->uuid('entity_uuid');

            // Sync is scoped to the sites a user is assigned to (SHEQ-OFF-1), so the feed carries
            // the site and the pull filters on it rather than fetching then discarding.
            $table->foreignId('site_id')->nullable()->constrained('sheq_sites')->nullOnDelete();

            $table->string('change_type', 20);               // CREATED|UPDATED|DELETED
            $table->unsignedInteger('entity_version')->default(1);
            $table->timestampTz('occurred_at');
            $table->timestamps();

            $table->index(['company_id', 'sequence']);
            $table->index(['company_id', 'entity_type', 'sequence']);
            $table->index(['company_id', 'site_id', 'sequence']);
        });

        Schema::create('sheq_sync_conflicts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            $table->foreignId('operation_id')->nullable()
                ->constrained('sheq_sync_operations')->nullOnDelete();

            $table->string('entity_type', 40);
            $table->uuid('entity_uuid');
            $table->string('field', 100);

            // Both sides, always. The queue exists so a person can compare them (SHEQ-OFF-5).
            $table->jsonb('base_value')->nullable();
            $table->jsonb('server_value')->nullable();
            $table->jsonb('client_value')->nullable();

            // Which rule in the SHEQ-OFF-4 table produced this, so the UI can explain itself.
            $table->string('rule', 40);

            $table->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('detected_at');

            $table->string('status', 20)->default('OPEN');    // OPEN|RESOLVED
            $table->string('resolution', 20)->nullable();     // TOOK_SERVER|TOOK_CLIENT|MERGED
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('resolved_at')->nullable();
            $table->text('resolution_notes')->nullable();

            $table->timestamps();

            $table->index(['company_id', 'status', 'detected_at']);
            $table->index(['company_id', 'entity_type', 'entity_uuid']);
        });

        $this->applyTenantRls([
            'sheq_sync_operations', 'sheq_sync_changes', 'sheq_sync_conflicts',
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('sheq_sync_conflicts');
        Schema::dropIfExists('sheq_sync_changes');
        Schema::dropIfExists('sheq_sync_operations');
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
