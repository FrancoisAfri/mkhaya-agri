<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MFG-008 -- the inventory foundation production relies on.
 *
 *   "If Mkhaya Hub does not yet have a central inventory ledger with these capabilities, that
 *    foundation must be implemented before production functionality relies on it."
 *
 * The ledger existed -- items, per-warehouse balances, movements posting journals, FIFO layers --
 * but it could not say WHERE stock was (bin), WHICH stock (lot, serial, expiry), or WHETHER it
 * could be used (quarantined, rejected, reserved). Every one of the spec's mandatory controls
 * depends on those three answers.
 *
 * This extends that ledger rather than building beside it. Control 7: "Manufacturing must not
 * create a second independent stock balance for the same inventory item." fin_stock_positions is
 * a BREAKDOWN of fin_stock_balances, maintained by the same function in the same transaction --
 * the sum of an item's positions in a warehouse is always that warehouse's balance, and a test
 * holds it to that. It is not a second answer; it is the first answer in more detail.
 */
return new class extends Migration
{
    private const TENANT_TABLES = [
        'fin_stock_bins', 'fin_stock_lots', 'fin_stock_positions', 'fin_stock_reservations',
    ];

    public function up(): void
    {
        // ── Where: bins inside a warehouse ────────────────────────────────────────────────────
        Schema::create('fin_stock_bins', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            // Nullable like the rest of the stock ledger: Finance supports a company-agnostic
            // standalone book, and its stock items carry no company.
            $table->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $table->foreignId('entity_id')->nullable()->constrained('fin_entities')->nullOnDelete();
            $table->foreignId('warehouse_id')->constrained('fin_warehouses')->cascadeOnDelete();
            $table->string('code', 32);
            $table->string('name')->nullable();

            /*
             * What the bin is FOR. A quarantine bin holds stock nobody may issue; a staging bin
             * holds stock picked for an order. The status on the stock is what is enforced -- the
             * kind is what a picker is told when choosing where to put something.
             */
            $table->string('kind', 16)->default('STORAGE'); // STORAGE|QUARANTINE|STAGING|PRODUCTION|DISPATCH
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        DB::statement('CREATE UNIQUE INDEX fin_bins_code_uniq ON fin_stock_bins (company_id, warehouse_id, code)');

        // ── Which: lots, batches and serial numbers ───────────────────────────────────────────
        Schema::create('fin_stock_lots', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            // Nullable like the rest of the stock ledger: Finance supports a company-agnostic
            // standalone book, and its stock items carry no company.
            $table->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $table->foreignId('entity_id')->nullable()->constrained('fin_entities')->nullOnDelete();
            $table->foreignId('item_id')->constrained('fin_stock_items')->cascadeOnDelete();
            $table->string('lot_number', 64);

            /** A serial number is a lot of exactly one; the kind says which rules apply. */
            $table->string('kind', 8)->default('LOT'); // LOT|SERIAL

            $table->date('manufactured_on')->nullable();
            $table->date('expires_on')->nullable();

            /*
             * Where the lot came from -- the first link in the traceability chain (MFG-010). A
             * supplier lot carries the supplier and its own batch reference; a production lot
             * carries the order that made it.
             */
            $table->string('origin', 16)->default('RECEIPT'); // RECEIPT|PRODUCTION|SPLIT|MERGE|RETURN|ADJUSTMENT
            $table->foreignId('supplier_id')->nullable();
            $table->string('supplier_lot_number', 64)->nullable();
            $table->string('source_type', 64)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'item_id', 'expires_on'], 'fin_lots_expiry_idx');
        });
        DB::statement('CREATE UNIQUE INDEX fin_lots_number_uniq ON fin_stock_lots (company_id, item_id, lot_number)');

        // ── The breakdown of a balance: item × warehouse × bin × lot × status ─────────────────
        Schema::create('fin_stock_positions', function (Blueprint $table) {
            $table->id();
            // Nullable like the rest of the stock ledger: Finance supports a company-agnostic
            // standalone book, and its stock items carry no company.
            $table->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $table->foreignId('entity_id')->nullable()->constrained('fin_entities')->nullOnDelete();
            $table->foreignId('item_id')->constrained('fin_stock_items')->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained('fin_warehouses')->cascadeOnDelete();
            $table->foreignId('bin_id')->nullable()->constrained('fin_stock_bins')->cascadeOnDelete();
            $table->foreignId('lot_id')->nullable()->constrained('fin_stock_lots')->cascadeOnDelete();

            /*
             * Stored, not derived: quarantine and rejection are decisions somebody made and must
             * be undone deliberately. Expiry is the exception -- it is a property of the lot and
             * of the date, so "expired" is computed rather than written, and stock can never be
             * left available by a nightly job that did not run.
             */
            $table->string('status', 16)->default('AVAILABLE'); // AVAILABLE|QUARANTINE|ON_HOLD|REJECTED

            $table->decimal('qty', 18, 4)->default(0);
            $table->timestamps();

            $table->index(['company_id', 'item_id', 'status'], 'fin_pos_item_status_idx');
        });
        DB::statement(
            'CREATE UNIQUE INDEX fin_pos_uniq ON fin_stock_positions '
            .'(item_id, warehouse_id, COALESCE(bin_id, 0), COALESCE(lot_id, 0), status)'
        );

        // ── Promised, not yet moved: reservations ─────────────────────────────────────────────
        Schema::create('fin_stock_reservations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            // Nullable like the rest of the stock ledger: Finance supports a company-agnostic
            // standalone book, and its stock items carry no company.
            $table->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $table->foreignId('entity_id')->nullable()->constrained('fin_entities')->nullOnDelete();
            $table->foreignId('item_id')->constrained('fin_stock_items')->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained('fin_warehouses')->cascadeOnDelete();
            $table->foreignId('lot_id')->nullable()->constrained('fin_stock_lots')->cascadeOnDelete();
            $table->decimal('qty', 18, 4);

            /** Issued against it so far. Remaining = qty - qty_consumed, never stored twice. */
            $table->decimal('qty_consumed', 18, 4)->default(0);

            $table->string('source_type', 64);
            $table->unsignedBigInteger('source_id');
            $table->string('status', 12)->default('OPEN'); // OPEN|CONSUMED|RELEASED
            $table->text('release_reason')->nullable();
            $table->timestampTz('released_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'item_id', 'warehouse_id', 'status'], 'fin_res_avail_idx');
            $table->index(['source_type', 'source_id'], 'fin_res_source_idx');
        });

        /*
         * One open reservation per source, item, warehouse and lot. MFG-005: "MRP must not
         * double-count stock already allocated" -- two open reservations for the same order line
         * would hold the stock twice and report a shortage that does not exist.
         */
        DB::statement(
            'CREATE UNIQUE INDEX fin_res_open_uniq ON fin_stock_reservations '
            ."(source_type, source_id, item_id, warehouse_id, COALESCE(lot_id, 0)) WHERE status = 'OPEN'"
        );

        // ── Movements say which stock, where, why, and whether they undo another ─────────────
        Schema::table('fin_stock_movements', function (Blueprint $table) {
            $table->foreignId('lot_id')->nullable()->constrained('fin_stock_lots')->nullOnDelete();
            $table->foreignId('bin_id')->nullable()->constrained('fin_stock_bins')->nullOnDelete();
            $table->foreignId('to_bin_id')->nullable()->constrained('fin_stock_bins')->nullOnDelete();
            $table->string('stock_status', 16)->nullable();
            $table->string('to_stock_status', 16)->nullable();

            /** Control 2/3: what authorised the movement -- a production order, an adjustment. */
            $table->string('source_type', 64)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();

            /** Control 5: a retried request finds the movement it already made. */
            $table->string('idempotency_key', 120)->nullable();

            /** Control 6: a correction is a new movement pointing at the one it undoes. */
            $table->foreignId('reversal_of_id')->nullable()->constrained('fin_stock_movements')->nullOnDelete();

            $table->index(['source_type', 'source_id'], 'fin_mov_source_idx');
            $table->index(['company_id', 'lot_id'], 'fin_mov_lot_idx');
        });
        DB::statement(
            'CREATE UNIQUE INDEX fin_mov_idem_uniq ON fin_stock_movements (company_id, idempotency_key) '
            .'WHERE idempotency_key IS NOT NULL'
        );
        // A movement is reversed at most once, or the correction itself double-counts.
        DB::statement(
            'CREATE UNIQUE INDEX fin_mov_reversal_uniq ON fin_stock_movements (reversal_of_id) '
            .'WHERE reversal_of_id IS NOT NULL'
        );

        $this->tenantRules();
        $this->backfillPositions();
        $this->guardPostedMovements();
        $this->addManufacturingAccounts();
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS fin_stock_movements_immutable ON fin_stock_movements');
            DB::unprepared('DROP FUNCTION IF EXISTS fin_refuse_movement_edit()');
        }

        DB::statement('DROP INDEX IF EXISTS fin_mov_idem_uniq');
        DB::statement('DROP INDEX IF EXISTS fin_mov_reversal_uniq');

        Schema::table('fin_stock_movements', function (Blueprint $table) {
            $table->dropIndex('fin_mov_source_idx');
            $table->dropIndex('fin_mov_lot_idx');
            foreach (['reversal_of_id', 'to_bin_id', 'bin_id', 'lot_id'] as $fk) {
                $table->dropConstrainedForeignId($fk);
            }
            $table->dropColumn(['stock_status', 'to_stock_status', 'source_type', 'source_id', 'idempotency_key']);
        });

        foreach (['fin_stock_reservations', 'fin_stock_positions', 'fin_stock_lots', 'fin_stock_bins'] as $t) {
            Schema::dropIfExists($t);
        }
    }

    /** RLS, and the same "a tenant row belongs to one of its businesses" CHECK as the rest of the stock ledger. */
    private function tenantRules(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $session = "NULLIF(current_setting('app.company_id', true), '')";
        $policy = "{$session} IS NULL OR company_id = {$session}::bigint";

        foreach (self::TENANT_TABLES as $table) {
            DB::statement("ALTER TABLE {$table} ALTER COLUMN company_id SET DEFAULT {$session}::bigint");
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            DB::statement("DROP POLICY IF EXISTS tenant_isolation ON {$table}");
            DB::statement("CREATE POLICY tenant_isolation ON {$table} USING ({$policy}) WITH CHECK ({$policy})");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_tenant_row_has_a_business "
                .'CHECK (company_id IS NULL OR entity_id IS NOT NULL) NOT VALID');
        }
    }

    /**
     * Every existing balance becomes one available, un-binned, un-lotted position of the same
     * quantity, so the breakdown agrees with the balance from the first moment it exists.
     */
    private function backfillPositions(): void
    {
        DB::statement(<<<'SQL'
            INSERT INTO fin_stock_positions (company_id, entity_id, item_id, warehouse_id, bin_id, lot_id, status, qty, created_at, updated_at)
            SELECT b.company_id, COALESCE(b.entity_id, i.entity_id), b.item_id, b.warehouse_id, NULL, NULL, 'AVAILABLE', b.qty, now(), now()
            FROM fin_stock_balances b
            JOIN fin_stock_items i ON i.id = b.item_id
        SQL);
    }

    /**
     * Control 6: "Corrections to posted movements must use reversal or adjustment transactions."
     *
     * In the database, because a movement that can be edited in place is a ledger that can be
     * rewritten, and no service method can promise nobody will write the row directly. A delete
     * cascading from a removed company is still allowed: pg_trigger_depth() is above 1 when the
     * delete arrives from a foreign key action rather than from a statement.
     */
    private function guardPostedMovements(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION fin_refuse_movement_edit() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF pg_trigger_depth() > 1 THEN
                        RETURN OLD;
                    END IF;
                    RAISE EXCEPTION 'Stock movement % is posted; reverse it instead of deleting it', OLD.id;
                END IF;

                IF NEW.item_id IS DISTINCT FROM OLD.item_id
                   OR NEW.qty IS DISTINCT FROM OLD.qty
                   OR NEW.value IS DISTINCT FROM OLD.value
                   OR NEW.unit_cost IS DISTINCT FROM OLD.unit_cost
                   OR NEW.type IS DISTINCT FROM OLD.type
                   OR NEW.warehouse_id IS DISTINCT FROM OLD.warehouse_id
                   OR NEW.lot_id IS DISTINCT FROM OLD.lot_id
                   OR NEW.bin_id IS DISTINCT FROM OLD.bin_id
                   OR NEW.movement_date IS DISTINCT FROM OLD.movement_date
                   OR NEW.reversal_of_id IS DISTINCT FROM OLD.reversal_of_id THEN
                    RAISE EXCEPTION 'Stock movement % is posted; reverse it instead of editing it', OLD.id;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER fin_stock_movements_immutable
                BEFORE UPDATE OR DELETE ON fin_stock_movements
                FOR EACH ROW EXECUTE FUNCTION fin_refuse_movement_edit();
        SQL);
    }

    /**
     * Material issued to production is not a cost of sale yet -- it is work in progress, and it
     * becomes finished-goods inventory when the order completes. Without a WIP account an issue
     * to production would land straight in the P&L. Variance and scrap are where the difference
     * between what an order should have cost and what it did cost goes.
     *
     * Added wherever a chart already has Inventory (1300), for the same company and business, and
     * to the platform's base chart. Query builder only: this runs with no tenant applied.
     */
    private function addManufacturingAccounts(): void
    {
        $accounts = [
            ['1310', 'Work in Progress', 'asset', 'debit'],
            // 5600/5610, not 5100/5110: Project Management's mapping posts project material
            // costs to the historic 5100, and a Production Variance account there would
            // swallow every project's materials.
            ['5600', 'Production Variance', 'expense', 'debit'],
            ['5610', 'Scrap & Spoilage', 'expense', 'debit'],
        ];

        $charts = DB::table('fin_accounts')->where('code', '1300')->get(['company_id', 'entity_id']);

        foreach ($charts as $chart) {
            foreach ($accounts as [$code, $name, $type, $normal]) {
                $exists = DB::table('fin_accounts')
                    ->where('code', $code)
                    ->where(fn ($q) => $chart->company_id === null ? $q->whereNull('company_id') : $q->where('company_id', $chart->company_id))
                    ->where(fn ($q) => $chart->entity_id === null ? $q->whereNull('entity_id') : $q->where('entity_id', $chart->entity_id))
                    ->exists();

                if ($exists) {
                    continue;
                }

                DB::table('fin_accounts')->insert([
                    'uuid' => (string) \Illuminate\Support\Str::uuid(),
                    'company_id' => $chart->company_id, 'entity_id' => $chart->entity_id,
                    'code' => $code, 'name' => $name, 'type' => $type, 'normal_balance' => $normal,
                    'is_postable' => true, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }
};
