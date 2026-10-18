<?php

namespace Tests\Feature\Budget;

use App\Modules\Budget\Models\Budget;
use App\Modules\Budget\Models\BudgetApprovalDelegation;
use App\Modules\Budget\Models\BudgetApprovalFlow;
use App\Modules\Budget\Models\BudgetApprovalStep;
use App\Modules\Budget\Services\BudgetApprovalService;
use App\Modules\Budget\Services\BudgetRollupService;
use App\Modules\Budget\Services\BudgetService;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Delegated approvals, and budgets that roll up.
 *
 * Delegation defends the dull, constant failure: an approver goes on leave and the queue stops.
 * Rollup defends the one a division head notices: fourteen cost-centre numbers and never the one
 * they are accountable for.
 */
class BudgetDelegationAndRollupTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

    private object $submitter;

    private object $approver;

    private object $standIn;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $companyId = DB::table('companies')->min('id');

        if ($companyId === null) {
            $this->markTestSkipped('No company in the test database.');
        }

        $this->companyId = (int) $companyId;
        app(CurrentCompany::class)->set($this->companyId);

        $this->submitter = $this->user();
        $this->approver = $this->user();
        $this->standIn = $this->user();

    }

    // ── Delegation ───────────────────────────────────────────────────────────

    public function test_a_stand_in_can_decide_what_the_person_on_leave_could_have(): void
    {
        $request = $this->pendingRequest();

        $this->assertFalse($this->approvals()->canAct($request, $request->currentStep(), $this->standIn));

        $this->delegate();

        $this->assertTrue($this->approvals()->canAct($request->fresh(), $request->currentStep(), $this->standIn));
    }

    public function test_the_person_on_leave_can_still_act_themselves(): void
    {
        $request = $this->pendingRequest();
        $this->delegate();

        // A delegation adds an approver; it does not move the right. People log in from the airport.
        $this->assertTrue($this->approvals()->canAct($request, $request->currentStep(), $this->approver));
    }

    public function test_a_delegation_outside_its_dates_does_nothing(): void
    {
        $request = $this->pendingRequest();

        $this->delegate(startsOn: now()->addWeek(), endsOn: now()->addWeeks(2));

        $this->assertFalse($this->approvals()->canAct($request, $request->currentStep(), $this->standIn));
    }

    public function test_a_delegation_capped_by_amount_does_not_cover_more_than_its_cap(): void
    {
        $request = $this->pendingRequest(amount: 80_000);

        $this->delegate(maxAmount: 50_000);

        // "Up to R50,000 while I am away" has to mean it. The original approver remains the only
        // person who can decide the rest, which is the correct answer rather than a gap.
        $this->assertFalse($this->approvals()->canAct($request, $request->currentStep(), $this->standIn));
    }

    public function test_a_delegation_narrowed_to_another_subject_does_not_cover_this_one(): void
    {
        $request = $this->pendingRequest();

        $this->delegate(subjectType: 'override');

        $this->assertFalse($this->approvals()->canAct($request, $request->currentStep(), $this->standIn));
    }

    public function test_a_stand_in_cannot_approve_what_the_person_on_leave_submitted(): void
    {
        // Standing in for somebody does not launder their own submission through a second name.
        $flow = $this->flow();
        $flow->steps()->update(['approver_ref' => (string) $this->approver->id]);

        Auth::login($this->approver);
        $budget = $this->budget('SoD budget');
        $adjustment = app(BudgetService::class)->adjustBudget($budget, 'increase', 5_000, null, $this->approver->id);
        $request = $this->approvals()->submit('adjustment', $adjustment, $budget, ['amount' => 5_000.0]);

        $this->delegate();

        $this->assertFalse($this->approvals()->canAct($request, $request->currentStep(), $this->standIn));
    }

    public function test_a_stand_in_actually_decides_it(): void
    {
        $request = $this->pendingRequest();
        $this->delegate();

        Auth::login($this->standIn);
        $decided = $this->approvals()->approve($request, 'covering while away');

        $this->assertSame('approved', $decided->status);
        $this->assertSame(1, $decided->actions()->where('decision', 'approved')->count());
    }

    public function test_a_second_delegation_over_the_same_days_is_refused(): void
    {
        $this->delegate(startsOn: now()->subDay(), endsOn: now()->addWeek());

        // Two live delegations covering the same day is two people believing they hold the
        // approvals, and a trail that cannot say which of them was meant to.
        $this->assertTrue(BudgetApprovalDelegation::hasOverlap(
            (int) $this->approver->id, now()->toDateString(), now()->addDays(3)->toDateString(),
        ));
    }

    public function test_a_delegation_after_the_last_one_ends_is_allowed(): void
    {
        $this->delegate(startsOn: now()->subWeek(), endsOn: now()->subDay());

        $this->assertFalse(BudgetApprovalDelegation::hasOverlap(
            (int) $this->approver->id, now()->toDateString(), now()->addWeek()->toDateString(),
        ));
    }

    public function test_an_open_ended_delegation_blocks_everything_after_it(): void
    {
        // Open-ended means until somebody ends it, so nothing can be scheduled behind it.
        $this->delegate(startsOn: now()->subDay(), endsOn: null);

        $this->assertTrue(BudgetApprovalDelegation::hasOverlap(
            (int) $this->approver->id, now()->addYear()->toDateString(), null,
        ));
    }

    // ── Rollup ───────────────────────────────────────────────────────────────

    public function test_a_parent_carries_its_childrens_position(): void
    {
        Auth::login($this->submitter);

        $division = $this->budget('Division', 400_000);
        $branchA = $this->budget('Branch A', 300_000);
        $branchB = $this->budget('Branch B', 300_000);

        $rollup = app(BudgetRollupService::class);
        $rollup->setParent($branchA, $division->id);
        $rollup->setParent($branchB, $division->id);

        app(BudgetService::class)->consumeBudget($branchA, 100_000);
        app(BudgetService::class)->consumeBudget($division, 50_000);

        $consolidated = $rollup->consolidated($division->fresh());

        $this->assertSame(1_000_000.0, $consolidated['allocated']);
        $this->assertSame(150_000.0, $consolidated['actual']);
        $this->assertSame(850_000.0, $consolidated['available']);
    }

    public function test_a_child_in_overrun_reduces_the_parents_headroom(): void
    {
        Auth::login($this->submitter);

        $division = $this->budget('Division', 100_000);
        $branch = $this->budget('Branch', 100_000);
        app(BudgetRollupService::class)->setParent($branch, $division->id);

        app(BudgetService::class)->consumeBudget($branch, 180_000);

        // A consolidation that floored each child at zero would hide exactly what it exists to show.
        $consolidated = app(BudgetRollupService::class)->consolidated($division->fresh());

        $this->assertSame(20_000.0, $consolidated['available']);
    }

    public function test_a_budget_cannot_roll_up_into_its_own_descendant(): void
    {
        Auth::login($this->submitter);

        $parent = $this->budget('Parent');
        $child = $this->budget('Child');
        app(BudgetRollupService::class)->setParent($child, $parent->id);

        // What happens the first time somebody reorganises a division by dragging.
        $this->expectException(InvalidArgumentException::class);
        app(BudgetRollupService::class)->setParent($parent, $child->id);
    }

    public function test_a_budget_cannot_roll_up_into_itself(): void
    {
        Auth::login($this->submitter);

        $budget = $this->budget('Lonely');

        $this->expectException(InvalidArgumentException::class);
        app(BudgetRollupService::class)->setParent($budget, $budget->id);
    }

    public function test_the_tree_nests_children_under_their_parent(): void
    {
        Auth::login($this->submitter);

        $division = $this->budget('Division tree');
        $branch = $this->budget('Branch tree');
        app(BudgetRollupService::class)->setParent($branch, $division->id);

        $tree = app(BudgetRollupService::class)->tree();
        $node = collect($tree)->firstWhere('id', $division->id);

        $this->assertNotNull($node);
        $this->assertTrue($node['has_children']);
        $this->assertSame($branch->id, $node['children'][0]['id']);
        $this->assertSame(1, $node['children'][0]['depth']);
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function approvals(): BudgetApprovalService
    {
        return app(BudgetApprovalService::class);
    }

    private function delegate(
        $startsOn = null, $endsOn = 'default', ?string $subjectType = null, ?float $maxAmount = null,
    ): BudgetApprovalDelegation {
        // 'default' rather than null as the sentinel, so a caller can ask for open-ended explicitly.
        $endsOn = $endsOn === 'default' ? now()->addWeek() : $endsOn;

        return BudgetApprovalDelegation::create([
            'from_user_id' => $this->approver->id,
            'to_user_id'   => $this->standIn->id,
            'starts_on'    => ($startsOn ?? now()->subDay())->toDateString(),
            'ends_on'      => $endsOn?->toDateString(),
            'subject_type' => $subjectType,
            'max_amount'   => $maxAmount,
            'is_active'    => true,
        ]);
    }

    private function pendingRequest(float $amount = 5_000)
    {
        $flow = $this->flow();
        $flow->steps()->update(['approver_ref' => (string) $this->approver->id]);

        Auth::login($this->submitter);
        $budget = $this->budget('Delegation budget');
        $adjustment = app(BudgetService::class)->adjustBudget($budget, 'increase', $amount, null, $this->submitter->id);

        return $this->approvals()->submit('adjustment', $adjustment, $budget, ['amount' => $amount]);
    }

    private function flow(): BudgetApprovalFlow
    {
        $flow = BudgetApprovalFlow::create([
            'name' => 'Delegation flow', 'subject_type' => 'adjustment', 'is_active' => true,
        ]);

        BudgetApprovalStep::create([
            'flow_id' => $flow->id, 'position' => 1, 'name' => 'Finance',
            'approver_type' => 'user', 'approver_ref' => (string) $this->approver->id, 'min_approvals' => 1,
        ]);

        return $flow->load('steps');
    }

    private function budget(string $name, float $allocated = 1_000_000): Budget
    {
        return Budget::create([
            'code' => 'BGT'.Str::random(6), 'name' => $name,
            'expenditure_type' => 'opex', 'scope_type' => 'general',
            'allocated_amount' => $allocated, 'status' => 'active',
        ]);
    }

    private function user(): object
    {
        $userClass = config('auth.providers.users.model');

        return $userClass::create([
            'uuid' => (string) Str::uuid(),
            'email' => 'deleg-'.Str::random(10).'@example.test',
            'password' => bcrypt('secret'),
            'company_id' => $this->companyId,
        ]);
    }
}
