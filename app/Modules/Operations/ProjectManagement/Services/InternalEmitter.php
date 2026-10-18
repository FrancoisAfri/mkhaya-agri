<?php

namespace App\Modules\Operations\ProjectManagement\Services;

use App\Modules\Operations\ProjectManagement\Models\EvidenceEvent;
use App\Modules\Operations\ProjectManagement\Models\Project;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;

/**
 * Turns PM's own state changes into evidence (PM-1004).
 *
 * The ledger is only worth anything if it is complete. If a status change, a document upload or a
 * timesheet approval reaches the database without reaching the ledger, then a derived figure is
 * computed from a partial record and every drill-down has a hole in it.
 *
 * So emission happens **inside the caller's transaction**, and this refuses to run outside one.
 * Emitting after a commit would mean a state change could exist with no evidence of it; emitting
 * before, that evidence could survive a rollback of the thing it claims happened.
 *
 * Deduplication is by `source_ref`, which callers set to something stable about the change
 * (`project:42:status:active`), so a retried request records the fact once.
 */
class InternalEmitter
{
    public function __construct(private readonly EvidenceIngestor $ingestor)
    {
    }

    /**
     * @param  array<string,mixed>  $attributes  quantity/unit/amount/currency/payload as needed
     */
    public function emit(Project $project, string $eventType, array $attributes = []): ?EvidenceEvent
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException(
                "Evidence for '{$eventType}' was emitted outside a transaction. It must be written in "
                . 'the same transaction as the change it records (PM-1004).'
            );
        }

        $result = $this->ingestor->ingest(array_merge([
            'project_id'    => $project->uuid,
            'subject_type'  => 'project',
            'event_type'    => $eventType,
            'source_module' => 'pm',
            'occurred_at'   => now()->toIso8601String(),
            // The project's own zone: "when did this happen" is answered where the work is, not
            // where the server is.
            'occurred_tz'   => $project->timezone ?: config('app.timezone', 'UTC'),
            'actor_type'    => Auth::check() ? 'user' : 'system',
            'actor_id'      => (string) Auth::id(),
            'payload'       => [],
        ], $attributes));

        // A rejected internal emission is a bug in PM, not bad input from a device — but it is
        // still held in the dead-letter table rather than thrown, because failing the user's save
        // over a bookkeeping problem would be the wrong trade.
        //
        // It is logged loudly, though. Returning null quietly is how an unknown subject type cost
        // this module a silent hole in the timeline: every screen reported success while nothing
        // reached the ledger, and the only visible symptom was evidence that was never there.
        if ($result->status === IngestResult::REJECTED) {
            Log::warning('PM internal evidence was rejected — the timeline will have a hole in it.', [
                'event_type' => $eventType,
                'project'    => $project->uuid,
                'errors'     => $result->deadLetter?->errors,
            ]);
        }

        return $result->event;
    }
}
