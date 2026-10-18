<?php

namespace App\Modules\Tenancy\Services;

use App\Core\User\Models\User;
use App\Modules\Tenancy\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Onboards a new tenant (Phase B): creates the company on a trial, its first
 * admin user pinned to that company, and grants that user access to the active
 * modules. Plan-based module gating comes later (Phase D); for now a new tenant
 * admin gets every active module.
 */
class CompanyProvisioner
{
    /** @return array{company: Company, user: User} */
    public function provision(array $data): array
    {
        $result = DB::transaction(function () use ($data) {
            // Trial period is chosen at creation time: 7/14/30 days, or 0 for a
            // company that goes active immediately (no trial).
            $trialDays = (int) ($data['trial_days'] ?? config('edition.trial_days', 30));
            $activeFromDayOne = $trialDays <= 0;

            // Founding customers who sign up during the launch window keep launch
            // pricing until the lock date (config: founding_lock_months).
            $foundingLock = LaunchWindow::foundingLockUntil();

            $company = Company::create([
                'name' => $data['company_name'],
                'registration_number' => $data['registration_number'] ?? null,
                'contact_person' => $data['admin_name'] ?? null,
                'contact_email' => $data['email'],
                'contact_phone' => $data['phone'] ?? null,
                'status' => $activeFromDayOne ? 'active' : 'trial',
                'plan' => $data['plan'] ?? 'standard',
                'founding_customer' => $foundingLock !== null,
                'price_locked_until' => $foundingLock?->toDateString(),
                'trial_ends_at' => $activeFromDayOne ? null : now()->addDays($trialDays),
            ]);

            $user = User::create([
                'uuid' => (string) Str::uuid(),
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
                'status' => 1,
                'is_verified' => true,
                'company_id' => $company->id,
                'password_changed_at' => now(),
            ]);

            // Record the admin user as the company OWNER (created_by). Only the owner
            // may manage the subscription + usage/billing — ordinary users can't.
            $company->update(['created_by' => $user->id]);

            if (method_exists($user, 'assignRole')) {
                try {
                    $user->assignRole('administrator');
                } catch (\Throwable) {
                    // role may not exist on a bare install — non-fatal
                }
            }

            // Give the admin an employee (hr_people) record so they are a findable
            // person across HR (leave allocation, org, etc.) — otherwise the tenant
            // has zero employees and searches return nothing. Written inside the
            // company's tenant context so RLS WITH CHECK passes and company_id is
            // stamped to the new company (not left NULL / invisible to the tenant).
            $this->createAdminEmployee($user, $company, $data);

            $this->grantAllModules($user->id);

            // Seed the company's module composition + monthly total from its plan,
            // so entitlements + billing reflect the subscription immediately.
            app(CompanySubscription::class)->syncFromPlan($company);

            return ['company' => $company->refresh(), 'user' => $user];
        });

        // Give the new tenant its own copy of the reference/lookup data (leave
        // types, categories, statuses, settings…) so its dropdowns aren't empty.
        // Runs post-commit and is non-fatal.
        try {
            app(TenantReferenceSeeder::class)->seedFor($result['company']);
        } catch (\Throwable $e) {
            Log::warning('CompanyProvisioner: reference seeding failed', ['company' => $result['company']->id, 'error' => $e->getMessage()]);
        }

        return $result;
    }

    /** Create the tenant admin's employee (hr_people) record. */
    private function createAdminEmployee(User $user, Company $company, array $data): void
    {
        $name = trim((string) ($data['admin_name'] ?? ''));
        $parts = $name !== '' ? preg_split('/\s+/', $name, 2) : [];
        $first = $parts[0] ?? ($data['company_name'] ?? 'Admin');
        $last = $parts[1] ?? 'Admin';

        app(TenantContext::class)->run((int) $company->id, function () use ($user, $company, $data, $first, $last) {
            if (DB::table('hr_people')->where('user_id', $user->id)->exists()) {
                return;
            }

            DB::table('hr_people')->insert([
                'uuid' => (string) Str::uuid(),
                'company_id' => $company->id,
                'user_id' => $user->id,
                'first_name' => $first,
                'surname' => $last,
                'email' => $data['email'],
                'employee_number' => 'EMP00001',
                'status' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    /** Grant the user access to every active category, module and ribbon (level 5). */
    private function grantAllModules(int $userId): void
    {
        $level = 5;
        $categories = DB::table('security_categories')->where('active', 1)->pluck('id');
        foreach ($categories as $categoryId) {
            DB::table('security_category_access')->updateOrInsert(
                ['category_id' => $categoryId, 'user_id' => $userId],
                ['active' => 1, 'access_level' => $level, 'updated_at' => now(), 'created_at' => now()],
            );
        }

        $modules = DB::table('security_modules')->where('active', 1)->pluck('id');
        foreach ($modules as $moduleId) {
            DB::table('security_module_access')->updateOrInsert(
                ['module_id' => $moduleId, 'user_id' => $userId],
                ['active' => 1, 'access_level' => $level, 'updated_at' => now(), 'created_at' => now()],
            );
        }

        DB::table('security_module_ribbons')->where('active', 1)->pluck('id')->each(function ($ribbonId) use ($userId, $level) {
            $exists = DB::table('security_ribbons_access')->where('ribbon_id', $ribbonId)->where('user_id', $userId)->exists();
            if (! $exists) {
                DB::table('security_ribbons_access')->insert([
                    'ribbon_id' => $ribbonId, 'user_id' => $userId, 'active' => 1,
                    'access_level' => $level, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });
    }
}
