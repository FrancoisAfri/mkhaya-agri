<?php

namespace App\Modules\HR\Onboarding\Actions;

use App\Modules\HR\Onboarding\Models\OnboardingProvisioningTask;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Complete a provisioning task by issuing a real asset from the register.
 *
 * The point is that the machine stops being a sentence and becomes a record. Until now "Laptop —
 * completed" told the Assets module nothing, left the person's custody record empty, and gave
 * offboarding nothing to ask for back.
 *
 * Deliberately does not create the asset. If it is not already on the register it is not an asset
 * the company is tracking, and inventing one here would put a machine into the books that nobody
 * bought.
 */
class IssueAssetForProvisioningAction
{
    public function execute(OnboardingProvisioningTask $task, int $assetId, int $issuedBy): OnboardingProvisioningTask
    {
        return DB::transaction(function () use ($task, $assetId, $issuedBy) {
            $companyId = app(CurrentCompany::class)->id();

            // Re-issuing would hand out a second machine for the same task and leave the first
            // assigned to nobody's knowledge.
            abort_if(
                $task->asset_assignment_id !== null,
                422,
                'An asset has already been issued against this provisioning task.'
            );

            $person = $task->hrPerson;

            abort_if($person === null, 422, 'This provisioning task is not attached to a person.');

            // The asset has to exist and be free. An asset already out on a current assignment is
            // somebody else's; issuing it again is how two people end up holding one laptop.
            $asset = DB::table('assets')->where('id', $assetId)->first();

            abort_if($asset === null, 404, 'That asset is not on the register.');

            $alreadyOut = DB::table('asset_assignments')
                ->where('asset_id', $assetId)
                ->where('is_current', true)
                ->exists();

            abort_if($alreadyOut, 422, 'That asset is already assigned to someone.');

            $assignmentId = DB::table('asset_assignments')->insertGetId([
                'uuid' => (string) Str::uuid(),
                'asset_id' => $assetId,
                // Assets are held by a user; a new hire without a login yet has none, so the
                // issuing administrator holds it until the account exists. Recorded rather than
                // hidden — the notes say who it is really for.
                'assigned_to' => $person->user_id ?? $issuedBy,
                'assigned_by' => $issuedBy,
                'assignment_type' => 'permanent',
                'effective_date' => now()->toDateString(),
                'is_current' => true,
                'notes' => 'Issued at onboarding for ' . $person->fullName(),
                'company_id' => $companyId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $task->update([
                'asset_id' => $assetId,
                'asset_assignment_id' => $assignmentId,
                'status' => 'completed',
                'completed_at' => now(),
            ]);

            return $task->fresh();
        });
    }
}
