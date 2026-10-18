<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Project Management — foundation tables (EPIC-001/004/008).
 *
 *  pm_external_refs      polymorphic link from a PM record to a record in another module
 *                        (HR employee, CRM customer, …) or to a purely local stand-in.
 *  pm_calendars          working-time definitions used by the CPM engine.
 *  pm_calendar_exceptions per-date overrides (public holidays, shutdowns, extra shifts).
 *  pm_outbox             transactional outbox (PM-ADR-008): domain events written in the same
 *                        transaction as the state change, drained by a scheduled worker.
 *
 * Conventions for every pm_* table, per the ADRs in docs/adr:
 *  - PM-ADR-001 tenancy: company_id + FORCE RLS via the app.company_id session variable.
 *  - PM-ADR-003 IDs:     bigint PK internally, UUIDv7 `uuid` column as the only public ID.
 *  - PM-ADR-007 delete:  deleted_at everywhere.
 *  - PM-ADR-006 money:   numeric(18,2) + explicit ISO currency. Never floats.
 */
return new class extends Migration
{
    /** Tables created here, in creation order (dropped in reverse). */
    private const TABLES = ['pm_external_refs', 'pm_calendars', 'pm_calendar_exceptions', 'pm_outbox'];

    public function up(): void
    {
        // ── Links to other modules ────────────────────────────────────────────
        // source_module 'local' means the record has no counterpart module installed and PM holds
        // the details itself — this is what makes the module standalone-capable.
        Schema::create('pm_external_refs', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->string('local_type', 40);            // project_member | project_client | resource | supplier
            $t->unsignedBigInteger('local_id')->nullable();
            $t->string('source_module', 30);         // hr | crm | accounting | procurement | assets | fleet | local
            $t->string('source_entity', 40);         // employee | customer | supplier | vehicle | asset
            $t->string('source_id', 64)->nullable(); // id in the originating module (null while 'local')
            $t->string('display_name', 190)->nullable(); // denormalised label, so PM renders when the
                                                         // source module is uninstalled or unreachable
            $t->json('snapshot')->nullable();        // last-known fields from the source module
            $t->timestamp('last_synced_at')->nullable();
            $t->string('sync_status', 12)->default('ok'); // ok | stale | orphaned | conflict
            $t->timestamps();
            $t->softDeletes();

            $t->index(['company_id', 'local_type', 'local_id']);
            $t->index(['company_id', 'source_module', 'source_entity', 'source_id'], 'pm_external_refs_source_idx');
        });

        // One link per (local record, source module): re-pointing a member at a different HR
        // employee updates the row rather than adding a second, ambiguous one. Scoped to live rows
        // so a deleted link does not block a new one (PM-ADR-007).
        $this->partialUnique('pm_external_refs', ['company_id', 'local_type', 'local_id', 'source_module'], 'pm_external_refs_unique');

        // ── Working time ──────────────────────────────────────────────────────
        Schema::create('pm_calendars', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->string('name', 120);
            $t->string('timezone', 64);              // IANA name, e.g. Africa/Johannesburg
            // {"mon":[["07:00","16:00"]],"sat":[],...} — local wall-clock windows in `timezone`.
            $t->json('work_week');
            $t->boolean('is_default')->default(false);
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('pm_calendar_exceptions', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->unsignedBigInteger('calendar_id');
            $t->date('exception_date');
            $t->boolean('is_working');               // false = non-working (holiday/shutdown)
            $t->json('windows')->nullable();         // when is_working, the replacement windows
            $t->string('reason', 190)->nullable();
            $t->timestamps();
            $t->softDeletes();

            $t->foreign('calendar_id')->references('id')->on('pm_calendars')->cascadeOnDelete();
            $t->index(['company_id', 'calendar_id', 'exception_date']);
        });

        $this->partialUnique('pm_calendar_exceptions', ['company_id', 'calendar_id', 'exception_date'], 'pm_calendar_exceptions_unique');

        // ── Transactional outbox (PM-ADR-008) ─────────────────────────────────
        // Written inside the business transaction; drained by pm:drain-outbox, which sets the
        // company context per row before dispatching. Delivery is at-least-once, so consumers
        // deduplicate on `uuid`.
        Schema::create('pm_outbox', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();              // stable event id for consumer dedup
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->string('event_type', 60);            // pm.task.updated | pm.evidence.appended | …
            $t->string('subject_type', 30);
            $t->uuid('subject_uuid')->nullable();    // public id of the subject (PM-ADR-003)
            $t->json('payload');
            $t->timestamp('available_at')->useCurrent();   // backoff target
            $t->unsignedSmallInteger('attempts')->default(0);
            $t->text('last_error')->nullable();
            $t->timestamp('delivered_at')->nullable();
            $t->timestamp('dead_at')->nullable();          // exhausted retries → dead letter
            $t->timestamps();

            // The drain query: undelivered, not dead, due now — oldest first.
            $t->index(['delivered_at', 'dead_at', 'available_at'], 'pm_outbox_drain_idx');
            $t->index(['company_id', 'subject_type', 'subject_uuid']);
        });

        $this->applyTenantRls(self::TABLES);
    }

    public function down(): void
    {
        foreach (array_reverse(self::TABLES) as $table) {
            Schema::dropIfExists($table);
        }
    }

    /**
     * Unique among live rows only. A hard UNIQUE would let a soft-deleted row block its
     * replacement, which PM-ADR-007 explicitly rules out.
     */
    private function partialUnique(string $table, array $columns, string $name): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $cols = implode(', ', $columns);
        DB::statement("CREATE UNIQUE INDEX {$name} ON {$table} ({$cols}) WHERE deleted_at IS NULL");
    }

    /**
     * PM-ADR-001: tenant isolation lives in the database, not only the ORM. Same mechanism the
     * rest of the platform uses — company_id defaults from the app.company_id session variable,
     * and FORCE RLS means even the table owner is subject to the policy. A NULL session variable
     * (console, platform admin) disables the filter, which is how seeders and platform jobs work.
     */
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
