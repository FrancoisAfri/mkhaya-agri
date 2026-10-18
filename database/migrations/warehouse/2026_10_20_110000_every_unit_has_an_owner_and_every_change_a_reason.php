<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WMS-001 (third-party stock), WMS-020 (reasons), WMS-024 (corrections with approval).
 *
 * Ownership sits on the item, not on each unit: in third-party logistics a SKU belongs to one
 * client, so `owner_party_id` on the item says whose goods every unit of it is, and `is_valued`
 * false keeps them off this company's books (WHMI decision 1). A unit-level owner would let the
 * same balance be half one company's asset and half another's, which no ledger can value.
 *
 * Client access is explicit (WMS-001): a person sees a client's stock only when granted that
 * client, or when they hold the permission to see every client.
 */
return new class extends Migration
{
    private const TENANT_TABLES = ['wms_reason_codes', 'wms_client_access', 'wms_stock_adjustments'];

    public function up(): void
    {
        Schema::table('fin_stock_items', function (Blueprint $t) {
            $t->foreignId('owner_party_id')->nullable()->constrained('fin_parties')->nullOnDelete();
            $t->boolean('is_valued')->default(true);
        });
        if (DB::getDriverName() === 'pgsql') {
            // A client's goods are never this company's asset.
            DB::statement('ALTER TABLE fin_stock_items ADD CONSTRAINT fin_items_owned_not_valued CHECK (owner_party_id IS NULL OR is_valued = false)');
        }

        Schema::create('wms_reason_codes', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $t->string('code', 32);
            $t->string('name', 120);
            $t->string('category', 16); // LOSS|DAMAGE|FOUND|COUNT|QUALITY|RETURN|SAMPLE|OTHER
            $t->string('direction', 4)->default('BOTH'); // IN|OUT|BOTH
            /** Adjustments above either limit wait for a second person's approval. */
            $t->decimal('approval_qty', 18, 4)->nullable();
            $t->decimal('approval_value', 18, 2)->nullable();
            $t->boolean('always_requires_approval')->default(false);
            $t->boolean('is_active')->default(true);
            $t->timestampsTz();
            $t->unique(['company_id', 'code'], 'wms_reason_code_uniq');
        });

        Schema::create('wms_client_access', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $t->foreignId('party_id')->constrained('fin_parties')->cascadeOnDelete();
            $t->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampsTz();
            $t->unique(['user_id', 'party_id'], 'wms_client_access_uniq');
        });

        Schema::create('wms_stock_adjustments', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $t->string('number', 24);
            $t->foreignId('warehouse_id')->constrained('fin_warehouses');
            $t->foreignId('item_id')->constrained('fin_stock_items');
            $t->foreignId('bin_id')->nullable()->constrained('fin_stock_bins');
            $t->foreignId('lot_id')->nullable()->constrained('fin_stock_lots');
            $t->string('stock_status', 20)->default('AVAILABLE');
            $t->decimal('qty_delta', 18, 4);
            $t->foreignId('txn_uom_id')->nullable()->constrained('mfg_uoms');
            $t->decimal('txn_qty', 18, 4)->nullable();
            $t->decimal('unit_cost', 18, 4)->default(0);
            $t->decimal('value_estimate', 18, 2)->default(0);
            $t->foreignId('reason_code_id')->constrained('wms_reason_codes');
            $t->text('notes')->nullable();
            $t->timestampTz('occurred_at');
            $t->string('status', 20)->default('PENDING_APPROVAL'); // PENDING_APPROVAL|POSTED|REJECTED|CANCELLED
            $t->jsonb('approval_reasons')->nullable();
            $t->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampTz('decided_at')->nullable();
            $t->text('decision_note')->nullable();
            $t->foreignId('movement_id')->nullable()->constrained('fin_stock_movements')->nullOnDelete();
            $t->timestampsTz();
            $t->unique(['company_id', 'number'], 'wms_adjustment_number_uniq');
            $t->index(['company_id', 'status'], 'wms_adjustment_status_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE wms_stock_adjustments ADD CONSTRAINT wms_adj_qty_nonzero CHECK (qty_delta <> 0)");
            DB::statement("ALTER TABLE wms_stock_adjustments ADD CONSTRAINT wms_adj_status_chk CHECK (status IN ('PENDING_APPROVAL','POSTED','REJECTED','CANCELLED'))");
            // Segregation of duties, in the schema: nobody approves their own adjustment.
            DB::statement("ALTER TABLE wms_stock_adjustments ADD CONSTRAINT wms_adj_four_eyes CHECK (decided_by IS NULL OR requested_by IS NULL OR decided_by <> requested_by OR status = 'CANCELLED')");

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
        Schema::dropIfExists('wms_stock_adjustments');
        Schema::dropIfExists('wms_client_access');
        Schema::dropIfExists('wms_reason_codes');
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE fin_stock_items DROP CONSTRAINT IF EXISTS fin_items_owned_not_valued');
        }
        Schema::table('fin_stock_items', function (Blueprint $t) {
            $t->dropConstrainedForeignId('owner_party_id');
            $t->dropColumn('is_valued');
        });
    }
};
