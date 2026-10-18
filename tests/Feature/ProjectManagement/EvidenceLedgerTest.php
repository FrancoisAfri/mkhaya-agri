<?php

namespace Tests\Feature\ProjectManagement;

use App\Modules\Operations\ProjectManagement\Models\EvidenceEvent;
use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Services\EvidenceLedger;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * The Evidence Ledger's guarantees (PM-ADR-002). These are the tests that must never be relaxed:
 * if any of them fails, derived progress and cost figures stop being defensible.
 */
class EvidenceLedgerTest extends ProjectManagementTestCase
{
    private EvidenceLedger $ledger;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ledger = app(EvidenceLedger::class);
        $this->project = $this->makeProject();
    }

    // ── Hash chain ───────────────────────────────────────────────────────────

    public function test_first_event_starts_a_chain_and_later_events_link_to_it(): void
    {
        $first = $this->append(['event_type' => 'progress.reported']);
        $second = $this->append(['event_type' => 'hours.booked']);
        $third = $this->append(['event_type' => 'cost.incurred', 'amount' => '150.00', 'currency' => 'ZAR']);

        $this->assertNull($first->prev_hash, 'The first event in a project chain has no predecessor.');
        $this->assertSame($first->hash, $second->prev_hash);
        $this->assertSame($second->hash, $third->prev_hash);
        $this->assertSame(64, strlen($first->hash));
    }

    public function test_verify_chain_reports_intact_for_untouched_evidence(): void
    {
        $this->append();
        $this->append(['event_type' => 'quantity.installed', 'quantity' => '12.5', 'unit' => 'm3']);

        $result = $this->ledger->verifyChain($this->project->company_id, $this->project->id);

        $this->assertTrue($result->intact);
        $this->assertSame(2, $result->eventsChecked);
        $this->assertNull($result->brokenAtUuid);
    }

    public function test_verify_chain_detects_tampering(): void
    {
        $this->append();
        $target = $this->append(['quantity' => '10.0000', 'unit' => 'm3']);

        // Simulate the threat the hash chain actually exists for: someone with direct database
        // access who can switch the append-only trigger off. The application cannot do this — the
        // trigger blocks ordinary tampering, and that is covered by its own test — so the chain is
        // the last line of defence, and it must still notice.
        DB::statement('ALTER TABLE pm_evidence_events DISABLE TRIGGER pm_evidence_events_append_only');

        try {
            DB::statement(
                'UPDATE pm_evidence_events SET quantity = 999 WHERE uuid = ? AND occurred_at = ?',
                [$target->uuid, $target->occurred_at]
            );
        } finally {
            DB::statement('ALTER TABLE pm_evidence_events ENABLE TRIGGER pm_evidence_events_append_only');
        }

        $result = $this->ledger->verifyChain($this->project->company_id, $this->project->id);

        $this->assertFalse($result->intact);
        $this->assertSame($target->uuid, $result->brokenAtUuid);
        $this->assertStringContainsString('does not match', (string) $result->reason);
    }

    public function test_each_project_has_its_own_chain(): void
    {
        $other = $this->makeProject(['code' => 'PRJ-OTHER']);

        $a1 = $this->append();
        $b1 = $this->append(['project_id' => $other->id]);
        $a2 = $this->append();

        // The second project's first event starts fresh rather than continuing project A's chain,
        // so one project can be verified — or archived — without the other.
        $this->assertNull($b1->prev_hash);
        $this->assertSame($a1->hash, $a2->prev_hash);
    }

    // ── Append-only ──────────────────────────────────────────────────────────

    public function test_the_database_rejects_an_update_to_evidence(): void
    {
        $event = $this->append();

        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->expectExceptionMessage('append-only');

        DB::statement(
            'UPDATE pm_evidence_events SET quantity = 5 WHERE uuid = ? AND occurred_at = ?',
            [$event->uuid, $event->occurred_at]
        );
    }

    public function test_the_database_rejects_a_delete_of_evidence(): void
    {
        $event = $this->append();

        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->expectExceptionMessage('append-only');

        DB::statement(
            'DELETE FROM pm_evidence_events WHERE uuid = ? AND occurred_at = ?',
            [$event->uuid, $event->occurred_at]
        );
    }

    public function test_the_model_refuses_to_update_or_delete_before_reaching_the_database(): void
    {
        $event = $this->append();

        try {
            $event->update(['quantity' => 5]);
            $this->fail('Updating an EvidenceEvent should throw.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }

        try {
            $event->delete();
            $this->fail('Deleting an EvidenceEvent should throw.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }
    }

    // ── Correction by supersession ───────────────────────────────────────────

    public function test_supersede_writes_a_correction_and_leaves_the_original_in_place(): void
    {
        $original = $this->append(['quantity' => '10.0000', 'unit' => 'm3']);

        $correction = $this->ledger->supersede($original, [
            'quantity'   => '8.0000',
            'unit'       => 'm3',
            'actor_type' => 'user',
            'payload'    => ['reason' => 'Re-measured after survey'],
        ]);

        $stored = EvidenceEvent::where('uuid', $original->uuid)->first();

        $this->assertSame($correction->uuid, $stored->superseded_by);
        $this->assertSame('10.0000', $stored->quantity, 'The original keeps its original value.');
        $this->assertSame($original->uuid, $correction->payload['supersedes']);

        // Superseded evidence drops out of derivation but stays readable.
        $this->assertSame(1, EvidenceEvent::live()->where('project_id', $this->project->id)->count());
        $this->assertSame(2, EvidenceEvent::where('project_id', $this->project->id)->count());
    }

    public function test_an_event_cannot_be_superseded_twice(): void
    {
        $original = $this->append();
        $this->ledger->supersede($original, ['actor_type' => 'user']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('already superseded');

        $this->ledger->supersede($original->fresh(), ['actor_type' => 'user']);
    }

    public function test_the_chain_stays_intact_after_a_correction(): void
    {
        $original = $this->append(['quantity' => '10.0000', 'unit' => 'm3']);
        $this->ledger->supersede($original, ['quantity' => '8.0000', 'unit' => 'm3', 'actor_type' => 'user']);

        $result = $this->ledger->verifyChain($this->project->company_id, $this->project->id);

        // superseded_by is excluded from the hash precisely so that correcting an event does not
        // invalidate the chain it sits in.
        $this->assertTrue($result->intact, (string) $result->reason);
    }

    // ── Offline sync ─────────────────────────────────────────────────────────

    public function test_a_client_supplied_uuid_is_stored_verbatim(): void
    {
        $uuid = '019f0000-0000-7000-8000-00000000abcd';

        $event = $this->append(['uuid' => $uuid]);

        $this->assertSame($uuid, $event->uuid, 'A device-generated id must never be remapped.');
    }

    public function test_replaying_the_same_idempotency_key_does_not_duplicate(): void
    {
        $first = $this->append(['idempotency_key' => 'device-7-batch-3']);
        $second = $this->append(['idempotency_key' => 'device-7-batch-3']);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, EvidenceEvent::where('project_id', $this->project->id)->count());
    }

    // ── Validation ───────────────────────────────────────────────────────────

    public function test_money_without_a_currency_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('currency');

        $this->append(['amount' => '100.00']);
    }

    public function test_money_supplied_as_a_float_is_rejected(): void
    {
        // PM-ADR-006: a float has already lost exactness, so the hash would describe something
        // other than what was banked.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('float');

        $this->append(['amount' => 100.50, 'currency' => 'ZAR']);
    }

    public function test_an_amount_with_too_many_decimals_is_rejected_rather_than_rounded(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('decimal places');

        $this->append(['amount' => '100.005', 'currency' => 'ZAR']);
    }

    public function test_an_invalid_timezone_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('IANA timezone');

        $this->append(['occurred_tz' => 'Mars/Olympus']);
    }

    // ── Timestamps ───────────────────────────────────────────────────────────

    public function test_the_instant_survives_the_round_trip_and_keeps_its_originating_zone(): void
    {
        $event = $this->append([
            'occurred_at' => '2026-08-06T08:00:00+02:00',
            'occurred_tz' => 'Africa/Johannesburg',
        ]);

        $stored = EvidenceEvent::where('uuid', $event->uuid)->first();

        // Stored UTC, with the zone it was observed in kept alongside (DoD 7).
        $this->assertSame('2026-08-06T06:00:00+00:00', $stored->occurred_at->toIso8601String());
        $this->assertSame('Africa/Johannesburg', $stored->occurred_tz);
    }

    // ── Outbox ───────────────────────────────────────────────────────────────

    public function test_appending_evidence_enqueues_an_outbox_event_in_the_same_transaction(): void
    {
        $event = $this->append();

        $this->assertDatabaseHas('pm_outbox', [
            'event_type'   => 'pm.evidence.appended',
            'subject_type' => $event->subject_type,
        ]);
    }

    public function test_a_rolled_back_append_leaves_no_outbox_event(): void
    {
        $before = DB::table('pm_outbox')->count();

        try {
            DB::transaction(function () {
                $this->append();
                throw new \RuntimeException('business rule failed after the append');
            });
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame($before, DB::table('pm_outbox')->count(),
            'An event must not survive the rollback of the change that caused it.');
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function append(array $overrides = []): EvidenceEvent
    {
        return $this->ledger->append(array_merge([
            'project_id'    => $this->project->id,
            'subject_type'  => 'project',
            'subject_id'    => $this->project->id,
            'subject_uuid'  => $this->project->uuid,
            'event_type'    => 'progress.reported',
            'source_module' => 'pm',
            'occurred_at'   => now()->toIso8601String(),
            'occurred_tz'   => 'Africa/Johannesburg',
            'actor_type'    => 'user',
            'actor_id'      => '1',
        ], $overrides));
    }
}
