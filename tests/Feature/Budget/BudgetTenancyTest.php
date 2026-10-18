<?php

namespace Tests\Feature\Budget;

use App\Modules\Budget\Models\Budget;
use App\Modules\Budget\Models\BudgetAlertRule;
use App\Modules\Budget\Models\BudgetApprovalFlow;
use App\Modules\Tenancy\Exceptions\TenantContextMissingException;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Budget data belongs to exactly one company, always.
 *
 * The anomaly this defends against was found in a live database: a budget with a null `company_id`,
 * created by a platform admin who had not switched into a tenant. Row level security matches
 * `company_id = <session>`, so the row was invisible to every tenant from the moment it was saved —
 * and the save looked like it worked.
 *
 * This test makes its own second company rather than skipping when the database has one. A
 * tenant-isolation test that quietly skips is a test that has never run.
 */
class BudgetTenancyTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyA;

    private int $companyB;

    protected function setUp(): void
    {
        parent::setUp();

        $existing = DB::table('companies')->min('id');

        $this->companyA = $existing !== null ? (int) $existing : $this->makeCompany('Tenancy A');
        $this->companyB = $this->makeCompany('Tenancy B');
    }

    protected function tearDown(): void
    {
        app(CurrentCompany::class)->clear();

        parent::tearDown();
    }

    public function test_a_budget_created_in_a_company_belongs_to_it(): void
    {
        app(CurrentCompany::class)->set($this->companyA);

        $this->assertSame($this->companyA, (int) $this->budget('Owned')->company_id);
    }

    public function test_a_budget_cannot_be_created_with_no_company_at_all(): void
    {
        app(CurrentCompany::class)->clear();

        // Refusing is the point. The alternative is a row nobody can ever see again, and a person
        // who believes they created a budget.
        $this->expectException(TenantContextMissingException::class);

        $this->budget('Nobody owns this');
    }

    public function test_one_company_cannot_see_another_companys_budgets(): void
    {
        app(CurrentCompany::class)->set($this->companyA);
        $mine = $this->budget('Company A budget');

        app(CurrentCompany::class)->set($this->companyB);

        $this->assertNull(Budget::find($mine->id), 'The global scope must not return another tenant\'s budget.');
        $this->assertFalse(Budget::where('name', 'Company A budget')->exists());
    }

    public function test_governance_configuration_is_scoped_too(): void
    {
        // Approval routing and alert rules decide who signs and what stops. Leaking either across
        // tenants would let one company's controls govern another's money.
        app(CurrentCompany::class)->set($this->companyA);

        BudgetApprovalFlow::create(['name' => 'A flow', 'subject_type' => 'budget', 'is_active' => true]);
        BudgetAlertRule::create([
            'name' => 'A rule', 'scope_type' => 'all', 'metric' => 'committed_pct',
            'threshold' => 90, 'severity' => 'block', 'is_active' => true,
        ]);

        app(CurrentCompany::class)->set($this->companyB);

        $this->assertFalse(BudgetApprovalFlow::where('name', 'A flow')->exists());
        $this->assertFalse(BudgetAlertRule::where('name', 'A rule')->exists());
    }

    public function test_a_sweep_does_not_carry_one_tenants_rules_into_another(): void
    {
        // The nightly command reuses one service instance across every tenant, and the rule list is
        // memoised. Without a reset at the sweep boundary, company B would be judged by company A's
        // blocking rules — a query optimisation quietly becoming a cross-tenant leak.
        app(CurrentCompany::class)->set($this->companyA);

        BudgetAlertRule::create([
            'name' => 'A-only rule', 'scope_type' => 'all', 'metric' => 'committed_pct',
            'threshold' => 1, 'severity' => 'block', 'notify_owners' => false, 'is_active' => true,
        ]);

        $tracking = app(\App\Modules\Budget\Services\BudgetTrackingService::class);
        $tracking->sweep(notify: false);

        app(CurrentCompany::class)->set($this->companyB);
        $budget = $this->budget('Company B budget');
        app(\App\Modules\Budget\Services\BudgetService::class)->consumeBudget($budget, 900);

        $tracking->sweep(notify: false);

        $this->assertSame([], $tracking->breaches($budget->fresh())->pluck('name')->all());
        $this->assertNull(
            \App\Modules\Budget\Models\BudgetAlert::live()->where('budget_id', $budget->id)->first(),
            'Company B has written no rules, so nothing should have been raised against it.'
        );
    }

    public function test_every_budget_model_declares_that_it_needs_a_company(): void
    {
        // A new model added to this module without the declaration is the exact way the original
        // anomaly gets reintroduced, so the rule is asserted rather than remembered.
        $missing = [];

        foreach (glob(app_path('Modules/Budget/Models/*.php')) as $file) {
            $class = 'App\\Modules\\Budget\\Models\\'.pathinfo($file, PATHINFO_FILENAME);

            if (! class_exists($class) || ! method_exists($class, 'requiresCompany')) {
                $missing[] = class_basename($class);

                continue;
            }

            if (! $class::requiresCompany()) {
                $missing[] = class_basename($class);
            }
        }

        $this->assertSame([], $missing, 'These budget models would allow an unowned row: '.implode(', ', $missing));
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function budget(string $name): Budget
    {
        return Budget::create([
            'code' => 'BGT'.Str::random(6), 'name' => $name,
            'expenditure_type' => 'opex', 'scope_type' => 'general',
            'allocated_amount' => 1000, 'status' => 'active',
        ]);
    }

    private function makeCompany(string $name): int
    {
        return (int) DB::table('companies')->insertGetId([
            'uuid'       => (string) Str::uuid(),
            'slug'       => Str::slug($name).'-'.Str::random(6),
            'name'       => $name.' '.Str::random(4),
            'status'     => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
