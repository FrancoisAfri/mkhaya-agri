<?php

namespace Tests\Feature\ProcurementGovernance;

use App\Modules\Procurement\Governance\Services\Capabilities;
use App\Modules\Procurement\Governance\Services\PlanningService;
use App\Modules\Procurement\Governance\Services\ReferenceGenerator;
use App\Modules\Procurement\Governance\Services\RulesEngine;
use App\Modules\Procurement\Governance\Services\SettingsService;
use App\Modules\Procurement\Governance\Services\TenderReviewService;
use App\Modules\Procurement\Governance\Support\Integration\BudgetBridge;
use App\Modules\Procurement\Governance\Support\Integration\SupplierBridge;
use App\Modules\Procurement\Governance\Support\Rules\Handlers\BudgetAvailabilityHandler;
use App\Modules\Procurement\Governance\Support\Rules\RuleOutcome;
use App\Modules\Procurement\Governance\Support\Rules\RuleSubject;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\MakesCompanies;
use Tests\TestCase;

/**
 * The two invariants that hold for every module on this platform: it must be fully usable with
 * nothing else installed, and one tenant must never see another's data.
 *
 * Standalone matters here more than most, because Procurement Governance reaches into Budget,
 * Supplier and Contract Management by design. The rule those integrations follow is that they *add*
 * capability — their absence degrades a view, it never blocks a workflow, and it never silently
 * turns "we cannot see this" into a number.
 */
class StandaloneAndTenancyTest extends TestCase
{
    use DatabaseTransactions, MakesCompanies;

    private int $companyId;

    protected function setUp(): void
    {
        parent::setUp();

        $companyId = DB::table('companies')->min('id');

        if ($companyId === null) {
            $this->markTestSkipped('No company in the test database.');
        }

        $this->companyId = (int) $companyId;

        Cache::flush();
        app(CurrentCompany::class)->set($this->companyId);
    }

    protected function tearDown(): void
    {
        app(CurrentCompany::class)->clear();
        Cache::flush();

        parent::tearDown();
    }

    /** Switch a neighbouring module off for the duration of a test. */
    private function withModuleOff(string $code, callable $body): void
    {
        $previous = DB::table('security_modules')->where('code_name', $code)->value('active');

        DB::table('security_modules')->where('code_name', $code)->update(['active' => 0]);
        Cache::flush();

        try {
            $body();
        } finally {
            DB::table('security_modules')->where('code_name', $code)->update(['active' => $previous ?? 1]);
            Cache::flush();
        }
    }

    private function plan(): object
    {
        return app(PlanningService::class)->createPlan($this->companyId, [
            'title' => 'Standalone plan',
            'financial_year_label' => '2026/27',
        ]);
    }

    // ── Standalone: no Budget Management ────────────────────────────────────────────────────────

    public function test_a_plan_can_be_created_and_run_without_the_budget_module(): void
    {
        $this->withModuleOff('budget_management', function () {
            $plan = $this->plan();

            $item = app(PlanningService::class)->addItem($this->companyId, (int) $plan->id, [
                'description' => 'Network switches',
                'estimated_value' => 250_000,
            ]);

            $this->assertNotNull($item->reference);

            // The workflow must still move — the absent module is not a blocker.
            $moved = app(PlanningService::class)
                ->transition($this->companyId, (int) $plan->id, 'DEPARTMENT_REVIEW');

            $this->assertSame('DEPARTMENT_REVIEW', $moved->status);
        });
    }

    public function test_an_absent_budget_module_reports_unavailable_rather_than_zero(): void
    {
        $this->withModuleOff('budget_management', function () {
            $plan = $this->plan();

            app(PlanningService::class)->addItem($this->companyId, (int) $plan->id, [
                'description' => 'Network switches',
                'estimated_value' => 250_000,
            ]);

            $position = app(PlanningService::class)->budgetPosition($this->companyId, (int) $plan->id);

            // "R0 available" is a claim; "we cannot see the budget" is a fact. Only one of them is
            // safe to put in front of a manager approving a plan.
            $this->assertFalse($position['budget_available']);
            $this->assertNull($position['available']);
            $this->assertNull($position['allocated']);
            $this->assertFalse($position['over_budget']);

            // The planned figure is the module's own and stays real.
            $this->assertEqualsWithDelta(250_000.0, (float) $position['planned'], 0.01);
        });
    }

