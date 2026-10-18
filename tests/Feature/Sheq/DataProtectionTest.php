<?php

namespace Tests\Feature\Sheq;

use App\Modules\Operations\Sheq\Security\DataSubjectService;
use App\Modules\Operations\Sheq\Security\SpecialInformation;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * POPIA data protection in SHEQ (§9).
 *
 * The centre of gravity here is the collision between the right to erasure and statutory retention.
 * Getting it wrong in either direction is serious: honour every erasure request and you commit an
 * offence under the retaining Act while destroying the evidence a sick worker would need years
 * later; refuse them all and you are simply not complying with POPIA. These tests pin the line.
 */
class DataProtectionTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

    private int $siteId;

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();

        $companyId = DB::table('companies')->min('id');

        if ($companyId === null) {
            $this->markTestSkipped('No company in the test database.');
        }

        $this->companyId = (int) $companyId;

        $this->siteId = DB::table('sheq_sites')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'code' => 'D'.Str::random(6), 'name' => 'Shaft', 'site_type' => 'SITE',
            'timezone' => 'Africa/Johannesburg', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->userId = DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(), 'email' => 'dp-'.Str::random(8).'@example.test',
            'password' => bcrypt('secret'), 'company_id' => $this->companyId, 'status' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * Activate a regulatory pack, so refusals can name the Act that compels retention.
     *
     * Without one the service falls back to generic wording — correct, but it would leave the
     * assertion that actually matters untested.
     */
    private function activatePack(string $packId): bool
    {
        $versionId = DB::table('sheq_regulatory_pack_versions as v')
            ->join('sheq_regulatory_packs as p', 'p.id', '=', 'v.pack_id')
            ->where('p.pack_id', $packId)
            ->orderByDesc('v.version')
            ->value('v.id');

        if ($versionId === null) {
            return false;
        }

        DB::table('sheq_regulatory_pack_activations')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'pack_version_id' => $versionId, 'status' => 'ACTIVE',
            'activated_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return true;
    }

    /** @return array{incident_id:int, person_uuid:string} */
    private function incidentWithPerson(array $incident = [], array $person = []): array
    {
        $incidentId = DB::table('sheq_incidents')->insertGetId(array_merge([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'reference' => 'INC-'.Str::random(6), 'site_id' => $this->siteId,
            'occurred_at' => now()->subYears(2), 'reported_at' => now()->subYears(2),
            'incident_type' => 'INJURY', 'description' => 'Struck by falling rock.',
            'status' => 'CLOSED', 'created_at' => now(), 'updated_at' => now(),
        ], $incident));

        $personUuid = (string) Str::uuid();
        $special = app(SpecialInformation::class);

        DB::table('sheq_incident_people')->insert($special->protectRow(array_merge([
            'uuid' => $personUuid, 'company_id' => $this->companyId,
            'incident_id' => $incidentId, 'role' => 'INJURED',
            'external_name' => 'Nomsa Dlamini', 'external_contact' => '082 555 0000',
            'body_part' => 'Left hand', 'injury_nature' => 'Crush injury, two fingers',
            'created_at' => now(), 'updated_at' => now(),
        ], $person)));

        return ['incident_id' => $incidentId, 'person_uuid' => $personUuid];
    }

    // ── Special personal information at rest ──────────────────────────────────────────────────

    public function test_injury_details_are_not_readable_in_the_database(): void
    {
        $this->incidentWithPerson();

        $raw = DB::table('sheq_incident_people')->where('company_id', $this->companyId)
            ->orderByDesc('id')->first(['injury_nature', 'body_part', 'external_contact']);

        // The realistic escape routes are a restored backup, a read replica pointed at a BI tool,
        // or an ad-hoc support query. All of them see exactly this.
        $this->assertStringNotContainsString('Crush injury', (string) $raw->injury_nature);
        $this->assertStringNotContainsString('Left hand', (string) $raw->body_part);
        $this->assertStringNotContainsString('082 555 0000', (string) $raw->external_contact);
    }

    public function test_the_details_round_trip_for_people_entitled_to_read_them(): void
    {
        $this->incidentWithPerson();
        $special = app(SpecialInformation::class);

        $row = DB::table('sheq_incident_people')->where('company_id', $this->companyId)
            ->orderByDesc('id')->first(['injury_nature', 'body_part']);

        $revealed = $special->revealRow($row);

        $this->assertSame('Crush injury, two fingers', $revealed->injury_nature);
        $this->assertSame('Left hand', $revealed->body_part);
    }

    public function test_records_written_before_encryption_still_read(): void
    {
        // A backfill rewriting every historical row would hold a long lock on a table under
        // forty-year retention. Instead old plaintext reads through untouched.
        $this->assertSame('Legacy plaintext value',
            app(SpecialInformation::class)->reveal('Legacy plaintext value'));
    }

    public function test_the_name_stays_searchable(): void
    {
        // Encrypting the name would make it impossible to find the records a data subject request
        // concerns — the request would be unanswerable, which is a worse POPIA outcome.
        $this->incidentWithPerson();

        $found = DB::table('sheq_incident_people')->where('company_id', $this->companyId)
            ->where('external_name', 'ilike', 'Nomsa Dlamini')->exists();

        $this->assertTrue($found);
    }

    // ── Section 23: the right to know what is held ────────────────────────────────────────────

    public function test_a_person_is_shown_their_own_details_in_plain_language(): void
    {
        $this->incidentWithPerson();

        $held = app(DataSubjectService::class)
            ->assemble($this->companyId, null, 'Nomsa Dlamini');

        $this->assertSame(1, $held['total']);
        // Handing the subject ciphertext would be a refusal dressed up as compliance.
        $this->assertSame('Crush injury, two fingers', $held['incidents'][0]['injury_nature']);
    }

    public function test_an_external_person_with_no_employee_record_still_has_rights(): void
    {
        // Contractors and members of the public have no hr_person_id and the same POPIA rights.
        $this->incidentWithPerson(person: ['hr_person_id' => null, 'external_name' => 'Piet Retief']);

        $held = app(DataSubjectService::class)
            ->assemble($this->companyId, null, 'Piet Retief');

        $this->assertSame(1, $held['total']);
    }

    // ── Section 24 versus statutory retention ─────────────────────────────────────────────────

    public function test_erasure_is_refused_while_statutory_retention_runs(): void
    {
        if (! $this->activatePack('za-mhsa')) {
            $this->markTestSkipped('The MHSA pack is not installed in the test database.');
        }

        $this->incidentWithPerson([
            // Forty years, as the MHSA requires for exposure records.
            'retention_until' => Carbon::now()->addYears(38),
        ]);

        $assessment = app(DataSubjectService::class)
            ->assessErasure($this->companyId, null, 'Nomsa Dlamini');

        $this->assertCount(0, $assessment['erasable']);
        $this->assertCount(1, $assessment['retained']);

        $reason = $assessment['retained'][0]['refusal_reason'];

        // "We cannot delete this" is stonewalling. The refusal has to name the instrument and the
        // date, so the subject can take it to the Information Regulator if they disagree.
        $this->assertStringContainsString('Mine Health and Safety Act', $reason,
            'The refusal must name the instrument that compels retention, not gesture at "the law".');
        $this->assertStringContainsString('section 24', $reason);
        $this->assertMatchesRegularExpression('/\d{4}/', $reason, 'The refusal must state until when.');
    }

    public function test_the_refusal_stays_honest_when_no_pack_is_activated(): void
    {
        $this->incidentWithPerson(['retention_until' => Carbon::now()->addYears(10)]);

        $reason = app(DataSubjectService::class)
            ->assessErasure($this->companyId, null, 'Nomsa Dlamini')['retained'][0]['refusal_reason'];

        // Naming the wrong Act would be worse than naming none, so with nothing activated the
        // wording stays general rather than guessing at a jurisdiction.
        $this->assertStringContainsString('applicable occupational health and safety legislation',
            $reason);
    }

    public function test_erasure_proceeds_once_retention_has_expired(): void
    {
        $this->incidentWithPerson(['retention_until' => Carbon::now()->subYear()]);

        $assessment = app(DataSubjectService::class)
            ->assessErasure($this->companyId, null, 'Nomsa Dlamini');

        $this->assertCount(1, $assessment['erasable'],
            'Once the statutory period has run, the right to erasure is unqualified.');
        $this->assertCount(0, $assessment['retained']);
    }

    public function test_a_legal_hold_beats_an_expired_retention_period(): void
    {
        $this->incidentWithPerson([
            'retention_until' => Carbon::now()->subYears(5), 'legal_hold' => true,
        ]);

        $assessment = app(DataSubjectService::class)
            ->assessErasure($this->companyId, null, 'Nomsa Dlamini');

        $this->assertCount(0, $assessment['erasable']);
        // Destroying evidence under hold is spoliation — a separate and more serious problem than
        // a retention breach, which is why it is checked first.
        $this->assertStringContainsString('spoliation', $assessment['retained'][0]['refusal_reason']);
    }

    public function test_an_uncalculated_retention_date_is_refused_not_assumed_safe(): void
    {
        $this->incidentWithPerson(['retention_until' => null]);

        $assessment = app(DataSubjectService::class)
            ->assessErasure($this->companyId, null, 'Nomsa Dlamini');

        // The failure modes are not symmetric: a wrongly kept record can be erased later, a wrongly
        // destroyed statutory record cannot be recovered and the offence is already committed.
        $this->assertCount(0, $assessment['erasable']);
        $this->assertCount(1, $assessment['retained']);
    }

    // ── Fulfilment ────────────────────────────────────────────────────────────────────────────

    public function test_fulfilling_an_erasure_anonymises_rather_than_deletes(): void
    {
        $created = $this->incidentWithPerson(['retention_until' => Carbon::now()->subYear()]);
        $service = app(DataSubjectService::class);

        $request = $service->receive($this->companyId, [
            'request_type' => 'ERASURE', 'subject_name' => 'Nomsa Dlamini',
        ]);

        $result = $service->fulfilErasure($this->companyId, $request['uuid'], $this->userId);

        $this->assertSame(1, $result['erased']);

        // The incident itself survives: its existence is a safety statistic, and removing the row
        // would understate this workplace's injury history.
        $this->assertTrue(DB::table('sheq_incidents')->where('id', $created['incident_id'])->exists());

        $person = DB::table('sheq_incident_people')->where('uuid', $created['person_uuid'])
            ->first(['external_name', 'injury_nature', 'external_contact', 'hr_person_id']);

        $this->assertSame('[erased on request]', $person->external_name);
        $this->assertNull($person->injury_nature);
        $this->assertNull($person->external_contact);
    }

    public function test_a_partly_refused_erasure_is_recorded_as_partly_fulfilled(): void
    {
        $this->incidentWithPerson(['retention_until' => Carbon::now()->subYear()]);
        $this->incidentWithPerson(['retention_until' => Carbon::now()->addYears(30)]);

        $service = app(DataSubjectService::class);

        $request = $service->receive($this->companyId, [
            'request_type' => 'ERASURE', 'subject_name' => 'Nomsa Dlamini',
        ]);

        $result = $service->fulfilErasure($this->companyId, $request['uuid'], $this->userId);

        $this->assertSame(1, $result['erased']);
        $this->assertSame(1, $result['retained']);

        $row = DB::table('sheq_data_subject_requests')->where('uuid', $request['uuid'])
            ->first(['status', 'refusals', 'records_erased', 'records_retained']);

        $this->assertSame('PARTIALLY_FULFILLED', $row->status);

        // "We deleted 1 of your 2 records" with no explanation of the other is not compliance.
        $refusals = json_decode((string) $row->refusals, true);
        $this->assertCount(1, $refusals);
        $this->assertNotEmpty($refusals[0]['reason']);
    }

    public function test_a_request_cannot_be_fulfilled_twice(): void
    {
        $this->incidentWithPerson(['retention_until' => Carbon::now()->subYear()]);
        $service = app(DataSubjectService::class);

        $request = $service->receive($this->companyId, [
            'request_type' => 'ERASURE', 'subject_name' => 'Nomsa Dlamini',
        ]);

        $service->fulfilErasure($this->companyId, $request['uuid'], $this->userId);

        $this->expectExceptionMessageMatches('/already/i');
        $service->fulfilErasure($this->companyId, $request['uuid'], $this->userId);
    }

    // ── The 30-day clock ──────────────────────────────────────────────────────────────────────

    public function test_the_deadline_is_stamped_at_receipt(): void
    {
        $request = app(DataSubjectService::class)->receive($this->companyId, [
            'request_type' => 'ACCESS', 'subject_name' => 'Nomsa Dlamini',
        ]);

        $row = DB::table('sheq_data_subject_requests')->where('uuid', $request['uuid'])
            ->first(['received_at', 'due_at']);

        // Stored rather than computed on read: changing the statutory period later must not
        // silently move the deadline of a request already accepted under the old one.
        $this->assertSame(30,
            (int) Carbon::parse($row->received_at)->diffInDays(Carbon::parse($row->due_at)));
    }

    public function test_overdue_requests_are_surfaced(): void
    {
        $service = app(DataSubjectService::class);

        $request = $service->receive($this->companyId, [
            'request_type' => 'ACCESS', 'subject_name' => 'Late Request',
        ]);

        DB::table('sheq_data_subject_requests')->where('uuid', $request['uuid'])
            ->update(['due_at' => Carbon::now()->subDays(3)]);

        $overdue = collect($service->overdue($this->companyId))
            ->firstWhere('uuid', $request['uuid']);

        $this->assertTrue($overdue['overdue']);
        $this->assertLessThan(0, $overdue['days_remaining']);
    }

    public function test_an_unknown_request_type_is_refused(): void
    {
        $this->expectExceptionMessageMatches('/not a POPIA request type/');

        app(DataSubjectService::class)->receive($this->companyId, [
            'request_type' => 'DELETE_EVERYTHING', 'subject_name' => 'X',
        ]);
    }
}
