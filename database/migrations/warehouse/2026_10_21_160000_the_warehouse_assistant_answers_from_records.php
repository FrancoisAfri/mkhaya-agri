<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WMS-140 to WMS-144: the warehouse assistant.
 *
 * A question is kept with the figures it was answered from, how long it took and what it cost; a
 * draft made from a recommendation is kept with a key that makes asking twice harmless; and users
 * can say when an answer was wrong or a recommendation useless, which is how its quality is measured.
 */
return new class extends Migration
{
    private const TENANT_TABLES = ['wms_assistant_queries', 'wms_assistant_actions', 'wms_assistant_feedback'];

    public function up(): void
    {
        Schema::create('wms_assistant_queries', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->text('question');
            $t->string('topic', 32)->nullable();
            $t->boolean('refused')->default(false);
            $t->jsonb('answer');
            $t->text('narration')->nullable();
            $t->string('narration_source', 10)->default('TEMPLATE'); // MODEL|TEMPLATE
            $t->unsignedInteger('latency_ms')->default(0);
            $t->unsignedInteger('tokens')->default(0);
            $t->decimal('cost', 12, 4)->default(0);
            $t->timestampsTz();
            $t->index(['company_id', 'created_at'], 'wms_assistant_q_created');
        });
        Schema::create('wms_assistant_actions', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $t->foreignId('query_id')->constrained('wms_assistant_queries')->cascadeOnDelete();
            $t->unsignedSmallInteger('rec_index');
            $t->string('kind', 20); // COUNT|TRANSFER_DRAFT|PURCHASE_REQUEST|INVESTIGATION|TASK
            $t->string('status', 10)->default('DRAFT'); // DRAFT|EXECUTED|CANCELLED
            $t->text('summary');
            $t->jsonb('payload')->nullable();
            $t->string('result_type', 32)->nullable();
            $t->string('result_reference', 64)->nullable();
            $t->string('idempotency_key', 120);
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampTz('decided_at')->nullable();
            $t->string('notes', 500)->nullable();
            $t->timestampsTz();
            $t->unique(['company_id', 'idempotency_key'], 'wms_assistant_action_once');
        });
        Schema::create('wms_assistant_feedback', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $t->foreignId('query_id')->constrained('wms_assistant_queries')->cascadeOnDelete();
            $t->unsignedSmallInteger('rec_index')->nullable();
            $t->string('verdict', 12); // CORRECT|INCORRECT|USEFUL|NOT_USEFUL
            $t->string('note', 1000)->nullable();
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampTz('created_at')->useCurrent();
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
