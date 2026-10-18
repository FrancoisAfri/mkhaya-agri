<?php

namespace Tests\Feature\Sheq;

use App\Modules\Operations\Sheq\Exceptions\NoActiveRegulatoryPack;
use App\Modules\Operations\Sheq\Regulatory\PackLoader;
use App\Modules\Operations\Sheq\Services\IncidentReporter;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * End-to-end incident reporting, against the real schema and the real za-ohsa pack.
 *
 * This is the §5.1 "statutory clock" acceptance criterion made executable: a fatality must classify
 * as OHSA_S24(a), raise the notification and W.Cl.2 obligations with the right deadlines, freeze a
 * context snapshot, and leave an audit entry — all of it or none of it.
 *
 * DatabaseTransactions rather than RefreshDatabase: this schema is 1200 tables and a migrate:fresh
 * per test class costs minutes and risks unrelated seeder failures. A rolled-back transaction gives
 * the same isolation for the cost of a BEGIN.
 */
class IncidentReportingTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

    private int $packVersionId;

    protected function setUp(): void
    {
        parent::setUp();

        // These tests read what a page hands its component, not the compiled front end. Without
        // this the suite depends on `public/build/manifest.json`, so an unrelated `vite build`
        // running in another checkout turns every page assertion below into a 500.
        $this->withoutVite();

        $companyId = DB::table('companies')->min('id');
        if ($companyId === null) {
            $this->markTestSkipped('No company in the test database to report against.');
        }
        $this->companyId = (int) $companyId;

        // Install and activate za-ohsa inside the transaction, so the test governs its own rulebook
        // rather than depending on what happens to be installed.
        $manifest = (new PackLoader)->load(base_path('resources/regulatory-packs/za-ohsa'));

        $packId = DB::table('sheq_regulatory_packs')->insertGetId([
            'pack_id' => 'za-ohsa-test-'.Str::random(6), 'jurisdiction' => 'ZA',
            'name' => 'Test pack', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->packVersionId = DB::table('sheq_regulatory_pack_versions')->insertGetId([
            'pack_id' => $packId, 'version' => $manifest->version,
            'effective_from' => '2020-01-01', 'manifest' => json_encode($manifest->manifest),
            'checksum' => $manifest->checksum(), 'signature_verified' => false,
            'installed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('sheq_regulatory_pack_activations')->insert([
            'company_id' => $this->companyId, 'uuid' => (string) Str::uuid(), 'site_id' => null,
            'pack_version_id' => $this->packVersionId, 'status' => 'ACCEPTED',
            'activated_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * Resolved from the container, not hand-built. Constructing the dependencies here is what let a
     * missing binding reach production once already: every test passed while the real request path
     * threw. If a collaborator cannot be resolved, these tests are the place that should hurt.
     */
    private function reporter(): IncidentReporter
    {
        return app(IncidentReporter::class);
    }

    public function test_a_fatality_is_classified_and_its_statutory_clocks_start(): void
    {
        $occurredAt = Carbon::parse('2026-08-06T14:00:00+02:00');

        $result = $this->reporter()->report($this->companyId, [
            'occurred_at' => $occurredAt->toIso8601String(),
            'incident_type' => 'INJURY',
            'outcome' => 'FATALITY',
            'description' => 'Fall from height during roof sheeting installation.',
            'actual_severity' => 5,
            'potential_severity' => 5,
        ]);

        $this->assertSame('OHSA_S24(a)', $result['classification']['statutory_category']);
        $this->assertTrue($result['classification']['reportable']);

        $obligations = DB::table('sheq_statutory_obligations')
            ->where('subject_id', $result['incident_id'])->where('subject_type', 'INCIDENT')
            ->pluck('due_at', 'obligation_def_id');

        $this->assertCount(3, $obligations);
        $this->assertSame(
            '2026-08-13 12:00:00',
            Carbon::parse($obligations['wcl_submission_7d'])->utc()->format('Y-m-d H:i:s'),
            'The W.Cl.2 clock must run seven days from the incident, in the site timezone.'
        );
        $this->assertSame(
            '2026-08-07 12:00:00',
            Carbon::parse($obligations['immediate_pd_notification'])->utc()->format('Y-m-d H:i:s')
        );

        // Every obligation starts PENDING and shows on the statutory queue (SHEQ-REG-5).
        $this->assertSame(3, DB::table('sheq_statutory_obligations')
            ->where('subject_id', $result['incident_id'])->where('status', 'PENDING')->count());
    }

    public function test_the_incident_records_the_pack_version_that_judged_it(): void
    {
        $result = $this->reporter()->report($this->companyId, [
            'occurred_at' => '2026-08-06T14:00:00+02:00',
            'incident_type' => 'INJURY', 'outcome' => 'FATALITY', 'description' => 'x',
        ]);

        $incident = DB::table('sheq_incidents')->find($result['incident_id']);

        // SHEQ-REG-2: without this, a later pack release silently reclassifies history.
        $this->assertNotNull($incident->regulatory_pack_version);
        $this->assertSame('s24a', $incident->classification_rule_id);
        $this->assertSame('2029-08-06', $incident->retention_until, 'Retention floor comes from the pack.');
    }

    public function test_a_context_snapshot_is_frozen_and_cannot_be_edited(): void
    {
        $result = $this->reporter()->report($this->companyId, [
            'occurred_at' => '2026-08-06T14:00:00+02:00',
            'incident_type' => 'NEAR_MISS', 'description' => 'Scaffold plank slipped.',
            'potential_severity' => 5,
        ]);

        $context = DB::table('sheq_incident_context')->where('incident_id', $result['incident_id'])->first();

        $this->assertNotNull($context, 'Every incident must carry a context snapshot (SHEQ-INC-6).');
        $this->assertSame('2026-08-06T12:00:00+00:00', Carbon::parse($context->as_at)->utc()->toIso8601String());

        // Sources are recorded so "we could not check" never reads as "we checked and it was fine".
        $sources = json_decode($context->sources, true);
        $this->assertArrayHasKey('employees', $sources);
        $this->assertArrayHasKey('competencies', $sources);

        $this->expectExceptionMessageMatches('/written once/i');
        DB::table('sheq_incident_context')->where('id', $context->id)->update(['gap_count' => 99]);
    }

    public function test_an_offline_replay_returns_the_original_incident(): void
    {
        // SHEQ-OFF-2: the same client_id must never file twice, however many times sync retries.
        $clientId = (string) Str::uuid();
        $payload = [
            'occurred_at' => '2026-08-06T14:00:00+02:00', 'incident_type' => 'NEAR_MISS',
            'description' => 'Reversing vehicle, no spotter.', 'client_id' => $clientId,
        ];

        $first = $this->reporter()->report($this->companyId, $payload);
        $second = $this->reporter()->report($this->companyId, $payload);

        $this->assertSame($first['incident_id'], $second['incident_id']);
        $this->assertTrue($second['replayed'] ?? false);
        $this->assertSame(1, DB::table('sheq_incidents')->where('client_id', $clientId)->count());
    }

    public function test_an_anonymous_report_stores_no_reporter(): void
    {
        // SHEQ-INC-3: anonymity that leaks the reporter is worse than no anonymity, because the
        // reporter believed they were protected.
        $result = $this->reporter()->report($this->companyId, [
            'occurred_at' => now()->toIso8601String(), 'incident_type' => 'SECURITY',
            'description' => 'Intimidation by a supervisor.', 'is_anonymous' => true,
        ], reportedBy: 1);

        $incident = DB::table('sheq_incidents')->find($result['incident_id']);

        $this->assertNull($incident->reported_by);
        $this->assertTrue((bool) $incident->is_anonymous);
        $this->assertNull(
            DB::table('sheq_audit_log')->where('subject_id', $result['incident_id'])->value('actor_id'),
            'The audit entry must not carry the reporter either.'
        );
    }

    public function test_reporting_appends_to_the_hash_chained_audit_log(): void
    {
        $result = $this->reporter()->report($this->companyId, [
            'occurred_at' => now()->toIso8601String(), 'incident_type' => 'NEAR_MISS',
            'description' => 'Unsecured load.',
        ]);

        $entry = DB::table('sheq_audit_log')
            ->where('subject_type', 'Incident')->where('subject_id', $result['incident_id'])->first();

        $this->assertNotNull($entry);
        $this->assertSame('incident.reported', $entry->action);
        $this->assertSame(64, strlen((string) $entry->hash), 'The chain hash must be populated.');
    }

    public function test_reporting_without_an_active_pack_is_refused(): void
    {
        DB::table('sheq_regulatory_pack_activations')->where('company_id', $this->companyId)->delete();

        $this->expectException(NoActiveRegulatoryPack::class);
        $this->expectExceptionMessageMatches('/no regulatory pack is active/i');

        $this->reporter()->report($this->companyId, [
            'occurred_at' => now()->toIso8601String(), 'incident_type' => 'INJURY',
            'outcome' => 'FATALITY', 'description' => 'x',
        ]);
    }

    /**
     * The refusal has to be readable, not a 500.
     *
     * In production a reporter filled in the form, pressed submit, and got a stack trace: the
     * refusal was a bare RuntimeException, so what they had typed was lost and nothing said an
     * administrator must activate a regulatory pack first.
     */
    public function test_the_refusal_comes_back_to_the_reporter_instead_of_crashing(): void
    {
        DB::table('sheq_regulatory_pack_activations')->where('company_id', $this->companyId)->delete();

        $exception = new NoActiveRegulatoryPack(siteId: 7, anyInstalled: true);

        // A browser gets the form back with its input, and the reason attached to a field.
        $web = $exception->render(Request::create('/sheq/incidents', 'POST'));
        $this->assertInstanceOf(RedirectResponse::class, $web);
        $errors = $web->getSession()?->get('errors');
        $this->assertNotNull($errors, 'the reason was not attached to anything the form can show');
        $this->assertMatchesRegularExpression(
            '/regulatory pack/i',
            (string) $errors->getBag('default')->first('site_id'),
        );

        // A device gets a 409 the offline queue records, rather than retrying for ever.
        $api = Request::create('/sheq/sync', 'POST');
        $api->headers->set('Accept', 'application/json');
        $json = $exception->render($api);
        $this->assertSame(409, $json->getStatusCode());
        $this->assertSame('SHEQ_NO_ACTIVE_PACK', $json->getData(true)['code']);
        $this->assertSame(7, $json->getData(true)['site_id']);
    }

    /**
     * The two ways of having no pack are fixed by different people, so they are said differently.
     *
     * Telling a tenant administrator to "activate one" when nothing is installed sends them to an
     * empty screen, and nothing runs `sheq:packs:install` automatically, so on a fresh deployment
     * that is the state they are in.
     */
    public function test_the_reason_distinguishes_nothing_installed_from_nothing_activated(): void
    {
        $notInstalled = NoActiveRegulatoryPack::reason(anyInstalled: false);
        $this->assertStringContainsString('installed on this system', $notInstalled);
        $this->assertStringContainsString('system administrator', $notInstalled);
        $this->assertStringNotContainsString('activate one under', $notInstalled);

        $notActivated = NoActiveRegulatoryPack::reason(anyInstalled: true);
        $this->assertStringContainsString('Regulatory Packs', $notActivated);
        $this->assertStringContainsString('activate', $notActivated);
    }

    // ── Who it happened to ───────────────────────────────────────────────────

    /**
     * The person the incident happened to reaches the W.Cl.2.
     *
     * `sheq_incident_people` has existed since the module was built, the reporter has always
     * written it and StatutoryFormService has always read it — looking for the person with role
     * INJURED or AFFECTED, because "a witness's name on a W.Cl.2 would be a false statement to the
     * Commissioner". Nothing ever supplied one, because the report form never asked. So the field
     * on the form is not a convenience: without it every statutory form goes to the Compensation
     * Commissioner with the injured person blank.
     */
    public function test_the_affected_person_is_recorded_against_the_incident(): void
    {
        $personId = DB::table('hr_people')->insertGetId([
            'first_name' => 'Thandi', 'surname' => 'Mokoena', 'status' => 1,
            'company_id' => $this->companyId, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $result = $this->reporter()->report($this->companyId, [
            'occurred_at' => Carbon::parse('2026-08-06T09:00:00+02:00')->toIso8601String(),
            'incident_type' => 'INJURY',
            'description' => 'Slipped on a wet ramp.',
            'outcome' => 'MEDICAL_TREATMENT',
            'people' => [
                ['role' => 'AFFECTED', 'hr_person_id' => $personId, 'external_name' => null],
            ],
        ], null);

        $row = DB::table('sheq_incident_people')
            ->where('company_id', $this->companyId)
            ->where('incident_id', DB::table('sheq_incidents')->where('uuid', $result['uuid'])->value('id'))
            ->first();

        $this->assertNotNull($row, 'Nobody was recorded against the incident.');
        $this->assertSame('AFFECTED', $row->role);
        $this->assertSame($personId, (int) $row->hr_person_id);
    }

    /**
     * A visitor, a delivery driver, a contractor's employee. Forced to pick from staff, a reporter
     * picks the nearest wrong name — and a wrong name on a statutory form is worse than none.
     */
    public function test_somebody_who_is_not_staff_can_still_be_named(): void
    {
        $result = $this->reporter()->report($this->companyId, [
            'occurred_at' => Carbon::parse('2026-08-06T09:00:00+02:00')->toIso8601String(),
            'incident_type' => 'INJURY',
            'description' => 'A delivery driver caught his hand in the tail lift.',
            'people' => [
                ['role' => 'AFFECTED', 'hr_person_id' => null, 'external_name' => 'A. Dlamini (courier)'],
            ],
        ], null);

        $row = DB::table('sheq_incident_people')
            ->where('incident_id', DB::table('sheq_incidents')->where('uuid', $result['uuid'])->value('id'))
            ->first();

        $this->assertNull($row->hr_person_id);
        // Deliberately not encrypted, unlike the injury detail beside it: a data subject request
        // has to be matchable to the records it concerns, and an encrypted name cannot be searched.
        $this->assertSame('A. Dlamini (courier)', $row->external_name);
    }

    /** The report form offers the staff it can name, scoped to the tenant reporting. */
    public function test_the_report_form_offers_this_company_s_staff(): void
    {
        DB::table('hr_people')->insert([
            'first_name' => 'Sipho', 'surname' => 'Ndlovu', 'status' => 1,
            'company_id' => $this->companyId, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $roster = app(\App\Modules\Operations\Sheq\Contracts\EmployeeDirectory::class)
            ->roster($this->companyId);

        $this->assertNotEmpty($roster, 'The picker would have been empty.');
        $this->assertContains('Sipho Ndlovu', array_column($roster, 'full_name'));

        foreach ($roster as $person) {
            // The interface deliberately withholds date of birth, ID number and address; a roster
            // that leaked them would be the cheapest possible way to get PII into an incident.
            $this->assertSame(['id', 'full_name', 'employee_number', 'job_title'], array_keys($person));
        }
    }
}
