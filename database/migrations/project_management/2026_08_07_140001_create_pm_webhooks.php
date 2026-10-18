<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Where a tenant's domain events are delivered (EPIC-070).
 *
 * The outbox already guarantees an event exists if and only if the change that produced it
 * committed (PM-ADR-008). These rows say who wants to hear about it, and the delivery log says what
 * actually happened — because "we sent it" and "they received it" are different claims, and the
 * second is the one an integrator asks about at four in the afternoon.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pm_webhooks', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->string('name', 120);
            $t->string('url', 500);
            // The signing secret, encrypted at rest. NOT hashed: HMAC needs the plaintext, and a
            // hashed secret could never sign anything. Encryption is what protects it in a database
            // dump; the application layer is what keeps it out of screens and API responses.
            $t->text('secret');
            $t->string('secret_hint', 12);           // last four, so a support call can identify the key
            // Empty means everything. A subscription to 40 event types is how a consumer ends up
            // ignoring 39 of them.
            $t->json('event_types')->default(DB::raw("'[]'::json"));
            $t->boolean('is_active')->default(true);
            $t->unsignedInteger('consecutive_failures')->default(0);
            $t->timestamp('disabled_at')->nullable();
            $t->string('disabled_reason', 255)->nullable();
            $t->timestamp('last_delivered_at')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->softDeletes();

            $t->index(['company_id', 'is_active']);
        });

        Schema::create('pm_webhook_deliveries', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->unsignedBigInteger('webhook_id');
            $t->unsignedBigInteger('outbox_id')->nullable();
            $t->string('event_type', 60);
            $t->unsignedSmallInteger('status_code')->nullable();
            $t->unsignedInteger('duration_ms')->nullable();
            $t->unsignedSmallInteger('attempt')->default(1);
            // Truncated: a consumer's 4 MB HTML error page helps nobody and fills the table.
            $t->string('error', 500)->nullable();
            $t->timestamp('delivered_at')->nullable();
            $t->timestamps();

            $t->foreign('webhook_id')->references('id')->on('pm_webhooks')->cascadeOnDelete();
            $t->index(['company_id', 'webhook_id', 'created_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            $session = "NULLIF(current_setting('app.company_id', true), '')";
            $policy = "{$session} IS NULL OR company_id = {$session}::bigint";

            foreach (['pm_webhooks', 'pm_webhook_deliveries'] as $table) {
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
        Schema::dropIfExists('pm_webhook_deliveries');
        Schema::dropIfExists('pm_webhooks');
    }
};
