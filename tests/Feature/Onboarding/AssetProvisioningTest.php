<?php

namespace Tests\Feature\Onboarding;

use App\Core\User\Models\User;
use App\Modules\HR\Onboarding\Actions\IssueAssetForProvisioningAction;
use App\Modules\HR\Onboarding\Models\OnboardingProvisioningTask;
use App\Modules\HR\PreBoarding\Models\HrPerson;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Provisioning that produces a record rather than a sentence.
 *
 * "Laptop — completed" told the Assets module nothing, left the person's custody record empty, and
 * gave offboarding nothing to ask for back — the exit clearance had to be assembled from memory.
 */
class AssetProvisioningTest extends TestCase
{
    use RefreshDatabase;

    private function asset(): int
    {
        return DB::table('assets')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function task(): OnboardingProvisioningTask
    {
        $person = HrPerson::factory()->create();

        return OnboardingProvisioningTask::create([
            'hr_person_id' => $person->id,
            'type' => 'laptop',
            'title' => 'Set up laptop',
            'status' => 'pending',
            'created_by' => User::factory()->create()->id,
        ]);
    }

    public function test_issuing_an_asset_records_the_assignment_and_closes_the_task(): void
    {
        $issuer = User::factory()->create();
        $task = $this->task();
        $assetId = $this->asset();

        $result = app(IssueAssetForProvisioningAction::class)->execute($task, $assetId, $issuer->id);

        $this->assertSame($assetId, $result->asset_id, 'The task should name the machine that satisfied it.');
        $this->assertNotNull($result->asset_assignment_id);
        $this->assertSame('completed', $result->status?->value ?? $result->status);

        $this->assertDatabaseHas('asset_assignments', [
            'id' => $result->asset_assignment_id,
            'asset_id' => $assetId,
            'is_current' => true,
        ]);
    }

    /** Two people holding one laptop is the failure this prevents. */
    public function test_an_asset_already_out_cannot_be_issued_again(): void
    {
        $issuer = User::factory()->create();
        $assetId = $this->asset();

        app(IssueAssetForProvisioningAction::class)->execute($this->task(), $assetId, $issuer->id);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

        app(IssueAssetForProvisioningAction::class)->execute($this->task(), $assetId, $issuer->id);
    }

    /** Re-issuing against the same task would hand out a second machine for one requirement. */
    public function test_a_task_cannot_issue_twice(): void
    {
        $issuer = User::factory()->create();
        $task = $this->task();

        app(IssueAssetForProvisioningAction::class)->execute($task, $this->asset(), $issuer->id);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

        app(IssueAssetForProvisioningAction::class)->execute($task->fresh(), $this->asset(), $issuer->id);
    }

    /** Inventing an asset here would put a machine on the books that nobody bought. */
    public function test_an_asset_that_is_not_on_the_register_is_refused(): void
    {
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

        app(IssueAssetForProvisioningAction::class)
            ->execute($this->task(), 999999, User::factory()->create()->id);
    }
}
