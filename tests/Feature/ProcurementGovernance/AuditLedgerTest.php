<?php

namespace Tests\Feature\ProcurementGovernance;

use App\Modules\Procurement\Governance\Services\AuditLedger;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\MakesCompanies;
use Tests\TestCase;

/**
 * The §7 universal audit trail.
 *
 * This is the module's evidence of itself. If the chain can be edited, or a removed row goes
 * unnoticed, then every approval, score and disqualification the module records becomes an
 * assertion rather than a record. These tests attack it the two ways it can actually be attacked:
 * through the application, and through the database directly.
 */
class AuditLedgerTest extends TestCase
{
    use DatabaseTransactions, MakesCompanies;

    private int $companyId;

    protected function setUp(): void
    {
        parent::setUp();

        $companyId = DB::table('companies')->min('id');

        if ($companyId === null) {
            $this->markTestSkipped('No company in the test database.');
        }

        $this->companyId = (int) $companyId;
    }

    private function ledger(): AuditLedger
    {
        return app(AuditLedger::class);
    }

    private function record(array $overrides = []): int
    {
        return $this->ledger()->write($this->companyId, array_merge([
            'action' => 'created',
            'entity_type' => 'procgov_deviations',
            'entity_id' => 1,
            'entity_reference' => 'DEV-2026-'.Str::random(4),
        ], $overrides));
    }

    // ── The chain ───────────────────────────────────────────────────────────────────────────────

    public function test_each_event_chains_to_its_predecessor(): void
    {
        $first = $this->record();
        $second = $this->record();

        $a = DB::table('procgov_audit_events')->find($first);
        $b = DB::table('procgov_audit_events')->find($second);

        $this->assertSame($a->hash, $b->previous_hash);
        $this->assertSame((int) $a->sequence + 1, (int) $b->sequence);
    }

    public function test_the_sequence_is_gap_free_so_a_removed_row_is_visible_as_a_hole(): void
    {
        $sequences = [];

        for ($i = 0; $i < 5; $i++) {
            $sequences[] = (int) DB::table('procgov_audit_events')->find($this->record())->sequence;
        }

        $this->assertSame(range($sequences[0], $sequences[0] + 4), $sequences);
    }

    public function test_a_clean_chain_verifies(): void
    {
        $this->record();
        $this->record();

        $result = $this->ledger()->verify($this->companyId);

        $this->assertTrue($result['ok'], $result['reason'] ?? '');
        $this->assertNull($result['broken_at']);
    }

    // ── Tamper detection ────────────────────────────────────────────────────────────────────────

    public function test_editing_an_event_breaks_verification(): void
    {
        $this->record(['summary' => 'Approved deviation DEV-2026-0042']);
        $target = $this->record(['summary' => 'Rejected deviation DEV-2026-0043']);
        $this->record();

        // The trigger blocks UPDATE, so this is what a direct-database attacker would do first:
        // drop it, edit the row, and put it back. The hash chain is what catches them.
        DB::statement('ALTER TABLE procgov_audit_events DISABLE TRIGGER procgov_audit_append_only');

        try {
            DB::table('procgov_audit_events')
                ->where('id', $target)
                ->update(['summary' => 'Approved deviation DEV-2026-0043']);

            $result = $this->ledger()->verify($this->companyId);
        } finally {
            DB::statement('ALTER TABLE procgov_audit_events ENABLE TRIGGER procgov_audit_append_only');
        }

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('does not match its recorded hash', $result['reason']);
    }

    public function test_deleting_an_event_breaks_verification(): void
    {
        $this->record();
        $target = $this->record();
        $this->record();

        DB::statement('ALTER TABLE procgov_audit_events DISABLE TRIGGER procgov_audit_append_only');

        try {
            DB::table('procgov_audit_events')->where('id', $target)->delete();

            $result = $this->ledger()->verify($this->companyId);
        } finally {
            DB::statement('ALTER TABLE procgov_audit_events ENABLE TRIGGER procgov_audit_append_only');
        }

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('sequence gap', $result['reason']);
    }

    // ── Immutability at the database ────────────────────────────────────────────────────────────

    public function test_the_database_refuses_an_update(): void
    {
        $id = $this->record();

        $this->expectException(QueryException::class);

        DB::table('procgov_audit_events')->where('id', $id)->update(['summary' => 'rewritten']);
    }

    public function test_the_database_refuses_a_delete(): void
    {
        $id = $this->record();

        $this->expectException(QueryException::class);

        DB::table('procgov_audit_events')->where('id', $id)->delete();
    }

    // ── Content ─────────────────────────────────────────────────────────────────────────────────

    public function test_an_event_records_the_decision_and_its_reason(): void
    {
        // §7's example line is exactly this shape, and the comment is frequently the only thing an
        // auditor actually wants.
        $id = $this->record([
            'action' => 'approval_approved',
            'decision' => 'APPROVED',
            'comment' => 'Emergency accepted; plant was down.',
            'summary' => 'Procurement Manager approved Deviation DEV-2026-0042',
        ]);

        $event = DB::table('procgov_audit_events')->find($id);

        $this->assertSame('APPROVED', $event->decision);
        $this->assertSame('Emergency accepted; plant was down.', $event->comment);
        $this->assertStringContainsString('DEV-2026-0042', $event->summary);
    }

    public function test_before_and_after_capture_only_what_changed(): void
    {
        $id = $this->record([
            'action' => 'updated',
            'before' => ['status' => 'DRAFT'],
            'after' => ['status' => 'SUBMITTED'],
        ]);

        $event = DB::table('procgov_audit_events')->find($id);

        $this->assertSame(['status' => 'DRAFT'], json_decode($event->before, true));
        $this->assertSame(['status' => 'SUBMITTED'], json_decode($event->after, true));
    }

    public function test_a_failing_write_is_swallowed_rather_than_taking_the_business_change_with_it(): void
    {
        // record() is best-effort by design: a logging fault must not roll back the approval it was
        // recording. `action` is NOT NULL, so this write cannot succeed.
        $result = $this->ledger()->record($this->companyId, ['entity_type' => 'procgov_deviations']);

        $this->assertNull($result);
    }

    // ── Tenancy ─────────────────────────────────────────────────────────────────────────────────

    public function test_each_tenant_has_its_own_independent_chain(): void
    {
        $other = $this->otherCompanyId($this->companyId);

        $mine = DB::table('procgov_audit_events')->find($this->record());
        $theirs = $this->ledger()->write((int) $other, [
            'action' => 'created',
            'entity_type' => 'procgov_deviations',
            'entity_id' => 1,
        ]);
        $theirs = DB::table('procgov_audit_events')->find($theirs);

        // One tenant's activity must never advance or link into another's chain, or a quiet period
        // for one company would look like missing evidence.
        $this->assertNotSame($mine->hash, $theirs->previous_hash);
        $this->assertSame((int) $other, (int) $theirs->company_id);
    }
}
