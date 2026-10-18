<?php

namespace App\Modules\Operations\ProjectManagement\Services;

use App\Modules\Operations\ProjectManagement\Models\EvidenceDeadLetter;
use App\Modules\Operations\ProjectManagement\Models\EvidenceEvent;
use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Support\Evidence\EvidenceTaxonomy;
use App\Modules\Operations\ProjectManagement\Support\Evidence\TaxonomyValidator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * The front door for evidence arriving from anywhere but the UI (PM-1003): the mobile app, a module
 * adapter, or an integration.
 *
 * What it adds on top of EvidenceLedger::append():
 *
 *  - **Natural-key idempotency.** A submission is identified by
 *    (source_module, source_ref, event_type). A GRN synced twice is the same GRN, whether or not
 *    the sender remembered an Idempotency-Key — and adapters replaying a module's event stream
 *    will submit the same thing more than once by design.
 *  - **Dead-lettering.** An invalid record is kept with its errors, never dropped. A day's captured
 *    work must not disappear because one field was wrong.
 *  - **Batch.** Devices sync a shift at a time, so the batch path is the normal path, not an
 *    optimisation.
 *  - **Future-dating rejected.** Backdating is legitimate — evidence is often captured after the
 *    fact — but an event more than a day ahead is a broken device clock, and letting it in would
 *    corrupt every "as at" figure until that date passes.
 */
class EvidenceIngestor
{
    /** Clock skew is real; a day of tolerance absorbs it. Beyond that the clock is simply wrong. */
    private const MAX_FUTURE_HOURS = 24;

    public function __construct(private readonly EvidenceLedger $ledger)
    {
    }

    /**
     * Ingest one submission.
     *
     * @param  array<string,mixed>  $submission
     */
    public function ingest(array $submission, ?string $batchId = null): IngestResult
    {
        $errors = $this->validate($submission);

        if ($errors !== []) {
            return IngestResult::rejected($this->deadLetter($submission, $errors, $batchId));
        }

        $project = Project::where('uuid', $submission['project_id'])->first();

        // Checked after validation so the caller gets every problem at once, not one per round trip.
        if (! $project) {
            return IngestResult::rejected(
                $this->deadLetter($submission, ['project_id does not match a project in this tenant.'], $batchId)
            );
        }

        if ($existing = $this->findDuplicate($submission)) {
            return IngestResult::duplicate($existing);
        }

        return IngestResult::accepted($this->ledger->append($this->prepare($submission, $project)));
    }

