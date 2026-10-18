<?php

namespace Tests\Feature\Sheq;

use App\Modules\Operations\Sheq\Regulatory\PackLoader;
use App\Modules\Operations\Sheq\Regulatory\PackManifest;
use App\Modules\Operations\Sheq\Services\LegalRegisterService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Legal register (§5.10, SHEQ-LEG-1..4).
 *
 * The requirement these tests defend is SHEQ-LEG-3: status is computed, never declared. So most of
 * them are about a line *refusing* to say COMPLIANT — with no evidence, with lapsed evidence, with
 * only some of the evidence the clause demands, and while a pack change sits unreviewed.
 */
class LegalRegisterTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

    private int $userId;

    private PackManifest $pack;

    protected function setUp(): void
    {
        parent::setUp();

        $companyId = DB::table('companies')->min('id');
        if ($companyId === null) {
            $this->markTestSkipped('No company in the test database.');
        }
        $this->companyId = (int) $companyId;

        $this->userId = DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(), 'email' => 'leg-'.Str::random(8).'@example.test',
            'password' => bcrypt('secret'), 'company_id' => $this->companyId, 'status' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->pack = app(PackLoader::class)->load(resource_path('regulatory-packs/za-ohsa'));
    }

    private function service(): LegalRegisterService
    {
        return app(LegalRegisterService::class);
    }

    /** A pack identical to the shipped one except for the edits given. */
    private function packWith(string $version, callable $mutate): PackManifest
    {
        $manifest = $this->pack->manifest;
        $manifest['legal_register'] = $mutate($manifest['legal_register']);

        return new PackManifest($this->pack->packId, $version, $manifest);
    }

    private function seedRegister(): array
    {
        return $this->service()->seedFromPack($this->companyId, $this->pack);
    }

    private function lineFor(string $clause): array
    {
        $lines = $this->service()->register($this->companyId, []);
        $match = array_values(array_filter($lines, fn ($l) => $l['clause'] === $clause));

        $this->assertNotEmpty($match, "No register line for clause {$clause}.");

        return $match[0];
    }

    // ── Seeding (SHEQ-LEG-1) ────────────────────────────────────────────────────────────────────

    public function test_the_register_is_seeded_from_the_active_pack(): void
    {
        $counts = $this->seedRegister();

        $this->assertSame(count($this->pack->legalRegister()), $counts['created']);

        $line = $this->lineFor('s16(2)');
        $this->assertSame('Occupational Health and Safety Act 85 of 1993', $line['instrument']);
        $this->assertStringContainsString('in writing', $line['requirement']);
        $this->assertSame('CEO', $line['responsible_role']);
        $this->assertSame(['APPOINTMENT'], $line['evidence_types']);
    }

    public function test_seeding_twice_changes_nothing(): void
    {
        $this->seedRegister();
        $second = $this->service()->seedFromPack($this->companyId, $this->pack);

        $this->assertSame(0, $second['created']);
        $this->assertSame(0, $second['amended']);
        $this->assertSame(count($this->pack->legalRegister()), $second['unchanged']);
    }

    public function test_a_freshly_seeded_line_is_not_assessed_rather_than_compliant(): void
    {
        // The default that matters. An empty register that reported compliance would be worse than
        // no register at all.
        $this->seedRegister();

        $this->assertSame('NOT_ASSESSED', $this->lineFor('s8')['status']);
    }

    public function test_the_pack_evidence_vocabulary_is_translated_to_linkable_records(): void
    {
        // A pack says INSPECTION, meaning a maintained regime — which here is a schedule.
        $this->seedRegister();

        $this->assertContains('INSPECTION_SCHEDULE', $this->lineFor('ER 9')['evidence_types']);
    }

    // ── Computed status (SHEQ-LEG-2 / SHEQ-LEG-3) ───────────────────────────────────────────────

    public function test_an_inspection_schedule_with_nothing_overdue_makes_the_line_compliant(): void
    {
        // The requirement's own worked example: fire equipment serviced annually.
        $this->seedRegister();
        $line = $this->lineFor('ER 9');

        $scheduleId = $this->schedule(nextDue: Carbon::today()->addMonths(6),
            lastCompleted: Carbon::today()->subMonths(6));

        $result = $this->service()->linkEvidence($this->companyId, $line['uuid'], [
            'evidence_type' => 'INSPECTION_SCHEDULE', 'evidence_id' => $scheduleId,
        ], $this->userId);

        $this->assertSame('COMPLIANT', $result['status']);
    }

    public function test_an_overdue_inspection_makes_the_line_non_compliant_without_anyone_saying_so(): void
    {
        $this->seedRegister();
        $line = $this->lineFor('ER 9');

        $scheduleId = $this->schedule(nextDue: Carbon::today()->subDays(30),
            lastCompleted: Carbon::today()->subYear());

        $result = $this->service()->linkEvidence($this->companyId, $line['uuid'], [
            'evidence_type' => 'INSPECTION_SCHEDULE', 'evidence_id' => $scheduleId,
        ], $this->userId);

        $this->assertSame('NON_COMPLIANT', $result['status']);
        $this->assertStringContainsString('overdue', $result['evidence'][0]['detail']);
    }

    public function test_a_schedule_that_was_switched_off_is_not_compliance(): void
    {
        $this->seedRegister();
        $line = $this->lineFor('ER 9');

        $scheduleId = $this->schedule(nextDue: Carbon::today()->addYear(), isActive: false);

        $result = $this->service()->linkEvidence($this->companyId, $line['uuid'], [
            'evidence_type' => 'INSPECTION_SCHEDULE', 'evidence_id' => $scheduleId,
        ], $this->userId);

        $this->assertSame('NON_COMPLIANT', $result['status']);
    }

    public function test_holding_only_some_of_the_evidence_a_clause_demands_is_partial(): void
    {
        /**
         * GSR 3 wants the first aid box inspected, a first aider trained *and* appointed. Producing
         * the inspection alone is the classic way to fail an audit while believing you passed.
         */
        $this->seedRegister();
        $line = $this->lineFor('GSR 3');
        $this->assertCount(3, $line['evidence_types']);

        $result = $this->service()->linkEvidence($this->companyId, $line['uuid'], [
            'evidence_type' => 'INSPECTION_SCHEDULE',
            'evidence_id' => $this->schedule(nextDue: Carbon::today()->addMonth(),
                lastCompleted: Carbon::today()->subDay()),
        ], $this->userId);

        $this->assertSame('PARTIAL', $result['status']);
        $this->assertEqualsCanonicalizing(['COMPETENCY', 'APPOINTMENT'],
            $result['status_detail']['missing_evidence_types']);
    }

    public function test_an_appointment_nobody_signed_is_not_an_appointment(): void
    {
        // Section 16(2) is specifically about the assignment being in writing.
        $this->seedRegister();
        $line = $this->lineFor('s16(2)');

        $recordId = $this->appointment(accepted: false);

        $result = $this->service()->linkEvidence($this->companyId, $line['uuid'], [
            'evidence_type' => 'APPOINTMENT', 'evidence_id' => $recordId,
        ], $this->userId);

        $this->assertSame('PARTIAL', $result['status']);
        $this->assertStringContainsString('not been accepted in writing',
            $result['evidence'][0]['detail']);
    }

    public function test_a_revoked_appointment_makes_the_line_non_compliant(): void
    {
        $this->seedRegister();
        $line = $this->lineFor('s16(2)');

        $recordId = $this->appointment(revokedAt: Carbon::now()->subDay());

        $result = $this->service()->linkEvidence($this->companyId, $line['uuid'], [
            'evidence_type' => 'APPOINTMENT', 'evidence_id' => $recordId,
        ], $this->userId);

        $this->assertSame('NON_COMPLIANT', $result['status']);
    }

    public function test_a_signed_unexpired_appointment_satisfies_the_clause(): void
    {
        $this->seedRegister();
        $line = $this->lineFor('s16(2)');

        $result = $this->service()->linkEvidence($this->companyId, $line['uuid'], [
            'evidence_type' => 'APPOINTMENT', 'evidence_id' => $this->appointment(),
        ], $this->userId);

        $this->assertSame('COMPLIANT', $result['status']);
    }

    public function test_a_status_goes_stale_on_its_own_when_the_evidence_lapses(): void
    {
        /**
         * The whole argument for computing rather than declaring: nobody touches the register, and
         * it still tells the truth the day after the schedule slips.
         */
        $this->seedRegister();
        $line = $this->lineFor('ER 9');
        $scheduleId = $this->schedule(nextDue: Carbon::today()->addDay(),
            lastCompleted: Carbon::today()->subMonth());

        $result = $this->service()->linkEvidence($this->companyId, $line['uuid'], [
            'evidence_type' => 'INSPECTION_SCHEDULE', 'evidence_id' => $scheduleId,
        ], $this->userId);
        $this->assertSame('COMPLIANT', $result['status']);

        // Time passes; the inspection is not done.
        DB::table('sheq_inspection_schedules')->where('id', $scheduleId)
            ->update(['next_due' => Carbon::today()->subDays(2)]);

        $this->assertSame('NON_COMPLIANT',
            $this->service()->compute($this->companyId, $line['uuid'])['status']);
    }

    public function test_unlinking_the_last_evidence_returns_the_line_to_not_assessed(): void
    {
        $this->seedRegister();
        $line = $this->lineFor('ER 9');
        $scheduleId = $this->schedule(nextDue: Carbon::today()->addYear(),
            lastCompleted: Carbon::today());

        $this->service()->linkEvidence($this->companyId, $line['uuid'], [
            'evidence_type' => 'INSPECTION_SCHEDULE', 'evidence_id' => $scheduleId,
        ], $this->userId);

        $result = $this->service()->unlinkEvidence($this->companyId, $line['uuid'],
            'INSPECTION_SCHEDULE', $scheduleId);

        $this->assertSame('NOT_ASSESSED', $result['status']);
    }

    public function test_evidence_from_another_tenant_cannot_be_linked(): void
    {
        $otherCompany = DB::table('companies')->insertGetId([
            'uuid' => (string) Str::uuid(), 'name' => 'Other Tenant',
            'slug' => 'other-'.Str::lower(Str::random(8)),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->seedRegister();
        $line = $this->lineFor('ER 9');
        $foreign = $this->schedule(nextDue: Carbon::today()->addYear(), companyId: $otherCompany);

        $this->expectExceptionMessageMatches('/does not exist in this company/');
        $this->service()->linkEvidence($this->companyId, $line['uuid'], [
            'evidence_type' => 'INSPECTION_SCHEDULE', 'evidence_id' => $foreign,
        ], $this->userId);
    }

    // ── Scoping ─────────────────────────────────────────────────────────────────────────────────

    public function test_a_clause_can_be_ruled_out_of_scope_but_only_with_a_reason(): void
    {
        $this->seedRegister();
        $line = $this->lineFor('CR 5');

        $result = $this->service()->markNotApplicable($this->companyId, $line['uuid'],
            'This employer undertakes no construction work.');

        $this->assertSame('NOT_APPLICABLE', $result['status']);
        $this->assertFalse($result['is_applicable']);
    }

    public function test_ruling_a_clause_out_of_scope_without_a_reason_is_refused(): void
    {
        $this->seedRegister();
        $line = $this->lineFor('CR 5');

        $this->expectExceptionMessageMatches('/needs a reason/');
        $this->service()->markNotApplicable($this->companyId, $line['uuid'], '   ');
    }

    public function test_out_of_scope_clauses_are_excluded_from_the_compliance_percentage(): void
    {
        // A company doing no construction work must not be scored down for CR 5.
        $this->seedRegister();
        $this->service()->markNotApplicable($this->companyId, $this->lineFor('CR 5')['uuid'],
            'No construction work.');

        $summary = $this->service()->summary($this->companyId);
        $assessable = $summary['lines'] - $summary['by_status']['NOT_APPLICABLE'];

        $this->assertSame(1, $summary['by_status']['NOT_APPLICABLE']);
        $this->assertSame(count($this->pack->legalRegister()) - 1, $assessable);
    }

    // ── Pack updates (SHEQ-LEG-4) ───────────────────────────────────────────────────────────────

    public function test_an_amended_clause_flags_the_line_for_review_with_a_diff(): void
    {
        $this->seedRegister();

        $amended = $this->packWith('2.0.0', function (array $register) {
            foreach ($register as $index => $clause) {
                if ($clause['clause'] === 's17') {
                    $register[$index]['requirement'] =
                        'Designate health and safety representatives where 10 or more employees are employed.';
                }
            }

            return $register;
        });

        $counts = $this->service()->seedFromPack($this->companyId, $amended);
        $this->assertSame(1, $counts['amended']);

        $line = $this->lineFor('s17');
        $this->assertSame('REVIEW_REQUIRED', $line['status']);
        $this->assertCount(1, $line['pending_changes']);

        $diff = $line['pending_changes'][0]['diff'];
        $this->assertStringContainsString('20 or more', $diff['requirement']['from']);
        $this->assertStringContainsString('10 or more', $diff['requirement']['to']);
        $this->assertSame('2.0.0', $line['pending_changes'][0]['to_version']);
    }

    public function test_an_unreviewed_clause_change_outranks_the_evidence_beneath_it(): void
    {
        /**
         * The evidence was gathered against wording that no longer stands. Reporting COMPLIANT here
         * would be asserting something nobody has checked.
         */
        $this->seedRegister();
        $line = $this->lineFor('ER 9');

        $this->service()->linkEvidence($this->companyId, $line['uuid'], [
            'evidence_type' => 'INSPECTION_SCHEDULE',
            'evidence_id' => $this->schedule(nextDue: Carbon::today()->addYear(),
                lastCompleted: Carbon::today()),
        ], $this->userId);
        $this->assertSame('COMPLIANT', $this->lineFor('ER 9')['status']);

        $amended = $this->packWith('2.0.0', function (array $register) {
            foreach ($register as $index => $clause) {
                if ($clause['clause'] === 'ER 9') {
                    $register[$index]['review_frequency'] = 'P6M';
                }
            }

            return $register;
        });
        $this->service()->seedFromPack($this->companyId, $amended);

        $this->assertSame('REVIEW_REQUIRED',
            $this->service()->compute($this->companyId, $line['uuid'])['status']);
    }

    public function test_acknowledging_the_change_releases_the_line_back_to_computed_status(): void
    {
        $this->seedRegister();
        $line = $this->lineFor('ER 9');

        $this->service()->linkEvidence($this->companyId, $line['uuid'], [
            'evidence_type' => 'INSPECTION_SCHEDULE',
            'evidence_id' => $this->schedule(nextDue: Carbon::today()->addYear(),
                lastCompleted: Carbon::today()),
        ], $this->userId);

        $amended = $this->packWith('2.0.0', function (array $register) {
            foreach ($register as $index => $clause) {
                if ($clause['clause'] === 'ER 9') {
                    $register[$index]['review_frequency'] = 'P6M';
                }
            }

            return $register;
        });
        $this->service()->seedFromPack($this->companyId, $amended);

        $result = $this->service()->acknowledgeChange($this->companyId, $line['uuid'],
            'Reviewed; the existing schedule already meets the shorter interval.', $this->userId);

        $this->assertSame('COMPLIANT', $result['status']);
        $this->assertSame([], $result['pending_changes']);
    }

    public function test_a_clause_the_new_pack_drops_is_flagged_rather_than_deleted(): void
    {
        // A repealed clause may still have evidence hanging off it, and the history matters.
        $this->seedRegister();

        $reduced = $this->packWith('2.0.0',
            fn (array $register) => array_values(array_filter($register,
                fn ($clause) => $clause['clause'] !== 'CR 5')));

        $counts = $this->service()->seedFromPack($this->companyId, $reduced);

        $this->assertSame(1, $counts['removed']);
        $line = $this->lineFor('CR 5');
        $this->assertSame('REVIEW_REQUIRED', $line['status']);
        $this->assertSame('REMOVED', $line['pending_changes'][0]['change_type']);
    }

    public function test_a_pack_update_does_not_destroy_the_tenants_own_work(): void
    {
        // Evidence links and local notes are the tenant's; the clause text is the pack's.
        $this->seedRegister();
        $line = $this->lineFor('ER 9');
        $scheduleId = $this->schedule(nextDue: Carbon::today()->addYear(),
            lastCompleted: Carbon::today());

        $this->service()->linkEvidence($this->companyId, $line['uuid'], [
            'evidence_type' => 'INSPECTION_SCHEDULE', 'evidence_id' => $scheduleId,
            'evidence_label' => 'Fire equipment servicing',
        ], $this->userId);

        $amended = $this->packWith('2.0.0', function (array $register) {
            foreach ($register as $index => $clause) {
                if ($clause['clause'] === 'ER 9') {
                    $register[$index]['requirement'] = 'Provide, maintain and test means of escape.';
                }
            }

            return $register;
        });
        $this->service()->seedFromPack($this->companyId, $amended);

        $after = $this->lineFor('ER 9');
        $this->assertCount(1, $after['evidence']);
        $this->assertSame('Fire equipment servicing', $after['evidence'][0]['label']);
        $this->assertSame('Provide, maintain and test means of escape.', $after['requirement']);
    }

    // ── Whole-register operations ───────────────────────────────────────────────────────────────

    public function test_recomputing_the_register_tallies_every_line(): void
    {
        $this->seedRegister();

        $tally = $this->service()->recomputeAll($this->companyId);

        $this->assertSame(count($this->pack->legalRegister()), array_sum($tally));
        $this->assertSame(count($this->pack->legalRegister()), $tally['NOT_ASSESSED'] ?? 0);
    }

    public function test_the_summary_reports_zero_compliance_for_an_unevidenced_register(): void
    {
        $this->seedRegister();

        $summary = $this->service()->summary($this->companyId);

        $this->assertSame(0.0, $summary['compliance_percent']);
        $this->assertSame(count($this->pack->legalRegister()), $summary['lines']);
    }

    // ── Fixtures ────────────────────────────────────────────────────────────────────────────────

    private function template(?int $companyId = null): int
    {
        return DB::table('sheq_inspection_templates')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $companyId ?? $this->companyId,
            'code' => 'T'.Str::random(6), 'title' => 'Fire equipment service',
            'schema' => json_encode(['items' => []]), 'status' => 'PUBLISHED',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function schedule(Carbon $nextDue, ?Carbon $lastCompleted = null, bool $isActive = true,
        ?int $companyId = null): int
    {
        return DB::table('sheq_inspection_schedules')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $companyId ?? $this->companyId,
            'template_id' => $this->template($companyId),
            'subject_type' => 'SITE', 'trigger_type' => 'CALENDAR', 'frequency_days' => 365,
            'next_due' => $nextDue->toDateString(),
            'last_completed' => $lastCompleted?->toDateString(),
            'is_active' => $isActive, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function appointment(bool $accepted = true, ?Carbon $revokedAt = null): int
    {
        $competencyId = DB::table('sheq_competencies')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'code' => 'APP'.Str::random(5), 'title' => 'Section 16(2) assignee',
            'competency_type' => 'APPOINTMENT', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return DB::table('sheq_competency_records')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'competency_id' => $competencyId, 'hr_person_id' => 4001,
            'status' => 'VALID', 'achieved_at' => Carbon::today()->subMonth(),
            'expires_at' => Carbon::today()->addYear(),
            'appointment_scope' => 'Whole undertaking',
            'accepted_at' => $accepted ? Carbon::now()->subMonth() : null,
            'revoked_at' => $revokedAt,
            'source' => 'SHEQ', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
