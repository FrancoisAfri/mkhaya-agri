<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Project Management — the Evidence Ledger (EPIC-010) and its derived values (EPIC-012).
 *
 * Every observation about the world is an immutable event; progress and cost are *derived* from
 * those events rather than typed in, which is what makes "why does this task say 62%?" answerable.
 * Implemented exactly as PM-ADR-002 requires:
 *
 *   append-only   BEFORE UPDATE OR DELETE trigger raises. The only permitted change is setting
 *                 superseded_by once, from NULL — corrections are new events, never overwrites.
 *   partitioned   native RANGE (occurred_at), one partition per month + a DEFAULT catch-all so a
 *                 late or far-future event can never fail to insert. pm:ensure-partitions keeps
 *                 the window ahead of "now".
 *   hash-chained  each event hashes its canonical fields plus the previous event's hash for the
 *                 same (company_id, project_id), so tampering is detectable per project.
 *
 * PostgreSQL requires the partition key in every unique constraint, so the PK is composite
 * (id, occurred_at) and the public UUID is unique per (uuid, occurred_at) with a plain lookup
 * index on uuid. Nothing foreign-keys *into* the ledger — references are by UUID — which also
 * keeps old partitions detachable for archival.
 */
return new class extends Migration
{
    /** Months of history and future to pre-create partitions for. */
    private const MONTHS_BACK = 3;

    private const MONTHS_AHEAD = 12;

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            // The ledger's guarantees (partitioning, append-only trigger, RLS) are PostgreSQL
            // features. Refusing loudly beats creating a table that silently lacks them.
            throw new RuntimeException('The PM Evidence Ledger requires PostgreSQL.');
        }

        $this->createLedger();
        $this->createPartitions();
        $this->createAppendOnlyTrigger();
        $this->createDerivedValues();
    }

    public function down(): void
    {
        Schema::dropIfExists('pm_derived_values');
        DB::statement('DROP TABLE IF EXISTS pm_evidence_events CASCADE');   // takes partitions with it
        DB::statement('DROP FUNCTION IF EXISTS pm_evidence_events_append_only() CASCADE');
        DB::statement('DROP SEQUENCE IF EXISTS pm_evidence_events_id_seq');
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function createLedger(): void
    {
        // A partitioned parent cannot use bigserial directly, so the sequence is explicit and the
        // default is evaluated on the parent at insert time.
        DB::statement('CREATE SEQUENCE IF NOT EXISTS pm_evidence_events_id_seq');

        DB::statement(<<<'SQL'
            CREATE TABLE pm_evidence_events (
                id              bigint       NOT NULL DEFAULT nextval('pm_evidence_events_id_seq'),
                uuid            uuid         NOT NULL,
                company_id      bigint,
                project_id      bigint       NOT NULL,

                subject_type    varchar(30)  NOT NULL,   -- task | wbs_node | project | resource | supplier
                subject_id      bigint,                  -- internal id, for joins
                subject_uuid    uuid,                    -- public id, survives an offline-created subject

                event_type      varchar(60)  NOT NULL,   -- taxonomy, see EvidenceEventType
                source_module   varchar(30)  NOT NULL,   -- pm | procurement | hr | fleet | mobile | integration
                source_ref      varchar(190),            -- id in the originating system

                -- Instants are stored in UTC with the observing zone kept alongside, so a time is
                -- never ambiguous. The column type is `timestamp` rather than `timestamptz` to
                -- match the platform: this database's session timezone is Africa/Johannesburg, and
                -- a timestamptz column would be silently shifted by that offset on every write and
                -- read (Laravel sends instants without an offset). Storing UTC explicitly makes the
                -- value independent of whatever timezone the server happens to be configured with —
                -- which also removes the same ambiguity from the partition boundaries below.
                -- Microsecond precision, because the hash covers the full instant.
                occurred_at     timestamp(6) NOT NULL,   -- when it happened in the world, UTC
                occurred_tz     varchar(64)  NOT NULL,   -- IANA zone of the observer
                recorded_at     timestamp(6) NOT NULL DEFAULT (now() AT TIME ZONE 'UTC'),  -- when we received it

                actor_type      varchar(20)  NOT NULL,   -- user | system | integration | device
                actor_id        varchar(64),

                quantity        numeric(18,4),           -- measured amounts keep 4 dp (m³, tonnes, hours)
                unit            varchar(20),
                amount          numeric(18,2),           -- PM-ADR-006: exact decimal, never a float
                currency        char(3),

                confidence      numeric(3,2) NOT NULL DEFAULT 1.00,   -- 0.00–1.00
                geo_lat         numeric(9,6),
                geo_lng         numeric(9,6),

                payload         jsonb        NOT NULL DEFAULT '{}',
                attachments     jsonb        NOT NULL DEFAULT '[]',

                superseded_by   uuid,                    -- the event that corrects this one
                idempotency_key varchar(190),            -- retried sync must not duplicate

                hash            char(64)     NOT NULL,   -- SHA-256 of canonical fields + prev_hash
                prev_hash       char(64),                -- NULL only for a project's first event
                hash_version    varchar(10)  NOT NULL DEFAULT 'v1',

                CONSTRAINT pm_evidence_events_pkey PRIMARY KEY (id, occurred_at),
                CONSTRAINT pm_evidence_events_confidence_chk CHECK (confidence >= 0 AND confidence <= 1),
                -- Money without a currency is unusable downstream; reject it at the door (DoD 7).
                CONSTRAINT pm_evidence_events_currency_chk CHECK (amount IS NULL OR currency IS NOT NULL)
            ) PARTITION BY RANGE (occurred_at)
        SQL);

        DB::statement('ALTER SEQUENCE pm_evidence_events_id_seq OWNED BY pm_evidence_events.id');

        // Unique on the public id. Must include the partition key (PostgreSQL requirement), so
        // global uniqueness rests on UUIDv7 in practice; the DB guarantee is per partition.
        DB::statement('CREATE UNIQUE INDEX pm_evidence_events_uuid_unique ON pm_evidence_events (uuid, occurred_at)');
        DB::statement('CREATE INDEX pm_evidence_events_uuid_idx ON pm_evidence_events (uuid)');

        // The two read paths: a project's timeline, and one subject's history.
        DB::statement('CREATE INDEX pm_evidence_events_project_idx ON pm_evidence_events (company_id, project_id, occurred_at DESC)');
        DB::statement('CREATE INDEX pm_evidence_events_subject_idx ON pm_evidence_events (company_id, subject_type, subject_id, occurred_at DESC)');
        DB::statement('CREATE INDEX pm_evidence_events_idem_idx ON pm_evidence_events (company_id, idempotency_key)');
        DB::statement('CREATE INDEX pm_evidence_events_chain_idx ON pm_evidence_events (company_id, project_id, id DESC)');

        $this->applyTenantRls('pm_evidence_events');
    }

    /**
     * Pre-create a rolling window of monthly partitions plus a DEFAULT catch-all. The DEFAULT is a
     * safety net, not a destination: pm:ensure-partitions runs monthly to keep real partitions
     * ahead of incoming evidence.
     */
    private function createPartitions(): void
    {
        $start = now()->startOfMonth()->subMonths(self::MONTHS_BACK);

        for ($i = 0; $i < self::MONTHS_BACK + self::MONTHS_AHEAD + 1; $i++) {
            $from = $start->copy()->addMonths($i);
            $to = $from->copy()->addMonth();
            $name = sprintf('pm_evidence_events_y%sm%s', $from->format('Y'), $from->format('m'));

            DB::statement(sprintf(
                "CREATE TABLE IF NOT EXISTS %s PARTITION OF pm_evidence_events FOR VALUES FROM ('%s') TO ('%s')",
                $name,
                $from->toDateString(),
                $to->toDateString()
            ));
            $this->applyTenantRls($name);
        }

        DB::statement('CREATE TABLE IF NOT EXISTS pm_evidence_events_default PARTITION OF pm_evidence_events DEFAULT');
        $this->applyTenantRls('pm_evidence_events_default');
    }

    /**
     * Append-only, enforced by the database so that a future bug, console command or raw SQL
     * script cannot quietly rewrite history (PM-ADR-002).
     *
     * DELETE always raises. UPDATE raises unless the *only* difference is superseded_by moving
     * from NULL to a value — the correction mechanism. Comparing the two rows as jsonb minus that
     * one key means new columns are covered automatically as the schema grows.
     */
    private function createAppendOnlyTrigger(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION pm_evidence_events_append_only() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'pm_evidence_events is append-only: DELETE is not permitted (id=%). Archive by detaching a partition.', OLD.id
                        USING ERRCODE = 'restrict_violation';
                END IF;

                -- Any change outside superseded_by is a rewrite of history. Comparing the rows as
                -- jsonb minus that one key covers columns added to the table later, automatically.
                IF (to_jsonb(NEW) - 'superseded_by') IS DISTINCT FROM (to_jsonb(OLD) - 'superseded_by') THEN
                    RAISE EXCEPTION 'pm_evidence_events is append-only: only superseded_by may change (id=%). Write a correcting event instead.', OLD.id
                        USING ERRCODE = 'restrict_violation';
                END IF;

                -- superseded_by is write-once: NULL -> value is the correction mechanism; changing
                -- or clearing an existing supersession would rewrite the correction history.
                IF NEW.superseded_by IS DISTINCT FROM OLD.superseded_by AND OLD.superseded_by IS NOT NULL THEN
                    RAISE EXCEPTION 'pm_evidence_events is append-only: event % is already superseded by %', OLD.uuid, OLD.superseded_by
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);

        // BEFORE row triggers on partitioned tables are supported from PostgreSQL 13; partitions
        // created later inherit this one automatically.
        DB::statement(<<<'SQL'
            CREATE TRIGGER pm_evidence_events_append_only
            BEFORE UPDATE OR DELETE ON pm_evidence_events
            FOR EACH ROW EXECUTE FUNCTION pm_evidence_events_append_only()
        SQL);
    }

    /**
     * Derived values — the answer to "what does the evidence add up to?", cached per
     * (subject, metric) with the evidence that produced it, so every number drills down to the
     * events behind it. Recomputable at any time; never the system of record.
     */
    private function createDerivedValues(): void
    {
        Schema::create('pm_derived_values', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->string('subject_type', 30);
            $t->unsignedBigInteger('subject_id');
            $t->uuid('subject_uuid')->nullable();
            $t->string('metric', 40);                 // progress_pct | actual_cost | actual_hours | qty_installed | last_activity_at
            $t->decimal('value_numeric', 18, 4)->nullable();
            $t->string('value_text', 190)->nullable();
            $t->char('currency', 3)->nullable();      // set whenever the metric is money
            $t->decimal('confidence', 3, 2)->default(1.00);
            $t->string('method_version', 20);         // reproducibility: which algorithm produced this
            $t->json('evidence_uuids');               // powers the drill-down
            $t->unsignedInteger('evidence_count')->default(0);
            $t->timestamp('computed_at');

            // A human can override a derived number, but never silently: the override records who,
            // why and when, and the underlying evidence stays visible beneath it.
            $t->boolean('is_overridden')->default(false);
            $t->unsignedBigInteger('override_by')->nullable();
            $t->text('override_reason')->nullable();
            $t->timestamp('overridden_at')->nullable();

            $t->timestamps();
            $t->softDeletes();

            $t->index(['company_id', 'subject_type', 'subject_id']);
        });

        DB::statement('CREATE UNIQUE INDEX pm_derived_values_unique ON pm_derived_values (company_id, subject_type, subject_id, metric) WHERE deleted_at IS NULL');

        $this->applyTenantRls('pm_derived_values');
    }

    /** PM-ADR-001 — identical mechanism to the rest of the platform. */
    private function applyTenantRls(string $table): void
    {
        $session = "NULLIF(current_setting('app.company_id', true), '')";
        $policy = "{$session} IS NULL OR company_id = {$session}::bigint";

        DB::statement("ALTER TABLE {$table} ALTER COLUMN company_id SET DEFAULT {$session}::bigint");
        DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
        DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
        DB::statement("DROP POLICY IF EXISTS tenant_isolation ON {$table}");
        DB::statement("CREATE POLICY tenant_isolation ON {$table} USING ({$policy}) WITH CHECK ({$policy})");
    }
};
