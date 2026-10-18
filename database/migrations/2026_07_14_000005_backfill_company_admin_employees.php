<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Backfill an employee (hr_people) record for each company's admin user that
 * lacks one. Tenants provisioned before CompanyProvisioner created admin employees
 * had a bare admin User with no hr_people row, so HR screens (leave allocation,
 * org, etc.) showed zero employees for that tenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('companies')) {
            return;
        }

        foreach (DB::table('companies')->get(['id', 'contact_email', 'contact_person', 'name']) as $company) {
            // The provisioned admin for this company: prefer the contact-email match,
            // else fall back to the earliest user pinned to the company.
            $user = null;
            if ($company->contact_email) {
                $user = DB::table('users')
                    ->where('company_id', $company->id)
                    ->where('email', $company->contact_email)
                    ->orderBy('id')
                    ->first();
            }
            $user ??= DB::table('users')
                ->where('company_id', $company->id)
                ->orderBy('id')
                ->first();

            if (! $user || DB::table('hr_people')->where('user_id', $user->id)->exists()) {
                continue;
            }

            // Align the RLS session var with the company so WITH CHECK passes and the
            // company_id default matches, then insert with an explicit company_id.
            DB::statement("SELECT set_config('app.company_id', ?, false)", [(string) $company->id]);

            $count = DB::table('hr_people')->where('company_id', $company->id)->count();
            $name  = trim((string) ($company->contact_person ?? ''));
            $parts = $name !== '' ? preg_split('/\s+/', $name, 2) : [];

            DB::table('hr_people')->insert([
                'uuid'            => (string) Str::uuid(),
                'company_id'      => $company->id,
                'user_id'         => $user->id,
                'first_name'      => $parts[0] ?? ($company->name ?? 'Admin'),
                'surname'         => $parts[1] ?? 'Admin',
                'email'           => $user->email,
                'employee_number' => 'EMP' . str_pad((string) ($count + 1), 5, '0', STR_PAD_LEFT),
                'status'          => 1,
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);
        }

        // Restore the unset session var so nothing downstream inherits a tenant.
        DB::statement("SELECT set_config('app.company_id', '', false)");
    }

    public function down(): void
    {
        // Non-reversible data backfill.
    }
};
