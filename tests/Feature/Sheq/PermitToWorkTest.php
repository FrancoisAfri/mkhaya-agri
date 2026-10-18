<?php

namespace Tests\Feature\Sheq;

use App\Modules\Operations\Sheq\Contracts\CompetencyLedger;
use App\Modules\Operations\Sheq\Services\PermitService;
use App\Modules\Operations\Sheq\Services\RiskAssessmentService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Permit to Work (§5.7) and competency gating (SHEQ-COMP-9).
 *
 * Every assertion here is a refusal or an override, because that is what a permit system is: the
 * set of conditions under which dangerous work may proceed, and the record of who accepted the risk
 * when one of them was not met.
 *
 * The competency ledger is faked so the gating rules are tested without an LMS fixture chain —
 * the seam from SHEQ-ADR-002 earning its keep again.
 */
class PermitToWorkTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

    private int $userId;

    private int $siteId;

    private int $hrPersonId = 4242;

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

        $this->userId = DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(), 'email' => 'ptw-'.Str::random(8).'@example.test',
            'password' => bcrypt('secret'), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->siteId = DB::table('sheq_sites')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'code' => 'P'.Str::random(5), 'name' => 'Refinery', 'site_type' => 'PLANT',
            'timezone' => 'Africa/Johannesburg', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** A ledger whose answers the test controls. */
    private function ledger(array $byCompetency = [], bool $available = true): CompetencyLedger
    {
        return new class($byCompetency, $available) implements CompetencyLedger
        {
            public function __construct(private array $byCompetency, private bool $available) {}

            public function statusFor(int $hrPersonId): array
            {
                return array_values($this->byCompetency);
            }

            public function statusForCompetency(int $hrPersonId, string $competencyCode): ?array
            {
                return $this->byCompetency[$competencyCode] ?? null;
            }

            public function isAvailable(): bool
            {
                return $this->available;
            }
        };
    }

    /**
     * Resolved from the container, with only the competency ledger swapped.
     *
     * Hand-constructing the whole service means every new collaborator breaks this test — and worse,
     * a collaborator the container cannot resolve would go unnoticed, which is the defect
     * SheqWiringTest exists for. Binding the one fake and letting the container build the rest keeps
     * both properties.
     */
    private function permits(?CompetencyLedger $ledger = null): PermitService
    {
        app()->instance(CompetencyLedger::class, $ledger ?? $this->ledger());

        return app()->makeWith(PermitService::class, []);
    }

    /** An approved risk assessment, since without one nothing can be issued (SHEQ-PTW-1). */
    private function approvedAssessment(): int
    {
        $service = app(RiskAssessmentService::class);

        $assessment = $service->create($this->companyId, [
            'title' => 'Hot work in process area', 'site_id' => $this->siteId,
        ], $this->userId);

        $service->addLine($this->companyId, $assessment['uuid'], [
            'activity' => 'Welding', 'hazard' => 'Ignition of flammable vapour',
            'inherent_likelihood' => 4, 'inherent_severity' => 5,
            'residual_likelihood' => 2, 'residual_severity' => 3,
        ], [['hierarchy_level' => 'ENGINEERING', 'description' => 'Purge and isolate the line']]);

        $service->addParticipant($this->companyId, $assessment['uuid'], ['name' => 'Crew']);
        $service->approve($this->companyId, $assessment['uuid'], $this->userId, ['SITE_MANAGER']);

        return $assessment['id'];
    }

    private function requestPermit(array $overrides = [], ?PermitService $service = null): array
    {
        return ($service ?? $this->permits())->request($this->companyId, array_merge([
            'permit_type' => 'HOT_WORK',
            'site_id' => $this->siteId,
            'work_description' => 'Weld a support bracket on line 4.',
            'location_text' => 'Process area, bay 2',
            'valid_from' => Carbon::now()->toDateTimeString(),
            'valid_to' => Carbon::now()->addHours(8)->toDateTimeString(),
            'risk_assessment_id' => $this->approvedAssessment(),
        ], $overrides), [
            ['name' => 'T. Mokoena', 'hr_person_id' => $this->hrPersonId, 'worker_role' => 'WORKER'],
        ], $this->userId);
    }

    private function approveAll(string $uuid, PermitService $service): void
    {
        $permit = DB::table('sheq_permits')->where('uuid', $uuid)->first();
        foreach (DB::table('sheq_permit_approvals')->where('permit_id', $permit->id)->get() as $approval) {
            $service->approve($this->companyId, $uuid, $approval->id, $this->userId);
        }
    }

    // ── SHEQ-PTW-1: no risk assessment, no permit ───────────────────────────────────────────────

    public function test_a_permit_without_a_risk_assessment_cannot_be_issued(): void
    {
        $service = $this->permits();
        $permit = $this->requestPermit(['risk_assessment_id' => null], $service);
        $this->approveAll($permit['uuid'], $service);

        $checks = $service->preIssueChecks($this->companyId, $permit['uuid']);
        $this->assertSame('NO_RISK_ASSESSMENT', $checks['blocking'][0]['type']);

        $this->expectExceptionMessageMatches('/cannot be issued/');
        $service->issue($this->companyId, $permit['uuid'], $this->userId);
    }

    public function test_a_permit_referencing_a_draft_assessment_is_blocked(): void
    {
        $service = $this->permits();

        $draft = app(RiskAssessmentService::class)->create($this->companyId, ['title' => 'Draft only'], $this->userId);
        $permit = $this->requestPermit(['risk_assessment_id' => $draft['id']], $service);

        $checks = $service->preIssueChecks($this->companyId, $permit['uuid']);

        $this->assertSame('RISK_ASSESSMENT_NOT_APPROVED', $checks['blocking'][0]['type']);
    }

    public function test_a_permit_with_an_approved_assessment_and_no_gaps_issues(): void
    {
        $service = $this->permits();
        $permit = $this->requestPermit([], $service);
        $this->approveAll($permit['uuid'], $service);

        $service->issue($this->companyId, $permit['uuid'], $this->userId);

        $this->assertSame('ACTIVE', DB::table('sheq_permits')->find($permit['id'])->status);
    }

    // ── Approvals (SHEQ-PTW-2) ──────────────────────────────────────────────────────────────────

    public function test_a_permit_with_outstanding_approvals_cannot_be_issued(): void
    {
        $service = $this->permits();
        $permit = $this->requestPermit([
            'approval_stages' => [
                ['stage' => 1, 'role' => 'AREA_OWNER'],
                ['stage' => 1, 'role' => 'ELECTRICAL_AUTHORITY'],   // parallel approvers
            ],
        ], $service);

        $approvals = DB::table('sheq_permit_approvals')->where('permit_id', $permit['id'])->get();
        $this->assertCount(2, $approvals);

        $service->approve($this->companyId, $permit['uuid'], $approvals[0]->id, $this->userId);

        $this->expectExceptionMessageMatches('/1 approval\(s\) are still outstanding/');
        $service->issue($this->companyId, $permit['uuid'], $this->userId);
    }

    // ── Competency gating (SHEQ-COMP-9) ─────────────────────────────────────────────────────────

    public function test_an_expired_competency_blocks_the_permit_and_names_the_worker(): void
    {
        DB::table('sheq_permit_requirements')->insert([
            'company_id' => $this->companyId, 'permit_type' => 'HOT_WORK',
            'competency_code' => 'Hot Work Authorisation', 'is_mandatory' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $service = $this->permits($this->ledger([
            'Hot Work Authorisation' => ['status' => CompetencyLedger::EXPIRED,
                'expires_at' => '2026-06-01', 'days_overdue' => 67],
        ]));

        $permit = $this->requestPermit([], $service);
        $this->approveAll($permit['uuid'], $service);

        $checks = $service->preIssueChecks($this->companyId, $permit['uuid']);
        $gap = collect($checks['blocking'])->firstWhere('type', 'COMPETENCY_GAP');

        $this->assertNotNull($gap);
        $this->assertSame('T. Mokoena', $gap['worker']);
        $this->assertStringContainsString('Hot Work Authorisation', $gap['detail']);
        $this->assertStringContainsString('2026-06-01', $gap['detail']);

        $this->expectExceptionMessageMatches('/cannot be issued/');
        $service->issue($this->companyId, $permit['uuid'], $this->userId);
    }

    public function test_a_missing_competency_record_blocks_just_as_an_expired_one_does(): void
    {
        // "We have no record" is not evidence of competence.
        DB::table('sheq_permit_requirements')->insert([
            'company_id' => $this->companyId, 'permit_type' => 'HOT_WORK',
            'competency_code' => 'Hot Work Authorisation', 'is_mandatory' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $service = $this->permits($this->ledger([]));   // ledger available, knows nothing
        $permit = $this->requestPermit([], $service);

        $gap = collect($service->preIssueChecks($this->companyId, $permit['uuid'])['blocking'])
            ->firstWhere('type', 'COMPETENCY_GAP');

        $this->assertNotNull($gap);
        $this->assertStringContainsString('no record', $gap['detail']);
    }

    public function test_an_override_with_a_reason_lets_the_permit_issue_and_is_recorded(): void
    {
        DB::table('sheq_permit_requirements')->insert([
            'company_id' => $this->companyId, 'permit_type' => 'HOT_WORK',
            'competency_code' => 'Hot Work Authorisation', 'is_mandatory' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $service = $this->permits($this->ledger([
            'Hot Work Authorisation' => ['status' => CompetencyLedger::EXPIRED,
                'expires_at' => '2026-06-01', 'days_overdue' => 67],
        ]));

        $permit = $this->requestPermit([], $service);
        $this->approveAll($permit['uuid'], $service);

        $service->overrideCompetency($this->companyId, $permit['uuid'],
            'Refresher booked for Monday; supervised by a certified welder for this task.', $this->userId);

        $service->issue($this->companyId, $permit['uuid'], $this->userId);

        $row = DB::table('sheq_permits')->find($permit['id']);
        $this->assertSame('ACTIVE', $row->status);
        $this->assertTrue((bool) $row->competency_overridden);
        $this->assertSame($this->userId, (int) $row->competency_overridden_by);

        // The acceptance of risk must be in the audit trail with its reason, not just a boolean.
        $this->assertStringContainsString('Refresher booked', (string) DB::table('sheq_audit_log')
            ->where('subject_type', 'Permit')->where('subject_id', $permit['id'])
            ->where('action', 'permit.competency_overridden')->value('reason'));
    }

    public function test_an_override_without_a_reason_is_refused(): void
    {
        $service = $this->permits();
        $permit = $this->requestPermit([], $service);

        $this->expectExceptionMessageMatches('/needs a reason/');
        $service->overrideCompetency($this->companyId, $permit['uuid'], '   ', $this->userId);
    }

    // ── Conflicts (SHEQ-PTW-5) ──────────────────────────────────────────────────────────────────

    public function test_an_overlapping_incompatible_permit_warns_the_issuer(): void
    {
        $service = $this->permits();

        $confined = $this->requestPermit(['permit_type' => 'CONFINED_SPACE'], $service);
        $this->approveAll($confined['uuid'], $service);
        $service->issue($this->companyId, $confined['uuid'], $this->userId);

        $hotWork = $this->requestPermit([], $service);   // HOT_WORK, same site, overlapping window
        $checks = $service->preIssueChecks($this->companyId, $hotWork['uuid']);

        $this->assertCount(1, $checks['warnings']);
        $this->assertSame('PERMIT_CONFLICT', $checks['warnings'][0]['type']);
        $this->assertStringContainsString($confined['reference'], $checks['warnings'][0]['detail']);

        // A warning, not a block: the issuer may know the areas are separated, and blocking
        // legitimate work teaches crews to work around the system.
        //
        // Asserted against the conflict specifically rather than against an empty list. This
        // permit has not been approved yet, so its chain is legitimately outstanding — an empty
        // `blocking` was standing in for "the conflict did not block it" and stopped being true
        // when outstanding approvals joined the gate.
        $this->assertNotContains('PERMIT_CONFLICT', array_column($checks['blocking'], 'type'));
    }

    public function test_a_non_overlapping_permit_raises_no_conflict(): void
    {
        $service = $this->permits();

        $earlier = $this->requestPermit([
            'permit_type' => 'CONFINED_SPACE',
            'valid_from' => Carbon::now()->subHours(10)->toDateTimeString(),
            'valid_to' => Carbon::now()->subHours(2)->toDateTimeString(),
        ], $service);
        $this->approveAll($earlier['uuid'], $service);

        $later = $this->requestPermit([], $service);

        $this->assertSame([], $service->preIssueChecks($this->companyId, $later['uuid'])['warnings']);
    }

    // ── Fire watch and closure (SHEQ-PTW-4) ─────────────────────────────────────────────────────

    public function test_hot_work_cannot_be_closed_during_its_fire_watch(): void
    {
        $service = $this->permits();
        $permit = $this->requestPermit([], $service);
        $this->approveAll($permit['uuid'], $service);
        $service->issue($this->companyId, $permit['uuid'], $this->userId);

        $this->expectExceptionMessageMatches('/fire watch runs until/');

        $service->close($this->companyId, $permit['uuid'], [
            'work_completed_at' => Carbon::now()->subMinutes(10)->toDateTimeString(),
            'area_clear' => true,
        ], $this->userId);
    }

    public function test_hot_work_closes_once_the_fire_watch_has_elapsed(): void
    {
        $service = $this->permits();
        $permit = $this->requestPermit([], $service);
        $this->approveAll($permit['uuid'], $service);
        $service->issue($this->companyId, $permit['uuid'], $this->userId);

        $service->close($this->companyId, $permit['uuid'], [
            'work_completed_at' => Carbon::now()->subMinutes(75)->toDateTimeString(),   // watch is 60
            'area_clear' => true, 'fire_watch_by' => 'S. Ndlovu',
        ], $this->userId);

        $row = DB::table('sheq_permits')->find($permit['id']);
        $this->assertSame('CLOSED', $row->status);
        $this->assertStringContainsString('S. Ndlovu', $row->closure_checks);
    }

    public function test_a_permit_with_a_fire_watch_needs_a_work_completion_time(): void
    {
        $service = $this->permits();
        $permit = $this->requestPermit([], $service);
        $this->approveAll($permit['uuid'], $service);
        $service->issue($this->companyId, $permit['uuid'], $this->userId);

        $this->expectExceptionMessageMatches('/when the work stopped/');
        $service->close($this->companyId, $permit['uuid'], ['area_clear' => true], $this->userId);
    }

    // ── Expiry, suspension and the live board (SHEQ-PTW-4, PTW-6) ───────────────────────────────

    public function test_a_lapsed_permit_expires_and_leaves_the_active_board(): void
    {
        $service = $this->permits();
        $permit = $this->requestPermit([], $service);
        $this->approveAll($permit['uuid'], $service);
        $service->issue($this->companyId, $permit['uuid'], $this->userId);

        $this->assertCount(1, $service->activeNow($this->companyId, $this->siteId));

        DB::table('sheq_permits')->where('id', $permit['id'])
            ->update(['valid_to' => Carbon::now()->subMinute()]);

        $this->assertSame(1, $service->expireLapsed($this->companyId));
        $this->assertSame('EXPIRED', DB::table('sheq_permits')->find($permit['id'])->status);
        $this->assertSame([], $service->activeNow($this->companyId, $this->siteId));
    }

    public function test_an_expired_permit_cannot_be_reinstated_after_suspension(): void
    {
        $service = $this->permits();
        $permit = $this->requestPermit([], $service);
        $this->approveAll($permit['uuid'], $service);
        $service->issue($this->companyId, $permit['uuid'], $this->userId);

        $service->suspend($this->companyId, $permit['uuid'], 'Gas alarm in the area.', $this->userId);
        $this->assertSame('SUSPENDED', DB::table('sheq_permits')->find($permit['id'])->status);

        DB::table('sheq_permits')->where('id', $permit['id'])
            ->update(['valid_to' => Carbon::now()->subMinute()]);

        $this->expectExceptionMessageMatches('/passed its validity window/');
        $service->reinstate($this->companyId, $permit['uuid'], $this->userId);
    }

    public function test_a_suspended_permit_within_its_window_can_be_reinstated(): void
    {
        $service = $this->permits();
        $permit = $this->requestPermit([], $service);
        $this->approveAll($permit['uuid'], $service);
        $service->issue($this->companyId, $permit['uuid'], $this->userId);

        $service->suspend($this->companyId, $permit['uuid'], 'Gas alarm.', $this->userId);
        $service->reinstate($this->companyId, $permit['uuid'], $this->userId);

        $this->assertSame('ACTIVE', DB::table('sheq_permits')->find($permit['id'])->status);
    }

    public function test_issuing_freezes_each_workers_competency_verdict(): void
    {
        // Same reasoning as the incident context snapshot: what was true at issue is what matters
        // when someone asks, months later, what the issuer knew.
        $service = $this->permits($this->ledger([
            'Hot Work Authorisation' => ['status' => CompetencyLedger::COMPLIANT, 'expires_at' => '2027-01-01'],
        ]));

        $permit = $this->requestPermit([], $service);
        $this->approveAll($permit['uuid'], $service);
        $service->issue($this->companyId, $permit['uuid'], $this->userId);

        $worker = DB::table('sheq_permit_workers')->where('permit_id', $permit['id'])->first();
        $snapshot = json_decode($worker->competency_snapshot, true);

        $this->assertNotNull($snapshot);
        $this->assertTrue($snapshot['source_available']);
        $this->assertNotEmpty($snapshot['checked_at']);
    }
}
