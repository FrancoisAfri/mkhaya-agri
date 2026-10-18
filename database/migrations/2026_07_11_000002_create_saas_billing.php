<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SaaS plans, subscriptions and subscription invoices (Phase D + E). A plan
 * defines which modules and how many users a company gets, and its monthly price.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $t) {
            $t->id();
            $t->string('code', 40)->unique();
            $t->string('name');
            $t->decimal('price_monthly', 12, 2)->default(0);
            $t->integer('max_users')->nullable();   // null = unlimited
            $t->json('modules')->nullable();         // null = all modules
            $t->boolean('is_active')->default(true);
            $t->integer('sort')->default(0);
            $t->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->unique();
            $t->string('plan_code', 40);
            $t->string('status', 20)->default('trialing'); // trialing | active | past_due | suspended | cancelled
            $t->timestamp('current_period_start')->nullable();
            $t->timestamp('current_period_end')->nullable();
            $t->timestamps();
        });

        Schema::create('subscription_invoices', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id');
            $t->string('period', 7);                 // YYYY-MM
            $t->decimal('amount', 12, 2);
            $t->string('status', 12)->default('open'); // open | paid | overdue | void
            $t->date('due_date');
            $t->timestamp('paid_at')->nullable();
            $t->string('reference', 120)->nullable();
            $t->string('gateway', 30)->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'period']);
        });

        $now = now();
        $plans = [
            ['code' => 'starter', 'name' => 'Starter', 'price_monthly' => 499, 'max_users' => 5, 'sort' => 1,
                'modules' => json_encode(['leave_management', 'time_attendance', 'user_management', 'reports', 'company_settings', 'system_settings'])],
            ['code' => 'standard', 'name' => 'Standard', 'price_monthly' => 1499, 'max_users' => 25, 'sort' => 2,
                'modules' => json_encode(['leave_management', 'time_attendance', 'payroll', 'accounting', 'crm', 'procurement_requests', 'claims', 'staff_loans', 'recruitment', 'performance_appraisal', 'user_management', 'roles_permissions', 'reports', 'company_settings', 'system_settings'])],
            ['code' => 'enterprise', 'name' => 'Enterprise', 'price_monthly' => 4999, 'max_users' => null, 'sort' => 3, 'modules' => null],
        ];
        foreach ($plans as $p) {
            DB::table('plans')->insert($p + ['is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
        }

        // The existing default company is on Enterprise (all modules) — no change for it.
        $default = DB::table('companies')->orderBy('id')->first();
        if ($default) {
            DB::table('subscriptions')->insert([
                'company_id' => $default->id, 'plan_code' => 'enterprise', 'status' => 'active',
                'current_period_start' => $now->copy()->startOfMonth(), 'current_period_end' => $now->copy()->endOfMonth(),
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_invoices');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('plans');
    }
};
