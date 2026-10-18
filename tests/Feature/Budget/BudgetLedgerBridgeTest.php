<?php

namespace Tests\Feature\Budget;

use App\Modules\Budget\Models\Budget;
use App\Modules\Budget\Models\FinancialYear;
use App\Modules\Budget\Services\BudgetLedgerBridge;
use App\Modules\Budget\Services\BudgetResolver;
use App\Modules\Budget\Services\BudgetService;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The one door every other module uses to tell the budget about money.
 *
 * The rules defended here: a cost lands on the most specific budget that describes it, the same
 * reference can never charge a budget twice, an unmapped cost is reported rather than guessed at,
 * spend is never refused even when it takes the budget over, a commitment is, and none of it ever
 * throws into the module that called it.
 */
class BudgetLedgerBridgeTest extends TestCase
{
    use DatabaseTransactions;

    private FinancialYear $year;

    protected function setUp(): void
    {
        parent::setUp();

        $companyId = DB::table('companies')->min('id');

        if ($companyId === null) {
            $this->markTestSkipped('No company in the test database.');
        }

        app(CurrentCompany::class)->set((int) $companyId);

        // The bridge is a no-op without the module installed, which is correct — but a test that
        // skips on it is a test that never runs.
        DB::table('security_modules')->updateOrInsert(
            ['code_name' => 'budget_management'],
            ['uuid' => (string) Str::uuid(), 'name' => 'Budget Management', 'active' => 1,
             'path' => '/budgets', 'category_id' => $this->categoryId(),
             'created_at' => now(), 'updated_at' => now()],
        );

        $this->year = FinancialYear::create([
            'name' => 'Bridge FY '.Str::random(4),
            'start_date' => Carbon::now()->startOfYear()->toDateString(),
            'end_date'   => Carbon::now()->endOfYear()->toDateString(),
        ]);

        $this->assertTrue($this->bridge()->enabled());
    }

    public function test_a_cost_lands_on_the_department_budget_that_describes_it(): void
    {
        $general = $this->budget('General', 'general', null);
        $department = $this->budget('Ops department', 'department', 42);

        $result = $this->bridge()->postSpend(
            sourceType: 'payroll_run', sourceId: 1, amount: 100_000,
            scope: ['department_id' => 42], reference: 'run:1',
        );

        $this->assertSame('posted', $result->outcome);
        $this->assertSame($department->id, $result->budgetId, 'The most specific budget wins.');
        $this->assertSame(0.0, app(BudgetService::class)->amounts($general)['actual']);
    }

    public function test_a_cost_with_no_matching_department_falls_to_the_general_budget(): void
    {
        $general = $this->budget('General', 'general', null);

        $result = $this->bridge()->postSpend(
            sourceType: 'payroll_run', sourceId: 2, amount: 50_000,
            scope: ['department_id' => 999], reference: 'run:2',
        );

        $this->assertSame($general->id, $result->budgetId);
        $this->assertSame(50_000.0, app(BudgetService::class)->amounts($general)['actual']);
    }

    public function test_the_same_reference_can_never_charge_the_budget_twice(): void
    {
        $general = $this->budget('General', 'general', null);

        $first = $this->bridge()->postSpend(sourceType: 'payroll_run', sourceId: 3, amount: 75_000, reference: 'run:3');
        $second = $this->bridge()->postSpend(sourceType: 'payroll_run', sourceId: 3, amount: 75_000, reference: 'run:3');

        $this->assertSame('posted', $first->outcome);
        // A corrected run re-released, or a retried job. Either way the money went out once.
        $this->assertSame('duplicate', $second->outcome);
        $this->assertSame($first->ledgerId, $second->ledgerId);
        $this->assertSame(75_000.0, app(BudgetService::class)->amounts($general)['actual']);
    }

    public function test_an_unmapped_cost_is_reported_rather_than_guessed_at(): void
    {
        // No budgets at all. Posting to whatever sorted first would be a number that is wrong and
        // believed, which is worse than one that is missing.
        $result = $this->bridge()->postSpend(sourceType: 'fleet_cost', sourceId: 9, amount: 1_000, reference: 'fleet:9');

        $this->assertSame('unmapped', $result->outcome);
        $this->assertNull($result->budgetId);
        $this->assertFalse($result->landed());
    }

    public function test_spend_is_recorded_even_when_it_takes_the_budget_over(): void
    {
        $general = $this->budget('General', 'general', null, allocated: 10_000);

        $result = $this->bridge()->postSpend(sourceType: 'payroll_run', sourceId: 4, amount: 40_000, reference: 'run:4');

        // By the time payroll has run the money has gone. A budget that refuses to record it looks
        // healthier than it is — the one outcome worse than an overrun.
        $this->assertSame('posted', $result->outcome);
        $this->assertSame(-30_000.0, app(BudgetService::class)->amounts($general)['available']);
    }

