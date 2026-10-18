<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Standard bundles the modules that have shipped since it was written.
 *
 * Standard listed sixteen. Twenty-nine sellable modules were in no plan at all — the whole
 * procurement family, SHEQ, document management, fleet, the bureau products, governance, content
 * marketing. Anybody on Standard had a plan that had quietly stopped describing the product.
 *
 * Starter, Growth and Business are untouched on purpose: they are base tiers, priced on users, AI
 * tokens and storage, with modules bought à la carte. Nothing in this migration changes that.
 *
 * ── The shape of the problem this fixes is the shape of the fix ──────────────
 *
 * Standard went stale because a plan's modules are a literal list, correct on the day it was
 * written and silently wrong from the next module onwards. Writing a longer literal list here
 * fixes today and sets the same trap for whoever ships module fifty.
 *
 * The self-maintaining alternative already exists: `modules = null` means every module, which is
 * how Enterprise is expressed, and it never goes stale. It is deliberately not used here, because
 * it would make Standard permanently identical to Enterprise in modules and remove the ability to
 * drop one from Standard later without rewriting. That is a pricing decision rather than a
 * technical one, and this keeps it open.
 *
 * What does not stay open is finding out by accident. EveryModuleCanBeSoldTest now fails when a
 * module ships without a billing row, which is how these twenty-nine became invisible.
 */
return new class extends Migration
{
    private const PLAN = 'standard';

    public function up(): void
    {
        if (! Schema::hasTable('plans') || ! Schema::hasTable('billing_modules')) {
            return;
        }

        $plan = DB::table('plans')->where('code', self::PLAN)->first();

        if (! $plan) {
            return;
        }

        /*
         * A plan whose `modules` is null already means every module — Enterprise is that. Widening
         * it to a literal list would narrow it, so it is left alone.
         */
        if ($plan->modules === null) {
            return;
        }

        $existing = json_decode($plan->modules, true) ?: [];

        // Every module the catalogue holds, sellable or core. `effectiveModules()` folds the core
        // ones in anyway, and keeping them listed matches how this row already reads.
        $all = DB::table('billing_modules')->where('is_active', true)->pluck('code')->all();

        $modules = array_values(array_unique(array_merge($existing, $all)));
        sort($modules);

        DB::table('plans')->where('code', self::PLAN)->update([
            'modules'    => json_encode($modules),
            'updated_at' => now(),
        ]);
    }

    /**
     * Back to the sixteen it listed before.
     *
     * Written out rather than derived, because "the modules that existed in September" is not a
     * question the database can answer later.
     */
    public function down(): void
    {
        if (! Schema::hasTable('plans')) {
            return;
        }

        DB::table('plans')->where('code', self::PLAN)->update([
            'modules' => json_encode([
                'leave_management', 'time_attendance', 'payroll', 'accounting', 'crm',
                'procurement_requests', 'claims', 'staff_loans', 'recruitment',
                'performance_appraisal', 'user_management', 'roles_permissions', 'reports',
                'company_settings', 'system_settings', 'project_management',
            ]),
            'updated_at' => now(),
        ]);
    }
};