    public function test_the_budget_bridge_answers_null_rather_than_throwing_when_absent(): void
    {
        $this->withModuleOff('budget_management', function () {
            $bridge = app(BudgetBridge::class);

            $this->assertFalse($bridge->isAvailable());
            $this->assertNull($bridge->amounts(1));
            $this->assertNull($bridge->check(1, 100.0));
            $this->assertNull($bridge->reserve(1, 100.0, 'procgov_plan_items', 1));
            $this->assertSame([], $bridge->selectable());
        });
    }

    public function test_a_budget_rule_skips_rather_than_passes_when_the_module_is_absent(): void
    {
        $this->withModuleOff('budget_management', function () {
            $handler = app(BudgetAvailabilityHandler::class);

            $outcome = $handler->evaluate(new RuleSubject(
                companyId: $this->companyId,
                type: 'procgov_deviations',
                id: 1,
                amount: 500_000.0,
                budgetId: 1,
            ), []);

            // A compliance dashboard must never go green for a check that never ran.
            $this->assertSame(RuleOutcome::SKIPPED, $outcome->outcome);
            $this->assertStringContainsString('not installed', $outcome->message);
        });
    }

    // ── Standalone: no Supplier Management ──────────────────────────────────────────────────────

    public function test_the_supplier_bridge_degrades_without_the_supplier_module(): void
    {
        $this->withModuleOff('supplier_management', function () {
            $bridge = app(SupplierBridge::class);

            $this->assertFalse($bridge->isAvailable());
            $this->assertNull($bridge->compliance(1, $this->companyId));
            $this->assertSame([], $bridge->search($this->companyId));
            $this->assertFalse($bridge->statistics($this->companyId, now()->subYear(), now())['available']);
        });
    }

    public function test_a_bid_can_still_be_recorded_from_an_unregistered_supplier(): void
    {
        // A bidder that is not on the supplier register must still be evaluable, or an open tender
        // becomes a closed panel by accident.
        $this->withModuleOff('supplier_management', function () {
            $reviewId = DB::table('procgov_tender_reviews')->insertGetId([
                'uuid' => (string) Str::uuid(),
                'company_id' => $this->companyId,
                'reference' => 'TR-STANDALONE-'.Str::random(4),
                'title' => 'Cleaning services',
                'methodology' => 'WEIGHTED',
                'status' => 'CRITERIA_SETUP',
                'currency' => 'ZAR',
                'created_at' => now(), 'updated_at' => now(),
            ]);

            $bidder = app(TenderReviewService::class)
                ->addBidder($this->companyId, $reviewId, [
                    'supplier_name' => 'Unregistered Bidder CC',
                    'bid_amount' => 120_000,
                ]);

            $this->assertNull($bidder->supplier_id);
            $this->assertSame('Unregistered Bidder CC', $bidder->supplier_name);
        });
    }

    // ── Standalone: no rules configured ─────────────────────────────────────────────────────────

    public function test_a_tenant_with_no_rule_set_passes_with_nothing_evaluated(): void
    {
        // The module must be usable from the first click rather than blocking every submission
        // until somebody finishes configuring a framework.
        DB::table('procgov_rule_sets')->where('company_id', $this->companyId)->update(['is_active' => false]);

        $check = app(RulesEngine::class)->check(new RuleSubject(
            companyId: $this->companyId,
            type: 'procgov_deviations',
            id: 1,
            reference: 'DEV-STANDALONE',
            amount: 10_000.0,
        ));

        $this->assertSame('PASS', $check->result);
        $this->assertSame(0, (int) $check->rules_evaluated);
    }

    // ── Tenancy ─────────────────────────────────────────────────────────────────────────────────

