<?php

namespace App\Modules\HR\Recruitment\Actions;

use App\Modules\HR\Recruitment\Enums\ShortlistStatus;
use App\Modules\HR\Recruitment\Models\Candidate;
use App\Modules\HR\Recruitment\Models\Shortlist;
use App\Modules\HR\Recruitment\Models\ShortlistOverride;
use App\Modules\Policies\Traits\PolicyValidationTrait;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OverrideShortlistAction
{
    use PolicyValidationTrait;

    /**
     * Request an AI shortlist override (add or remove a candidate).
     * Creates a pending override record — a second approver must call ApproveOverrideAction.
     *
     * @param  'added'|'removed'  $overrideAction
     */
    public function execute(
        Shortlist  $shortlist,
        Candidate  $candidate,
        string     $overrideAction,
        string     $reason,
        int        $requesterId,
    ): ShortlistOverride {
        if (!in_array($overrideAction, ['added', 'removed'])) {
            throw ValidationException::withMessages([
                'action' => 'Override action must be "added" or "removed".',
            ]);
        }

        if ($shortlist->status === ShortlistStatus::Approved) {
            throw ValidationException::withMessages([
                'shortlist_id' => 'An approved shortlist cannot be overridden. Reject it first.',
            ]);
        }

        $this->validatePolicyAction(
            module: 'Recruitment',
            action: 'shortlist.override_requested',
            payload: [
                'shortlist_id'  => $shortlist->id,
                'candidate_id'  => $candidate->id,
                'action'        => $overrideAction,
                'requester_id'  => $requesterId,
            ],
            userId: $requesterId,
        );

        return DB::transaction(function () use ($shortlist, $candidate, $overrideAction, $reason, $requesterId) {
            $override = ShortlistOverride::create([
                'shortlist_id'    => $shortlist->id,
                'candidate_id'    => $candidate->id,
                'action'          => $overrideAction,
                'override_reason' => $reason,
                'overridden_by'   => $requesterId,
                // approver_id and approved_at remain null until ApproveOverrideAction runs
            ]);

            $this->dispatchPolicyEvent(
                module:      'Recruitment',
                action:      'shortlist.override_requested',
                subjectId:   $override->id,
                subjectType: ShortlistOverride::class,
                payload:     [
                    'shortlist_id' => $shortlist->id,
                    'candidate_id' => $candidate->id,
                    'action'       => $overrideAction,
                    'reason'       => $reason,
                ],
                userId: $requesterId,
            );

            return $override;
        });
    }
}
