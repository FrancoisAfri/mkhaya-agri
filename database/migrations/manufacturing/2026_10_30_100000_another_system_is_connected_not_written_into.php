<?php

use Database\Seeders\ManufacturingModuleSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MFG-022 -- external ERP and industrial integrations.
 *
 * An integration names the system (SYSPRO, Cin7 Core, Sage 200, Acumatica, a barcode scanner or
 * label printer, a scale, an MES, an IoT gateway, or plain files), how it is reached (its API, file
 * exchange, or signed webhooks -- never by writing into the other system's database), and its
 * secrets, which are encrypted at rest and never logged. It cannot go live until somebody records
 * that the customer's edition, licence and API access were checked.
 *
 * Mappings pair each side's references for products, units, warehouses, orders and the rest.
 * Every message in or out is an event with an idempotency key, so a retry or a duplicate delivery
 * is recognised and never processed twice; failures retry with back-off and, after the last
 * attempt, wait in the dead-letter queue for a person.
 */
return new class extends Migration
{
    private const TABLES = ['mfg_integrations', 'mfg_integration_mappings', 'mfg_integration_events'];

    public function up(): void
    {
        Schema::create('mfg_integrations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('name');
            $table->string('connector', 20);                 // SYSPRO|CIN7_CORE|SAGE_200|ACUMATICA|BARCODE|LABEL_PRINTER|SCALE|MES|IOT_GATEWAY|FILE
            $table->string('method', 8);                     // API|FILE|WEBHOOK
            $table->string('base_url')->nullable();
            $table->string('auth_type', 10)->default('NONE'); // NONE|API_KEY|BASIC|BEARER|OAUTH2
            $table->text('credentials')->nullable();         // encrypted
            $table->text('webhook_secret')->nullable();      // encrypted
            $table->jsonb('settings')->nullable();           // endpoints, edition, company code -- no secrets
            $table->unsignedInteger('sync_every_minutes')->nullable();
            $table->string('status', 10)->default('DRAFT');  // DRAFT|VALIDATED|ACTIVE|PAUSED
            $table->text('validation_notes')->nullable();    // edition, licence and API access as checked
            $table->foreignId('validated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('validated_at')->nullable();
            $table->timestampTz('last_synced_at')->nullable();
            $table->text('last_sync_result')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('mfg_integration_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('integration_id')->constrained('mfg_integrations')->cascadeOnDelete();
            $table->string('entity', 16);                    // PRODUCT|UNIT|WAREHOUSE|ORDER|SUPPLIER|CUSTOMER|WORK_CENTRE
            $table->unsignedBigInteger('local_id');
            $table->string('external_ref');
            $table->timestamps();

            $table->unique(['integration_id', 'entity', 'external_ref'], 'mfg_integration_map_ext_uq');
            $table->unique(['integration_id', 'entity', 'local_id'], 'mfg_integration_map_local_uq');
        });

        Schema::create('mfg_integration_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('integration_id')->constrained('mfg_integrations')->cascadeOnDelete();
            $table->string('direction', 3);                  // IN|OUT
            $table->string('event_type', 24);
            $table->string('external_id')->nullable();
            $table->string('idempotency_key');
            $table->jsonb('payload');                        // secrets removed before storing
            $table->string('status', 10)->default('RECEIVED'); // RECEIVED|PROCESSED|FAILED|DEAD|SKIPPED
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestampTz('next_attempt_at')->nullable();
            $table->text('last_error')->nullable();
            $table->unsignedInteger('duplicates')->default(0);
            $table->string('result_type', 32)->nullable();
            $table->unsignedBigInteger('result_id')->nullable();
            $table->string('result_reference')->nullable();
            $table->timestampTz('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['integration_id', 'idempotency_key'], 'mfg_integration_event_key_uq');
            $table->index(['company_id', 'status', 'next_attempt_at'], 'mfg_integration_event_due_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE mfg_integrations ADD CONSTRAINT mfg_integration_method_chk CHECK (method IN ('API','FILE','WEBHOOK'))");
            // Going live needs the edition/licence/API check on record.
            DB::statement("ALTER TABLE mfg_integrations ADD CONSTRAINT mfg_integration_validated_chk CHECK (status NOT IN ('VALIDATED','ACTIVE') OR (validated_at IS NOT NULL AND validation_notes IS NOT NULL))");
            $session = "NULLIF(current_setting('app.company_id', true), '')";
            $policy = "{$session} IS NULL OR company_id = {$session}::bigint";
            foreach (self::TABLES as $t) {
                DB::statement("ALTER TABLE {$t} ALTER COLUMN company_id SET DEFAULT {$session}::bigint");
                DB::statement("ALTER TABLE {$t} ENABLE ROW LEVEL SECURITY");
                DB::statement("ALTER TABLE {$t} FORCE ROW LEVEL SECURITY");
                DB::statement("DROP POLICY IF EXISTS tenant_isolation ON {$t}");
                DB::statement("CREATE POLICY tenant_isolation ON {$t} USING ({$policy}) WITH CHECK ({$policy})");
            }
        }

        (new ManufacturingModuleSeeder)->run();
    }

    public function down(): void
    {
        foreach (array_reverse(self::TABLES) as $t) {
            Schema::dropIfExists($t);
        }
    }
};
