<?php

namespace Tests\Feature\Budget;

use App\Modules\Budget\Exceptions\BudgetBlockedException;
use App\Modules\Budget\Models\Budget;
use App\Modules\Budget\Models\BudgetAlert;
use App\Modules\Budget\Models\BudgetAlertRule;
use App\Modules\Budget\Services\BudgetService;
use App\Modules\Budget\Services\BudgetTrackingService;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Budget tracking: thresholds that warn, thresholds that stop, and the arithmetic underneath.
 *
 * The rules defended here: a rule only fires once and re-stamps thereafter, it resolves itself when
 * the condition clears, a blocking rule refuses through the same gate every other module calls, and
 * an overrun is reported whether or not anybody wrote a rule about it.
 */
class BudgetTrackingTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $companyId = DB::table('companies')->min('id');

        if ($companyId === null) {
            $this->markTestSkipped('No company in the test database.');
        }

        $this->companyId = (int) $companyId;

        // Act as a request inside a tenant does. `company_id` is not fillable on these
        // models by design — the trait owns it — so passing it to create() did nothing and
        // the fixtures were silently landing unowned.
        app(CurrentCompany::class)->set($this->companyId);
    }

    public function test_the_position_counts_reserved_and_committed_as_spoken_for(): void
    {
        $budget = $this->budget(1_000_000);
        $budgets = app(BudgetService::class);

        $budgets->reserveBudget($budget, 100_000);
        $budgets->commitBudget($budget, 200_000);
        $budgets->consumeBudget($budget, 50_000);

        $amounts = $budgets->amounts($budget);

        $this->assertSame(650_000.0, $amounts['available']);
        $this->assertSame(35.0, app(BudgetTrackingService::class)->percentages($amounts)['committed_pct']);
    }

    public function test_a_pending_reservation_holds_nothing(): void
    {
        $budget = $this->budget(1_000_000);
        $budgets = app(BudgetService::class);

        // Routed for approval: it must not take the money off the balance before it is granted.
        $budgets->reserveBudget($budget, 400_000, 'manual', null, null, 'pending');

        $this->assertSame(1_000_000.0, $budgets->amounts($budget)['available']);
    }

    public function test_a_warning_rule_does_not_block_and_a_blocking_rule_does(): void
    {
        $budget = $this->budget(1_000_000);
        app(BudgetService::class)->consumeBudget($budget, 850_000);

        $this->rule('Warn at 80', 80, 'warning');
        $this->rule('Stop at 90', 90, 'block');

        $tracking = app(BudgetTrackingService::class);

        $this->assertSame(['Warn at 80'], $tracking->breaches($budget)->pluck('name')->all());
        $this->assertNull($tracking->blockReason($budget));

        app(BudgetService::class)->consumeBudget($budget, 60_000);

        $this->assertStringContainsString('Stop at 90', (string) $tracking->blockReason($budget->fresh()));
    }

    public function test_an_encumbrance_beyond_the_headroom_is_refused_with_the_numbers(): void
    {
        $budget = $this->budget(1_000_000);
        app(BudgetService::class)->consumeBudget($budget, 900_000);

        $this->expectException(BudgetBlockedException::class);
        // 100,000 is left; asking for 200,000 has to say so rather than failing generically.
        $this->expectExceptionMessageMatches('/100,000\.00/');

        app(BudgetTrackingService::class)->assertCanEncumber($budget, 200_000);
    }

    public function test_the_availability_gate_every_module_calls_honours_a_blocking_rule(): void
    {
        $budget = $this->budget(1_000_000);
        app(BudgetService::class)->consumeBudget($budget, 950_000);
        $this->rule('Stop at 90', 90, 'block');

        // A rule enforced only on the Budget module's own pages is enforced only on the people who
        // use them. This is the check procurement and the marketplace go through.
        $check = app(BudgetService::class)->validateBudgetAvailability($budget, 1.0);

        $this->assertFalse($check['ok']);
        $this->assertTrue($check['blocked']);
        $this->assertStringContainsString('Stop at 90', (string) $check['reason']);
    }

    public function test_a_sweep_raises_once_re_stamps_thereafter_and_resolves_when_it_clears(): void
    {
        $budget = $this->budget(1_000_000);
        app(BudgetService::class)->consumeBudget($budget, 850_000);
        $rule = $this->rule('Warn at 80', 80, 'warning');

        $tracking = app(BudgetTrackingService::class);

        $this->assertSame(1, $tracking->sweep(notify: false)['raised']);
        // Re-running must not grow the page by a row a night.
        $this->assertSame(0, $tracking->sweep(notify: false)['raised']);
        $this->assertSame(1, BudgetAlert::live()->where('budget_id', $budget->id)->count());

        // Condition clears — an increase lands — and the alert closes itself.
        $budget->update(['allocated_amount' => 2_000_000]);

        $this->assertSame(1, $tracking->sweep(notify: false)['resolved']);
        $this->assertSame(0, BudgetAlert::live()->where('budget_id', $budget->id)->count());
        $this->assertNotNull($rule->fresh());
    }

    public function test_an_overrun_is_reported_with_no_rules_at_all(): void
    {
        $budget = $this->budget(1_000_000);
        app(BudgetService::class)->consumeBudget($budget, 1_200_000);

        $result = app(BudgetTrackingService::class)->sweep(notify: false);

        $this->assertGreaterThanOrEqual(1, $result['raised']);
        $this->assertTrue(
            BudgetAlert::live()->where('budget_id', $budget->id)->whereNull('rule_id')->exists(),
            'A negative balance is arithmetic, not a preference somebody has to configure.'
        );
    }

    public function test_a_decrease_cannot_strand_money_that_is_already_committed(): void
    {
        $budget = $this->budget(1_000_000);
        $budgets = app(BudgetService::class);
        $budgets->commitBudget($budget, 900_000);

        $adjustment = $budgets->adjustBudget($budget, 'decrease', 500_000);

        $this->expectException(\RuntimeException::class);
        $budgets->approveAdjustment($adjustment);
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function budget(float $allocated): Budget
    {
        return Budget::create([
            'code' => 'BGT'.Str::random(6), 'name' => 'Tracked budget',
            'expenditure_type' => 'opex', 'scope_type' => 'general',
            'currency' => 'ZAR', 'allocated_amount' => $allocated, 'status' => 'active',
        ]);
    }

    private function rule(string $name, float $threshold, string $severity): BudgetAlertRule
    {
        return BudgetAlertRule::create([ 'name' => $name, 'scope_type' => 'all',
            'metric' => 'committed_pct', 'threshold' => $threshold, 'severity' => $severity,
            'notify_owners' => false, 'is_active' => true,
        ]);
    }
}
