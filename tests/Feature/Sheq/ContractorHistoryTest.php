<?php

namespace Tests\Feature\Sheq;

use App\Core\User\Models\User;
use App\Modules\Operations\Sheq\Compliance\ContractorHistoryService;
use App\Modules\Operations\Sheq\Services\AccessPassService;
use App\Modules\Operations\Sheq\Services\ContractorPerformanceService;
use App\Modules\Operations\Sheq\Services\ContractorService;
use App\Modules\Operations\Sheq\Services\SiteApprovalService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A contractor's whole story, the scans behind it, and the recalculation nobody triggered.
 *
 * CON-22, CON-23, COM-4 and COM-5 are one piece of work because they are one question asked four
 * ways: what happened, what was presented at the gate, is the record still true, and why is the
 * answer what it is. Each existed in part; none could be answered in full.
 */
class ContractorHistoryTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

    private User $user;

    private array $contractor;

    private int $workerId;

    private string $workerUuid;

    private int $siteId;

    protected function setUp(): void
    {
        parent::setUp();

        // These tests read what a page hands its component, not the compiled front end. Without
        // this the suite depends on `public/build/manifest.json`, so an unrelated `vite build`
        // running in another checkout turns every page assertion below into a 500.
        $this->withoutVite();

        $companyId = DB::table('companies')->min('id');

        if ($companyId === null) {
            $this->markTestSkipped('No company in the test database.');
        }

        $this->companyId = (int) $companyId;
        $this->user = $this->userWith(['sheq.contractor.manage', 'sheq.configure']);

        $this->siteId = (int) DB::table('sheq_sites')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'code' => 'H'.Str::upper(Str::random(5)), 'name' => 'Depot', 'site_type' => 'DEPOT',
            'timezone' => 'Africa/Johannesburg', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('sheq_settings')->updateOrInsert(
            ['company_id' => $this->companyId],
            ['contractor_required_documents' => json_encode([]), 'updated_at' => now()],
        );

        $this->contractor = app(ContractorService::class)->register($this->companyId, [
            'name' => 'Sibanye Electrical', 'trade' => 'ELECTRICAL',
        ], $this->user->id);

        $this->workerUuid = (string) Str::uuid();

        $this->workerId = (int) DB::table('sheq_contractor_workers')->insertGetId([
            'uuid' => $this->workerUuid, 'company_id' => $this->companyId,
            'contractor_id' => $this->contractor['id'], 'name' => 'Lerato Dlamini',
            'inducted_on' => Carbon::today()->subMonth()->toDateString(),
            'induction_expires' => Carbon::today()->addYear()->toDateString(),
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function history(): ContractorHistoryService
    {
        return app(ContractorHistoryService::class);
    }

    // ── CON-22 ────────────────────────────────────────────────────────────────────────────────

    public function test_every_kind_of_event_the_requirement_lists_has_a_source(): void
    {
        /*
         * CON-22 names ten kinds. A history screen silently missing one is the worst failure
         * available here: it looks complete, and it is produced in answer to a formal request
         * from an auditor or an attorney.
         */
        foreach (['APPROVAL', 'REJECTION', 'BLOCK', 'UNBLOCK', 'DOCUMENT', 'SAFETY_FILE',
            'ACCESS_DECISION', 'SCORE', 'INCIDENT', 'AUDIT'] as $kind) {
            $this->assertArrayHasKey($kind, ContractorHistoryService::SOURCES,
                "CON-22 requires {$kind} history and nothing reads it.");
        }
    }

    public function test_registering_a_contractor_already_shows_in_the_history(): void
    {
        $result = $this->history()->forContractor($this->companyId, $this->contractor['id']);

        $this->assertNotEmpty($result['events']);
        $this->assertContains('APPROVAL', array_column($result['events'], 'kind'));

        // The sealed chain is a property of the storage, not a promise made by the screen.
        $audit = collect($result['events'])->firstWhere('source', 'sheq_audit_log');
        $this->assertTrue($audit['sealed'],
            'The audit log row carries no hash, so nothing can show the chain is intact.');
    }

    public function test_the_audit_log_really_is_append_only(): void
    {
        /*
         * CON-22 says immutable. Asserted against the database rather than trusted, because the
         * triggers that enforce it are invisible from PHP and a migration could drop them without
         * any test noticing.
         */
        $row = DB::table('sheq_audit_log')->where('company_id', $this->companyId)
            ->orderByDesc('id')->first(['id', 'hash', 'prev_hash']);

        $this->assertNotNull($row);
        $this->assertNotNull($row->hash, 'The hash chain is not being written.');

        foreach (['update', 'delete'] as $operation) {
            /*
             * A savepoint rather than a rollback, and the first version of this test got it
             * wrong in a way worth recording: rolling back undid the insert that created the row,
             * so the DELETE then matched nothing, returned 0 and threw nothing — and the test
             * reported that the delete guard was missing when it was working perfectly.
             */
            DB::statement('SAVEPOINT tamper_attempt');

            try {
                $operation === 'update'
                    ? DB::table('sheq_audit_log')->where('id', $row->id)
                        ->update(['reason' => 'tampered'])
                    : DB::table('sheq_audit_log')->where('id', $row->id)->delete();

                $this->fail("A row in the audit log could be {$operation}d.");
            } catch (\Illuminate\Database\QueryException $e) {
                $this->assertStringContainsString('append-only', $e->getMessage());
                DB::statement('ROLLBACK TO SAVEPOINT tamper_attempt');
            }
        }

        // Still there, and unchanged.
        $this->assertNotNull(DB::table('sheq_audit_log')->where('id', $row->id)->first());
    }

    public function test_a_refused_safety_file_is_its_own_kind_of_event(): void
    {
        // A rejection is the event a contractor disputes and the one an auditor asks about
        // first. Filed among ordinary state changes it is unfindable.
        $submissionId = DB::table('sheq_safety_file_submissions')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'contractor_id' => $this->contractor['id'], 'version' => 1, 'state' => 'CHANGES_REQUIRED',
            'submitted_at' => now()->subDays(3), 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('sheq_safety_file_transitions')->insert([
            'company_id' => $this->companyId, 'submission_id' => $submissionId,
            'from_state' => 'UNDER_REVIEW', 'to_state' => 'CHANGES_REQUIRED',
            'actor_id' => $this->user->id, 'occurred_at' => now()->subDays(2),
            'reason' => 'The method statement does not cover the switchgear.',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $result = $this->history()->forContractor($this->companyId, $this->contractor['id']);

        $rejection = collect($result['events'])->firstWhere('kind', 'REJECTION');

        $this->assertNotNull($rejection, 'A refused safety file is not reported as a rejection.');
        $this->assertStringContainsString('switchgear', $rejection['detail']);
        // Named, because "the file was refused" without a name is a decision nobody owns.
        $this->assertNotNull($rejection['actor']);
    }

    public function test_the_history_is_newest_first_and_can_be_narrowed(): void
    {
        DB::table('sheq_incidents')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'reference' => 'INC-'.Str::upper(Str::random(5)), 'site_id' => $this->siteId,
            'contractor_id' => $this->contractor['id'], 'incident_type' => 'ELECTRIC_SHOCK',
            'occurred_at' => now()->subDay(), 'reported_at' => now()->subDay(),
            'description' => 'Contact with a live conductor.', 'status' => 'OPEN',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $all = $this->history()->forContractor($this->companyId, $this->contractor['id']);

        $times = array_column($all['events'], 'at');
        $sorted = $times;
        rsort($sorted);
        $this->assertSame($sorted, $times, 'The history is not in order.');

        $narrowed = $this->history()->forContractor($this->companyId, $this->contractor['id'],
            ['kinds' => ['INCIDENT']]);

        $this->assertNotEmpty($narrowed['events']);
        $this->assertSame(['INCIDENT'],
            array_values(array_unique(array_column($narrowed['events'], 'kind'))));
    }

    public function test_a_score_change_is_recorded_and_an_unchanged_score_is_not(): void
    {
        /*
         * Nothing audited scores at all. A contractor could move from GOOD to POOR between two
         * monthly runs with no trace but the new number overwriting the old one in place.
         */
        $performance = app(ContractorPerformanceService::class);

        $performance->computeMonth($this->companyId, $this->contractor['id']);

        $first = DB::table('sheq_audit_log')->where('company_id', $this->companyId)
            ->where('action', 'contractor.score_changed')->count();

        $this->assertSame(1, $first, 'The first score was not recorded as a change.');

        // Re-running the same period must not file a second identical change, or the one month
        // that actually moved is buried among the re-runs.
        $performance->computeMonth($this->companyId, $this->contractor['id']);

        $this->assertSame($first, DB::table('sheq_audit_log')
            ->where('company_id', $this->companyId)
            ->where('action', 'contractor.score_changed')->count());
    }

    // ── COM-5 ─────────────────────────────────────────────────────────────────────────────────

    public function test_the_explanation_names_the_evidence_and_not_only_the_verdict(): void
    {
        app(SiteApprovalService::class)->decide($this->companyId, $this->contractor['uuid'],
            $this->siteId, ['status' => 'BLOCKED',
                'reason' => 'Refused after the switchgear incident in March.'], $this->user->id);

        $why = $this->history()->why($this->companyId, $this->contractor['id'],
            $this->workerId, $this->siteId);

        $this->assertFalse($why['allowed']);
        $this->assertNotEmpty($why['timeline']);

        $blocking = array_values(array_filter($why['timeline'],
            fn (array $i) => $i['severity'] === 'BLOCKING'));

        $this->assertNotEmpty($blocking, 'Nothing in the explanation accounts for the refusal.');

        /*
         * Across all of them, not just the first. A blocked contractor fails the contractor check
         * too, and that one reads generically — the site's own reason is the useful sentence and
         * it must be somewhere in the explanation.
         */
        $this->assertStringContainsString('switchgear',
            implode(' ', array_column($blocking, 'detail')));

        // The code as well as the sentence, so the explanation is usable by a machine too.
        foreach ($blocking as $item) {
            $this->assertNotSame('', $item['code']);
        }
    }

    public function test_the_explanation_puts_the_refusals_before_the_advisories(): void
    {
        // Somebody asking why a gate closed does not want to read nine advisories first.
        DB::table('sheq_contractor_workers')->where('id', $this->workerId)
            ->update(['inducted_on' => null, 'induction_expires' => null]);

        $why = $this->history()->why($this->companyId, $this->contractor['id'],
            $this->workerId, $this->siteId);

        $severities = array_column($why['timeline'], 'severity');

        $this->assertSame('BLOCKING', $severities[0] ?? null);

        // Every blocking item sits above every advisory one: once an advisory has appeared,
        // nothing blocking may follow it.
        $seenAdvisory = false;

        foreach ($severities as $severity) {
            if ($severity !== 'BLOCKING') {
                $seenAdvisory = true;

                continue;
            }

            $this->assertFalse($seenAdvisory, 'A refusal was listed below an advisory.');
        }
    }

    public function test_a_clean_contractor_has_nothing_in_the_way(): void
    {
        app(SiteApprovalService::class)->decide($this->companyId, $this->contractor['uuid'],
            $this->siteId, ['status' => 'APPROVED'], $this->user->id);

        $why = $this->history()->why($this->companyId, $this->contractor['id'],
            $this->workerId, $this->siteId);

        /*
         * Not "nothing blocking at all": a newly registered contractor has no safety file yet,
         * and that is a real refusal this fixture has not cleared. What matters is that the SITE
         * is no longer one of the reasons once the site has approved them.
         */
        $codes = array_column(array_filter($why['timeline'],
            fn (array $i) => $i['severity'] === 'BLOCKING'), 'code');

        $this->assertNotContains('SITE_BLOCKED', $codes);
        $this->assertNotContains('SITE_NOT_APPROVED', $codes,
            'An approved site was still reported as not having approved them.');
    }

    public function test_an_approved_site_is_shown_as_a_check_that_passed(): void
    {
        app(SiteApprovalService::class)->decide($this->companyId, $this->contractor['uuid'],
            $this->siteId, ['status' => 'APPROVED'], $this->user->id);

        $why = $this->history()->why($this->companyId, $this->contractor['id'],
            $this->workerId, $this->siteId);

        $this->assertNotContains('SITE_NOT_APPROVED', array_column($why['timeline'], 'code'));
    }

    // ── CON-23 ────────────────────────────────────────────────────────────────────────────────

    public function test_a_scan_that_matches_nobody_is_recorded(): void
    {
        /*
         * The one kind of scan that left no trace at all: verification returned "not found"
         * before reaching anything that writes. They are also the only interesting kind — a
         * replaced card, a card from another site, a photograph of somebody else's pass, or
         * somebody at a boom trying codes.
         */
        $before = DB::table('sheq_pass_scans')->count();

        $this->get('/sheq/pass/'.$this->unmatchedToken('one').'?site='.$this->siteId)->assertOk();

        $scan = DB::table('sheq_pass_scans')->orderByDesc('id')
            ->first(['outcome', 'company_id', 'site_id', 'token_hash']);

        $this->assertSame($before + 1, DB::table('sheq_pass_scans')->count());
        $this->assertSame('UNKNOWN_PASS', $scan->outcome);

        // Attributed through the gate the reader said it was at, so an unknown code still
        // reaches the host who needs to see it.
        $this->assertSame($this->companyId, (int) $scan->company_id);
        $this->assertSame($this->siteId, (int) $scan->site_id);

        // Hashed, never the token. A log holding live credentials turns a database read into a
        // working card.
        $this->assertSame(hash('sha256', $this->unmatchedToken('one')), $scan->token_hash);
    }

    public function test_a_real_scan_records_everything_the_requirement_lists(): void
    {
        $token = app(AccessPassService::class)
            ->workerToken($this->companyId, $this->workerUuid, $this->user->id);

        $this->get("/sheq/pass/{$token}?site={$this->siteId}&activity=HOT_WORK&device=GATE-3")
            ->assertOk();

        $scan = DB::table('sheq_pass_scans')->orderByDesc('id')->first();

        // CON-23's list: timestamp, site, contractor/worker, result, reason, device/user.
        $this->assertNotNull($scan->scanned_at);
        $this->assertSame($this->siteId, (int) $scan->site_id);
        $this->assertSame($this->contractor['id'], (int) $scan->contractor_id);
        $this->assertSame('CONTRACTOR_WORKER', $scan->subject_type);
        $this->assertSame('Lerato Dlamini', $scan->person_name);
        $this->assertContains($scan->outcome, ['ALLOWED', 'DENIED', 'REVIEW_REQUIRED', 'UNKNOWN']);
        $this->assertSame('GATE-3', $scan->device_ref);
        $this->assertSame('HOT_WORK', $scan->activity);
        $this->assertNotNull($scan->ip_address);
    }

    public function test_the_scan_trail_cannot_be_rewritten(): void
    {
        $this->get('/sheq/pass/'.$this->unmatchedToken('two').'?site='.$this->siteId)->assertOk();

        $id = DB::table('sheq_pass_scans')->orderByDesc('id')->value('id');

        try {
            DB::table('sheq_pass_scans')->where('id', $id)->update(['outcome' => 'ALLOWED']);
            $this->fail('A scan record could be rewritten.');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
            DB::rollBack();
            DB::beginTransaction();
        }
    }

    public function test_the_scan_page_groups_unknown_codes_rather_than_listing_them_flat(): void
    {
        /*
         * One code scanned eleven times is a lost card; eleven different codes is somebody
         * testing the gate. Listed flat those are indistinguishable, and they call for entirely
         * different responses.
         */
        foreach (range(1, 3) as $ignored) {
            $this->get('/sheq/pass/'.$this->unmatchedToken('three').'?site='.$this->siteId)->assertOk();
        }

        $props = $this->actingAs($this->user)->get('/sheq/pass-scans')
            ->assertOk()->viewData('page')['props'];

        $grouped = collect($props['unknownCodes'])
            ->firstWhere('token_ref', substr(hash('sha256', $this->unmatchedToken('three')), 0, 8));

        $this->assertNotNull($grouped);
        $this->assertSame(3, $grouped['attempts']);
        $this->assertSame(3, $props['counts']['unknown_pass']);
    }

    // ── COM-4 ─────────────────────────────────────────────────────────────────────────────────

    public function test_an_expiry_that_nobody_wrote_still_changes_the_record(): void
    {
        /*
         * COM-4's own example. Compliance recalculated whenever somebody DID something and never
         * when a date simply went past — and an expiry writes no row. So the stored status went
         * on reading COMPLIANT until something unrelated touched that contractor.
         *
         * The gate was right the whole time, which is what made it invisible: it evaluates live.
         * Everything else reads the stored column.
         */
        DB::table('sheq_settings')->where('company_id', $this->companyId)
            ->update(['contractor_required_documents' => json_encode(['PUBLIC_LIABILITY'])]);

        DB::table('sheq_contractor_documents')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'contractor_id' => $this->contractor['id'], 'document_type' => 'PUBLIC_LIABILITY',
            'title' => 'Public liability policy', 'status' => 'ACCEPTED',
            'is_mandatory' => true, 'version' => 1,
            'expires_on' => Carbon::today()->addDay()->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        app(ContractorService::class)->refreshAccessStatus($this->companyId,
            $this->contractor['uuid'], [], $this->user->id);

        $this->assertNotSame('BLOCKED', DB::table('sheq_contractors')
            ->where('id', $this->contractor['id'])->value('access_status'));

        // Time passes. Nobody writes anything.
        DB::table('sheq_contractor_documents')
            ->where('contractor_id', $this->contractor['id'])
            ->update(['expires_on' => Carbon::today()->subDay()->toDateString()]);

        $stale = DB::table('sheq_contractors')
            ->where('id', $this->contractor['id'])->value('access_status');

        $this->assertNotSame('BLOCKED', $stale,
            'The premise of this test is wrong: something already corrected the record.');

        $this->artisan('sheq:compliance:recalculate', ['--company' => $this->companyId])
            ->assertSuccessful();

        $this->assertSame('BLOCKED', DB::table('sheq_contractors')
            ->where('id', $this->contractor['id'])->value('access_status'),
            'A lapsed mandatory document left the register saying the contractor was compliant.');
    }

    public function test_one_contractors_bad_data_does_not_stop_the_sweep(): void
    {
        /*
         * A nightly job that dies on the third of four hundred leaves the rest of the estate
         * reading last month's verdict, with nothing but a log line to say so.
         */
        $other = app(ContractorService::class)->register($this->companyId,
            ['name' => 'Second Contractor', 'trade' => 'PLUMBING'], $this->user->id);

        $this->artisan('sheq:compliance:recalculate', ['--company' => $this->companyId])
            ->assertSuccessful();

        $this->assertNotNull(DB::table('sheq_contractors')
            ->where('id', $other['id'])->value('access_status_computed_at'),
            'The sweep did not reach every contractor.');
    }

    public function test_the_recalculation_runs_per_tenant(): void
    {
        // A tenant-scoped read from a command returns NOTHING and reports success, so a sweep
        // that forgets to fan out looks like a sweep that found nothing wrong.
        $this->assertContains('RunsPerTenant', array_map(
            fn (string $trait) => class_basename($trait),
            class_uses(\App\Modules\Operations\Sheq\Console\RecalculateComplianceCommand::class)));
    }

    // ── CON-24 ────────────────────────────────────────────────────────────────────────────────

    public function test_a_replacement_creates_a_version_and_retires_the_one_before_it(): void
    {
        $contractors = app(ContractorService::class);

        $first = $contractors->attachDocument($this->companyId, $this->contractor['uuid'], [
            'document_type' => 'COIDA_LETTER', 'title' => 'COID letter 2025',
            'expires_on' => Carbon::today()->subWeek()->toDateString(),
        ], $this->user->id);

        $second = $contractors->attachDocument($this->companyId, $this->contractor['uuid'], [
            'document_type' => 'COIDA_LETTER', 'title' => 'COID letter 2026',
            'expires_on' => Carbon::today()->addYear()->toDateString(),
        ], $this->user->id);

        $rows = DB::table('sheq_contractor_documents')
            ->whereIn('id', [$first, $second])->orderBy('id')
            ->get(['version', 'superseded_at', 'supersede_reason', 'replaces_document_id']);

        // A new version, not an overwrite.
        $this->assertSame(1, (int) $rows[0]->version);
        $this->assertSame(2, (int) $rows[1]->version);
        $this->assertSame($first, (int) $rows[1]->replaces_document_id);

        /*
         * And the one it replaced stops being in force at that moment. Without this both stay
         * live, the compliance file has to choose between them, and it chose the furthest expiry
         * — so a date typed as 2030 beat the correct certificate filed afterwards.
         */
        $this->assertNotNull($rows[0]->superseded_at);
        $this->assertStringContainsString('version 2', (string) $rows[0]->supersede_reason);
        $this->assertNull($rows[1]->superseded_at);
    }

    public function test_a_replacement_awaiting_review_does_not_retire_the_live_certificate(): void
    {
        /*
         * A renewal filed for review has not replaced anything yet. Superseding the live
         * certificate the moment a contractor uploads its successor would leave them with no
         * valid document at all for however long the review takes — a gate closing on somebody
         * for being early.
         */
        $contractors = app(ContractorService::class);

        $live = $contractors->attachDocument($this->companyId, $this->contractor['uuid'], [
            'document_type' => 'PUBLIC_LIABILITY', 'title' => 'Policy',
            'expires_on' => Carbon::today()->addMonth()->toDateString(),
        ], $this->user->id);

        $contractors->attachDocument($this->companyId, $this->contractor['uuid'], [
            'document_type' => 'PUBLIC_LIABILITY', 'title' => 'Renewal',
            'status' => 'SUPPLIED',
            'expires_on' => Carbon::today()->addYear()->toDateString(),
        ], $this->user->id);

        $this->assertNull(DB::table('sheq_contractor_documents')
            ->where('id', $live)->value('superseded_at'),
            'A certificate was retired by a replacement nobody had accepted yet.');
    }

    public function test_the_version_in_force_at_a_past_moment_can_be_recovered(): void
    {
        /*
         * CON-24's third requirement, and the one nothing could answer. A safety file exported in
         * March, an access decision taken in March, an auditor asking what was on file in March —
         * all of them could only ever be shown today's document.
         */
        $contractors = app(ContractorService::class);

        $old = $contractors->attachDocument($this->companyId, $this->contractor['uuid'], [
            'document_type' => 'LETTER_OF_GOOD_STANDING', 'title' => 'Old standing letter',
            'expires_on' => Carbon::today()->addMonths(2)->toDateString(),
        ], $this->user->id);

        // Backdated so there is a past to ask about.
        DB::table('sheq_contractor_documents')->where('id', $old)
            ->update(['created_at' => Carbon::today()->subMonths(6)]);

        $replacedAt = Carbon::today()->subMonth();

        $new = $contractors->attachDocument($this->companyId, $this->contractor['uuid'], [
            'document_type' => 'LETTER_OF_GOOD_STANDING', 'title' => 'New standing letter',
            'expires_on' => Carbon::today()->addYear()->toDateString(),
        ], $this->user->id);

        DB::table('sheq_contractor_documents')->where('id', $old)
            ->update(['superseded_at' => $replacedAt]);
        DB::table('sheq_contractor_documents')->where('id', $new)
            ->update(['created_at' => $replacedAt]);

        $threeMonthsAgo = $contractors->documentsAsAt($this->companyId,
            $this->contractor['id'], Carbon::today()->subMonths(3));

        $titles = array_column($threeMonthsAgo, 'title');

        $this->assertContains('Old standing letter', $titles);
        $this->assertNotContains('New standing letter', $titles,
            'A document was shown as in force before it existed.');

        $now = array_column($contractors->documentsAsAt($this->companyId,
            $this->contractor['id'], Carbon::now()), 'title');

        $this->assertContains('New standing letter', $now);
        $this->assertNotContains('Old standing letter', $now);
    }

    public function test_expiry_is_judged_as_at_the_moment_asked_about(): void
    {
        // A certificate that expired last week was perfectly valid in March, and a history that
        // marks it expired makes the past look worse than it was.
        $id = app(ContractorService::class)->attachDocument($this->companyId,
            $this->contractor['uuid'], [
                'document_type' => 'COIDA_LETTER', 'title' => 'Lapsed letter',
                'expires_on' => Carbon::today()->subWeek()->toDateString(),
            ], $this->user->id);

        DB::table('sheq_contractor_documents')->where('id', $id)
            ->update(['created_at' => Carbon::today()->subYear()]);

        $then = collect(app(ContractorService::class)->documentsAsAt($this->companyId,
            $this->contractor['id'], Carbon::today()->subMonths(6)))
            ->firstWhere('title', 'Lapsed letter');

        $now = collect(app(ContractorService::class)->documentsAsAt($this->companyId,
            $this->contractor['id'], Carbon::now()))
            ->firstWhere('title', 'Lapsed letter');

        $this->assertFalse($then['expired_then']);
        $this->assertTrue($now['expired_then']);
    }

    public function test_the_version_chain_is_readable_with_who_retired_what_and_why(): void
    {
        $contractors = app(ContractorService::class);

        $contractors->attachDocument($this->companyId, $this->contractor['uuid'], [
            'document_type' => 'COIDA_LETTER', 'title' => 'First',
            'expires_on' => Carbon::today()->addMonth()->toDateString(),
        ], $this->user->id);

        $contractors->attachDocument($this->companyId, $this->contractor['uuid'], [
            'document_type' => 'COIDA_LETTER', 'title' => 'Second',
            'expires_on' => Carbon::today()->addYear()->toDateString(),
        ], $this->user->id);

        $chain = $contractors->documentVersions($this->companyId, $this->contractor['id']);

        $this->assertArrayHasKey('COIDA_LETTER', $chain);
        $this->assertCount(2, $chain['COIDA_LETTER']);

        // Newest version first, and it is the one in force.
        $this->assertSame(2, (int) $chain['COIDA_LETTER'][0]['version']);
        $this->assertTrue($chain['COIDA_LETTER'][0]['in_force']);
        $this->assertFalse($chain['COIDA_LETTER'][1]['in_force']);

        // Named, so a version set aside by somebody is attributable.
        $this->assertNotNull($chain['COIDA_LETTER'][1]['superseded_by_name']);

        // And never the storage path: a screen showing it invites somebody to try reaching it.
        $this->assertNull($chain['COIDA_LETTER'][0]['file_path']);
    }

    public function test_the_versions_page_answers_as_at_a_date(): void
    {
        app(ContractorService::class)->attachDocument($this->companyId,
            $this->contractor['uuid'], [
                'document_type' => 'COIDA_LETTER', 'title' => 'Current letter',
                'expires_on' => Carbon::today()->addYear()->toDateString(),
            ], $this->user->id);

        $props = $this->actingAs($this->user)
            ->get('/sheq/document-versions?contractor='.$this->contractor['uuid'])
            ->assertOk()->viewData('page')['props'];

        $this->assertArrayHasKey('COIDA_LETTER', $props['versions']);
        $this->assertNotEmpty($props['inForceThen']);

        // A date before anything was filed shows nothing rather than today's documents.
        $empty = $this->actingAs($this->user)
            ->get('/sheq/document-versions?contractor='.$this->contractor['uuid']
                .'&as_at='.Carbon::today()->subYears(3)->toDateString())
            ->assertOk()->viewData('page')['props'];

        $this->assertSame([], $empty['inForceThen']);
    }

    // ── The screens ───────────────────────────────────────────────────────────────────────────

    public function test_the_history_page_shows_the_story_and_the_explanation_together(): void
    {
        $props = $this->actingAs($this->user)
            ->get('/sheq/contractor-history?contractor='.$this->contractor['uuid'])
            ->assertOk()->viewData('page')['props'];

        $this->assertSame($this->contractor['uuid'], $props['selected']['uuid']);
        $this->assertNotEmpty($props['history']['events']);
        // Read together on purpose: one says what the answer is, the other what made it that.
        $this->assertNotNull($props['why']);
        $this->assertCount(count(ContractorHistoryService::SOURCES), $props['kinds']);
    }

    public function test_the_history_page_offers_no_way_to_change_anything(): void
    {
        // A history a screen can write to is not a history.
        $routes = collect(app('router')->getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'sheq/contractor-history'))
            ->flatMap(fn ($route) => $route->methods())
            ->unique()->values()->all();

        $this->assertSame([], array_diff($routes, ['GET', 'HEAD']),
            'The contractor history is writable.');
    }

    /**
     * A code shaped like one of ours that belongs to nobody.
     *
     * The public route constrains the token to 32-64 alphanumerics, so a short or punctuated
     * string never reaches the controller at all — it 404s at the router, and the first version
     * of these tests was asserting against a redirect it had caused itself.
     */
    private function unmatchedToken(string $seed): string
    {
        return substr(str_repeat(md5($seed), 2), 0, 40);
    }

    private function userWith(array $permissions): User
    {
        $userId = DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(), 'email' => 'hist-'.Str::random(8).'@example.test',
            'password' => bcrypt('secret'), 'company_id' => $this->companyId, 'status' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $moduleId = DB::table('security_modules')->where('code_name', 'sheq')->value('id');

        if ($moduleId === null) {
            $this->markTestSkipped('SHEQ module is not registered.');
        }

        DB::table('security_module_access')->insert([
            'module_id' => $moduleId, 'user_id' => $userId, 'access_level' => 4,
            'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('security_modules')->where('id', $moduleId)->update(['active' => 1]);
        Cache::forget('module_active_sheq');

        $user = User::withoutGlobalScopes()->findOrFail($userId);

        foreach ($permissions as $permission) {
            $user->givePermissionTo($permission);
        }

        $user->forgetCachedPermissions();

        return $user;
    }
}
