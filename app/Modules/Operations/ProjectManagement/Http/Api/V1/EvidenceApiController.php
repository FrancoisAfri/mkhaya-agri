<?php

namespace App\Modules\Operations\ProjectManagement\Http\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Operations\ProjectManagement\Models\EvidenceDeadLetter;
use App\Modules\Operations\ProjectManagement\Models\EvidenceEvent;
use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Services\EvidenceIngestor;
use App\Modules\Operations\ProjectManagement\Services\IngestResult;
use App\Modules\Operations\ProjectManagement\Support\Api\ApiQuery;
use App\Modules\Operations\ProjectManagement\Support\Evidence\EvidenceTaxonomy;
use App\Modules\Operations\ProjectManagement\Support\ProjectPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Evidence over the API (PM-1003) — the endpoint the mobile app and the module adapters use.
 *
 * `POST /evidence` takes one submission or a batch of them. A batch is answered per record, so a
 * device can mark exactly what synced instead of retrying a whole shift because one record was
 * malformed.
 *
 * There is no update and no delete, here or anywhere: the ledger is append-only (PM-ADR-002).
 */
class EvidenceApiController extends Controller
{
    /** Devices sync a shift at a time; beyond this, split the batch. */
    private const MAX_BATCH = 500;

    public function __construct(private readonly EvidenceIngestor $ingestor)
    {
    }

    /**
     * The vocabulary and its payload contracts, so a client validates before it ever leaves the
     * device — cheaper than a round trip on a bad connection, and the same declarations the server
     * enforces (PM-1001).
     */
    public function schema(): JsonResponse
    {
        return response()->json(EvidenceTaxonomy::export())
            // The taxonomy only grows, so a client may cache it for a day.
            ->header('Cache-Control', 'public, max-age=86400');
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', EvidenceEvent::class);

        $query = EvidenceEvent::query();

        ApiQuery::applyFilters($query, $request, [
            'project_id'   => function ($q, $v) {
                $q->where('project_id', Project::where('uuid', $v)->value('id') ?? 0);
            },
            'event_type'   => fn ($q, $v) => $q->whereIn('event_type', (array) $v),
            'subject_type' => fn ($q, $v) => $q->whereIn('subject_type', (array) $v),
            'since'        => fn ($q, $v) => $q->where('occurred_at', '>=', $v),
        ]);

        if (! $request->boolean('filter.include_superseded')) {
            $query->live();
        }

        $page = $query->orderBy('id')->cursorPaginate(
            ApiQuery::limit($request), ['*'], 'cursor', $request->query('cursor')
        );

        return response()->json(ApiQuery::envelope(
            collect($page->items())
                ->map(fn (EvidenceEvent $e) => ApiQuery::selectFields(ProjectPresenter::evidence($e), $request))
                ->all(),
            $page->nextCursor()?->encode(),
        ));
    }

    /**
     * Record evidence: `{...}` for one, or `{"events": [...]}` for a batch.
     *
     * Always 200, never 207 or a partial failure code: every record has its own status in the body,
     * and an HTTP-level error would tell a client to retry records that were accepted.
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('record', EvidenceEvent::class);

        if ($request->has('events')) {
            $request->validate([
                'events'   => ['required', 'array', 'max:' . self::MAX_BATCH],
                'events.*' => ['array'],
            ]);

            return response()->json($this->ingestor->ingestBatch($request->input('events')));
        }

        $result = $this->ingestor->ingest($request->all());

        return response()->json(
            $result->toArray(),
            match ($result->status) {
                IngestResult::ACCEPTED => 201,
                // A duplicate is 200 with the original, not 201: nothing was created, and the
                // caller's record already counts (PM-1003).
                IngestResult::DUPLICATE => 200,
                default => 422,
            }
        );
    }

    /** What was refused and why — the queue somebody works through, not a log nobody reads. */
    public function deadLetters(Request $request): JsonResponse
    {
        $this->authorize('viewAny', EvidenceEvent::class);

        $query = EvidenceDeadLetter::query()->pending();

        ApiQuery::applyFilters($query, $request, [
            'batch_id'   => fn ($q, $v) => $q->where('batch_id', $v),
            'event_type' => fn ($q, $v) => $q->where('event_type', $v),
        ]);

        $page = $query->orderByDesc('id')->cursorPaginate(
            ApiQuery::limit($request), ['*'], 'cursor', $request->query('cursor')
        );

        return response()->json(ApiQuery::envelope(
            collect($page->items())->map(fn (EvidenceDeadLetter $l) => [
                'id'         => $l->uuid,
                'event_type' => $l->event_type,
                'source'     => $l->source_module,
                'batch_id'   => $l->batch_id,
                'errors'     => $l->errors,
                'payload'    => $l->payload,
                'created_at' => $l->created_at?->toIso8601String(),
            ])->all(),
            $page->nextCursor()?->encode(),
        ));
    }

    /** Correct and retry a rejected submission. */
    public function replay(Request $request, string $uuid): JsonResponse
    {
        $this->authorize('record', EvidenceEvent::class);

        $letter = EvidenceDeadLetter::where('uuid', $uuid)->firstOrFail();

        $corrections = $request->validate([
            'corrections' => ['nullable', 'array'],
        ])['corrections'] ?? null;

        $result = $this->ingestor->replay($letter, $corrections);

        return response()->json(
            $result->toArray(),
            $result->status === IngestResult::REJECTED ? 422 : 200
        );
    }

    /** Correct an earlier observation by superseding it. */
    public function supersede(Request $request, string $uuid): JsonResponse
    {
        $original = EvidenceEvent::where('uuid', $uuid)->firstOrFail();

        $this->authorize('supersede', $original);

        $data = $request->validate([
            'reason'     => ['required', 'string', 'max:500'],
            'quantity'   => ['nullable', 'numeric'],
            'unit'       => ['nullable', 'string', 'max:20'],
            'amount'     => ['nullable', 'string', 'regex:/^-?\d+(\.\d{1,2})?$/'],
            'currency'   => ['nullable', 'string', 'size:3', Rule::in(\App\Support\Money\Currencies::codes())],
            'confidence' => ['nullable', 'numeric', 'between:0,1'],
        ]);

        $replacement = app(\App\Modules\Operations\ProjectManagement\Services\EvidenceLedger::class)
            ->supersede($original, [
                ...array_diff_key($data, ['reason' => null]),
                'actor_type' => 'user',
                'actor_id'   => (string) $request->user()?->id,
                'payload'    => ['reason' => $data['reason']],
            ]);

        return response()->json(['data' => ProjectPresenter::evidence($replacement)], 201);
    }
}
