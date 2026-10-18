<?php

namespace App\Modules\Fleet\Services;

use App\Modules\Fleet\Models\FleetJobCard;
use App\Modules\Fleet\Models\FleetJobCardApproval;
use App\Modules\Fleet\Models\FleetJobCardApprovalStep;
use App\Modules\Fleet\Models\FleetJobCardHistory;
use App\Modules\Fleet\Models\FleetJobCardSetting;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Owns everything about a job card's life beyond plain CRUD: its number, the
 * approval chain it must pass through, and the status transitions that are
 * legal from where it currently is.
 *
 * Keeping this in one place means the controllers never invent a status or
 * write history by hand, so a card can't end up in a state the workflow
 * doesn't recognise.
 */
class FleetJobCardWorkflow
{
    /** Which statuses may follow which. Anything not listed here is rejected. */
    private const TRANSITIONS = [
        FleetJobCard::STATUS_DRAFT             => [FleetJobCard::STATUS_PENDING_APPROVAL, FleetJobCard::STATUS_APPROVED, FleetJobCard::STATUS_CANCELLED],
        FleetJobCard::STATUS_PENDING_APPROVAL  => [FleetJobCard::STATUS_APPROVED, FleetJobCard::STATUS_REJECTED, FleetJobCard::STATUS_CANCELLED],
        FleetJobCard::STATUS_APPROVED          => [FleetJobCard::STATUS_IN_PROGRESS, FleetJobCard::STATUS_CANCELLED],
        FleetJobCard::STATUS_IN_PROGRESS       => [FleetJobCard::STATUS_AWAITING_FEEDBACK, FleetJobCard::STATUS_COMPLETED, FleetJobCard::STATUS_CANCELLED],
        FleetJobCard::STATUS_AWAITING_FEEDBACK => [FleetJobCard::STATUS_COMPLETED, FleetJobCard::STATUS_CANCELLED],
        FleetJobCard::STATUS_COMPLETED         => [FleetJobCard::STATUS_CLOSED],
        FleetJobCard::STATUS_CLOSED            => [],
        FleetJobCard::STATUS_REJECTED          => [FleetJobCard::STATUS_DRAFT],
        FleetJobCard::STATUS_CANCELLED         => [],
    ];

    public function canTransition(FleetJobCard $card, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$card->status] ?? [], true);
    }

    /**
     * Move a card to a new status, recording the change. Returns false when the
     * transition is not legal, so callers can report it rather than silently skip.
     */
    public function transition(FleetJobCard $card, string $to, ?string $description = null): bool
    {
        if (! $this->canTransition($card, $to)) {
            return false;
        }

        $from = $card->status;
        $card->status = $to;
        $card->save();

        $this->log($card, 'status_changed', $description, $from, $to);

        return true;
    }

    /** Next sequential card number for the active tenant, e.g. JC-2026-00042. */
    public function nextCardNumber(): string
    {
        return DB::transaction(function () {
            $settings = FleetJobCardSetting::current();
            $number = max(1, (int) $settings->next_card_number);
            $settings->next_card_number = $number + 1;
            $settings->save();

            return sprintf('%s-%s-%05d', $settings->card_number_prefix ?: 'JC', now()->format('Y'), $number);
        });
    }

    /**
     * Build the approval chain for a card from the configured steps, skipping any
     * whose min_amount is above the card's total. When approval is switched off,
     * or no step applies, the card is approved outright.
     */
    public function submitForApproval(FleetJobCard $card): string
    {
        $settings = FleetJobCardSetting::current();

        $card->recalculateTotals();
        $card->refresh();

        $steps = FleetJobCardApprovalStep::active()
            ->orderBy('sequence')
            ->get()
            ->filter(fn ($s) => $s->appliesTo((float) $card->total_cost))
            ->values();

        if (! $settings->require_approval || $steps->isEmpty()) {
            $this->transition($card, FleetJobCard::STATUS_APPROVED, 'Approved automatically — no approval step applies');

            return FleetJobCard::STATUS_APPROVED;
        }

        // Replace any chain from a previous submission so a resubmitted card
        // doesn't accumulate stale pending rows.
        $card->approvals()->delete();

        foreach ($steps as $i => $step) {
            FleetJobCardApproval::create([
                'job_card_id'      => $card->id,
                'step_id'          => $step->id,
                'step_name'        => $step->name,
                'sequence'         => $i + 1,
                'approver_user_id' => $step->approver_user_id,
                'status'           => 'pending',
            ]);
        }

        $card->current_step = 1;
        $card->save();

        $this->transition($card, FleetJobCard::STATUS_PENDING_APPROVAL, 'Submitted for approval');

        return FleetJobCard::STATUS_PENDING_APPROVAL;
    }

    /**
     * Record one approver's decision. Approving the last outstanding step approves
     * the card; a rejection at any step rejects the whole card immediately.
     */
    public function decide(FleetJobCard $card, FleetJobCardApproval $approval, bool $approved, ?string $comment = null): void
    {
        $approval->status = $approved ? 'approved' : 'rejected';
        $approval->comment = $comment;
        $approval->actioned_at = now();
        $approval->approver_user_id ??= Auth::id();
        $approval->save();

        if (! $approved) {
            $this->transition($card, FleetJobCard::STATUS_REJECTED, 'Rejected at step: ' . $approval->step_name);

            return;
        }

        $this->log($card, 'approved', 'Approved at step: ' . $approval->step_name);

        $stillPending = $card->approvals()->where('status', 'pending')->orderBy('sequence')->first();

        if ($stillPending) {
            $card->current_step = $stillPending->sequence;
            $card->save();

            return;
        }

        $this->transition($card, FleetJobCard::STATUS_APPROVED, 'All approval steps completed');
    }

    /** Append a history entry. Every state-changing action funnels through here. */
    public function log(FleetJobCard $card, string $action, ?string $description = null, ?string $from = null, ?string $to = null): void
    {
        FleetJobCardHistory::create([
            'job_card_id'  => $card->id,
            'action'       => $action,
            'from_status'  => $from,
            'to_status'    => $to,
            'description'  => $description,
            'performed_by' => Auth::id(),
            'performed_at' => now(),
        ]);
    }
}
