<?php

namespace Tests\Feature\ProcurementGovernance;

use App\Modules\Procurement\Governance\Services\Capabilities;
use App\Modules\Procurement\Governance\Support\Integration\BudgetBridge;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/**
 * §2.3: the budget picker must offer the budgets a planner can actually spend against.
 *
 * The picker was empty on a tenant that had budgets, because this bridge asked for status
 * 'approved' — and approving a budget does not leave it there. BudgetService::approve() moves it to
 * 'active', so the filter excluded exactly the budgets that had been approved and admitted only
 * ones stranded mid-workflow. Nothing failed; the dropdown was simply, silently, empty, which reads
 * to a user as "this organisation has no budgets" rather than "this query is wrong".
 *
 * The definition of spendable belongs to the Budget module, so these tests pin this bridge to that
 * module's own answer rather than to a list repeated here.
 */
class BudgetBridgeTest extends TestCase
{
    use DatabaseTransactions;

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

        if (! Schema::hasTable('budgets')) {
            $this->markTestSkipped('Budget Management is not installed in this build.');
        }

        // Whether this particular tenant has bought Budget Management is a separate question, and
        // one StandaloneAndTenancyTest already covers. Answering it "yes" here keeps these tests
        // about the thing they are actually testing — which budgets are spendable — instead of
        // quietly skipping on a test database where the module happens not to be entitled.
        $capabilities = Mockery::mock(Capabilities::class)->makePartial();
        $capabilities->shouldReceive('integratesWith')->with('finance.budgets')->andReturnTrue();
        $this->app->instance(Capabilities::class, $capabilities);
        $this->app->forgetInstance(BudgetBridge::class);

        $this->assertTrue(
            app(BudgetBridge::class)->isAvailable(),
            'The bridge should report available once the tenant has the budget integration.',
        );
    }

    protected function tearDown(): void
    {
        app(CurrentCompany::class)->clear();
        parent::tearDown();
    }

    private function makeBudget(string $status): int
    {
        return DB::table('budgets')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'company_id' => $this->companyId,
            'code' => 'BGT-'.Str::upper(Str::random(6)),
            'name' => "Budget in {$status}",
            'status' => $status,
            'allocated_amount' => 100000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function selectableIds(): array
    {
        return array_column(app(BudgetBridge::class)->selectable(), 'id');
    }

    public function test_an_active_budget_is_offered_to_the_planner(): void
    {
        // The regression itself: approving a budget lands it on 'active', and it was being hidden.
        $id = $this->makeBudget('active');

        $this->assertContains($id, $this->selectableIds());
    }

    public function test_an_approved_budget_is_offered_to_the_planner(): void
    {
        $id = $this->makeBudget('approved');

        $this->assertContains($id, $this->selectableIds());
    }

    public function test_a_budget_still_working_through_approval_is_not_offered(): void
    {
        // Planning against money nobody has signed off is the thing this filter exists to prevent.
        foreach (['draft', 'pending', 'rejected'] as $status) {
            $this->assertNotContains(
                $this->makeBudget($status),
                $this->selectableIds(),
                "A {$status} budget was offered as though it could be spent.",
            );
        }
    }

    public function test_the_bridge_agrees_with_the_budget_module_on_what_spendable_means(): void
    {
        // BudgetTrackingService gates spending on the same pair. If that module widens or narrows
        // its definition, this test fails rather than the two drifting apart in silence.
        $tracking = file_get_contents(base_path('app/Modules/Budget/Services/BudgetTrackingService.php'));

        $this->assertMatchesRegularExpression(
            "/in_array\(\s*\\\$budget->status,\s*\['active',\s*'approved'\]/",
            $tracking,
            'Budget Management changed its definition of a spendable budget; BudgetBridge must follow.',
        );
    }

    public function test_budgets_are_filtered_to_the_financial_year_when_one_is_given(): void
    {
        $id = $this->makeBudget('active');

        $otherYear = ((int) DB::table('budgets')->max('financial_year_id')) + 99;

        $this->assertNotContains(
            $id,
            array_column(app(BudgetBridge::class)->selectable($otherYear), 'id'),
            'A budget from another financial year was offered.',
        );
    }

    public function test_a_budget_belonging_to_another_tenant_is_never_offered(): void
    {
        // The second tenant is created here rather than assumed. A RefreshDatabase test elsewhere
        // in the suite rebuilds this database, so a fixture company added by hand disappears and
        // this test would quietly skip — leaving cross-tenant leakage unchecked exactly when the
        // schema had just been rebuilt. DatabaseTransactions rolls it back.
        $other = DB::table('companies')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'slug' => 'budget-bridge-'.Str::lower(Str::random(8)),
            'name' => 'Other tenant',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $id = DB::table('budgets')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'company_id' => (int) $other,
            'code' => 'BGT-'.Str::upper(Str::random(6)),
            'name' => 'Another tenant budget',
            'status' => 'active',
            'allocated_amount' => 100000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertNotContains($id, $this->selectableIds());
    }
}
