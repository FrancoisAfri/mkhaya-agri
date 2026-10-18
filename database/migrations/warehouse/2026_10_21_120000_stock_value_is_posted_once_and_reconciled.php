<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * WMS-100 to WMS-103: valuation and the books.
 *
 * Costing (weighted average, FIFO layers, standard) and journals are Finance's and stay there; the
 * warehouse adds what the books need from it: a treatment per inventory event that finance approves
 * (who owns it, which accounts, whether it posts), landed costs and revaluations as documents that
 * post once, and an exception queue that reconciles the stock ledger with the general ledger.
 *
 * Accounts come from Finance's account map (module "warehouse"), so a tenant whose chart differs
 * maps its own; the three this introduces are added to every chart that has an inventory account.
 */
return new class extends Migration
{
    private const TENANT_TABLES = ['wms_accounting_rules', 'wms_accounting_issues', 'wms_landed_costs', 'wms_landed_cost_lines', 'wms_revaluations'];

    private const ACCOUNTS = [
        ['5620', 'Inventory Adjustments', 'expense', 'debit'],
        ['5630', 'Inventory Revaluation', 'expense', 'debit'],
        ['2160', 'Landed Cost Clearing', 'liability', 'credit'],
    ];

    private const MAPPINGS = [
        ['adjustment', '5620', 'Stock adjustments and write-offs (the other side of inventory)'],
        ['revaluation', '5630', 'Inventory revaluation gains and losses'],
        ['landed_cost_clearing', '2160', 'Freight, duty and other landed costs awaiting their invoices'],
        ['cogs', '5000', 'Cost of goods dispatched'],
        ['grni', '2150', 'Goods received not invoiced'],
    ];

    public function up(): void
    {
        Schema::create('wms_accounting_rules', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $t->string('event', 32);
            $t->string('owner', 16); // WAREHOUSE|PROCUREMENT|FINANCE|MANUFACTURING
            $t->string('treatment', 8); // POST|MEMO|NONE
            $t->string('debit', 40)->nullable();
            $t->string('credit', 40)->nullable();
            $t->text('description');
            $t->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampTz('approved_at')->nullable();
            $t->timestampTz('suspended_at')->nullable();
            $t->string('suspended_reason', 500)->nullable();
            $t->timestampsTz();
            $t->unique(['company_id', 'event'], 'wms_acc_rule_uniq');
        });

        Schema::create('wms_accounting_issues', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $t->string('kind', 32);
            $t->string('severity', 8)->default('MEDIUM');
            $t->string('reference', 120); // stable key: one open issue per thing that disagrees
            $t->text('message');
            $t->decimal('amount', 18, 2)->default(0);
            $t->jsonb('detail')->nullable();
            $t->string('status', 10)->default('OPEN'); // OPEN|RESOLVED|ACCEPTED
            $t->text('resolution')->nullable();
            $t->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampTz('resolved_at')->nullable();
            $t->timestampTz('last_seen_at')->nullable();
            $t->timestampsTz();
        });
        DB::statement("CREATE UNIQUE INDEX wms_acc_issue_open ON wms_accounting_issues (company_id, reference) WHERE status = 'OPEN'");

        Schema::create('wms_landed_costs', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $t->string('number', 24);
            $t->foreignId('grn_id')->nullable()->constrained('goods_received_notes')->nullOnDelete();
            $t->foreignId('party_id')->nullable()->constrained('fin_parties')->nullOnDelete();
            $t->string('invoice_reference', 120)->nullable();
            $t->string('basis', 8)->default('VALUE'); // VALUE|QTY|WEIGHT
            $t->jsonb('components'); // [{kind, description, amount}]
            $t->decimal('total', 18, 2);
            $t->string('status', 10)->default('DRAFT'); // DRAFT|POSTED|CANCELLED
            $t->foreignId('journal_id')->nullable()->constrained('fin_journals')->nullOnDelete();
            $t->decimal('to_inventory', 18, 2)->default(0);
            $t->decimal('to_cogs', 18, 2)->default(0);
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampTz('posted_at')->nullable();
            $t->timestampsTz();
            $t->unique(['company_id', 'number'], 'wms_landed_number_uniq');
        });
        Schema::create('wms_landed_cost_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $t->foreignId('landed_cost_id')->constrained('wms_landed_costs')->cascadeOnDelete();
            $t->foreignId('item_id')->constrained('fin_stock_items');
            $t->foreignId('movement_id')->nullable()->constrained('fin_stock_movements')->nullOnDelete();
            $t->decimal('qty', 18, 4);
            $t->decimal('base_value', 18, 2)->default(0);
            $t->decimal('weight_kg', 18, 4)->default(0);
            $t->decimal('allocated', 18, 2)->default(0);
            $t->decimal('to_inventory', 18, 2)->default(0);
            $t->decimal('to_cogs', 18, 2)->default(0);
            $t->timestampsTz();
        });

        Schema::create('wms_revaluations', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $t->string('number', 24);
            $t->foreignId('item_id')->constrained('fin_stock_items');
            $t->decimal('qty', 18, 4);
            $t->decimal('old_cost', 18, 4);
            $t->decimal('new_cost', 18, 4);
            $t->decimal('delta', 18, 2);
            $t->text('reason');
            $t->string('status', 18)->default('PENDING_APPROVAL'); // PENDING_APPROVAL|POSTED|REJECTED
            $t->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampTz('decided_at')->nullable();
            $t->string('decision_note', 500)->nullable();
            $t->foreignId('journal_id')->nullable()->constrained('fin_journals')->nullOnDelete();
            $t->timestampsTz();
            $t->unique(['company_id', 'number'], 'wms_reval_number_uniq');
        });
        DB::statement('ALTER TABLE wms_revaluations ADD CONSTRAINT wms_reval_four_eyes CHECK (decided_by IS NULL OR requested_by IS NULL OR decided_by <> requested_by)');

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

        $this->addAccounts();
        foreach (self::MAPPINGS as [$key, $code, $label]) {
            DB::table('fin_account_mappings')->updateOrInsert(['company_id' => null, 'entity_id' => null, 'source_module' => 'warehouse', 'key' => $key],
                ['uuid' => (string) Str::uuid(), 'account_code' => $code, 'label' => $label, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        DB::table('fin_account_mappings')->whereNull('company_id')->where('source_module', 'warehouse')->delete();
        foreach (array_reverse(self::TENANT_TABLES) as $t) {
            Schema::dropIfExists($t);
        }
    }

    /** The accounts above, in every chart that has an inventory account. */
    private function addAccounts(): void
    {
        foreach (DB::table('fin_accounts')->where('code', '1300')->get(['company_id', 'entity_id']) as $chart) {
            foreach (self::ACCOUNTS as [$code, $name, $type, $normal]) {
                $exists = DB::table('fin_accounts')->where('code', $code)
                    ->where(fn ($q) => $chart->company_id === null ? $q->whereNull('company_id') : $q->where('company_id', $chart->company_id))
                    ->where(fn ($q) => $chart->entity_id === null ? $q->whereNull('entity_id') : $q->where('entity_id', $chart->entity_id))
                    ->exists();
                if (! $exists) {
                    DB::table('fin_accounts')->insert([
                        'uuid' => (string) Str::uuid(), 'company_id' => $chart->company_id, 'entity_id' => $chart->entity_id,
                        'code' => $code, 'name' => $name, 'type' => $type, 'normal_balance' => $normal,
                        'is_postable' => true, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }
        }
    }
};
