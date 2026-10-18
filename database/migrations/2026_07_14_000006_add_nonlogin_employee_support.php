<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Support "non-login employees" (e.g. cleaners with no email who never sign in,
 * whose leave a supervisor applies on their behalf):
 *  - a per-company unique index on employee_number so no-email employees have a
 *    stable identity/dedup key (email was the de-facto key and is now often NULL);
 *  - a billing add-on so tenants are charged per non-login employee separately
 *    from user seats (SaaS). Self-hosted is not seat-metered.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Per-tenant unique employee number (partial: only when set, so multiple
        // NULLs are allowed and this doesn't clash with legacy blank numbers).
        if (Schema::hasTable('hr_people') && Schema::hasColumn('hr_people', 'employee_number')) {
            DB::statement(
                "CREATE UNIQUE INDEX IF NOT EXISTS hr_people_company_employee_number_unique
                 ON hr_people (company_id, employee_number)
                 WHERE employee_number IS NOT NULL AND employee_number <> ''"
            );
        }

        // Separate billing line for non-login employees (SaaS). Price is editable
        // by the platform admin afterwards; seeded at a modest default.
        if (Schema::hasTable('billing_addons')) {
            DB::table('billing_addons')->updateOrInsert(
                ['code' => 'nonlogin_employee'],
                [
                    'type'          => 'employee',
                    'name'          => 'Non-login employee',
                    'unit_label'    => 'employee',
                    'units'         => 1,
                    'price_monthly' => 15.00,
                    'sort'          => 50,
                    'is_active'     => true,
                    'updated_at'    => now(),
                    'created_at'    => now(),
                ]
            );
        }
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS hr_people_company_employee_number_unique');
        if (Schema::hasTable('billing_addons')) {
            DB::table('billing_addons')->where('code', 'nonlogin_employee')->delete();
        }
    }
};
