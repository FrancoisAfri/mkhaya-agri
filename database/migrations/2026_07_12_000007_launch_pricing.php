<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Launch pricing foundation (Pricing Guide, first-6-months promotion).
 *
 * - Plans become the mandatory Base Platform tiers (Starter/Growth/Business/
 *   Enterprise) carrying a launch price + a regular (post-launch) price, plus
 *   database storage and a support-tier matrix.
 * - Every functional module is à la carte on top of the base plan (module
 *   prices re-aligned to the guide). Enterprise stays all-inclusive so the
 *   platform's own company keeps full access; the other tiers are base-only.
 * - Add-ons (extra users, AI token packs, storage packs) and the one-off
 *   professional/support services catalog are modelled as data.
 * - Companies gain a founding-customer flag + a price-lock date: founding
 *   customers keep launch pricing until `price_locked_until` (12 months), then
 *   billing falls back to the regular price.
 *
 * All prices EXCLUDE VAT and are in ZAR. Idempotent (safe to re-run).
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Plans: launch/regular price, storage, support matrix ──────────────
        Schema::table('plans', function (Blueprint $t) {
            if (! Schema::hasColumn('plans', 'regular_price_monthly')) {
                $t->decimal('regular_price_monthly', 12, 2)->nullable()->after('price_monthly');
            }
            if (! Schema::hasColumn('plans', 'storage_gb')) {
                $t->unsignedInteger('storage_gb')->nullable()->after('sms_quota');
            }
            if (! Schema::hasColumn('plans', 'support')) {
                $t->json('support')->nullable()->after('storage_gb');
            }
        });

        // ── Companies: founding-customer price lock ───────────────────────────
        Schema::table('companies', function (Blueprint $t) {
            if (! Schema::hasColumn('companies', 'founding_customer')) {
                $t->boolean('founding_customer')->default(false)->after('plan');
            }
            if (! Schema::hasColumn('companies', 'price_locked_until')) {
                $t->date('price_locked_until')->nullable()->after('founding_customer');
            }
        });

        // ── Add-on catalog (extra users, AI token packs, storage packs) ───────
        if (! Schema::hasTable('billing_addons')) {
            Schema::create('billing_addons', function (Blueprint $t) {
                $t->id();
                $t->string('type', 20)->index();            // user | ai_pack | storage_pack
                $t->string('code', 60)->unique();
                $t->string('name', 120);
                $t->string('unit_label', 40)->nullable();   // "tokens", "GB", "per user"
                $t->unsignedBigInteger('units')->nullable(); // tokens / GB / 1 (per user); null = custom/quote
                $t->decimal('price_monthly', 12, 2)->nullable(); // null = quote
                $t->unsignedInteger('sort')->default(0);
                $t->boolean('is_active')->default(true);
                $t->timestamps();
            });
        }

        // ── One-off professional & support services catalog ──────────────────
        if (! Schema::hasTable('service_offerings')) {
            Schema::create('service_offerings', function (Blueprint $t) {
                $t->id();
                $t->string('category', 40)->index();        // professional | support
                $t->string('name', 150);
                $t->decimal('price', 12, 2)->nullable();    // null when quote/free-with-no-figure
                $t->string('price_type', 12)->default('fixed'); // free | fixed | from | hourly | daily | monthly | quote
                $t->string('unit', 30)->nullable();         // hour | day | month | visit
                $t->string('note', 150)->nullable();
                $t->unsignedInteger('sort')->default(0);
                $t->boolean('is_active')->default(true);
                $t->timestamps();
            });
        }

        $now = now();

        // ── Seed / update the 4 Base Platform tiers ───────────────────────────
        // support matrix per the guide (remote_hours kept as text for "Unlimited").
        $support = fn (array $r) => json_encode($r);
        $plans = [
            ['code' => 'starter', 'name' => 'Starter', 'price_monthly' => 399, 'regular_price_monthly' => 599,
                'max_users' => 5, 'ai_token_quota' => 200000, 'sms_quota' => 100, 'storage_gb' => 2, 'sort' => 1,
                'modules' => json_encode(self::BASE_ONLY),
                'support' => $support(['email' => true, 'whatsapp' => false, 'phone' => false, 'remote_hours' => '1 Hour/Month', 'response' => '24 Business Hours', 'emergency' => false, 'account_manager' => false, 'training' => 'Knowledge Base', 'health_check' => null, 'priority_bugfix' => false, 'onsite' => null])],
            ['code' => 'growth', 'name' => 'Growth', 'price_monthly' => 699, 'regular_price_monthly' => 999,
                'max_users' => 10, 'ai_token_quota' => 500000, 'sms_quota' => 500, 'storage_gb' => 5, 'sort' => 2,
                'modules' => json_encode(self::BASE_ONLY),
                'support' => $support(['email' => true, 'whatsapp' => true, 'phone' => true, 'remote_hours' => '3 Hours/Month', 'response' => '8 Business Hours', 'emergency' => false, 'account_manager' => false, 'training' => 'Online Videos', 'health_check' => 'Annual', 'priority_bugfix' => false, 'onsite' => null])],
            ['code' => 'business', 'name' => 'Business', 'price_monthly' => 1299, 'regular_price_monthly' => 1999,
                'max_users' => 25, 'ai_token_quota' => 2000000, 'sms_quota' => 2000, 'storage_gb' => 20, 'sort' => 3,
                'modules' => json_encode(self::BASE_ONLY),
                'support' => $support(['email' => true, 'whatsapp' => true, 'phone' => true, 'remote_hours' => '8 Hours/Month', 'response' => '4 Business Hours', 'emergency' => true, 'account_manager' => true, 'training' => '2 Remote Sessions/Year', 'health_check' => 'Quarterly', 'priority_bugfix' => true, 'onsite' => '1 Visit/Year'])],
            ['code' => 'enterprise', 'name' => 'Enterprise', 'price_monthly' => 3999, 'regular_price_monthly' => 5999,
                'max_users' => 100, 'ai_token_quota' => 10000000, 'sms_quota' => 10000, 'storage_gb' => 100, 'sort' => 4,
                'modules' => null, // all-inclusive: protects the platform owner + top tier
                'support' => $support(['email' => true, 'whatsapp' => true, 'phone' => true, 'remote_hours' => 'Unlimited (Fair Use)', 'response' => '1 Business Hour', 'emergency' => true, 'account_manager' => true, 'training' => 'Unlimited Remote Training', 'health_check' => 'Monthly', 'priority_bugfix' => true, 'onsite' => '1 Visit/Quarter'])],
        ];
        foreach ($plans as $p) {
            DB::table('plans')->updateOrInsert(['code' => $p['code']], $p + ['is_active' => true, 'updated_at' => $now, 'created_at' => $now]);
        }
        // Retire the legacy "Standard" tier (superseded by Growth/Business) without
        // deleting it, so any historical subscription still resolves its price.
        DB::table('plans')->where('code', 'standard')->update(['is_active' => false, 'updated_at' => $now]);

        // ── Re-price the module catalog to the guide ──────────────────────────
        // [code => price]. Codes already exist from 000003_module_pricing.
        $modulePrices = [
            'recognition' => 99,                                                   // Culture
            'petty_cash' => 99, 'claims' => 149, 'staff_loans' => 149, 'budget_management' => 299, 'accounting' => 599, // Finance
            'policy_enforcement' => 199, 'popia_compliance' => 199, 'whistleblower' => 249, 'board_committee' => 299,   // Governance
            'boarding' => 99, 'offboarding' => 99, 'leave_management' => 149, 'time_attendance' => 149, 'disciplinary' => 149,
            'recruitment' => 299, 'performance_appraisal' => 299, 'learning_management' => 599, 'payroll' => 599, 'payroll_bureau' => 999, // HR & People
            'resources' => 99, 'marketplace' => 149, 'procurement_requests' => 199, 'supplier_management' => 299,
            'contract_management' => 299, 'asset_management' => 299, 'fleet_management' => 599, 'tender_pursuit' => 299, // Operations
            'surveys' => 99, 'conferences' => 249, 'help_desk' => 199, 'content_management' => 199, 'crm' => 599,        // Sales & Service
        ];
        foreach ($modulePrices as $code => $price) {
            DB::table('billing_modules')->where('code', $code)->update(['price_monthly' => $price, 'updated_at' => $now]);
        }
        // "Onboarding" reads better than the internal "boarding" code.
        DB::table('billing_modules')->where('code', 'boarding')->update(['name' => 'Onboarding', 'updated_at' => $now]);

        // ── Seed add-ons ──────────────────────────────────────────────────────
        $addons = [
            ['type' => 'user', 'code' => 'additional_user', 'name' => 'Additional User', 'unit_label' => 'per user', 'units' => 1, 'price_monthly' => 30, 'sort' => 0],
            ['type' => 'ai_pack', 'code' => 'ai_starter', 'name' => 'Starter AI', 'unit_label' => 'tokens', 'units' => 2000000, 'price_monthly' => 99, 'sort' => 1],
            ['type' => 'ai_pack', 'code' => 'ai_growth', 'name' => 'Growth AI', 'unit_label' => 'tokens', 'units' => 5000000, 'price_monthly' => 199, 'sort' => 2],
            ['type' => 'ai_pack', 'code' => 'ai_business', 'name' => 'Business AI', 'unit_label' => 'tokens', 'units' => 20000000, 'price_monthly' => 699, 'sort' => 3],
            ['type' => 'ai_pack', 'code' => 'ai_enterprise', 'name' => 'Enterprise AI', 'unit_label' => 'tokens', 'units' => 100000000, 'price_monthly' => 2499, 'sort' => 4],
            ['type' => 'ai_pack', 'code' => 'ai_corporate', 'name' => 'Corporate', 'unit_label' => 'tokens', 'units' => null, 'price_monthly' => null, 'sort' => 5],
            ['type' => 'storage_pack', 'code' => 'storage_5', 'name' => '+5 GB', 'unit_label' => 'GB', 'units' => 5, 'price_monthly' => 49, 'sort' => 1],
            ['type' => 'storage_pack', 'code' => 'storage_10', 'name' => '+10 GB', 'unit_label' => 'GB', 'units' => 10, 'price_monthly' => 89, 'sort' => 2],
            ['type' => 'storage_pack', 'code' => 'storage_25', 'name' => '+25 GB', 'unit_label' => 'GB', 'units' => 25, 'price_monthly' => 199, 'sort' => 3],
            ['type' => 'storage_pack', 'code' => 'storage_50', 'name' => '+50 GB', 'unit_label' => 'GB', 'units' => 50, 'price_monthly' => 349, 'sort' => 4],
            ['type' => 'storage_pack', 'code' => 'storage_100', 'name' => '+100 GB', 'unit_label' => 'GB', 'units' => 100, 'price_monthly' => 599, 'sort' => 5],
        ];
        foreach ($addons as $a) {
            DB::table('billing_addons')->updateOrInsert(['code' => $a['code']], $a + ['is_active' => true, 'updated_at' => $now, 'created_at' => $now]);
        }

        // ── Seed professional & support services ──────────────────────────────
        $services = [
            // Professional services
            ['category' => 'professional', 'name' => 'System Setup', 'price' => 0, 'price_type' => 'free', 'unit' => null, 'note' => null, 'sort' => 1],
            ['category' => 'professional', 'name' => 'Remote Onboarding', 'price' => 0, 'price_type' => 'free', 'unit' => null, 'note' => 'Launch Promotion', 'sort' => 2],
            ['category' => 'professional', 'name' => 'Data Migration', 'price' => 2500, 'price_type' => 'from', 'unit' => null, 'note' => null, 'sort' => 3],
            ['category' => 'professional', 'name' => 'Administrator Training', 'price' => 1500, 'price_type' => 'fixed', 'unit' => null, 'note' => 'Remote', 'sort' => 4],
            ['category' => 'professional', 'name' => 'On-site Training', 'price' => 5000, 'price_type' => 'from', 'unit' => 'day', 'note' => null, 'sort' => 5],
            ['category' => 'professional', 'name' => 'API Integration', 'price' => 5000, 'price_type' => 'from', 'unit' => null, 'note' => null, 'sort' => 6],
            ['category' => 'professional', 'name' => 'Custom Development', 'price' => 950, 'price_type' => 'hourly', 'unit' => 'hour', 'note' => null, 'sort' => 7],
            ['category' => 'professional', 'name' => 'Dedicated Technical Consultant', 'price' => 3000, 'price_type' => 'from', 'unit' => 'month', 'note' => null, 'sort' => 8],
            // Additional support services
            ['category' => 'support', 'name' => 'Additional Remote Support', 'price' => 650, 'price_type' => 'hourly', 'unit' => 'hour', 'note' => null, 'sort' => 1],
            ['category' => 'support', 'name' => 'Half-Day On-site Support', 'price' => 2500, 'price_type' => 'fixed', 'unit' => null, 'note' => null, 'sort' => 2],
            ['category' => 'support', 'name' => 'Full-Day On-site Support', 'price' => 4500, 'price_type' => 'fixed', 'unit' => null, 'note' => null, 'sort' => 3],
            ['category' => 'support', 'name' => 'After-Hours Remote Support', 'price' => 950, 'price_type' => 'hourly', 'unit' => 'hour', 'note' => null, 'sort' => 4],
            ['category' => 'support', 'name' => 'Weekend/Public Holiday Support', 'price' => 1250, 'price_type' => 'hourly', 'unit' => 'hour', 'note' => null, 'sort' => 5],
            ['category' => 'support', 'name' => 'Travel Outside Operating Area', 'price' => null, 'price_type' => 'quote', 'unit' => null, 'note' => null, 'sort' => 6],
        ];
        foreach ($services as $s) {
            DB::table('service_offerings')->updateOrInsert(
                ['category' => $s['category'], 'name' => $s['name']],
                $s + ['is_active' => true, 'updated_at' => $now, 'created_at' => $now]
            );
        }
    }

    /** Base Platform tiers include only the always-on core modules; the rest is à la carte. */
    private const BASE_ONLY = ['user_management', 'roles_permissions', 'reports', 'company_settings', 'system_settings'];

    public function down(): void
    {
        Schema::dropIfExists('service_offerings');
        Schema::dropIfExists('billing_addons');
        Schema::table('companies', function (Blueprint $t) {
            if (Schema::hasColumn('companies', 'price_locked_until')) {
                $t->dropColumn('price_locked_until');
            }
            if (Schema::hasColumn('companies', 'founding_customer')) {
                $t->dropColumn('founding_customer');
            }
        });
        Schema::table('plans', function (Blueprint $t) {
            foreach (['support', 'storage_gb', 'regular_price_monthly'] as $col) {
                if (Schema::hasColumn('plans', $col)) {
                    $t->dropColumn($col);
                }
            }
        });
    }
};
