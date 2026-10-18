<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reseller program (platform-level, Phase 1 — admin side). A reseller is a partner that
 * brings clients (tenant companies) onto the platform and earns a monthly commission —
 * a percentage or fixed amount of each client's monthly subscription. Resellers are a
 * platform concept managed by platform admins, so these tables are NOT tenant-scoped and
 * carry no company_id / RLS (mirrors plans, subscriptions, subscription_invoices).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resellers', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->string('name');
            $t->string('contact_person')->nullable();
            $t->string('contact_email')->nullable();
            $t->string('contact_phone', 40)->nullable();
            // Commission the reseller earns on each client's monthly subscription.
            $t->string('commission_type', 10)->default('percent'); // percent | fixed
            $t->decimal('commission_value', 12, 2)->default(0);     // 10 => 10% (percent) or R10 (fixed)
            $t->string('status', 12)->default('active');            // active | suspended
            $t->timestamp('suspended_at')->nullable();
            $t->string('suspended_reason')->nullable();
            $t->text('notes')->nullable();
            $t->unsignedBigInteger('user_id')->nullable();          // reserved for the Phase 2 reseller login
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
        });

        // Which service offerings a reseller is authorised to sell (one or many).
        Schema::create('reseller_service_offering', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('reseller_id');
            $t->unsignedBigInteger('service_offering_id');
            $t->timestamps();
            $t->unique(['reseller_id', 'service_offering_id'], 'reseller_offering_unique');
            $t->index('reseller_id');
        });

        // The reseller who brought each client (tenant). Null = direct / no reseller.
        Schema::table('companies', function (Blueprint $t) {
            $t->unsignedBigInteger('reseller_id')->nullable()->after('created_by');
            $t->index('reseller_id');
        });

        // Monthly commission ledger — one accrued row per (reseller, client, period).
        Schema::create('reseller_commissions', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('reseller_id');
            $t->unsignedBigInteger('company_id');                       // the client
            $t->unsignedBigInteger('subscription_invoice_id')->nullable();
            $t->string('period', 7);                                    // YYYY-MM
            $t->decimal('base_amount', 12, 2)->default(0);              // client's subscription amount that month
            $t->string('commission_type', 10);                          // snapshot of type at accrual time
            $t->decimal('commission_value', 12, 2);                     // snapshot of value at accrual time
            $t->decimal('commission_amount', 12, 2)->default(0);        // computed payout
            $t->string('status', 12)->default('pending');               // pending | paid | void
            $t->timestamp('paid_at')->nullable();
            $t->string('reference', 120)->nullable();
            $t->timestamps();
            $t->unique(['reseller_id', 'company_id', 'period'], 'reseller_commission_period_unique');
            $t->index(['reseller_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $t) {
            $t->dropIndex(['reseller_id']);
            $t->dropColumn('reseller_id');
        });
        Schema::dropIfExists('reseller_commissions');
        Schema::dropIfExists('reseller_service_offering');
        Schema::dropIfExists('resellers');
    }
};
