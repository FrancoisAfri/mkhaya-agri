<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A shared onboarding template catalogue every tenant can read.
 *
 * The templates belong to nobody: `company_id IS NULL` means "the platform ships this". The
 * existing `tenant_isolation` policy is `session IS NULL OR company_id = session`, under which a
 * NULL row evaluates `NULL = 5` → NULL → not true, so a global template would have been invisible
 * to every tenant. That is why this needs a migration rather than just a seeder.
 *
 * The fix is an additional PERMISSIVE policy rather than a rewrite of the existing one, because
 * permissive policies are OR'ed and the split falls exactly where it should:
 *
 *   SELECT  → tenant_isolation OR global_catalogue_read   (own rows + the shared catalogue)
 *   INSERT  ┐
 *   UPDATE  ├→ tenant_isolation only                      (own rows, and nothing else)
 *   DELETE  ┘
 *
 * So a tenant reads the catalogue and cannot edit, delete or impersonate it — copying a global
 * template into their own company is the only way to change one, which is the intended flow.
 * Seeding still works because the console has no `app.company_id` set, and `session IS NULL`
 * already satisfies the write policy.
 */
return new class extends Migration
{
    /** Both tables: a template's tasks have to be as readable as the template itself. */
    private const TABLES = ['onboarding_templates', 'onboarding_template_tasks'];

    public function up(): void
    {
        // A platform-shipped template has no author inside any tenant. Leaving this NOT NULL would
        // force the seeder to borrow some tenant's user id, which is both untrue and a cross-tenant
        // reference in a row that belongs to no tenant.
        if (Schema::hasColumn('onboarding_templates', 'created_by')) {
            DB::statement('ALTER TABLE onboarding_templates ALTER COLUMN created_by DROP NOT NULL');
        }

        foreach (self::TABLES as $table) {
            DB::statement("DROP POLICY IF EXISTS global_catalogue_read ON {$table}");
            DB::statement("CREATE POLICY global_catalogue_read ON {$table} FOR SELECT USING (company_id IS NULL)");
        }

        // Marks a row as shipped by the platform rather than merely un-tenanted by accident, and
        // gives the copy-to-tenant flow something to point back at.
        if (! Schema::hasColumn('onboarding_templates', 'catalogue_key')) {
            Schema::table('onboarding_templates', function ($table) {
                $table->string('catalogue_key')->nullable()->index();
                $table->string('industry')->nullable()->index();
                $table->unsignedBigInteger('copied_from_template_id')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            DB::statement("DROP POLICY IF EXISTS global_catalogue_read ON {$table}");
        }

        if (Schema::hasColumn('onboarding_templates', 'catalogue_key')) {
            Schema::table('onboarding_templates', function ($table) {
                $table->dropColumn(['catalogue_key', 'industry', 'copied_from_template_id']);
            });
        }

        // created_by is deliberately left nullable: rows seeded while it was nullable would break
        // a re-tightened constraint, and nothing depends on it being NOT NULL.
    }
};
