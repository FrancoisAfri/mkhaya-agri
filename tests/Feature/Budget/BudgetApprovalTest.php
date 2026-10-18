<?php

namespace Tests\Feature\Budget;

use App\Modules\Budget\Models\Budget;
use App\Modules\Budget\Models\BudgetApprovalFlow;
use App\Modules\Budget\Models\BudgetApprovalStep;
use App\Modules\Budget\Services\BudgetApprovalService;
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
 * Configurable budget approval routing.
 *
 * The rules defended here are the ones the feature exists for: a threshold means small things are
 * not routed, a designed ladder is climbed in order, the person who asked cannot be the person who
 * signs unless a step says so, a rejection ends it at whatever rung, and a decision only reaches
 * the money once the ladder is finished.
 */
class BudgetApprovalTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

    private object $submitter;

    private object $approver;

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
        $this->submitter = $this->user();
        $this->approver = $this->user();
    }

    public function test_a_submission_below_the_threshold_is_not_routed(): void
    {
        $budget = $this->budget();
        $this->flow('adjustment', threshold: 10_000);

        Auth::login($this->submitter);

        $adjustment = app(BudgetService::class)->adjustBudget($budget, 'increase', 500, 'small', $this->submitter->id);

        $this->assertNull(
            $this->approvals()->submit('adjustment', $adjustment, $budget, ['amount' => 500.0]),
            'A company that set a threshold does not want small corrections crossing desks.'
        );
    }

    public function test_a_submission_above_the_threshold_routes_to_the_named_approver(): void
    {
        $budget = $this->budget();
        $this->flow('adjustment', threshold: 10_000);

        Auth::login($this->submitter);

        $adjustment = app(BudgetService::class)->adjustBudget($budget, 'increase', 50_000, 'big', $this->submitter->id);
        $request = $this->approvals()->submit('adjustment', $adjustment, $budget, ['amount' => 50_000.0]);

        $this->assertNotNull($request);
        $this->assertSame('pending', $request->status);
        $this->assertSame('Finance', $request->currentStep()->name);
    }

    public function test_the_submitter_cannot_approve_their_own_request(): void
    {
        $budget = $this->budget();
        $this->flow('adjustment');

        Auth::login($this->submitter);

        $adjustment = app(BudgetService::class)->adjustBudget($budget, 'increase', 5_000, null, $this->submitter->id);
        $request = $this->approvals()->submit('adjustment', $adjustment, $budget, ['amount' => 5_000.0]);

        $this->assertFalse($this->approvals()->canAct($request, $request->currentStep(), $this->submitter));
        $this->assertTrue($this->approvals()->canAct($request, $request->currentStep(), $this->approver));
    }

    public function test_a_step_can_waive_separation_of_duties(): void
    {
        $budget = $this->budget();
        // The two-person business that genuinely needs it. Off by default, on deliberately.
        $flow = $this->flow('adjustment');
        $flow->steps()->update(['allow_self_approval' => true, 'approver_ref' => (string) $this->submitter->id]);

        Auth::login($this->submitter);

        $adjustment = app(BudgetService::class)->adjustBudget($budget, 'increase', 5_000, null, $this->submitter->id);
        $request = $this->approvals()->submit('adjustment', $adjustment, $budget, ['amount' => 5_000.0]);

        $this->assertTrue($this->approvals()->canAct($request->fresh(), $request->fresh()->currentStep(), $this->submitter));
    }

    public function test_a_two_step_ladder_is_climbed_in_order(): void
    {
        $budget = $this->budget();
        $flow = $this->flow('adjustment');
        $second = $this->user();

        BudgetApprovalStep::create([
            'flow_id' => $flow->id, 'position' => 2, 'name' => 'CFO',
            'approver_type' => 'user', 'approver_ref' => (string) $second->id, 'min_approvals' => 1,
        ]);

        Auth::login($this->submitter);
        $adjustment = app(BudgetService::class)->adjustBudget($budget, 'increase', 5_000, null, $this->submitter->id);
        $request = $this->approvals()->submit('adjustment', $adjustment, $budget, ['amount' => 5_000.0]);

        Auth::login($this->approver);
        $afterFirst = $this->approvals()->approve($request, 'first rung');

        // Still pending: an intermediate signature approves that rung, not the thing.
        $this->assertSame('pending', $afterFirst->status);
        $this->assertSame(2, $afterFirst->current_position);

        Auth::login($second);
        $this->assertSame('approved', $this->approvals()->approve($afterFirst)->status);
    }

    public function test_two_signatures_are_two_people(): void
    {
        $budget = $this->budget();
        $flow = $this->flow('adjustment');
        $flow->steps()->update(['approver_type' => 'role', 'approver_ref' => $this->role(), 'min_approvals' => 2]);

        $second = $this->user();
        $this->approver->assignRole($flow->steps()->first()->approver_ref);
        $second->assignRole($flow->steps()->first()->approver_ref);

        Auth::login($this->submitter);
        $adjustment = app(BudgetService::class)->adjustBudget($budget, 'increase', 5_000, null, $this->submitter->id);
        $request = $this->approvals()->submit('adjustment', $adjustment, $budget, ['amount' => 5_000.0]);

        Auth::login($this->approver);
        $once = $this->approvals()->approve($request);
        $this->assertSame('pending', $once->status, 'One signature does not satisfy a two-signature rule.');

        // The same person cannot supply the second signature by clicking again.
        $this->expectException(InvalidArgumentException::class);
        $this->approvals()->approve($once);
    }

    public function test_a_rejection_ends_it_and_carries_its_reason(): void
    {
        $budget = $this->budget();
        $this->flow('adjustment');

        Auth::login($this->submitter);
        $adjustment = app(BudgetService::class)->adjustBudget($budget, 'increase', 5_000, null, $this->submitter->id);
        $request = $this->approvals()->submit('adjustment', $adjustment, $budget, ['amount' => 5_000.0]);

        Auth::login($this->approver);
        $decided = $this->approvals()->reject($request, 'The motivation does not cover this.');

        $this->assertSame('rejected', $decided->status);
        $this->assertSame('The motivation does not cover this.', $decided->decision_reason);
    }

    public function test_resubmitting_supersedes_the_live_request(): void
    {
        $budget = $this->budget();
        $this->flow('adjustment');

        Auth::login($this->submitter);
        $adjustment = app(BudgetService::class)->adjustBudget($budget, 'increase', 5_000, null, $this->submitter->id);

        $first = $this->approvals()->submit('adjustment', $adjustment, $budget, ['amount' => 5_000.0]);
        $second = $this->approvals()->submit('adjustment', $adjustment, $budget, ['amount' => 5_000.0]);

        // Two live requests for the same money is the same money queued twice.
        $this->assertSame('cancelled', $first->fresh()->status);
        $this->assertSame('pending', $second->fresh()->status);
    }

    public function test_with_no_flow_designed_it_still_queues_for_whoever_may_approve(): void
    {
        $budget = $this->budget();

        Auth::login($this->submitter);
        $adjustment = app(BudgetService::class)->adjustBudget($budget, 'increase', 5_000, null, $this->submitter->id);
        $request = $this->approvals()->submit('adjustment', $adjustment, $budget, ['amount' => 5_000.0]);

        $this->assertNotNull($request, 'A tenant that never opens the designer still gets a queue.');
        $this->assertNull($request->flow_id);
        $this->assertNull($request->currentStep());
    }

    public function test_a_manager_step_is_for_the_submitters_line_manager_then_their_second(): void
    {
        $budget = $this->budget();
        $flow = $this->flow('adjustment');
        $flow->steps()->update(['approver_type' => 'manager', 'approver_ref' => null]);
        $outsider = $this->user();
        $second = $this->user();

        $managerPerson = $this->person($this->approver);
        $secondPerson = $this->person($second);
        $submitterPerson = $this->person($this->submitter, managerId: $managerPerson, secondManagerId: $secondPerson);
        $this->person($outsider);

        Auth::login($this->submitter);
        $adjustment = app(BudgetService::class)->adjustBudget($budget, 'increase', 5_000, null, $this->submitter->id);
        $request = $this->approvals()->submit('adjustment', $adjustment, $budget, ['amount' => 5_000.0]);

        $this->assertTrue($this->approvals()->canAct($request, $request->currentStep(), $this->approver));
        $this->assertFalse($this->approvals()->canAct($request, $request->currentStep(), $outsider));

        // No line manager recorded: the second manager stands in.
        DB::table('hr_people')->where('id', $submitterPerson)->update(['manager_id' => null]);
        $this->assertTrue($this->approvals()->canAct($request, $request->currentStep(), $second));
        $this->assertFalse($this->approvals()->canAct($request, $request->currentStep(), $this->approver));
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function person(object $user, ?int $managerId = null, int $secondManagerId = 0): int
    {
        return (int) DB::table('hr_people')->insertGetId(['user_id' => $user->id, 'first_name' => 'Test', 'surname' => 'Person', 'email' => $user->email,
            'status' => 1, 'manager_id' => $managerId, 'second_manager_id' => $secondManagerId, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function approvals(): BudgetApprovalService
    {
        return app(BudgetApprovalService::class);
    }

    private function budget(): Budget
    {
        return Budget::create([
            'code' => 'BGT'.Str::random(6), 'name' => 'Test budget',
            'expenditure_type' => 'opex', 'scope_type' => 'general',
            'currency' => 'ZAR', 'allocated_amount' => 1_000_000, 'status' => 'active',
        ]);
    }

    private function flow(string $subjectType, ?float $threshold = null): BudgetApprovalFlow
    {
        $flow = BudgetApprovalFlow::create([ 'name' => 'Test flow',
            'subject_type' => $subjectType, 'is_active' => true, 'threshold_amount' => $threshold,
        ]);

        BudgetApprovalStep::create([ 'flow_id' => $flow->id, 'position' => 1, 'name' => 'Finance',
            'approver_type' => 'user', 'approver_ref' => (string) $this->approver->id, 'min_approvals' => 1,
        ]);

        return $flow->load('steps');
    }

    private function user(): object
    {
        $userClass = config('auth.providers.users.model');

        return $userClass::create([
            'uuid' => (string) Str::uuid(),
            'email' => 'budget-'.Str::random(10).'@example.test',
            'password' => bcrypt('secret'),
        ]);
    }

    private function role(): string
    {
        $name = 'budget-approver-'.Str::random(6);

        \Spatie\Permission\Models\Role::findOrCreate($name, 'web');

        return $name;
    }
}
