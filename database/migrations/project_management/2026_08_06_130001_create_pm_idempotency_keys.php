<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotency-Key storage for the PM API (§4, PM-1003).
 *
 * A field device on a bad connection retries. Without this, a retry that the server already
 * processed creates a second project, a second timesheet, a second anything — the single most
 * common way an offline-first product corrupts its own data.
 *
 * The stored response is replayed verbatim on a repeat, so the client sees exactly what it would
 * have seen had the first attempt reached it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pm_idempotency_keys', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->string('key', 190);
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('method', 10);
            $t->string('path', 255);

            // Fingerprint of the request body. A key replayed with a *different* body is a client
            // bug, not a retry, and is rejected rather than silently returning the wrong response.
            $t->char('request_hash', 64);

            $t->unsignedSmallInteger('response_status')->nullable();
            $t->json('response_body')->nullable();

            // Set when processing starts and cleared on completion, so two simultaneous retries of
            // the same key cannot both execute.
            $t->timestamp('locked_at')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->timestamps();
        });

        if (DB::getDriverName() === 'pgsql') {
            // One key per tenant. Scoping to the company means two tenants' devices can never
            // collide on a locally-generated key.
            DB::statement('CREATE UNIQUE INDEX pm_idempotency_keys_unique ON pm_idempotency_keys (company_id, key)');

            $session = "NULLIF(current_setting('app.company_id', true), '')";
            $policy = "{$session} IS NULL OR company_id = {$session}::bigint";
            DB::statement("ALTER TABLE pm_idempotency_keys ALTER COLUMN company_id SET DEFAULT {$session}::bigint");
            DB::statement('ALTER TABLE pm_idempotency_keys ENABLE ROW LEVEL SECURITY');
            DB::statement('ALTER TABLE pm_idempotency_keys FORCE ROW LEVEL SECURITY');
            DB::statement('DROP POLICY IF EXISTS tenant_isolation ON pm_idempotency_keys');
            DB::statement("CREATE POLICY tenant_isolation ON pm_idempotency_keys USING ({$policy}) WITH CHECK ({$policy})");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pm_idempotency_keys');
    }
};
