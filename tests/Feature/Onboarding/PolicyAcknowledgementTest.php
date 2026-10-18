<?php

namespace Tests\Feature\Onboarding;

use App\Core\User\Models\User;
use App\Modules\HR\Onboarding\Actions\AcknowledgePolicyAction;
use App\Modules\HR\Onboarding\Models\OnboardingPlan;
use App\Modules\HR\Onboarding\Models\OnboardingPolicyAcknowledgement;
use App\Modules\HR\Onboarding\Models\OnboardingTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A policy acknowledgement that proves something.
 *
 * `policy_acknowledgement` was a bare task type — a checkbox recording that somebody ticked
 * something, with no policy, no version and no signature behind it. "Did they accept the code of
 * conduct?" could only be answered as "a task with that title was marked done", which proves
 * nothing once the code has been revised, and policies get revised.
 */
class PolicyAcknowledgementTest extends TestCase
{
    use RefreshDatabase;

    private function task(): OnboardingTask
    {
        $plan = OnboardingPlan::factory()->create(['status' => 'active']);

        return OnboardingTask::factory()->create([
            'onboarding_plan_id' => $plan->id,
            'type' => 'policy_acknowledgement',
            'status' => 'pending',
        ]);
    }

    /** A policy version to point at. The policy module owns the table; this only references it. */
    private function policyVersionId(): int
    {
        // The version hangs off a policy; the policy module owns both tables and this only
        // references them.
        $policyId = DB::table('policy')->insertGetId([
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return DB::table('policy_versions')->insertGetId([
            'policy_id' => $policyId,
            'version_number' => '2.0',
            // NOT NULL on the policy module's own table; this test only references the row.
            'snapshot' => json_encode(['body' => 'Policy text']),
            'created_by' => User::factory()->create()->id,
            'published_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_acknowledging_records_the_version_and_closes_the_task(): void
    {
        $user = User::factory()->create();
        $task = $this->task();
        $versionId = $this->policyVersionId();

        $ack = app(AcknowledgePolicyAction::class)
            ->execute($task, $versionId, $user->id, signature: 'N Dlamini', ipAddress: '196.25.1.1');

        $this->assertSame($versionId, $ack->policy_version_id, 'The version is the whole point.');
        $this->assertSame('N Dlamini', $ack->signature);
        $this->assertSame('196.25.1.1', $ack->ip_address);
        $this->assertNotNull($ack->acknowledged_at);

        $task->refresh();

        $this->assertSame('completed', $task->status?->value ?? $task->status);
        $this->assertNotNull($task->completed_at, 'Evidence and task state have to move together.');
    }

    /** Re-reading a policy is not a second agreement. */
    public function test_acknowledging_the_same_version_twice_does_not_duplicate_the_evidence(): void
    {
        $user = User::factory()->create();
        $task = $this->task();
        $versionId = $this->policyVersionId();

        $first = app(AcknowledgePolicyAction::class)->execute($task, $versionId, $user->id);
        $second = app(AcknowledgePolicyAction::class)->execute($task, $versionId, $user->id);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, OnboardingPolicyAcknowledgement::count());
    }

    /** A new version is a new thing to agree to, and gets its own row. */
    public function test_a_revised_policy_needs_its_own_acknowledgement(): void
    {
        $user = User::factory()->create();
        $task = $this->task();

        app(AcknowledgePolicyAction::class)->execute($task, $this->policyVersionId(), $user->id);
        app(AcknowledgePolicyAction::class)->execute($task, $this->policyVersionId(), $user->id);

        $this->assertSame(2, OnboardingPolicyAcknowledgement::count());
    }
}
