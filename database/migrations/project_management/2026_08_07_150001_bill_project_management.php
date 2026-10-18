<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Make Project Management purchasable (billing).
 *
 * Two routes to the same module:
 *
 *   à la carte   `billing_modules` — any tenant on any plan can add it for R450/month.
 *   in a package Standard and Enterprise include it at no extra charge.
 *
 * Standard was chosen because it already bundles payroll, accounting, CRM and procurement — the
 * operational half of the platform — and a construction or services company buying that bundle is
 * exactly who runs projects. Enterprise carries an empty module list, which the entitlement layer
 * reads as "everything", so it needed nothing.
 *
 * Until this ran, `project_management` was sellable in the catalogue but in no plan, so a real
 * tenant hit a correct 403 on every route. Platform admins bypass the check, which is why the gap
 * survived development unnoticed.
 */
return new class extends Migration
{
    private const PRICE = 450.00;

    /** Plans that include it outright. Enterprise is absent because its empty list means all. */
    private const BUNDLED_INTO = ['standard'];

    public function up(): void
    {
        // ── À la carte ────────────────────────────────────────────────────────
        DB::table('billing_modules')->updateOrInsert(
            ['code' => 'project_management'],
            [
                'name'          => 'Project Management',
                'category'      => 'Operations',
                'price_monthly' => self::PRICE,
                'is_sellable'   => true,
                'is_active'     => true,
                'sort'          => 51,
                'updated_at'    => now(),
                'created_at'    => now(),
            ]
        );

        // ── In a package ──────────────────────────────────────────────────────
        foreach (self::BUNDLED_INTO as $code) {
            $plan = DB::table('plans')->where('code', $code)->first();

            if (! $plan) {
                continue;
            }

            $modules = json_decode($plan->modules ?? '[]', true) ?: [];

            // An empty list means "every module" to CompanyEntitlements; adding one code to it
            // would silently *narrow* such a plan to a single module. Leave those alone.
            if ($modules === []) {
                continue;
            }

            if (in_array('project_management', $modules, true)) {
                continue;
            }

            $modules[] = 'project_management';

            DB::table('plans')->where('id', $plan->id)->update([
                'modules'    => json_encode(array_values($modules)),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        foreach (self::BUNDLED_INTO as $code) {
            $plan = DB::table('plans')->where('code', $code)->first();

            if (! $plan) {
                continue;
            }

            $modules = json_decode($plan->modules ?? '[]', true) ?: [];

            DB::table('plans')->where('id', $plan->id)->update([
                'modules'    => json_encode(array_values(array_diff($modules, ['project_management']))),
                'updated_at' => now(),
            ]);
        }

        // The catalogue row is left in place: withdrawing a module someone is paying for is a
        // commercial decision, not something a rollback should do on its own.
    }
};
