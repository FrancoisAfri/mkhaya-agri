<?php

namespace Tests\Feature\ProjectManagement;

use App\Modules\Operations\ProjectManagement\Models\EvidenceDeadLetter;
use App\Modules\Operations\ProjectManagement\Models\EvidenceEvent;
use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Services\EvidenceIngestor;
use App\Modules\Operations\ProjectManagement\Services\IngestResult;
use App\Modules\Operations\ProjectManagement\Support\Evidence\EvidenceTaxonomy;

/**
 * Evidence ingestion (PM-1001, PM-1003): the path a field device and a module adapter both use.
 */
class EvidenceIngestionTest extends ProjectManagementTestCase
{
    private EvidenceIngestor $ingestor;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ingestor = app(EvidenceIngestor::class);
        $this->project = $this->makeProject();
    }

    // ── Taxonomy ─────────────────────────────────────────────────────────────

    public function test_a_known_type_takes_its_declared_confidence(): void
    {
        // A measured quantity is worth more than somebody stating a status, and derivation depends
        // on that ordering being declared rather than assumed.
        $measured = $this->ingestor->ingest($this->submission([
            'event_type' => 'quantity.installed', 'quantity' => '12.5', 'unit' => 'm3',
        ]));
        $stated = $this->ingestor->ingest($this->submission([
            'event_type' => 'status.reported', 'source_ref' => 'ref-2',
        ]));

        $this->assertSame('1.00', $measured->event->confidence);
        $this->assertSame('0.60', $stated->event->confidence);
        $this->assertGreaterThan(
            EvidenceTaxonomy::defaultConfidence('status.reported'),
            EvidenceTaxonomy::defaultConfidence('quantity.installed')
        );
    }

    public function test_a_sender_may_state_its_own_confidence(): void
    {
        $result = $this->ingestor->ingest($this->submission([
            'event_type' => 'quantity.installed', 'confidence' => 0.5,
        ]));

        $this->assertSame('0.50', $result->event->confidence);
    }

    public function test_an_unknown_type_is_stored_but_carries_no_weight(): void
    {
        // Forward compatibility: a newer client must not lose data against an older server.
        $result = $this->ingestor->ingest($this->submission(['event_type' => 'drone.survey.flown']));

        $this->assertSame(IngestResult::ACCEPTED, $result->status);
        $this->assertSame('drone.survey.flown', $result->event->event_type);
        // Zero, not a guess — it cannot move a derived number until somebody declares what it means.
        $this->assertSame('0.00', $result->event->confidence);
        $this->assertFalse(EvidenceTaxonomy::knows('drone.survey.flown'));
    }

    public function test_a_known_type_missing_a_required_payload_field_is_dead_lettered(): void
    {
        // inspection.failed must say who inspected and why; without that it proves nothing.
        $result = $this->ingestor->ingest($this->submission([
            'event_type' => 'inspection.failed', 'payload' => ['inspector' => 'M. Dlamini'],
        ]));

        $this->assertSame(IngestResult::REJECTED, $result->status);
        $this->assertSame(0, EvidenceEvent::count(), 'Nothing may be half-stored.');
        $this->assertStringContainsString('payload.reason is required', implode(' ', $result->deadLetter->errors));
    }

    public function test_extra_payload_fields_are_kept(): void
    {
        $result = $this->ingestor->ingest($this->submission([
            'event_type' => 'diary.submitted',
            'payload'    => ['weather' => 'Rain', 'crew_mood' => 'poor'],
        ]));

        $this->assertSame(IngestResult::ACCEPTED, $result->status);
        $this->assertSame('poor', $result->event->payload['crew_mood']);
    }

    // ── Idempotency on the natural key ───────────────────────────────────────

    public function test_the_same_fact_submitted_twice_is_not_stored_twice(): void
    {
        // An adapter replaying a module's event stream will submit the same GRN more than once by
        // design, with or without an Idempotency-Key.
        $first = $this->ingestor->ingest($this->submission([
            'event_type' => 'grn.received', 'source_module' => 'procurement',
            'source_ref' => 'GRN-4471', 'payload' => ['reference' => 'GRN-4471'],
        ]));
        $second = $this->ingestor->ingest($this->submission([
            'event_type' => 'grn.received', 'source_module' => 'procurement',
            'source_ref' => 'GRN-4471', 'payload' => ['reference' => 'GRN-4471'],
        ]));

        $this->assertSame(IngestResult::ACCEPTED, $first->status);
        $this->assertSame(IngestResult::DUPLICATE, $second->status);
        $this->assertSame($first->event->uuid, $second->event->uuid);
        $this->assertSame(1, EvidenceEvent::count());
    }

    public function test_the_same_reference_from_a_different_source_is_a_different_fact(): void
    {
        $this->ingestor->ingest($this->submission([
            'event_type' => 'grn.received', 'source_module' => 'procurement',
            'source_ref' => '4471', 'payload' => ['reference' => '4471'],
        ]));
        $other = $this->ingestor->ingest($this->submission([
            'event_type' => 'grn.received', 'source_module' => 'mobile',
            'source_ref' => '4471', 'payload' => ['reference' => '4471'],
        ]));

        $this->assertSame(IngestResult::ACCEPTED, $other->status);
        $this->assertSame(2, EvidenceEvent::count());
    }

    // ── Time ─────────────────────────────────────────────────────────────────

    public function test_backdated_evidence_is_accepted(): void
    {
        // Capture after the fact is normal: the trench had no signal on Tuesday.
        $result = $this->ingestor->ingest($this->submission([
            'occurred_at' => now()->subDays(9)->toIso8601String(),
        ]));

        $this->assertSame(IngestResult::ACCEPTED, $result->status);
    }

    public function test_evidence_more_than_a_day_in_the_future_is_refused(): void
    {
        $result = $this->ingestor->ingest($this->submission([
            'occurred_at' => now()->addDays(3)->toIso8601String(),
        ]));

        $this->assertSame(IngestResult::REJECTED, $result->status);
        $this->assertStringContainsString('device clock', implode(' ', $result->deadLetter->errors));
    }

    public function test_a_few_hours_ahead_is_tolerated_as_clock_skew(): void
    {
        $result = $this->ingestor->ingest($this->submission([
            'occurred_at' => now()->addHours(3)->toIso8601String(),
        ]));

        $this->assertSame(IngestResult::ACCEPTED, $result->status);
    }

    // ── Batch ────────────────────────────────────────────────────────────────

    public function test_one_bad_record_does_not_lose_the_rest_of_the_batch(): void
    {
        $batch = [
            $this->submission(['source_ref' => 'a']),
            $this->submission(['source_ref' => 'b', 'event_type' => 'inspection.failed', 'payload' => []]),
            $this->submission(['source_ref' => 'c']),
        ];

        $outcome = $this->ingestor->ingestBatch($batch);

        $this->assertSame(2, $outcome['accepted']);
        $this->assertSame(1, $outcome['rejected']);
        $this->assertSame(2, EvidenceEvent::count());
        // Per-record answers, so a device marks exactly what synced.
        $this->assertSame(IngestResult::REJECTED, $outcome['results'][1]['status']);
        $this->assertSame(1, $outcome['results'][1]['index']);
    }

    public function test_a_rejected_record_is_held_with_its_batch_for_someone_to_fix(): void
    {
        $outcome = $this->ingestor->ingestBatch([
            $this->submission(['source_ref' => 'x', 'event_type' => 'inspection.failed', 'payload' => []]),
        ]);

        $letter = EvidenceDeadLetter::pending()->firstOrFail();

        $this->assertSame($outcome['batch_id'], $letter->batch_id);
        // The submission is kept exactly as it arrived, not a normalised version that would hide
        // the malformation being recorded.
        $this->assertSame('inspection.failed', $letter->payload['event_type']);
    }

    public function test_a_corrected_dead_letter_replays(): void
    {
        $result = $this->ingestor->ingest($this->submission([
            'event_type' => 'inspection.failed', 'payload' => ['inspector' => 'M. Dlamini'],
        ]));
        $letter = $result->deadLetter;

        $replayed = $this->ingestor->replay($letter, [
            'payload' => ['inspector' => 'M. Dlamini', 'reason' => 'Cover to reinforcement short'],
        ]);

        $this->assertSame(IngestResult::ACCEPTED, $replayed->status);
        $this->assertNotNull($letter->fresh()->replayed_at);
        $this->assertSame($replayed->event->uuid, $letter->fresh()->replayed_as);
        $this->assertSame(0, EvidenceDeadLetter::pending()->count());
    }

    /**
     * A shift's capture is 500 records, and it syncs when the bakkie reaches signal. The budget is
     * the ticket's: under 2 seconds.
     */
    public function test_a_batch_of_500_ingests_within_budget(): void
    {
        $batch = [];
        for ($i = 0; $i < 500; $i++) {
            $batch[] = $this->submission([
                'source_ref' => "bulk-{$i}",
                'event_type' => 'quantity.installed',
                'quantity'   => '1.0000',
                'unit'       => 'm3',
            ]);
        }

        $started = microtime(true);
        $outcome = $this->ingestor->ingestBatch($batch);
        $elapsed = microtime(true) - $started;

        $this->assertSame(500, $outcome['accepted']);
        $this->assertLessThan(2.0, $elapsed, sprintf('500 events took %.2fs; the budget is 2s.', $elapsed));
    }

    // ── Over the API ─────────────────────────────────────────────────────────

    public function test_the_schema_is_published_for_clients(): void
    {
        $schema = EvidenceTaxonomy::export();

        $this->assertSame('v1', $schema['version']);
        $this->assertArrayHasKey('quantity.installed', $schema['schemas']);
        $this->assertSame(['reason'], $schema['schemas']['task.reopened']['required']);
        // Unknown keys are permitted, so a newer client's extra field is not stripped.
        $this->assertTrue($schema['schemas']['diary.submitted']['additionalProperties']);
    }

    public function test_every_declared_type_carries_a_domain_and_a_confidence(): void
    {
        foreach (EvidenceTaxonomy::types() as $type) {
            $this->assertNotNull(EvidenceTaxonomy::domainOf($type), "{$type} has no domain.");
            $this->assertGreaterThan(0, EvidenceTaxonomy::defaultConfidence($type), "{$type} has no confidence.");
        }
    }

    // ─────────────────────────────────────────────────────────────────────────

    /** @param  array<string,mixed>  $overrides */
    private function submission(array $overrides = []): array
    {
        return array_merge([
            'project_id'    => $this->project->uuid,
            'subject_type'  => 'project',
            'event_type'    => 'progress.reported',
            'source_module' => 'mobile',
            'source_ref'    => 'ref-' . uniqid(),
            'occurred_at'   => now()->subHour()->toIso8601String(),
            'occurred_tz'   => 'Africa/Johannesburg',
            'actor_type'    => 'device',
            // progress.reported requires the figure itself — an event announcing progress without
            // saying how much moves nothing (see EvidenceTaxonomy).
            'payload'       => ['percent' => 25],
        ], $overrides);
    }
}
