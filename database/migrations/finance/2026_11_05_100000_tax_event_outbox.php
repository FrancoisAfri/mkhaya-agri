<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tax events (SRS §19): durable, de-duplicated, delivered with retries.
 *
 * An event row is written in the same transaction as the change it reports, so a change cannot
 * happen without its event or the other way round. `dedupe_key` makes a retried operation that
 * publishes the same fact again a no-op. Delivery (`tax:events`) hands each to the application's
 * listeners and records the outcome; failures stay visible until they are delivered or given up on.
 * Payloads carry identifiers only — never amounts or personal data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_events', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->string('name', 80);
            $t->jsonb('payload');
            $t->string('dedupe_key', 64);
            $t->string('status', 20)->default('pending'); // pending | delivered | failed
            $t->unsignedSmallInteger('attempts')->default(0);
            $t->text('last_error')->nullable();
            $t->timestampTz('available_at');
            $t->timestampTz('delivered_at')->nullable();
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['company_id', 'dedupe_key']);
            $t->index(['status', 'available_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            $session = "NULLIF(current_setting('app.company_id', true), '')";
            $policy = "{$session} IS NULL OR company_id = {$session}::bigint";
            DB::statement("ALTER TABLE tax_events ALTER COLUMN company_id SET DEFAULT {$session}::bigint");
            DB::statement('ALTER TABLE tax_events ENABLE ROW LEVEL SECURITY');
            DB::statement('ALTER TABLE tax_events FORCE ROW LEVEL SECURITY');
            DB::statement('DROP POLICY IF EXISTS tenant_isolation ON tax_events');
            DB::statement("CREATE POLICY tenant_isolation ON tax_events USING ({$policy}) WITH CHECK ({$policy})");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_events');
    }
};
