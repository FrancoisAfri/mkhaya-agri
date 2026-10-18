<?php

use App\Modules\Operations\Manufacturing\Services\ManufacturingReferenceData;
use Database\Seeders\ManufacturingModuleSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MFG-010 -- product recall.
 *
 * A recall starts from one lot, traces forward to every lot it went into, holds what is still in
 * stock, lists what has already gone out and to whom, and keeps a record of every action taken
 * until it is closed. The affected lots are stored as they were found, so the recall still shows
 * what it covered after the stock has moved on.
 */
return new class extends Migration
{
    private const TABLES = ['mfg_recalls', 'mfg_recall_lots', 'mfg_recall_actions'];

    public function up(): void
    {
        Schema::create('mfg_recalls', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('number', 48);
            $table->string('title');
            $table->text('reason');
            $table->string('severity', 8)->default('CLASS_II'); // CLASS_I|CLASS_II|CLASS_III
            $table->string('status', 10)->default('OPEN');      // OPEN|CLOSED
            $table->foreignId('origin_lot_id')->constrained('fin_stock_lots');
            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('opened_at');
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('closed_at')->nullable();
            $table->text('closure_notes')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'number'], 'mfg_recall_number_uq');
        });

        Schema::create('mfg_recall_lots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('recall_id')->constrained('mfg_recalls')->cascadeOnDelete();
            $table->foreignId('lot_id')->constrained('fin_stock_lots');
            $table->foreignId('item_id')->constrained('fin_stock_items');
            $table->unsignedSmallInteger('depth')->default(0);         // 0 = the origin lot
            $table->decimal('in_stock_at_open', 18, 4)->default(0);
            $table->decimal('dispatched_at_open', 18, 4)->default(0);
            $table->decimal('held', 18, 4)->default(0);
            $table->decimal('retrieved', 18, 4)->default(0);
            $table->decimal('disposed', 18, 4)->default(0);
            $table->decimal('released', 18, 4)->default(0);
            $table->timestamps();

            $table->unique(['recall_id', 'lot_id'], 'mfg_recall_lot_uq');
        });

        Schema::create('mfg_recall_actions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('recall_id')->constrained('mfg_recalls')->cascadeOnDelete();
            // HOLD|RELEASE|RETRIEVED|DISPOSED|CUSTOMER_NOTIFIED|REGULATOR_NOTIFIED|NOTE
            $table->string('kind', 20);
            $table->foreignId('lot_id')->nullable()->constrained('fin_stock_lots');
            $table->decimal('qty', 18, 4)->nullable();
            $table->string('reference')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('acted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('acted_at');
            $table->timestamps();
        });

        if (DB::getDriverName() === 'pgsql') {
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

        // The traceability and recall permissions, the Traceability ribbon, and the RECALL number
        // sequence for every company already numbering manufacturing documents.
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
    }
};
