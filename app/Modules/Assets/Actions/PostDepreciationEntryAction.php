<?php

namespace App\Modules\Assets\Actions;

use App\Modules\Assets\Models\AssetDepreciationEntry;

class PostDepreciationEntryAction
{
    public function execute(AssetDepreciationEntry $entry, int $postedBy): AssetDepreciationEntry
    {
        abort_if($entry->is_posted, 422, 'Entry is already posted.');

        // Enforce sequential posting
        if ($entry->period_sequence > 1) {
            $priorPosted = AssetDepreciationEntry::where('schedule_id', $entry->schedule_id)
                ->where('period_sequence', $entry->period_sequence - 1)
                ->where('is_posted', true)
                ->exists();

            abort_unless($priorPosted, 422, 'Prior period must be posted first.');
        }

        $entry->update([
            'is_posted' => true,
            'posted_at' => now(),
            'posted_by' => $postedBy,
        ]);

        return $entry->refresh();
    }
}
