<?php

namespace App\Modules\CRM\Services;

use App\Modules\CRM\Models\CrmAccount;
use App\Modules\CRM\Models\CrmActivity;
use App\Modules\CRM\Models\CrmContact;
use App\Modules\CRM\Models\CrmDeal;
use App\Modules\CRM\Models\CrmLead;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Hands leads, deals, accounts and contacts from one sales person to another.
 *
 * Two rules hold the whole thing together, and both are enforced here rather than in the screen:
 * you may only move a record you can already see, and you may only move it to somebody you can
 * also see. Between them a manager reassigns freely inside their own reporting tree and cannot
 * reach outside it, while an executive can move anything anywhere.
 *
 * Open work follows the record by default. A task still to be done is the new owner's problem the
 * moment they take the record over, whereas a completed call belongs in the history of whoever
 * actually made it — so finished activities are deliberately left where they are.
 */
class CrmReassignService
{
    /** The record types that can be reassigned, and the model behind each. */
    public const TYPES = [
        'lead'    => CrmLead::class,
        'deal'    => CrmDeal::class,
        'account' => CrmAccount::class,
        'contact' => CrmContact::class,
    ];

    public function __construct(
        private CrmVisibilityService $visibility,
        private CrmEventBus $events,
    ) {
    }

    /**
     * Move a set of records of one type to a new owner.
     *
     * @param  string      $type    one of self::TYPES
     * @param  array<int>  $ids     the records to move
     * @param  int         $ownerId who to move them to
     * @param  bool        $moveOpenActivities also hand over unfinished tasks on those records
     * @return array{moved:int, activities:int, owner:string}
     */
    public function reassign(string $type, array $ids, int $ownerId, bool $moveOpenActivities = true): array
    {
        $model = self::TYPES[$type] ?? null;
        if (! $model) {
            throw ValidationException::withMessages(['type' => 'That kind of record cannot be reassigned.']);
        }

        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if ($ids === []) {
            throw ValidationException::withMessages(['ids' => 'Choose at least one record to reassign.']);
        }

        // You cannot give a record to somebody you cannot see — it would vanish from your own
        // list the instant you saved it, and nobody in your team could get it back.
        if (! $this->visibility->canSee($ownerId)) {
            throw ValidationException::withMessages(['owner_id' => 'You can only reassign to someone in your team.']);
        }

        $ownerName = (string) (DB::table('users')
            ->leftJoin('hr_people', 'hr_people.user_id', '=', 'users.id')
            ->where('users.id', $ownerId)
            ->selectRaw("COALESCE(NULLIF(TRIM(CONCAT(hr_people.first_name, ' ', COALESCE(hr_people.surname, ''))), ''), users.email) AS name")
            ->value('name') ?: 'the new owner');

        $records = $model::whereIn('id', $ids)->get();
        if ($records->count() !== count($ids)) {
            throw ValidationException::withMessages(['ids' => 'Some of those records no longer exist.']);
        }

        // Check every record BEFORE moving any of them, so a selection containing one record the
        // user may not touch fails whole rather than half-applying.
        foreach ($records as $record) {
            $this->visibility->authorize($record);
        }

        $moved = 0;
        $activities = 0;

        DB::transaction(function () use ($records, $ownerId, $moveOpenActivities, $type, &$moved, &$activities) {
            foreach ($records as $record) {
                $previous = (int) $record->owner_id;
                if ($previous === $ownerId) {
                    continue;   // already theirs; nothing to record
                }

                $record->owner_id = $ownerId;
                $record->save();
                $moved++;

                if ($moveOpenActivities) {
                    $activities += CrmActivity::query()
                        ->where('related_type', $type)
                        ->where('related_id', $record->id)
                        ->whereNull('completed_at')
                        ->where('status', '!=', 'done')
                        ->update(['owner_id' => $ownerId]);
                }

                // Automations listen for ownership changes (welcome tasks, notifications), and the
                // previous owner is the part of the story the record itself no longer remembers.
                $this->events->fire($type . '.reassigned', $record, [
                    'from'        => $previous,
                    'to'          => $ownerId,
                    'reassigned_by' => Auth::id(),
                ]);
            }
        });

        return ['moved' => $moved, 'activities' => $activities, 'owner' => $ownerName];
    }
}