    public function test_reference_sequences_are_independent_per_tenant(): void
    {
        $other = $this->otherCompanyId($this->companyId);

        $generator = app(ReferenceGenerator::class);

        $mine = $generator->claim($this->companyId, 'tenancy-probe', 2026);
        $theirs = $generator->claim((int) $other, 'tenancy-probe', 2026);
        $mineAgain = $generator->claim($this->companyId, 'tenancy-probe', 2026);

        // Each tenant counts from its own 1; one company's volume must not advance another's
        // numbering, or a reference gap looks like a deleted record.
        $this->assertSame(1, $mine);
        $this->assertSame(1, $theirs);
        $this->assertSame(2, $mineAgain);
    }

    public function test_a_plan_belongs_to_its_tenant_and_is_not_visible_to_another(): void
    {
        $other = $this->otherCompanyId($this->companyId);

        $plan = $this->plan();

        $this->assertSame($this->companyId, (int) $plan->company_id);

        $visibleToOther = DB::table('procgov_plans')
            ->where('company_id', (int) $other)
            ->where('id', $plan->id)
            ->exists();

        $this->assertFalse($visibleToOther);
    }

    public function test_every_module_table_carries_a_tenant_column(): void
    {
        // A table without company_id cannot be isolated, by row-level security or by anything else.
        $tables = DB::table('information_schema.tables')
            ->where('table_schema', 'public')
            ->where('table_name', 'like', 'procgov\_%')
            ->pluck('table_name');

        $this->assertGreaterThan(40, $tables->count());

        foreach ($tables as $table) {
            $this->assertTrue(
                DB::table('information_schema.columns')
                    ->where('table_schema', 'public')
                    ->where('table_name', $table)
                    ->where('column_name', 'company_id')
                    ->exists(),
                "{$table} has no company_id and therefore cannot be tenant-isolated.",
            );
        }
    }

    public function test_every_module_table_has_row_level_security_forced(): void
    {
        // FORCE matters: without it the table owner bypasses the policy, and the application
        // connects as the owner.
        $unprotected = DB::select("
            select c.relname
            from pg_class c
            join pg_namespace n on n.oid = c.relnamespace
            where n.nspname = 'public'
              and c.relkind = 'r'
              and c.relname like 'procgov\\_%'
              and (c.relrowsecurity = false or c.relforcerowsecurity = false)
        ");

        $this->assertSame(
            [],
            array_map(fn ($row) => $row->relname, $unprotected),
            'These procurement governance tables are missing FORCE row-level security.',
        );
    }

    public function test_every_module_table_has_a_tenant_isolation_policy(): void
    {
        $missing = DB::select("
            select c.relname
            from pg_class c
            join pg_namespace n on n.oid = c.relnamespace
            where n.nspname = 'public'
              and c.relkind = 'r'
              and c.relname like 'procgov\\_%'
              and not exists (
                  select 1 from pg_policy p
                  where p.polrelid = c.oid and p.polname = 'tenant_isolation'
              )
        ");

        $this->assertSame([], array_map(fn ($row) => $row->relname, $missing));
    }

    // ── Capability gating (§12) ─────────────────────────────────────────────────────────────────

    public function test_switching_a_capability_off_hides_only_that_capability(): void
    {
        app(SettingsService::class)
            ->update($this->companyId, ['cap_deviations' => false]);

        $capabilities = app(Capabilities::class);
        $capabilities->forget($this->companyId);

        $this->assertFalse($capabilities->has('deviations'));
        $this->assertTrue($capabilities->has('planning'));
        $this->assertTrue($capabilities->has('tender_evaluation'));
    }

    public function test_no_capability_is_available_when_the_module_itself_is_off(): void
    {
        $this->withModuleOff('procurement_governance', function () {
            $capabilities = app(Capabilities::class);
            $capabilities->forget($this->companyId);

            $this->assertFalse($capabilities->moduleAvailable());
            $this->assertSame([], $capabilities->enabled());

            foreach (Capabilities::ALL as $capability) {
                $this->assertFalse($capabilities->has($capability));
            }
        });
    }

    public function test_an_unknown_capability_is_never_granted(): void
    {
        $this->assertFalse(app(Capabilities::class)->has('everything'));
    }
}