    /**
     * Ingest a batch, one record at a time.
     *
     * Deliberately not all-or-nothing: 499 good records must not be lost because one was malformed.
     * Each is accepted, deduplicated or dead-lettered on its own, and the caller gets a per-record
     * answer so a device can mark exactly what synced.
     *
     * @param  list<array<string,mixed>>  $submissions
     * @return array{batch_id:string, accepted:int, duplicates:int, rejected:int, results:list<array<string,mixed>>}
     */
    public function ingestBatch(array $submissions): array
    {
        $batchId = (string) Str::uuid();
        $results = [];
        $accepted = $duplicates = $rejected = 0;

        // Everything a per-record path would query 500 times, done once. The naive loop cost about
        // 3,500 queries and 500 transactions for a single shift's sync; a device on a bad
        // connection cannot afford that and neither can the 2-second budget.
        $projects = $this->resolveProjects($submissions);
        $seen = $this->existingKeys($submissions);

        // Grouped per project so each chain is locked once, not once per event.
        $pending = [];
        $slots = [];

        foreach ($submissions as $index => $submission) {
            $errors = $this->validate($submission);
            $project = $projects[$submission['project_id'] ?? ''] ?? null;

            if ($errors === [] && ! $project) {
                $errors = ['project_id does not match a project in this tenant.'];
            }

            if ($errors !== []) {
                $results[$index] = IngestResult::rejected($this->deadLetter($submission, $errors, $batchId))->toArray();
                $rejected++;

                continue;
            }

            $key = $this->naturalKey($submission);

            if ($key !== null && isset($seen[$key])) {
                $results[$index] = IngestResult::duplicate($seen[$key])->toArray();
                $duplicates++;

                continue;
            }

            $prepared = $this->prepare($submission, $project);

            // A batch may repeat a fact within itself, not just against what is already stored.
            if ($key !== null) {
                $seen[$key] = null;
            }

            $pending[$project->id][] = $prepared;
            $slots[$project->id][] = $index;
        }

        foreach ($pending as $projectId => $batch) {
            $events = $this->ledger->appendMany($projectId, $batch);

            foreach ($events as $position => $event) {
                $results[$slots[$projectId][$position]] = IngestResult::accepted($event)->toArray();
                $accepted++;
            }
        }

        ksort($results);

        return [
            'batch_id'   => $batchId,
            'accepted'   => $accepted,
            'duplicates' => $duplicates,
            'rejected'   => $rejected,
            'results'    => array_values(array_map(
                fn ($index, $result) => $result + ['index' => $index],
                array_keys($results),
                $results
            )),
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $submissions
     * @return array<string, Project>  keyed by public uuid
     */
    private function resolveProjects(array $submissions): array
    {
        $uuids = array_filter(array_unique(array_column($submissions, 'project_id')));

        return Project::whereIn('uuid', $uuids)->get()->keyBy('uuid')->all();
    }

    /**
     * Facts already stored, for the whole batch in one query.
     *
     * @return array<string, EvidenceEvent>
     */
    private function existingKeys(array $submissions): array
    {
        $refs = array_filter(array_unique(array_column($submissions, 'source_ref')));

        if ($refs === []) {
            return [];
        }

        return EvidenceEvent::whereIn('source_ref', $refs)
            ->get()
            ->keyBy(fn (EvidenceEvent $e) => $e->source_module . '|' . $e->source_ref . '|' . $e->event_type)
            ->all();
    }

    private function naturalKey(array $submission): ?string
    {
        return empty($submission['source_ref'])
            ? null
            : $submission['source_module'] . '|' . $submission['source_ref'] . '|' . $submission['event_type'];
    }

    /**
     * Replay a dead letter after it has been corrected.
     *
     * @param  array<string,mixed>|null  $corrections  fields to overwrite before retrying
     */
    public function replay(EvidenceDeadLetter $letter, ?array $corrections = null): IngestResult
    {
        $result = $this->ingest(array_merge($letter->payload, $corrections ?? []));

        if ($result->status !== IngestResult::REJECTED) {
            $letter->update([
                'replayed_at' => now(),
                'replayed_as' => $result->event?->uuid,
            ]);
        }

        return $result;
    }

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * A submission as the ledger wants it.
     *
     * @param  array<string,mixed>  $submission
     * @return array<string,mixed>
     */
    private function prepare(array $submission, Project $project): array
    {
        return [
            'uuid'            => $submission['uuid'] ?? null,
            'project_id'      => $project->id,
            'subject_type'    => $submission['subject_type'],
            'subject_uuid'    => $submission['subject_id'] ?? null,
            'subject_id'      => $submission['subject_type'] === 'project' ? $project->id : null,
            'event_type'      => $submission['event_type'],
            'source_module'   => $submission['source_module'],
            'source_ref'      => $submission['source_ref'] ?? null,
            'occurred_at'     => $submission['occurred_at'],
            'occurred_tz'     => $submission['occurred_tz'],
            'actor_type'      => $submission['actor_type'] ?? (Auth::check() ? 'user' : 'integration'),
            'actor_id'        => $submission['actor_id'] ?? (string) Auth::id(),
            'quantity'        => $submission['quantity'] ?? null,
            'unit'            => $submission['unit'] ?? null,
            'amount'          => $submission['amount'] ?? null,
            'currency'        => $submission['currency'] ?? null,
            // The taxonomy's default stands unless the sender states its own confidence — a device
            // that knows it is estimating should be able to say so.
            'confidence'      => $submission['confidence'] ?? EvidenceTaxonomy::defaultConfidence($submission['event_type']),
            'geo_lat'         => $submission['geo_lat'] ?? null,
            'geo_lng'         => $submission['geo_lng'] ?? null,
            'payload'         => $submission['payload'] ?? [],
            'attachments'     => $submission['attachments'] ?? [],
            'idempotency_key' => $submission['idempotency_key'] ?? null,
        ];
    }

    /** @return list<string> */
    private function validate(array $submission): array
    {
        $errors = [];

        foreach (['project_id', 'subject_type', 'event_type', 'source_module', 'occurred_at', 'occurred_tz'] as $field) {
            if (empty($submission[$field])) {
                $errors[] = "{$field} is required.";
            }
        }

        if ($errors !== []) {
            return $errors;
        }

        if (! in_array($submission['subject_type'], EvidenceEvent::SUBJECT_TYPES, true)) {
            $errors[] = "subject_type '{$submission['subject_type']}' is not a known subject.";
        }

        try {
            $occurredAt = Carbon::parse($submission['occurred_at']);
        } catch (\Throwable) {
            return array_merge($errors, ['occurred_at is not a valid timestamp.']);
        }

        if ($occurredAt->isAfter(now()->addHours(self::MAX_FUTURE_HOURS))) {
            $errors[] = 'occurred_at is more than 24 hours in the future; check the device clock.';
        }

        if (! in_array($submission['occurred_tz'], timezone_identifiers_list(), true)) {
            $errors[] = "occurred_tz '{$submission['occurred_tz']}' is not a valid IANA timezone.";
        }

        if (($submission['amount'] ?? null) !== null && empty($submission['currency'])) {
            $errors[] = 'amount requires a currency.';
        }

        // Unknown event types pass this: they are stored, not rejected (PM-1001).
        return array_merge($errors, TaxonomyValidator::validate($submission['event_type'], $submission['payload'] ?? []));
    }

    /**
     * The same real-world fact submitted twice.
     *
     * Scoped to a source: two modules may legitimately reference the same id, and only the pair
     * identifies the fact.
     */
    private function findDuplicate(array $submission): ?EvidenceEvent
    {
        if (empty($submission['source_ref'])) {
            return null;
        }

        return EvidenceEvent::query()
            ->where('source_module', $submission['source_module'])
            ->where('source_ref', $submission['source_ref'])
            ->where('event_type', $submission['event_type'])
            ->first();
    }

    /** @param  list<string>  $errors */
    private function deadLetter(array $submission, array $errors, ?string $batchId): EvidenceDeadLetter
    {
        $project = ! empty($submission['project_id'])
            ? Project::where('uuid', $submission['project_id'])->value('id')
            : null;

        return EvidenceDeadLetter::create([
            'source_module' => $submission['source_module'] ?? null,
            'source_ref'    => $submission['source_ref'] ?? null,
            'event_type'    => $submission['event_type'] ?? null,
            'project_id'    => $project,
            'batch_id'      => $batchId,
            'payload'       => $submission,
            'errors'        => $errors,
            'submitted_by'  => Auth::id(),
        ]);
    }
}
