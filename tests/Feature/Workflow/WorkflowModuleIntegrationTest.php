<?php

namespace Tests\Feature\Workflow;

use App\Modules\Budget\Models\Budget;
use App\Modules\Finance\Claims\Models\Claim;
use App\Modules\Finance\Claims\Models\ClaimApproval;
use App\Modules\Finance\Claims\Models\ClaimCategory;
use App\Modules\Finance\Claims\Services\ClaimsService;
use App\Modules\Procurement\Models\ProcurementApproval;
use App\Modules\Procurement\Services\PurchaseRequestService;
use App\Modules\Workflow\Models\WorkflowActionRun;
use App\Modules\Workflow\Models\WorkflowInstance;
use App\Modules\Workflow\Services\DefinitionService;
use App\Modules\Workflow\Services\Workflows;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * PRS §13: modules start workflows through the contract and get outcomes back through their own
 * validated code. The company switch decides; without it nothing changes for the module.
 */
class WorkflowModuleIntegrationTest extends WorkflowTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    private function moduleWorkflow(string $template): \App\Modules\Workflow\Models\WorkflowDefinition
    {
        $this->settings(['publication_requires_review' => false]);
        $this->actingAs($this->admin);
        $d = app(DefinitionService::class)->installTemplate($template);
        app(DefinitionService::class)->submit($d, $this->admin, 'Go live');

        return $d->refresh();
    }

    private function claim(float $amount): Claim
    {
        $this->actingAs($this->requester);
        $category = ClaimCategory::create(['name' => 'General', 'code' => 'GEN-' . uniqid(), 'requires_receipt' => false, 'max_age_days' => 30, 'active' => true, 'is_travel' => false]);
        $claim = Claim::create(['uuid' => (string) Str::uuid(), 'reference_number' => 'CLM-T-' . Str::random(5), 'employee_id' => $this->requester->id, 'category_id' => $category->id,
            'title' => 'Client visit', 'currency' => 'ZAR', 'total_amount' => $amount, 'claim_date' => now()->toDateString(), 'status' => 'draft', 'created_by' => $this->requester->id]);
        $claim->items()->create(['item_date' => now()->toDateString(), 'description' => 'Fuel', 'unit_price' => $amount, 'quantity' => 1, 'amount' => $amount, 'receipt_required' => false, 'receipt_uploaded' => false]);

        return $claim->refresh();
    }

    public function test_without_the_company_switch_claims_keep_their_own_approval_chain(): void
    {
        $this->moduleWorkflow('expense_claim');
        $claim = $this->claim(800);
        app(ClaimsService::class)->submitClaim($claim);
        $this->assertSame(0, WorkflowInstance::where('subject_id', $claim->uuid)->count());
        $this->assertSame('submitted', $claim->fresh()->status);
    }

    public function test_a_claim_runs_through_the_workflow_and_the_outcome_lands_in_claims(): void
    {
        $this->moduleWorkflow('expense_claim');
        $this->settings(['modules' => ['finance.claims' => true]]);
        $this->assertTrue(app(Workflows::class)->handles('finance.claims'));

        $claim = $this->claim(7500);
        app(ClaimsService::class)->submitClaim($claim);
        $this->assertSame(['submitted', null], [$claim->fresh()->status, $claim->fresh()->current_approver_id]);
        $this->assertSame(0, ClaimApproval::where('claim_id', $claim->id)->count(), 'The claim chain was not built.');

        $instance = WorkflowInstance::where('subject_type', 'finance.claims')->where('subject_id', $claim->uuid)->firstOrFail();
        $this->assertSame('7500.00', $instance->data['record']['amount'], 'Only exposed fields are read.');
        $this->assertSame($claim->reference_number, $instance->subject_ref);
        $this->decide($this->manager, $this->openTask($instance, 'manager'), 'approved');
        // Above R5 000: finance approves (the template uses the Workflow Administrator role).
        $this->admin->assignRole(\Spatie\Permission\Models\Role::findOrCreate('Workflow Administrator', 'web'));
        $this->assertSame('running', $instance->fresh()->status);
        $finance = $this->openTask($instance->fresh());
        $this->assertSame('finance', $finance->node_key);
        $this->assertSame('exception', $finance->type, 'Nobody held the role when the step started, so it was raised as an exception.');

        // The process manager hands it to a person who may approve; it becomes the approval again.
        $this->actingAs($this->admin);
        app(\App\Modules\Workflow\Services\TaskService::class)->reassign($finance, $this->admin, $this->finance->id, 'Finance approver for this team', true);
        $this->decide($this->finance, $finance->fresh(), 'approved', 'Receipts checked');

        $this->assertSame('completed', $instance->fresh()->status);
        $this->assertSame('approved', $claim->fresh()->status, 'Claims applied the outcome through its own code.');
        $this->assertSame('succeeded', WorkflowActionRun::where('instance_id', $instance->id)->where('action_key', 'provider.outcome')->value('status'));
    }

    public function test_a_rejected_claim_is_rejected_in_claims_with_the_reason(): void
    {
        $this->moduleWorkflow('expense_claim');
        $this->settings(['modules' => ['finance.claims' => true]]);
        $claim = $this->claim(300);
        app(ClaimsService::class)->submitClaim($claim);
        $instance = app(Workflows::class)->openFor('finance.claims', $claim->uuid);
        $this->decide($this->manager, $this->openTask($instance), 'rejected', 'Not a business expense');
        $this->assertSame(['rejected', 'Not a business expense'], [$claim->fresh()->status, $claim->fresh()->rejection_reason]);
    }

    public function test_when_the_module_refuses_the_outcome_the_request_is_not_shown_as_completed(): void
    {
        $this->moduleWorkflow('expense_claim');
        $this->settings(['modules' => ['finance.claims' => true]]);
        $claim = $this->claim(300);
        app(ClaimsService::class)->submitClaim($claim);
        $instance = app(Workflows::class)->openFor('finance.claims', $claim->uuid);
        // Someone cancels the claim in Claims while the approval is in progress (§17: the record changed).
        DB::table('claims')->where('id', $claim->id)->update(['status' => 'cancelled']);
        $this->decide($this->manager, $this->openTask($instance), 'approved');
        $instance = $instance->fresh();
        $this->assertSame('running', $instance->status, 'Never "completed" while the hand-off has not succeeded.');
        $this->assertSame('approved', $instance->outcome);
        $run = WorkflowActionRun::where('instance_id', $instance->id)->where('action_key', 'provider.outcome')->first();
        $this->assertSame('pending', $run->status);
        $this->assertStringContainsString('cannot be applied', $run->error);
    }

    public function test_a_purchase_requisition_reserves_its_budget_only_when_the_workflow_approves(): void
    {
        $this->moduleWorkflow('procurement_approval');
        $this->settings(['modules' => ['procurement.requisition' => true]]);
        $this->actingAs($this->requester);
        $budget = Budget::create(['code' => 'WF' . Str::random(6), 'name' => 'Ops budget', 'expenditure_type' => 'opex', 'scope_type' => 'general',
            'currency' => 'ZAR', 'allocated_amount' => 500000, 'status' => 'active', 'owner_id' => $this->finance->id]);
        $pr = app(PurchaseRequestService::class)->create(['uuid' => (string) Str::uuid(), 'budget_id' => $budget->id, 'justification' => 'Laptops', 'currency' => 'ZAR', 'urgency' => 'normal', 'requester_id' => $this->requester->id]);
        $pr->items()->create(['uuid' => (string) Str::uuid(), 'name' => 'Laptop', 'quantity' => 2, 'unit_price' => 12000, 'line_total' => 24000]);
        $pr->recalcTotal();

        $result = app(PurchaseRequestService::class)->submit($pr->refresh(), $this->requester->id);
        $this->assertTrue($result['ok'], $result['message']);
        $this->assertSame(0, ProcurementApproval::where('source_id', $pr->id)->count(), 'The procurement chain was not built.');
        $instance = app(Workflows::class)->openFor('procurement.requisition', $pr->uuid);
        $this->assertTrue($instance->data['record']['within_budget']);
        $this->assertSame('pending_approval', $pr->fresh()->status);

        $this->decide($this->manager, $this->openTask($instance, 'manager'), 'approved');
        // ≤ R50 000 → procurement queue → receipt → done. The outcome is applied at the end of the workflow.
        $this->admin->assignRole(\Spatie\Permission\Models\Role::findOrCreate('Workflow Administrator', 'web'));
        $procure = $this->openTask($instance->fresh());
        $this->actingAs($this->admin);
        app(\App\Modules\Workflow\Services\TaskService::class)->reassign($procure, $this->admin, $this->finance->id, 'Buyer', true);
        $this->decide($this->finance, $procure->fresh(), 'completed');
        $this->decide($this->requester, $this->openTask($instance->fresh(), 'receipt'), 'completed');
        $pr = $pr->fresh();
        $this->assertSame('approved', $pr->status);
        $this->assertNotNull($pr->budget_reservation_id, 'Budget reserved by Procurement on approval.');
        $this->assertSame('24000.00', number_format((float) $pr->reserved_amount, 2, '.', ''));
    }

    private function leaveRequest(): \App\Modules\HR\Leave\Models\LeaveRequest
    {
        $type = \App\Modules\HR\Leave\Models\LeaveType::create(['name' => 'Annual ' . uniqid(), 'status' => 1]);
        $personId = DB::table('hr_people')->where('user_id', $this->requester->id)->value('id');
        $svc = app(\App\Modules\HR\Leave\Services\LeaveBalanceService::class);
        $svc->manualAdjust($personId, $type->id, 160, 'opening');
        $req = \App\Modules\HR\Leave\Models\LeaveRequest::create([
            'uuid' => (string) Str::uuid(), 'request_number' => 'LR-WF-' . uniqid(), 'employee_id' => $personId, 'leave_type_id' => $type->id,
            'start_date' => now()->addWeek()->toDateString(), 'end_date' => now()->addWeek()->addDay()->toDateString(),
            'working_days' => 2, 'working_hours' => 16, 'hours_reserved' => 16, 'status' => 'pending', 'submitted_by' => $this->requester->id,
        ]);
        $svc->reserve($req);

        return $req->refresh();
    }

    public function test_leave_approved_through_the_workflow_consumes_the_reservation_once(): void
    {
        $this->moduleWorkflow('leave_request');
        $this->settings(['modules' => ['hr.leave' => true]]);
        $req = $this->leaveRequest();
        $svc = app(\App\Modules\HR\Leave\Services\LeaveBalanceService::class);
        $before = $svc->getBalance($req->employee_id, $req->leave_type_id);

        // What LeaveRequestService::submit does once the company routes leave through the engine.
        [$instance] = app(Workflows::class)->emit('hr.leave.submitted', 'hr.leave', (string) $req->id, $this->requester->id);
        $this->assertSame('2', explode('.', $instance->data['record']['days'])[0]);
        $this->assertSame($this->manager->id, $this->openTask($instance)->assignee_id, 'Line manager from hr_people.manager_id.');
        $this->decide($this->manager, $this->openTask($instance), 'approved');

        $this->assertSame('completed', $instance->fresh()->status);
        $this->assertSame('approved', $req->fresh()->status->value);
        $this->assertEquals($before, $svc->getBalance($req->employee_id, $req->leave_type_id), 'The reservation became the deduction: no second deduction.');

        // The same outcome delivered again changes nothing (idempotent hand-off).
        app(\App\Modules\Workflow\Providers\LeaveProvider::class)->onOutcome($req->fresh(), 'approved', $this->manager->id, null);
        $this->assertEquals($before, $svc->getBalance($req->employee_id, $req->leave_type_id));
    }

    public function test_leave_rejected_through_the_workflow_releases_the_reservation(): void
    {
        $this->moduleWorkflow('leave_request');
        $this->settings(['modules' => ['hr.leave' => true]]);
        $req = $this->leaveRequest();
        $svc = app(\App\Modules\HR\Leave\Services\LeaveBalanceService::class);
        $reserved = $svc->getBalance($req->employee_id, $req->leave_type_id);
        [$instance] = app(Workflows::class)->emit('hr.leave.submitted', 'hr.leave', (string) $req->id, $this->requester->id);
        $this->decide($this->manager, $this->openTask($instance), 'rejected', 'Month-end close: please move these dates');
        $this->assertSame('rejected', $req->fresh()->status->value);
        $this->assertSame('Month-end close: please move these dates', $req->fresh()->rejection_reason);
        $this->assertEquals($reserved + 16, $svc->getBalance($req->employee_id, $req->leave_type_id), 'The 16 reserved hours are back.');
    }
}
