<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant webhook subscriptions and their deliveries (§8.2, SHEQ-API-5).
 *
 * The secret is stored because HMAC signing needs the shared key back — this is not a password, and
 * hashing it would make signing impossible. It is shown to the tenant once at creation; afterwards
 * only `secret_hint` is displayed, so a leaked screenshot of the settings page is not a leaked key.
 *
 * Deliveries are recorded in full: SHEQ-API-5 wants 24 hours of retries and a replayable dead-letter
 * queue, and neither is possible if a failed POST leaves no trace. An integration that silently
 * stopped receiving events is the kind of failure nobody notices until an audit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sheq_webhooks', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            $table->string('name');
            $table->string('url');
            $table->string('secret');                       // HMAC-SHA256 shared key
            $table->string('secret_hint', 20)->nullable();  // last four, for recognition only
            // Null means every event. A list means only these — most integrations want two or three.
            $table->jsonb('event_types')->nullable();

            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('consecutive_failures')->default(0);
            $table->timestampTz('disabled_at')->nullable();
            $table->text('disabled_reason')->nullable();
            $table->timestampTz('last_delivered_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'is_active']);
        });

        Schema::create('sheq_webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('webhook_id')->constrained('sheq_webhooks')->cascadeOnDelete();

            $table->uuid('event_id');
            $table->string('event_type', 120);
            $table->unsignedSmallInteger('attempt')->default(1);

            $table->unsignedSmallInteger('response_status')->nullable();
            $table->text('response_body')->nullable();       // truncated; for diagnosis only
            $table->unsignedInteger('duration_ms')->nullable();
            $table->text('error')->nullable();

            $table->timestampTz('delivered_at');
            $table->timestamps();

            $table->index(['company_id', 'webhook_id', 'delivered_at']);
            $table->index(['company_id', 'event_id']);
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $session = "NULLIF(current_setting('app.company_id', true), '')";
        $policy = "{$session} IS NULL OR company_id = {$session}::bigint";

        foreach (['sheq_webhooks', 'sheq_webhook_deliveries'] as $t) {
            DB::statement("ALTER TABLE {$t} ALTER COLUMN company_id SET DEFAULT {$session}::bigint");
            DB::statement("ALTER TABLE {$t} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$t} FORCE ROW LEVEL SECURITY");
            DB::statement("DROP POLICY IF EXISTS tenant_isolation ON {$t}");
            DB::statement("CREATE POLICY tenant_isolation ON {$t} USING ({$policy}) WITH CHECK ({$policy})");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sheq_webhook_deliveries');
        Schema::dropIfExists('sheq_webhooks');
    }
};
