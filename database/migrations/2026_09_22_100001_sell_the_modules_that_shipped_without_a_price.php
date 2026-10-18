<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Four modules that exist in the app and cannot be sold.
 *
 * `security_modules` is what the application installs and gates access by; `billing_modules` is
 * what the platform can price, bundle into a plan and put on an invoice. They are meant to line
 * up, and four rows had fallen out of the second: Finance Executive, E-Sign, AI Campaign Manager
 * and Leave Bureau.
 *
 * Nothing failed, which is why it went unnoticed. The modules work, people use them; they simply
 * cannot appear on the pricing screen, cannot be added to a plan, and are never charged for. A
 * module that ships without a billing row is a module given away.
 *
 * ── The prices here are a starting point ─────────────────────────────────────
 *
 * Each is set alongside its nearest neighbour rather than invented: the bureau products together,
 * the AI products together, and so on. They are editable on Platform → Modules, which is where
 * this decision belongs — this migration exists to make them visible there at all.
 */
return new class extends Migration
{
    private const MODULES = [
        [
            'code' => 'finance_executive', 'name' => 'Finance Executive', 'category' => 'Finance',
            // Beside budget_management (R299); an executive roll-up over the finance modules.
            'price_monthly' => 299.00, 'sort' => 60,
        ],
        [
            'code' => 'esign', 'name' => 'E-Sign', 'category' => 'Operations',
            // Beside document_management (R350); signing is narrower than the document library.
            'price_monthly' => 199.00, 'sort' => 90,
        ],
        [
            'code' => 'ai_campaign_manager', 'name' => 'AI Campaign Manager',
            'category' => 'Operations & Support',
            // Beside content_marketing (R750), which is the larger suite of the two.
            'price_monthly' => 450.00, 'sort' => 20,
        ],
        [
            'code' => 'leave_bureau', 'name' => 'Leave Bureau', 'category' => 'HR & People',
            // A bureau product like payroll_bureau (R999), over a narrower subject.
            'price_monthly' => 599.00, 'sort' => 70,
        ],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('billing_modules')) {
            return;
        }

        foreach (self::MODULES as $module) {
            // Only where it is genuinely absent: an installation that has already priced one of
            // these keeps its own figure.
            if (DB::table('billing_modules')->where('code', $module['code'])->exists()) {
                continue;
            }

            DB::table('billing_modules')->insert($module + [
                'is_sellable' => true,
                'is_active'   => true,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('billing_modules')) {
            DB::table('billing_modules')->whereIn('code', array_column(self::MODULES, 'code'))->delete();
        }
    }
};
