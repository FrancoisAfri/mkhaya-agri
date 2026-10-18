<?php

namespace App\Modules\Finance\Sars\Services;

use App\Modules\Finance\Sars\Models\SarsSubmission;
use App\Modules\Finance\Sars\Models\SarsSubmissionEvent;
use App\Modules\Finance\Sars\Support\SubmissionLifecycle;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Moves a SARS return through its lifecycle, and writes down that it happened.
 *
 * Every status change in the module goes through here — the employer's own EMP201, a bureau
 * filing for a client, VAT201, EMP501, IRP5 and UI-19 all share the table, so they all share this.
 */
class SubmissionStatusService
{
    /**
     * @throws ValidationException when the move is not one the lifecycle allows
     */
    public function move(SarsSubmission $submission, string $to, ?string $note = null): SarsSubmission
    {
        $from = (string) $submission->status;

        if (! SubmissionLifecycle::canMove($from, $to)) {
            // A validation error rather than a 403: it is the requested status that is wrong, and
            // this way the reason lands on the field the user chose it in.
            throw ValidationException::withMessages([
                'status' => SubmissionLifecycle::refusalReason($from, $to),
            ]);
        }

        return DB::transaction(function () use ($submission, $from, $to, $note) {
            $submission->update([
                'status' => $to,
                'notes'  => $note ?? $submission->notes,
                // Only ever set, never cleared: the date a return was filed is a fact about the
                // past, and the lifecycle no longer allows a filed return to be moved back anyway.
                'submitted_at' => $to === 'submitted' ? now() : $submission->submitted_at,
            ]);

            $this->record($submission, $from, $to, $note);

            return $submission->refresh();
        });
    }

    /** The opening event, written when a return is first generated. */
    public function recordGenerated(SarsSubmission $submission, ?string $note = null): void
    {
        $this->record($submission, null, (string) $submission->status, $note, 'generated');
    }

    private function record(SarsSubmission $submission, ?string $from, string $to, ?string $note, string $event = 'status_changed'): void
    {
        SarsSubmissionEvent::create([
            'submission_id' => $submission->id,
            'company_id'    => $submission->company_id,
            'from_status'   => $from,
            'to_status'     => $to,
            'event'         => $event,
            'note'          => $note,
            'actor_id'      => Auth::id(),
            // Resolved now and stored verbatim. A trail that renders "user #41" once somebody has
            // left the company answers nobody's question.
            'actor_name'    => $this->actorName(),
            'ip'            => request()?->ip(),
        ]);
    }

    private function actorName(): ?string
    {
        $user = Auth::user();

        if (! $user) {
            return null;   // a queued job or a console command
        }

        // There is no users.name column — the name lives on the HR person record.
        $person = $user->person ?? null;

        return $person
            ? trim($person->first_name . ' ' . $person->surname)
            : ($user->email ?? null);
    }
}
