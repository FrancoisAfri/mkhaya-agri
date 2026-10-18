<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WMS-150 to WMS-154: scanners, labels and printers, and work done without a signal.
 *
 * An action recorded on a device while offline arrives later, perhaps more than once. Each is kept
 * by its device-made identifier with when it happened on the device and when it arrived, so a
 * repeat is recognised and answered with the first result, and one that no longer fits -- the count
 * closed, the task done by somebody else, the stock gone -- waits for a person instead of being
 * forced through.
 */
return new class extends Migration
{
    private const TENANT_TABLES = ['wms_offline_actions', 'wms_label_templates', 'wms_printers'];

    public function up(): void
    {
        Schema::create('wms_offline_actions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('client_ref', 64);
            $t->string('kind', 20); // COUNT_ENTRY|PICK_CONFIRM|PUTAWAY_COMPLETE
            $t->jsonb('payload');
            $t->timestampTz('recorded_at'); // on the device
            $t->timestampTz('received_at'); // here
            $t->string('status', 10); // APPLIED|CONFLICT|REJECTED|RESOLVED|DISCARDED
            $t->text('message')->nullable();
            $t->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampTz('resolved_at')->nullable();
            $t->timestampsTz();
            $t->unique(['company_id', 'client_ref'], 'wms_offline_once');
        });
        Schema::create('wms_label_templates', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $t->string('kind', 16); // PRODUCT|BIN|PALLET|CARTON|HANDLING_UNIT|DISPATCH
            $t->string('name', 120);
            $t->decimal('width_mm', 6, 1)->default(100);
            $t->decimal('height_mm', 6, 1)->default(50);
            $t->string('symbology', 12)->default('CODE128'); // CODE128|GS1_128|QR
            $t->jsonb('fields'); // which facts to print, in order
            $t->boolean('is_default')->default(false);
            $t->timestampsTz();
        });
        Schema::create('wms_printers', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $t->foreignId('warehouse_id')->nullable()->constrained('fin_warehouses')->nullOnDelete();
            $t->string('name', 120);
            $t->string('language', 6)->default('ZPL'); // ZPL
            $t->string('host', 64); // private network address only
            $t->unsignedInteger('port')->default(9100);
            $t->unsignedSmallInteger('dpi')->default(203);
            $t->boolean('is_active')->default(true);
            $t->timestampsTz();
        });

        if (DB::getDriverName() === 'pgsql') {
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
        foreach (array_reverse(self::TENANT_TABLES) as $t) {
            Schema::dropIfExists($t);
        }
    }
};
