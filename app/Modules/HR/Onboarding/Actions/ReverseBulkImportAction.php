<?php

namespace App\Modules\HR\Onboarding\Actions;

use App\Modules\HR\Onboarding\Models\OnboardingBulkImport;
use App\Modules\HR\Onboarding\Models\OnboardingPlan;
use App\Modules\HR\PreBoarding\Models\HrPerson;
use Illuminate\Support\Facades\DB;

/**
 * Undo a bulk import.
 *
 * Two hundred people imported against the wrong template, or with last year's start dates, is a
 * mistake that takes one click to make and — until now — two hundred to correct. Every imported
 * person carries `bulk_import_id`, so the set is already known exactly.
 *
 * What it will not do is reverse work that has since happened. A person whose documents have been
 * uploaded, whose tasks have been ticked off, or who has been given a portal link has been dealt
 * with by somebody, and silently deleting them would destroy that work along with the mistake.
 * Those are skipped and reported, so the reversal is honest about what it left behind.
 */
class ReverseBulkImportAction
{
    /** @return array{deleted: int, skipped: list<array{name: string, reason: string}>} */
    public function execute(OnboardingBulkImport $import): array
    {
        return DB::transaction(function () use ($import) {
            $deleted = 0;
            $skipped = [];

            foreach (HrPerson::where('bulk_import_id', $import->id)->get() as $person) {
                $reason = $this->reasonNotToDelete($person);

                if ($reason !== null) {
                    $skipped[] = ['name' => $person->fullName(), 'reason' => $reason];

                    continue;
                }

                // Soft deletes throughout, so a reversal is itself recoverable — the rows leave the
                // module without leaving the database.
                OnboardingPlan::where('hr_person_id', $person->id)->each(function (OnboardingPlan $plan) {
                    $plan->tasks()->delete();
                    $plan->delete();
                });

                $person->onboardingDocuments()->delete();
                $person->delete();

                $deleted++;
            }

            $import->update([
                'status' => $skipped === [] ? 'reversed' : 'partially_reversed',
                'row_errors' => array_merge($import->row_errors ?? [], array_map(
                    fn ($s) => ['row' => null, 'name' => $s['name'], 'error' => 'Not reversed: ' . $s['reason']],
                    $skipped
                )),
            ]);

            return ['deleted' => $deleted, 'skipped' => $skipped];
        });
    }

    /** Why this person should survive the reversal, or null if nothing has happened to them yet. */
    private function reasonNotToDelete(HrPerson $person): ?string
    {
        // Either place the bytes may be. This guard decides whether deleting a person is safe,
        // so a document it fails to see is a document destroyed — checking the column alone would
        // stop protecting anyone whose files had moved to the shared disk.
        if ($person->onboardingDocuments()
            ->where(fn ($q) => $q->whereNotNull('file_data')->orWhereNotNull('storage_path'))
            ->exists()) {
            return 'documents have already been uploaded';
        }

        $hasProgress = OnboardingPlan::where('hr_person_id', $person->id)
            ->whereHas('tasks', fn ($q) => $q->whereIn('status', ['completed', 'skipped']))
            ->exists();

        if ($hasProgress) {
            return 'onboarding tasks have already been completed';
        }

        if ($person->portal_token !== null) {
            return 'a portal link has already been issued to them';
        }

        return null;
    }
}