    public function test_a_commitment_can_be_released_by_its_reference(): void
    {
        $general = $this->budget('General', 'general', null);

        $this->bridge()->postCommitment(sourceType: 'contract', sourceId: 7, amount: 20_000, reference: 'contract:7');
        $this->assertSame(20_000.0, app(BudgetService::class)->amounts($general)['committed']);

        $released = $this->bridge()->release('contract:7');

        $this->assertSame('posted', $released->outcome);
        $this->assertSame(0.0, app(BudgetService::class)->amounts($general)['committed']);
    }

    public function test_a_draft_budget_never_takes_a_posting(): void
    {
        $this->budget('Not approved yet', 'general', null, status: 'draft');

        // A draft is a plan nobody has agreed to. Spending against it would move a number that has
        // not been signed off.
        $this->assertSame('unmapped', $this->bridge()
            ->postSpend(sourceType: 'payroll_run', sourceId: 5, amount: 1_000, reference: 'run:5')
            ->outcome);
    }

    public function test_it_never_throws_into_the_module_that_called_it(): void
    {
        $this->budget('General', 'general', null);

        // A nonsense scope, a zero amount, a missing reference — none of it may unwind the caller's
        // own work, which has already succeeded by the time the bridge is called.
        $this->assertSame('unmapped', $this->bridge()->postSpend(sourceType: 'x', sourceId: null, amount: 0)->outcome);
        $this->assertSame('posted', $this->bridge()->postSpend(sourceType: 'x', sourceId: null, amount: 5)->outcome);
    }

    public function test_the_resolver_picks_the_year_the_cost_falls_in(): void
    {
        $thisYear = $this->budget('This year', 'general', null);

        $lastYear = FinancialYear::create([
            'name' => 'Last year', 'start_date' => Carbon::now()->subYear()->startOfYear()->toDateString(),
            'end_date' => Carbon::now()->subYear()->endOfYear()->toDateString(),
        ]);

        $old = Budget::create([
            'code' => 'BGT'.Str::random(6), 'name' => 'Last year general',
            'financial_year_id' => $lastYear->id, 'expenditure_type' => 'opex',
            'scope_type' => 'general', 'allocated_amount' => 500_000, 'status' => 'active',
        ]);

        $resolver = app(BudgetResolver::class);

        $this->assertSame($thisYear->id, $resolver->resolve([], Carbon::now())?->id);
        $this->assertSame($old->id, $resolver->resolve([], Carbon::now()->subYear())?->id);
    }

    public function test_spend_can_be_taken_back_when_it_should_not_have_been_recorded(): void
    {
        $general = $this->budget('General', 'general', null);

        $this->bridge()->postSpend(sourceType: 'fleet_fuel', sourceId: 11, amount: 3_000, reference: 'fleet_fuel:11');
        $this->assertSame(3_000.0, app(BudgetService::class)->amounts($general)['actual']);

        // The ordinary correction: a mistyped fuel log deleted and re-entered. Without this the
        // budget carries the wrong figure and the right one.
        $reversed = $this->bridge()->reverseSpend('fleet_fuel:11');

        $this->assertSame('posted', $reversed->outcome);
        $this->assertSame(0.0, app(BudgetService::class)->amounts($general)['actual']);
    }

    public function test_reversing_something_that_was_never_posted_says_so(): void
    {
        $this->budget('General', 'general', null);

        $this->assertSame('unmapped', $this->bridge()->reverseSpend('never:1')->outcome);
    }

    public function test_a_reversed_reference_can_be_posted_again(): void
    {
        $general = $this->budget('General', 'general', null);

        $this->bridge()->postSpend(sourceType: 'fleet_fuel', sourceId: 12, amount: 3_000, reference: 'fleet_fuel:12');
        $this->bridge()->reverseSpend('fleet_fuel:12');

        // Re-entering the corrected cost must not be refused as a duplicate of the one taken back.
        $again = $this->bridge()->postSpend(sourceType: 'fleet_fuel', sourceId: 12, amount: 2_400, reference: 'fleet_fuel:12');

        $this->assertSame('posted', $again->outcome);
        $this->assertSame(2_400.0, app(BudgetService::class)->amounts($general)['actual']);
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function bridge(): BudgetLedgerBridge
    {
        return app(BudgetLedgerBridge::class);
    }

    private function budget(string $name, string $scopeType, ?int $scopeId, float $allocated = 1_000_000, string $status = 'active'): Budget
    {
        return Budget::create([
            'code' => 'BGT'.Str::random(6), 'name' => $name,
            'financial_year_id' => $this->year->id,
            'expenditure_type' => 'opex',
            'scope_type' => $scopeType, 'scope_id' => $scopeId,
            'allocated_amount' => $allocated, 'status' => $status,
        ]);
    }

    private function categoryId(): int
    {
        $existing = DB::table('security_categories')->min('id');

        return $existing !== null ? (int) $existing : (int) DB::table('security_categories')->insertGetId([
            'name' => 'Test Category', 'active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
