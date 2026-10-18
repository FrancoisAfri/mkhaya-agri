<?php

namespace App\Console\Commands\CRM;

use App\Modules\CRM\Models\CrmSequenceEnrollment;
use App\Modules\Tenancy\Services\TenantContext;
use App\Support\Console\RunsPerTenant;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Put a cadence that has already collapsed back onto the spacing its author wrote.
 *
 * `CrmSequenceService::advance()` now keeps the interval between two steps as the sequence
 * defines it, rather than a flat one-day floor. That governs the *next* time an enrolment moves —
 * it cannot help the enrolments whose `next_step_at` was already written by the old rule, and on a
 * live sequence that is most of them: a six-week cadence with two thousand people on it had every
 * remaining step queued a day apart before the fix landed.
 *
 * So this walks the enrolments still running and rewrites `next_step_at` from the last send that
 * actually happened plus the gap the cadence asks for between the step just sent and the step
 * coming. It only ever moves a send *later*, because bringing one forward is the fault being
 * repaired, not a repair.
 *
 * Dry by default. `--apply` writes.
 */
class RespaceSequences extends Command
{
    use RunsPerTenant;

    protected $signature = 'crm:respace-sequences
        {--company= : Only this company}
        {--sequence= : Only this sequence id}
        {--apply : Write the corrected dates (otherwise report only)}';

    protected $description = 'Re-space in-flight sequence enrolments onto the cadence their steps define';

    public function handle(TenantContext $tenants): int
    {
        $apply = (bool) $this->option('apply');
        $totals = ['examined' => 0, 'moved' => 0];

        $this->eachTenant($tenants, function () use ($apply, &$totals) {
            $query = CrmSequenceEnrollment::with('sequence')
                ->where('status', 'active')
                ->whereNotNull('next_step_at');

            if ($only = $this->option('sequence')) {
                $query->where('sequence_id', (int) $only);
            }

            $query->chunkById(500, function ($enrolments) use ($apply, &$totals) {
                foreach ($enrolments as $e) {
                    $totals['examined']++;

                    $due = $this->correctedDueDate($e);

                    // Only ever later. An enrolment already spaced correctly, or one whose next
                    // send is further out than the cadence asks, is left exactly as it is.
                    if ($due === null || ! $due->greaterThan($e->next_step_at)) {
                        continue;
                    }

                    $totals['moved']++;

                    if ($apply) {
                        $e->forceFill(['next_step_at' => $due])->save();
                    }
                }
            });
        });

        $this->info(sprintf(
            '%s: %d enrolment(s) examined, %d needed more room.',
            $apply ? 'Re-spaced' : 'Dry run',
            $totals['examined'],
            $totals['moved'],
        ));

        if (! $apply && $totals['moved'] > 0) {
            $this->line('Re-run with --apply to write these dates.');
        }

        return self::SUCCESS;
    }

    /**
     * Where this enrolment's next send belongs, counting from the send that actually happened.
     *
     * Null when there is nothing to go on: an enrolment that has never been sent to has no anchor
     * to measure a gap from, and its `next_step_at` is whatever enrolment scheduled — which the old
     * floor never touched.
     */
    private function correctedDueDate(CrmSequenceEnrollment $e): ?Carbon
    {
        $steps = $e->sequence?->steps;

        if (! is_array($steps) || $e->last_sent_at === null) {
            return null;
        }

        $current = (int) $e->current_step;

        // `current_step` is the step about to be sent, so the one already sent is the one before it.
        $previous = $current - 1;

        if ($previous < 0 || ! isset($steps[$current], $steps[$previous])) {
            return null;
        }

        $gap = (int) ($steps[$current]['day_offset'] ?? $current)
            - (int) ($steps[$previous]['day_offset'] ?? $previous);

        if ($gap < 1) {
            return null;
        }

        return $e->last_sent_at->copy()->addDays($gap);
    }
}
