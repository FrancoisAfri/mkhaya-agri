<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WMS-170 to WMS-173: carriers, other systems, and the events they hear.
 *
 * A carrier is configured once with the driver that talks to it -- MANUAL until a live API is
 * contracted -- and a shipment that goes with it keeps its booking and every tracking event. What
 * the warehouse does is written to an outbox in the same transaction as the work itself, each event
 * with its own identifier and schema version, and delivered from there to subscribed endpoints with
 * retries and a dead letter. ERP connections reuse Manufacturing's integration tables; nothing here
 * duplicates them.
 */
return new class extends Migration
{
    private const TENANT_TABLES = ['wms_carriers', 'wms_carrier_rates', 'wms_tracking_events', 'wms_outbox', 'wms_webhooks',
        'wms_webhook_deliveries', 'wms_idempotency_keys', 'wms_erp_reconciliations'];

    public function up(): void
    {
        Schema::create('wms_carriers', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $t->string('code', 20);
            $t->string('name', 120);
            $t->string('driver', 16)->default('MANUAL');
            $t->string('account_ref', 60)->nullable();
            $t->text('credentials')->nullable(); // encrypted
            $t->jsonb('settings')->nullable();
            $t->string('tracking_url', 300)->nullable(); // with {tracking}
            $t->boolean('is_active')->default(true);
            $t->timestampsTz();
            $t->unique(['company_id', 'code'], 'wms_carrier_code_uq');
        });
        Schema::create('wms_carrier_rates', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $t->foreignId('carrier_id')->constrained('wms_carriers')->cascadeOnDelete();
            $t->string('service', 60);
            $t->string('zone', 60)->nullable(); // NULL: anywhere
            $t->decimal('max_weight_kg', 12, 3);
            $t->decimal('price', 14, 2);
            $t->unsignedSmallInteger('transit_days')->nullable();
            $t->timestampsTz();
        });
        Schema::table('wms_shipments', function (Blueprint $t) {
            $t->foreignId('carrier_id')->nullable()->constrained('wms_carriers')->nullOnDelete();
            $t->string('booking_reference', 120)->nullable();
            $t->timestampTz('booked_at')->nullable();
            $t->decimal('quoted_price', 14, 2)->nullable();
            $t->string('tracking_status', 20)->nullable();
            $t->timestampTz('tracked_at')->nullable();
        });
        Schema::create('wms_tracking_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $t->foreignId('shipment_id')->constrained('wms_shipments')->cascadeOnDelete();
            $t->string('status', 20); // BOOKED|COLLECTED|IN_TRANSIT|OUT_FOR_DELIVERY|DELIVERED|FAILED|RETURNED|EXCEPTION
            $t->timestampTz('occurred_at');
            $t->string('location', 160)->nullable();
            $t->string('detail', 500)->nullable();
            $t->string('source', 10); // MANUAL|CARRIER|API
            $t->string('external_id', 120)->nullable();
            $t->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampTz('created_at')->nullable();
            $t->unique(['shipment_id', 'external_id'], 'wms_tracking_once');
        });

        Schema::create('wms_outbox', function (Blueprint $t) {
            $t->id();
            $t->uuid('event_id')->unique();
            $t->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $t->foreignId('warehouse_id')->nullable()->constrained('fin_warehouses')->nullOnDelete();
            $t->string('event_type', 48);
            $t->unsignedSmallInteger('schema_version');
            $t->string('subject_type', 32);
            $t->string('subject_ref', 64);
            $t->jsonb('payload');
            $t->timestampTz('occurred_at');
            $t->timestampTz('available_at');
            $t->timestampTz('published_at')->nullable();
            $t->unsignedSmallInteger('attempts')->default(0);
            $t->text('last_error')->nullable();
            $t->timestampTz('failed_at')->nullable(); // dead letter
            $t->timestampTz('replayed_at')->nullable(); // deliveries before this are sent again
            $t->timestampTz('created_at')->nullable();
            $t->index(['company_id', 'published_at', 'failed_at', 'available_at'], 'wms_outbox_pending');
            $t->index(['company_id', 'id'], 'wms_outbox_feed');
        });
        Schema::create('wms_webhooks', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $t->string('name', 120);
            $t->string('url', 500);
            $t->text('secret'); // encrypted
            $t->string('secret_hint', 8);
            $t->jsonb('event_types');
            $t->jsonb('warehouse_ids')->nullable(); // NULL: every warehouse
            $t->boolean('is_active')->default(true);
            $t->unsignedInteger('consecutive_failures')->default(0);
            $t->timestampTz('disabled_at')->nullable();
            $t->string('disabled_reason', 300)->nullable();
            $t->timestampTz('last_delivered_at')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampsTz();
        });
        Schema::create('wms_webhook_deliveries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $t->foreignId('webhook_id')->constrained('wms_webhooks')->cascadeOnDelete();
            $t->foreignId('outbox_id')->constrained('wms_outbox')->cascadeOnDelete();
            $t->unsignedSmallInteger('attempt');
            $t->unsignedSmallInteger('response_status')->nullable();
            $t->string('error', 500)->nullable();
            $t->unsignedInteger('duration_ms')->nullable();
            $t->boolean('delivered')->default(false);
            $t->timestampTz('created_at')->nullable();
            $t->index(['webhook_id', 'outbox_id']);
        });
        Schema::create('wms_idempotency_keys', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $t->string('key', 120);
            $t->string('method', 8);
            $t->string('path', 300);
            $t->char('request_hash', 64);
            $t->unsignedSmallInteger('response_status')->nullable();
            $t->text('response_body')->nullable();
            $t->timestampTz('completed_at')->nullable();
            $t->timestampTz('created_at')->nullable();
            $t->unique(['company_id', 'user_id', 'key'], 'wms_idempotency_once');
        });
        Schema::create('wms_erp_reconciliations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $t->foreignId('integration_id')->constrained('mfg_integrations')->cascadeOnDelete();
            $t->foreignId('integration_event_id')->nullable()->constrained('mfg_integration_events')->nullOnDelete();
            $t->unsignedInteger('compared');
            $t->unsignedInteger('differences');
            $t->unsignedInteger('unmapped');
            $t->jsonb('lines');
            $t->timestampTz('created_at')->nullable();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE wms_carriers ADD CONSTRAINT wms_carrier_driver_chk CHECK (driver IN ('MANUAL'))");
            DB::statement("ALTER TABLE wms_tracking_events ADD CONSTRAINT wms_tracking_status_chk CHECK (status IN ('BOOKED','COLLECTED','IN_TRANSIT','OUT_FOR_DELIVERY','DELIVERED','FAILED','RETURNED','EXCEPTION'))");
            // The outbox is a record of what happened: an event's content never changes after it is written.
            DB::statement(<<<'SQL'
                CREATE OR REPLACE FUNCTION wms_outbox_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
                BEGIN
                    IF NEW.event_id <> OLD.event_id OR NEW.event_type <> OLD.event_type OR NEW.schema_version <> OLD.schema_version
                       OR NEW.payload::text <> OLD.payload::text OR NEW.occurred_at <> OLD.occurred_at OR NEW.company_id IS DISTINCT FROM OLD.company_id THEN
                        RAISE EXCEPTION 'A warehouse event cannot be rewritten once recorded';
                    END IF;
                    RETURN NEW;
                END $$
            SQL);
            DB::statement('CREATE TRIGGER wms_outbox_immutable BEFORE UPDATE ON wms_outbox FOR EACH ROW EXECUTE FUNCTION wms_outbox_immutable()');

            $session = "NULLIF(current_setting('app.company_id', true), '')";
            $policy = "{$session} IS NULL OR company_id = {$session}::bigint";
            foreach (self::TENANT_TABLES as $table) {
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
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS wms_outbox_immutable ON wms_outbox');
            DB::statement('DROP FUNCTION IF EXISTS wms_outbox_immutable()');
        }
        Schema::table('wms_shipments', function (Blueprint $t) {
            $t->dropConstrainedForeignId('carrier_id');
            $t->dropColumn(['booking_reference', 'booked_at', 'quoted_price', 'tracking_status', 'tracked_at']);
        });
        foreach (array_reverse(self::TENANT_TABLES) as $t) {
            Schema::dropIfExists($t);
        }
    }
};
