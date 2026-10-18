<?php

use Database\Seeders\ManufacturingModuleSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MFG-001.09 and its acceptance criterion:
 *
 *   "A user assigned to Factory A must not access Factory B data unless explicitly authorised.
 *    Company-level isolation must apply regardless of the factory selected."
 *
 * Company isolation is row-level security and was already in place. This is the layer inside it: a
 * company runs several plants, and the people at one are not entitled to the books of another.
 *
 * An assignment names a user and a factory, and optionally a department -- "by company, factory
 * and department". With a department it confines the person to that department's work centres
 * within the factory; without one, to the whole factory.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mfg_factory_access', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('factory_id')->constrained('mfg_factories')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            /** NULL is the whole factory, not "no department". */
            $table->foreignId('department_id')->nullable()
                ->constrained('mfg_departments')->cascadeOnDelete();

            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'user_id'], 'mfg_fa_user_idx');
            $table->index(['company_id', 'factory_id'], 'mfg_fa_factory_idx');
        });

        // One row per person, factory and department. A NULL department is "the whole factory",
        // and Postgres never collides two NULLs, so a plain unique key would allow the same
        // whole-factory grant to be written twice.
        DB::statement(
            'CREATE UNIQUE INDEX mfg_fa_uniq ON mfg_factory_access '
            .'(company_id, user_id, factory_id, COALESCE(department_id, 0))'
        );

        if (DB::getDriverName() === 'pgsql') {
            $session = "NULLIF(current_setting('app.company_id', true), '')";
            $policy = "{$session} IS NULL OR company_id = {$session}::bigint";

            DB::statement("ALTER TABLE mfg_factory_access ALTER COLUMN company_id SET DEFAULT {$session}::bigint");
            DB::statement('ALTER TABLE mfg_factory_access ENABLE ROW LEVEL SECURITY');
            DB::statement('ALTER TABLE mfg_factory_access FORCE ROW LEVEL SECURITY');
            DB::statement('DROP POLICY IF EXISTS tenant_isolation ON mfg_factory_access');
            DB::statement("CREATE POLICY tenant_isolation ON mfg_factory_access USING ({$policy}) WITH CHECK ({$policy})");
        }

        // Creates mfg.factory.all and grants it to the platform admin roles. Idempotent.
        (new ManufacturingModuleSeeder)->run();
    }

    public function down(): void
    {
        Schema::dropIfExists('mfg_factory_access');
    }
};
