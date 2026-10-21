<?php

use App\Modules\Agri\Support\AgriSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 §6: budgets with owners, approval and revisions at every level a farm plans at (§6.1);
 * the loans and capital spending a cash-flow forecast needs (§6.4); and a controlled, idempotent
 * export of the farm's journals to an accounting system that is the source of truth (§6.5).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('farm_budget_lines', function (Blueprint $t) {
            $t->unsignedBigInteger('field_id')->nullable();
            $t->unsignedBigInteger('work_order_id')->nullable();
            $t->unsignedBigInteger('machine_id')->nullable();
            $t->unsignedBigInteger('cost_centre_id')->nullable();          // company unit / department
            $t->unsignedBigInteger('owner_id')->nullable();                // the budget owner
            $t->string('status', 12)->default('approved');                 // draft | submitted | approved | rejected (existing lines count as approved)
            $t->unsignedSmallInteger('version')->default(1);
            $t->unsignedBigInteger('submitted_by')->nullable();
            $t->unsignedBigInteger('approved_by')->nullable();
            $t->timestamp('approved_at')->nullable();
        });
        Schema::create('farm_budget_revisions', function (Blueprint $t) {
            AgriSchema::farmOwned($t);
            $t->foreignId('budget_line_id')->constrained('farm_budget_lines')->cascadeOnDelete();
            $t->decimal('from_amount', 14, 2);
            $t->decimal('to_amount', 14, 2);
            $t->string('reason', 500);
            $t->string('status', 12)->default('submitted');                // submitted | approved | rejected
            $t->unsignedBigInteger('requested_by')->nullable();
            $t->unsignedBigInteger('decided_by')->nullable();
            $t->timestamp('decided_at')->nullable();
            $t->string('decision_note', 300)->nullable();
            $t->timestamps();
        });

        Schema::create('farm_loans', function (Blueprint $t) {
            AgriSchema::owned($t);
            $t->unsignedBigInteger('farm_id')->nullable();
            $t->string('lender', 160);
            $t->string('reference', 60)->nullable();
            $t->decimal('principal', 14, 2);
            $t->decimal('annual_rate_pct', 6, 3)->default(0);
            $t->date('first_payment_on');
            $t->decimal('payment', 14, 2);                                  // per instalment
            $t->string('frequency', 10)->default('monthly');               // monthly | quarterly | annually | seasonal
            $t->unsignedSmallInteger('instalments');
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('farm_capex_plans', function (Blueprint $t) {
            AgriSchema::owned($t);
            $t->unsignedBigInteger('farm_id')->nullable();
            $t->string('description', 200);
            $t->decimal('amount', 14, 2);
            $t->date('planned_on');
            $t->string('status', 12)->default('planned');                  // planned | committed | done | dropped
            $t->timestamps();
        });

        Schema::create('agri_finance_exports', function (Blueprint $t) {
            AgriSchema::owned($t);
            $t->string('target', 40);                                       // the accounting system
            $t->date('period_from');
            $t->date('period_to');
            $t->unsignedInteger('journals')->default(0);
            $t->decimal('total', 16, 2)->default(0);
            $t->string('status', 16)->default('exported');                 // exported | acknowledged | partly_failed
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
        });
        Schema::create('agri_exported_journals', function (Blueprint $t) {
            AgriSchema::owned($t);
            $t->unsignedBigInteger('journal_id');
            $t->unsignedBigInteger('export_id');
            $t->string('external_ref', 120);                                // what the other system knows it by
            $t->string('status', 12)->default('sent');                     // sent | acknowledged | failed
            $t->string('error', 500)->nullable();
            $t->string('target_id', 120)->nullable();                       // the other system's id, once it acknowledges
            $t->unsignedSmallInteger('attempts')->default(1);
            $t->timestamps();
            $t->unique(['company_id', 'journal_id']);                       // a journal is exported once
        });

        AgriSchema::tenantRls(['farm_budget_revisions', 'farm_loans', 'farm_capex_plans', 'agri_finance_exports', 'agri_exported_journals']);
    }

    public function down(): void
    {
        foreach (['agri_exported_journals', 'agri_finance_exports', 'farm_capex_plans', 'farm_loans', 'farm_budget_revisions'] as $x) {
            Schema::dropIfExists($x);
        }
        Schema::table('farm_budget_lines', fn (Blueprint $t) => $t->dropColumn(['field_id', 'work_order_id', 'machine_id', 'cost_centre_id', 'owner_id',
            'status', 'version', 'submitted_by', 'approved_by', 'approved_at']));
    }
};
