<?php

use App\Modules\Operations\Manufacturing\Services\ManufacturingReferenceData;
use Database\Seeders\ManufacturingModuleSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MFG-024 -- multi-factory operations inside one company.
 *
 * A factory's inventory is the warehouses it is given (mfg_factory_warehouses, besides its default).
 * Master data is shared across a company's factories unless a policy says otherwise: a product set
 * to LISTED may only be made at the factories listed for it. Stock moves between factories through
 * a transfer order -- requested, approved, shipped (held at the destination while in transit, so
 * nobody uses what has not arrived) and received -- whose movements are warehouse transfers: value
 * stays in inventory, so no journal (the INTER_FACTORY_TRANSFER treatment).
 *
 * Everything here is inside one company. Nothing reaches across companies: a transfer between two
 * companies is a sale in one and a purchase in the other, in their own books.
 */
return new class extends Migration
{
    private const TABLES = ['mfg_factory_warehouses', 'mfg_item_factories', 'mfg_transfers', 'mfg_transfer_lines'];

    public function up(): void
    {
        Schema::create('mfg_factory_warehouses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('factory_id')->constrained('mfg_factories')->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained('fin_warehouses')->cascadeOnDelete();
            $table->string('role', 12)->default('GENERAL');   // STORES|FINISHED|GENERAL
            $table->timestamps();

            $table->unique(['factory_id', 'warehouse_id'], 'mfg_factory_warehouse_uq');
        });

        Schema::table('mfg_items', function (Blueprint $table) {
            $table->string('factory_scope', 6)->default('ALL');   // ALL|LISTED
        });

        Schema::create('mfg_item_factories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('mfg_items')->cascadeOnDelete();
            $table->foreignId('factory_id')->constrained('mfg_factories')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['item_id', 'factory_id'], 'mfg_item_factory_uq');
        });

        Schema::create('mfg_transfers', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('number', 40);
            $table->foreignId('from_factory_id')->constrained('mfg_factories');
            $table->foreignId('to_factory_id')->constrained('mfg_factories');
            $table->foreignId('from_warehouse_id')->constrained('fin_warehouses');
            $table->foreignId('to_warehouse_id')->constrained('fin_warehouses');
            $table->string('status', 12)->default('REQUESTED');   // REQUESTED|APPROVED|SHIPPED|RECEIVED|CANCELLED
            $table->date('needed_by')->nullable();
            $table->text('reason')->nullable();
            $table->foreignId('production_order_id')->nullable()->constrained('mfg_production_orders')->nullOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->foreignId('shipped_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('shipped_at')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('received_at')->nullable();
            $table->text('discrepancy')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'number'], 'mfg_transfer_number_uq');
        });

        Schema::create('mfg_transfer_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('transfer_id')->constrained('mfg_transfers')->cascadeOnDelete();
            $table->foreignId('stock_item_id')->constrained('fin_stock_items');
            $table->foreignId('lot_id')->nullable()->constrained('fin_stock_lots')->nullOnDelete();
            $table->decimal('qty_requested', 18, 6);
            $table->decimal('qty_shipped', 18, 6)->default(0);
            $table->decimal('qty_received', 18, 6)->default(0);
            $table->foreignId('shipment_movement_id')->nullable()->constrained('fin_stock_movements')->nullOnDelete();
            $table->timestamps();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE mfg_items ADD CONSTRAINT mfg_items_factory_scope_chk CHECK (factory_scope IN ('ALL','LISTED'))");
            DB::statement('ALTER TABLE mfg_transfers ADD CONSTRAINT mfg_transfer_between_factories_chk CHECK (from_factory_id <> to_factory_id)');
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
        $installer = app(ManufacturingReferenceData::class);
        foreach (DB::table('mfg_number_sequences')->whereNotNull('company_id')->distinct()->pluck('company_id') as $companyId) {
            $installer->installFor((int) $companyId);
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::TABLES) as $t) {
            Schema::dropIfExists($t);
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE mfg_items DROP CONSTRAINT IF EXISTS mfg_items_factory_scope_chk');
        }
        Schema::table('mfg_items', fn (Blueprint $table) => $table->dropColumn('factory_scope'));
    }
};
