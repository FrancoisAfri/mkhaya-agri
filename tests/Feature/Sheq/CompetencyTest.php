<?php

namespace Tests\Feature\Sheq;

use App\Modules\Operations\Sheq\Contracts\CompetencyLedger;
use App\Modules\Operations\Sheq\Services\CompetencyService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Competency governance and legal appointments (§5.6).
 *
 * The rules defended here: precedence resolves conflicting requirements, an unaccepted appointment
 * is not in force, a revoked one is expired whatever its date says, expiry status is derived rather
 * than stored, and the matrix reports compliance per person and per competency.
 */
class CompetencyTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

    private int $userId;

    private int $hrPersonId = 9001;

    protected function setUp(): void
    {
        parent::setUp();

        $companyId = DB::table('companies')->min('id');
        if ($companyId === null) {
            $this->markTestSkipped('No company in the test database.');
        }
        $this->companyId = (int) $companyId;

        $this->userId = DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(), 'email' => 'comp-'.Str::random(8).'@example.test',
            'password' => bcrypt('secret'), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function service(): CompetencyService
    {
        return app(CompetencyService::class);
    }

    private function competency(array $overrides = []): int
    {
        return DB::table('sheq_competencies')->insertGetId(array_merge([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'code' => 'C'.Str::random(6), 'title' => 'Working at Heights',
            'competency_type' => 'TRAINING', 'validity_months' => 24,
            'blocks_work_when_expired' => true, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ], $overrides));
    }

    private function requirement(int $competencyId, string $type, string $id, array $overrides = []): void
    {
        DB::table('sheq_competency_requirements')->insert(array_merge([
            'company_id' => $this->companyId, 'competency_id' => $competencyId,
            'applies_to_type' => $type, 'applies_to_id' => $id,
            'created_at' => now(), 'updated_at' => now(),
        ], $overrides));
    }

    // ── Precedence (SHEQ-COMP-2) ────────────────────────────────────────────────────────────────

    public function test_requirements_apply_from_role_department_and_site(): void
    {
        $a = $this->competency(['code' => 'WAH', 'title' => 'Working at Heights']);
        $b = $this->competency(['code' => 'FA', 'title' => 'First Aid']);

        $this->requirement($a, 'ROLE', 'Electrician');
        $this->requirement($b, 'SITE', '7');

        $required = $this->service()->requiredFor($this->companyId, $this->hrPersonId, [
            'ROLE' => 'Electrician', 'SITE' => '7',
        ]);

        $this->assertCount(2, $required);
    }

    public function test_an_individual_exemption_beats_a_site_wide_requirement(): void
    {
        // The whole point of precedence: a named exemption must win, or the register cannot
        // represent a new starter who is not yet cleared for the work.
        $competency = $this->competency(['code' => 'CSE', 'title' => 'Confined Space Entry']);

        $this->requirement($competency, 'SITE', '7');
        $this->requirement($competency, 'EMPLOYEE', (string) $this->hrPersonId, ['is_exempt' => true]);

        $required = $this->service()->requiredFor($this->companyId, $this->hrPersonId, ['SITE' => '7']);

        $this->assertSame([], $required);
    }

    public function test_a_role_requirement_beats_a_site_one_for_grace_days(): void
    {
        $competency = $this->competency(['code' => 'IND', 'title' => 'Site Induction']);

        $this->requirement($competency, 'SITE', '7', ['grace_days' => 30]);
        $this->requirement($competency, 'ROLE', 'Electrician', ['grace_days' => 0]);

        $required = $this->service()->requiredFor($this->companyId, $this->hrPersonId, [
            'ROLE' => 'Electrician', 'SITE' => '7',
        ]);

        $this->assertCount(1, $required);
        $this->assertSame('ROLE', $required[0]['required_by'], 'The more specific rule wins.');
        $this->assertSame(0, $required[0]['grace_days']);
    }

    // ── Gaps and derived status ─────────────────────────────────────────────────────────────────

    public function test_a_person_with_no_record_has_a_gap(): void
    {
        $competency = $this->competency(['code' => 'WAH']);
        $this->requirement($competency, 'ROLE', 'Rigger');

        $gaps = $this->service()->gapsFor($this->companyId, $this->hrPersonId, ['ROLE' => 'Rigger']);

        $this->assertCount(1, $gaps);
        $this->assertSame('NOT_STARTED', $gaps[0]['status']);
        $this->assertTrue($gaps[0]['blocks_work']);
    }

    public function test_an_expired_record_is_a_gap_and_a_valid_one_is_not(): void
    {
        $competency = $this->competency(['code' => 'WAH']);
        $this->requirement($competency, 'ROLE', 'Rigger');

        $this->service()->recordManual($this->companyId, $this->hrPersonId, 'WAH', [
            'achieved_at' => Carbon::today()->subMonths(30)->toDateString(),   // 24-month validity
        ], $this->userId);

        $this->assertCount(1, $this->service()->gapsFor($this->companyId, $this->hrPersonId, ['ROLE' => 'Rigger']));

        $this->service()->recordManual($this->companyId, $this->hrPersonId, 'WAH', [
            'achieved_at' => Carbon::today()->subMonth()->toDateString(),
        ], $this->userId);

        $this->assertSame([], $this->service()->gapsFor($this->companyId, $this->hrPersonId, ['ROLE' => 'Rigger']));
    }

    public function test_expiry_status_is_derived_from_dates_not_stored(): void
    {
        // A certificate that lapsed overnight must be EXPIRED in the morning without a job running.
        $competency = $this->competency(['code' => 'PDP', 'validity_months' => 12]);

        $this->service()->recordManual($this->companyId, $this->hrPersonId, 'PDP', [
            'achieved_at' => Carbon::today()->subMonths(13)->toDateString(),
        ], $this->userId);

        $record = DB::table('sheq_competency_records')
            ->where('hr_person_id', $this->hrPersonId)->first();

        $this->assertSame('COMPLIANT', $record->status, 'The stored value is only a starting point.');
        $this->assertSame('EXPIRED', $this->service()->deriveStatus($record), 'The derived state is the truth.');
    }

    public function test_grace_days_extend_the_derived_expiry(): void
    {
        $competency = $this->competency(['code' => 'IND', 'validity_months' => 12]);

        $this->service()->recordManual($this->companyId, $this->hrPersonId, 'IND', [
            'achieved_at' => Carbon::today()->subMonths(12)->subDays(5)->toDateString(),
        ], $this->userId);

        $record = DB::table('sheq_competency_records')->where('hr_person_id', $this->hrPersonId)->first();

        $this->assertSame('EXPIRED', $this->service()->deriveStatus($record));
        $this->assertSame('EXPIRING', $this->service()->deriveStatus($record, graceDays: 30));
    }

    // ── External certificates (SHEQ-COMP-7) ─────────────────────────────────────────────────────

    public function test_an_external_certificate_is_captured_with_its_issuing_body(): void
    {
        $this->competency(['code' => 'FORKLIFT', 'title' => 'Forklift Operator', 'validity_months' => 24]);

        $this->service()->recordManual($this->companyId, $this->hrPersonId, 'FORKLIFT', [
            'achieved_at' => '2026-01-15',
            'certificate_number' => 'FLT-99213',
            'issuing_body' => 'Reef Training Academy',
        ], $this->userId);

        $record = DB::table('sheq_competency_records')->where('hr_person_id', $this->hrPersonId)->first();

        $this->assertSame('FLT-99213', $record->certificate_number);
        $this->assertSame('Reef Training Academy', $record->issuing_body);
        $this->assertSame('2028-01-15', $record->expires_at, 'Expiry derives from the validity period.');
    }

    // ── Legal appointments (SHEQ-COMP-8) ────────────────────────────────────────────────────────

    public function test_an_appointment_needs_a_scope_and_a_signed_letter(): void
    {
        $this->competency(['code' => 'OHSA_16_2', 'title' => 'OHSA 16(2) Appointee',
            'competency_type' => 'APPOINTMENT', 'regulatory_reference' => 'OHSA 16(2)']);

        $this->expectExceptionMessageMatches('/needs a scope/');

        $this->service()->appoint($this->companyId, $this->hrPersonId, 'OHSA_16_2', [
            'document_id' => 1,
        ], $this->userId);
    }

    public function test_an_appointment_without_its_letter_is_refused(): void
    {
        $this->competency(['code' => 'GSR3', 'title' => 'First Aider (GSR 3)',
            'competency_type' => 'APPOINTMENT']);

        $this->expectExceptionMessageMatches('/signed letter/');

        $this->service()->appoint($this->companyId, $this->hrPersonId, 'GSR3', [
            'scope' => 'First aid for the Rustenburg plant',
        ], $this->userId);
    }

    public function test_an_unaccepted_appointment_is_not_in_force(): void
    {
        // A letter nobody signed is paper. This is exactly what an inspector asks to see.
        $this->competency(['code' => 'GSR3', 'competency_type' => 'APPOINTMENT']);

        $recordId = $this->service()->appoint($this->companyId, $this->hrPersonId, 'GSR3', [
            'scope' => 'First aid, Rustenburg plant', 'evidence_path' => 'letters/gsr3.pdf',
        ], $this->userId);

        $record = DB::table('sheq_competency_records')->find($recordId);
        $this->assertSame('NOT_STARTED', $this->service()->deriveStatus($record));

        $this->service()->acceptAppointment($this->companyId, $recordId, 'data:image/png;base64,SIG', $this->userId);

        $accepted = DB::table('sheq_competency_records')->find($recordId);
        $this->assertNotNull($accepted->accepted_at);
        $this->assertSame('COMPLIANT', $this->service()->deriveStatus($accepted));
    }

    public function test_a_revoked_appointment_is_expired_whatever_its_date_says(): void
    {
        // An appointment that outlives the job is how a company keeps a legally appointed first
        // aider who left two years ago.
        $this->competency(['code' => 'GSR3', 'competency_type' => 'APPOINTMENT', 'validity_months' => 60]);

        $recordId = $this->service()->appoint($this->companyId, $this->hrPersonId, 'GSR3', [
            'scope' => 'First aid', 'evidence_path' => 'letters/gsr3.pdf',
        ], $this->userId);
        $this->service()->acceptAppointment($this->companyId, $recordId, 'SIG', $this->userId);

        $this->service()->revoke($this->companyId, $recordId, 'Transferred to another site.', $this->userId);

        $record = DB::table('sheq_competency_records')->find($recordId);

        $this->assertSame('EXPIRED', $this->service()->deriveStatus($record));
        $this->assertSame('Transferred to another site.', $record->revocation_reason);
    }

    public function test_recording_a_training_competency_as_an_appointment_is_refused(): void
    {
        $this->competency(['code' => 'WAH', 'competency_type' => 'TRAINING']);

        $this->expectExceptionMessageMatches('/is not an appointment/');

        $this->service()->appoint($this->companyId, $this->hrPersonId, 'WAH', [
            'scope' => 'x', 'evidence_path' => 'y',
        ], $this->userId);
    }

    // ── The matrix (SHEQ-COMP-6) ────────────────────────────────────────────────────────────────

    public function test_the_matrix_reports_compliance_per_person_and_per_competency(): void
    {
        $wah = $this->competency(['code' => 'WAH', 'title' => 'Working at Heights']);
        $fa = $this->competency(['code' => 'FA', 'title' => 'First Aid']);

        $this->requirement($wah, 'ROLE', 'Rigger');
        $this->requirement($fa, 'ROLE', 'Rigger');

        $riggerA = 9101;
        $riggerB = 9102;

        // A holds both; B holds only one.
        $this->service()->recordManual($this->companyId, $riggerA, 'WAH', ['achieved_at' => Carbon::today()->toDateString()]);
        $this->service()->recordManual($this->companyId, $riggerA, 'FA', ['achieved_at' => Carbon::today()->toDateString()]);
        $this->service()->recordManual($this->companyId, $riggerB, 'WAH', ['achieved_at' => Carbon::today()->toDateString()]);

        $matrix = $this->service()->matrix($this->companyId, [
            $riggerA => ['ROLE' => 'Rigger'],
            $riggerB => ['ROLE' => 'Rigger'],
        ]);

        $this->assertSame(100.0, $matrix['rows'][0]['percent']);
        $this->assertSame(50.0, $matrix['rows'][1]['percent']);
        $this->assertSame(100.0, $matrix['by_competency']['WAH']['percent']);
        $this->assertSame(50.0, $matrix['by_competency']['FA']['percent']);
        $this->assertSame(75.0, $matrix['overall_percent']);
    }

    public function test_an_expiring_competency_still_counts_as_compliant_today(): void
    {
        // Treating a renewal window as non-compliance would make every fleet permanently red.
        $wah = $this->competency(['code' => 'WAH', 'validity_months' => 12]);
        $this->requirement($wah, 'ROLE', 'Rigger');

        $this->service()->recordManual($this->companyId, $this->hrPersonId, 'WAH', [
            'achieved_at' => Carbon::today()->subMonths(11)->toDateString(),   // ~30 days left
        ]);

        $matrix = $this->service()->matrix($this->companyId, [$this->hrPersonId => ['ROLE' => 'Rigger']]);

        $this->assertSame('EXPIRING', $matrix['rows'][0]['cells']['WAH']['status']);
        $this->assertSame(100.0, $matrix['rows'][0]['percent']);
    }

    // ── Expiry notifications (SHEQ-COMP-5) ──────────────────────────────────────────────────────

    public function test_notifications_fire_only_on_the_defined_gates(): void
    {
        // Repeating daily is the fastest way to teach people to ignore the message.
        $this->competency(['code' => 'WAH', 'validity_months' => 12]);

        $this->service()->recordManual($this->companyId, $this->hrPersonId, 'WAH', [
            'achieved_at' => Carbon::today()->subMonths(12)->addDays(30)->toDateString(),
        ]);

        $due = $this->service()->expiryNotificationsDue($this->companyId);
        $mine = collect($due)->firstWhere('hr_person_id', $this->hrPersonId);

        $this->assertNotNull($mine, 'A record 30 days from expiry sits on a notification gate.');
        $this->assertSame(30, $mine['gate']);

        // 29 days out is not a gate.
        DB::table('sheq_competency_records')->where('hr_person_id', $this->hrPersonId)
            ->update(['expires_at' => Carbon::today()->addDays(29)->toDateString()]);

        $this->assertNull(collect($this->service()->expiryNotificationsDue($this->companyId))
            ->firstWhere('hr_person_id', $this->hrPersonId));
    }

    // ── The merged ledger, as permits and incidents see it ──────────────────────────────────────

    public function test_the_ledger_surfaces_sheq_records_to_permits_and_incidents(): void
    {
        $this->competency(['code' => 'HOTWORK', 'title' => 'Hot Work Authorisation', 'validity_months' => 12]);

        $this->service()->recordManual($this->companyId, $this->hrPersonId, 'HOTWORK', [
            'achieved_at' => Carbon::today()->subMonths(18)->toDateString(),   // expired
        ]);

        $ledger = app(CompetencyLedger::class);
        $status = $ledger->statusForCompetency($this->hrPersonId, 'Hot Work Authorisation');

        $this->assertNotNull($status, 'A manually captured certificate must gate work like an LMS one.');
        $this->assertSame(CompetencyLedger::EXPIRED, $status['status']);
        $this->assertGreaterThan(0, $status['days_overdue']);
    }

    public function test_the_ledger_matches_on_the_competency_code_as_well_as_the_title(): void
    {
        $this->competency(['code' => 'HOTWORK', 'title' => 'Hot Work Authorisation']);

        $this->service()->recordManual($this->companyId, $this->hrPersonId, 'HOTWORK', [
            'achieved_at' => Carbon::today()->toDateString(),
        ]);

        $this->assertNotNull(app(CompetencyLedger::class)
            ->statusForCompetency($this->hrPersonId, 'HOTWORK'));
    }
}
