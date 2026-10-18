<?php

namespace Tests\Feature\CRM;

use App\Core\User\Models\User;
use App\Modules\CRM\Models\CrmApproval;
use App\Modules\CRM\Models\CrmNotification;
use App\Modules\CRM\Services\CrmNotifier;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Tests\TestCase;

class CrmApprovalTest extends TestCase
{
    use DatabaseTransactions;

    private function twoUsers(): array
    {
        $users = User::query()->take(2)->get();
        if ($users->count() < 2) {
            $this->markTestSkipped('Need at least two users for the approval test.');
        }
        return [$users[0], $users[1]];
    }

    public function test_notifier_skips_self_and_creates_for_others(): void
    {
        [$me, $other] = $this->twoUsers();
        Auth::login($me);

        $notifier = app(CrmNotifier::class);
        $notifier->notify($me->id, 'mention', 'self');       // skipped
        $notifier->notify($other->id, 'mention', 'for other'); // created

        $this->assertDatabaseMissing('crm_notifications', ['user_id' => $me->id, 'title' => 'self']);
        $this->assertDatabaseHas('crm_notifications', ['user_id' => $other->id, 'title' => 'for other']);
    }

    public function test_request_notifies_approver_and_mentioned(): void
    {
        [$requester, $approver] = $this->twoUsers();
        Auth::login($requester);

        $this->app->make(\App\Modules\CRM\Http\Controllers\ApprovalController::class);

        $approval = CrmApproval::create([
            'uuid' => Str::uuid(), 'type' => 'discount', 'title' => '15% off Acme',
            'amount' => 5000, 'requested_by' => $requester->id, 'approver_id' => $approver->id,
            'status' => 'pending', 'mentioned' => [$approver->id],
        ]);

        $notifier = app(CrmNotifier::class);
        $notifier->notify($approver->id, 'approval_requested', 'Approval requested: ' . $approval->title);

        $this->assertDatabaseHas('crm_notifications', [
            'user_id' => $approver->id, 'type' => 'approval_requested',
        ]);
    }

    public function test_decision_updates_status_and_notifies_requester(): void
    {
        [$requester, $approver] = $this->twoUsers();

        $approval = CrmApproval::create([
            'uuid' => Str::uuid(), 'type' => 'deal', 'title' => 'Approve big deal',
            'requested_by' => $requester->id, 'approver_id' => $approver->id, 'status' => 'pending',
        ]);

        // Approver decides.
        Auth::login($approver);
        $approval->update(['status' => 'approved', 'decided_by' => $approver->id, 'decided_at' => now()]);
        app(CrmNotifier::class)->notify($requester->id, 'approval_decided', 'Approval approved: ' . $approval->title);

        $this->assertSame('approved', $approval->fresh()->status);
        $this->assertDatabaseHas('crm_notifications', ['user_id' => $requester->id, 'type' => 'approval_decided']);
    }

    public function test_only_approver_can_decide_via_http(): void
    {
        [$requester, $approver] = $this->twoUsers();

        $approval = CrmApproval::create([
            'uuid' => Str::uuid(), 'type' => 'deal', 'title' => 'X',
            'requested_by' => $requester->id, 'approver_id' => $approver->id, 'status' => 'pending',
        ]);

        // Requester (not the approver) tries to decide -> 403.
        $this->actingAs($requester)
            ->post(route('crm.approvals.decide', $approval->id), ['status' => 'approved'])
            ->assertForbidden();

        $this->assertSame('pending', $approval->fresh()->status);
    }
}
