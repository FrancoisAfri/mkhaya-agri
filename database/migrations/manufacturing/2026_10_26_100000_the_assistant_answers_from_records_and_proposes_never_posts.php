<?php

use Database\Seeders\ManufacturingModuleSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MFG-019 -- the Mkhaya AI manufacturing assistant.
 *
 * Every figure in an answer is computed from the company's own records by code, with the period
 * and the source records beside it; a language model may only word what was computed, and its
 * wording is thrown away if it contains a number that is not in the figures. What the assistant
 * recommends is recorded, with the measure it should move and that measure's value at the time,
 * so the outcome can be checked later against what actually happened.
 *
 * The assistant may draft a task or an investigation request. Anything that would buy, move stock,
 * post money or change production is a proposed action that waits for somebody holding the right
 * permission -- never the person who asked -- to approve it, and then goes through the same
 * services a person would use. All three tables are tenant tables under row-level security, and
 * nothing here is cached across companies.
 */
return new class extends Migration
{
    private const TABLES = ['mfg_assistant_queries', 'mfg_assistant_recommendations', 'mfg_assistant_actions'];

    public function up(): void
    {
        Schema::create('mfg_assistant_queries', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('question');
            $table->string('topic', 32)->nullable();
            $table->date('period_from')->nullable();
            $table->date('period_to')->nullable();
            $table->boolean('refused')->default(false);   // the user's permissions did not cover the figures
            $table->jsonb('answer')->nullable();          // the computed figures, sources and flags
            $table->text('narration')->nullable();
            $table->string('narration_source', 12)->nullable(); // MODEL|TEMPLATE
            $table->timestamps();
        });

        Schema::create('mfg_assistant_recommendations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('query_id')->constrained('mfg_assistant_queries')->cascadeOnDelete();
            $table->string('topic', 32);
            $table->text('text');
            $table->string('subject_type', 32)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('subject_label')->nullable();
            $table->string('url')->nullable();
            // What it should move, and where that stood when it was made.
            $table->string('metric', 32)->nullable();
            $table->decimal('baseline_value', 18, 4)->nullable();
            $table->date('baseline_from')->nullable();
            $table->date('baseline_to')->nullable();
            $table->string('status', 16)->default('PROPOSED'); // PROPOSED|ACTED|DISMISSED
            $table->decimal('measured_value', 18, 4)->nullable();
            $table->timestampTz('measured_at')->nullable();
            $table->text('outcome')->nullable();
            $table->timestamps();
        });

        Schema::create('mfg_assistant_actions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('recommendation_id')->nullable()->constrained('mfg_assistant_recommendations')->nullOnDelete();
            $table->string('kind', 24);                     // TASK|INVESTIGATION|PURCHASE_REQUEST|PRIORITISE_ORDER
            $table->string('status', 12)->default('DRAFT'); // DRAFT|APPROVED|EXECUTED|REJECTED|DONE
            $table->text('summary');
            $table->jsonb('payload')->nullable();
            $table->string('requires_permission')->nullable();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('due_date')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('decided_at')->nullable();
            $table->text('decision_notes')->nullable();
            $table->string('result_type', 32)->nullable();
            $table->unsignedBigInteger('result_id')->nullable();
            $table->string('result_reference')->nullable();
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

        (new ManufacturingModuleSeeder)->run();
    }

    public function down(): void
    {
        foreach (array_reverse(self::TABLES) as $t) {
            Schema::dropIfExists($t);
        }
    }
};
