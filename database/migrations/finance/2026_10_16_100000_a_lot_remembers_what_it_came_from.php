<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lot genealogy for splits and merges (MFG-010).
 *
 * Production genealogy needs no table of its own: a lot issued to an order and a lot received from
 * it are both movements naming that order, so "what went in" and "what came out" are already in
 * the ledger. A split or a merge leaves no such trail -- stock simply changes lot -- so it is
 * recorded twice over: as a movement (`relot`, naming both lots) and as a parent/child link with
 * the quantity that passed between them. Traceability walks the links; the ledger proves them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fin_stock_movements', function (Blueprint $table) {
            $table->foreignId('to_lot_id')->nullable()->after('lot_id')->constrained('fin_stock_lots')->nullOnDelete();
        });

        Schema::create('fin_stock_lot_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $table->foreignId('entity_id')->nullable()->constrained('fin_entities')->nullOnDelete();
            $table->foreignId('parent_lot_id')->constrained('fin_stock_lots')->cascadeOnDelete();
            $table->foreignId('child_lot_id')->constrained('fin_stock_lots')->cascadeOnDelete();
            $table->string('kind', 8); // SPLIT|MERGE
            $table->decimal('qty', 18, 4);
            $table->foreignId('movement_id')->nullable()->constrained('fin_stock_movements')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('parent_lot_id', 'fin_lot_links_parent_idx');
            $table->index('child_lot_id', 'fin_lot_links_child_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            $session = "NULLIF(current_setting('app.company_id', true), '')";
            // The same policy as the other stock tables (positions, lots, reservations).
            $policy = "{$session} IS NULL OR company_id = {$session}::bigint";
            DB::statement("ALTER TABLE fin_stock_lot_links ALTER COLUMN company_id SET DEFAULT {$session}::bigint");
            DB::statement('ALTER TABLE fin_stock_lot_links ENABLE ROW LEVEL SECURITY');
            DB::statement('ALTER TABLE fin_stock_lot_links FORCE ROW LEVEL SECURITY');
            DB::statement('DROP POLICY IF EXISTS tenant_isolation ON fin_stock_lot_links');
            DB::statement("CREATE POLICY tenant_isolation ON fin_stock_lot_links USING ({$policy}) WITH CHECK ({$policy})");

            // A tenant's row always sits in one of its businesses, as on every other finance table.
            DB::statement('ALTER TABLE fin_stock_lot_links ADD CONSTRAINT fin_stock_lot_links_tenant_row_has_a_business '
                .'CHECK (company_id IS NULL OR entity_id IS NOT NULL)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_stock_lot_links');
        Schema::table('fin_stock_movements', fn (Blueprint $t) => $t->dropConstrainedForeignId('to_lot_id'));
    }
};
