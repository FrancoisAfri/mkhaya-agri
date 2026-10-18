<?php

namespace App\Modules\HR\Recruitment\Actions;

use App\Modules\HR\Recruitment\Models\ShortlistItem;
use App\Modules\HR\Recruitment\Models\ShortlistOverride;
use App\Modules\Policies\Traits\PolicyValidationTrait;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApproveOverrideAction
{
    use PolicyValidationTrait;

    /**
     * Four-eyes approval for a shortlist override.
     * The approver must be a different person from whoever requested the override.
     */
    public function execute(ShortlistOverride $override, int $approverId): ShortlistOverride
    {
        // Four-eyes enforcement — approver cannot be the same person who requested
        if ($approverId === $override->overridden_by) {
            throw ValidationException::withMessages([
                'approver_id' => 'You cannot approve your own shortlist override.',
            ]);
        }

        if ($override->approved_at !== null) {
            throw ValidationException::withMessages([
                'override_id' => 'This override has already been approved.',
            ]);
        }

        $this->validatePolicyAction(
            module: 'Recruitment',
            action: 'shortlist.override_approved',
            payload: [
                'override_id'  => $override->id,
                'shortlist_id' => $override->shortlist_id,
                'approver_id'  => $approverId,
                'action'       => $override->action,
            ],
            userId: $approverId,
        );

        return DB::transaction(function () use ($override, $approverId) {
            // Stamp the approval — approver_id/approved_at are guarded on the model
            // (four-eyes, never mass-assignable from HTTP), so force-fill them here.
            $override->forceFill([
                'approver_id' => $approverId,
                'approved_at' => now(),
            ])->save();

            // Apply the override to the shortlist items
            if ($override->action === 'added') {
                ShortlistItem::firstOrCreate([
                    'shortlist_id' => $override->shortlist_id,
                    'candidate_id' => $override->candidate_id,
                ], [
                    'application_id'  => $this->resolveApplicationId($override),
                    'ai_recommended'  => false,
                ]);
            } elseif ($override->action === 'removed') {
                ShortlistItem::where('shortlist_id', $override->shortlist_id)
                    ->where('candidate_id', $override->candidate_id)
                    ->delete();
            }

            $this->dispatchPolicyEvent(
                module:      'Recruitment',
                action:      'shortlist.override_approved',
                subjectId:   $override->id,
                subjectType: ShortlistOverride::class,
                payload:     [
                    'shortlist_id' => $override->shortlist_id,
                    'candidate_id' => $override->candidate_id,
                    'action'       => $override->action,
                    'approver_id'  => $approverId,
                ],
                userId: $approverId,
            );

            return $override->fresh();
        });
    }

    private function resolveApplicationId(ShortlistOverride $override): ?int
    {
        return \App\Modules\HR\Recruitment\Models\Application::where('candidate_id', $override->candidate_id)
            ->where('job_id', $override->shortlist->job_id)
            ->value('id');
    }
}
