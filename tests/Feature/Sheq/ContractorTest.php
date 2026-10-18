<?php

namespace Tests\Feature\Sheq;

use App\Modules\Operations\Sheq\Services\ContractorService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Contractor management (§5.9).
 *
 * The host employer carries duties for whoever is on its premises, so these tests are about the
 * gate: what blocks a contractor, what merely warns, what an override costs, and whether the
 * statistics can be split the way the annual return requires.
 */
class ContractorTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

    private int $userId;

    private int $siteId;

    /** What this host demands before anyone comes on site. */
    private array $required = ['PUBLIC_LIABILITY', 'COIDA_LETTER', 'SAFETY_FILE'];

    protected function setUp(): void
    {
        parent::setUp();

        $companyId = DB::table('companies')->min('id');
        if ($companyId === null) {
            $this->markTestSkipped('No company in the test database.');
        }
        $this->companyId = (int) $companyId;

        $this->userId = DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(), 'email' => 'con-'.Str::random(8).'@example.test',
            'password' => bcrypt('secret'), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->siteId = DB::table('sheq_sites')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'code' => 'C'.Str::random(5), 'name' => 'Construction site', 'site_type' => 'PROJECT',
            'timezone' => 'Africa/Johannesburg', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function service(): ContractorService
    {
        return app(ContractorService::class);
    }

    private function contractor(string $name = 'Reef Scaffolding'): array
    {
        return $this->service()->register($this->companyId, [
            'name' => $name, 'trade' => 'Scaffolding', 'site_id' => $this->siteId,
            'contact_email' => 'ops@reef.test',
        ], $this->userId);
    }

    private function attach(string $uuid, string $type, ?string $expires, bool $mandatory = true): void
    {
        $this->service()->attachDocument($this->companyId, $uuid, [
            'document_type' => $type, 'expires_on' => $expires, 'is_mandatory' => $mandatory,
            'reference' => Str::random(6),
        ], $this->userId);
    }

    private function fullyCompliant(string $uuid, ?string $expires = null): void
    {
        $expires ??= Carbon::today()->addYear()->toDateString();
        foreach ($this->required as $type) {
            $this->attach($uuid, $type, $expires);
        }
    }

    /**
     * Build a compliance file with an exact expiry per type.
     *
     * Layering a second document over a valid one does not express "this certificate has expired" —
     * the latest-per-type rule correctly keeps the valid renewal — so a test that means expiry has
     * to say so from the start.
     *
     * @param  array<string,?string>  $expiries  document type => expiry date (null = never expires)
     */
    private function fileWith(string $uuid, array $expiries): void
    {
        foreach ($expiries as $type => $expires) {
            $this->attach($uuid, $type, $expires);
        }
    }

    public function test_registering_returns_the_record_it_created(): void
    {
        /**
         * A caller that announces "X registered" reads these keys straight back. Assuming a key the
         * service never returned is what produced a 500 on the contractor form, so the shape is
         * asserted rather than trusted.
         */
        $contractor = $this->contractor('Reef Scaffolding');

        foreach (['id', 'uuid', 'name'] as $key) {
            $this->assertArrayHasKey($key, $contractor, "register() did not return {$key}.");
        }

        $this->assertSame('Reef Scaffolding', $contractor['name']);
    }

    // ── The gate (SHEQ-CON-2) ───────────────────────────────────────────────────────────────────

    public function test_a_new_contractor_starts_blocked(): void
    {
        // Blocked until the file proves otherwise is the safe default for a site gate.
        $contractor = $this->contractor();

        $this->assertSame('BLOCKED', DB::table('sheq_contractors')->find($contractor['id'])->access_status);
    }

    public function test_a_missing_mandatory_document_blocks_exactly_as_an_expired_one_does(): void
    {
        // "We never received it" and "it expired" leave the host in the same position under COIDA.
        $contractor = $this->contractor();
        $this->attach($contractor['uuid'], 'PUBLIC_LIABILITY', Carbon::today()->addYear()->toDateString());

        $file = $this->service()->complianceFile($this->companyId, $contractor['uuid'], $this->required);

        $this->assertSame('BLOCKED', $file['status']);
        $reasons = array_column($file['blocking'], 'reason');
        $this->assertContains('MISSING', $reasons);
        $this->assertCount(2, $file['blocking'], 'COIDA letter and safety file are both absent.');
    }

    public function test_a_complete_in_date_file_is_compliant(): void
    {
        $contractor = $this->contractor();
        $this->fullyCompliant($contractor['uuid']);

        $result = $this->service()->mayWork($this->companyId, $contractor['uuid'], $this->required);

        $this->assertTrue($result['allowed']);
        $this->assertSame('COMPLIANT', $result['status']);
        $this->assertSame([], $result['reasons']);
    }

    public function test_an_expired_document_blocks_and_says_which_one(): void
    {
        $contractor = $this->contractor();
        $this->fileWith($contractor['uuid'], [
            'PUBLIC_LIABILITY' => Carbon::today()->addYear()->toDateString(),
            'SAFETY_FILE' => Carbon::today()->addYear()->toDateString(),
            'COIDA_LETTER' => Carbon::today()->subDays(10)->toDateString(),   // lapsed, not renewed
        ]);

        $result = $this->service()->mayWork($this->companyId, $contractor['uuid'], $this->required);

        $this->assertFalse($result['allowed']);
        $this->assertSame('BLOCKED', $result['status']);
        $this->assertStringContainsString('COIDA letter of good standing', $result['reasons'][0]);
        $this->assertStringContainsString('10 days ago', $result['reasons'][0]);
    }

    public function test_a_document_expiring_soon_warns_without_blocking(): void
    {
        $contractor = $this->contractor();
        $this->fileWith($contractor['uuid'], [
            'PUBLIC_LIABILITY' => Carbon::today()->addYear()->toDateString(),
            'COIDA_LETTER' => Carbon::today()->addYear()->toDateString(),
            'SAFETY_FILE' => Carbon::today()->addDays(14)->toDateString(),   // due for renewal
        ]);

        $file = $this->service()->complianceFile($this->companyId, $contractor['uuid'], $this->required);

        $this->assertSame('EXPIRING', $file['status']);
        $this->assertSame([], $file['blocking']);
        $this->assertStringContainsString('expires in 14 days', $file['expiring'][0]['detail']);
    }

    public function test_a_renewed_certificate_supersedes_its_predecessor(): void
    {
        $contractor = $this->contractor();
        $this->fullyCompliant($contractor['uuid']);

        // The old one expired; the renewal runs to next year. The latest per type is what counts.
        $this->attach($contractor['uuid'], 'COIDA_LETTER', Carbon::today()->subDay()->toDateString());
        $this->attach($contractor['uuid'], 'COIDA_LETTER', Carbon::today()->addYear()->toDateString());

        $this->assertSame('COMPLIANT',
            $this->service()->complianceFile($this->companyId, $contractor['uuid'], $this->required)['status']);
    }

    public function test_a_document_with_no_expiry_never_blocks(): void
    {
        $contractor = $this->contractor();
        foreach ($this->required as $type) {
            $this->attach($contractor['uuid'], $type, null);
        }

        $this->assertSame('COMPLIANT',
            $this->service()->complianceFile($this->companyId, $contractor['uuid'], $this->required)['status']);
    }

    // ── Override ────────────────────────────────────────────────────────────────────────────────

    public function test_an_override_admits_a_contractor_but_never_reads_as_compliant(): void
    {
        $contractor = $this->contractor();
        $this->attach($contractor['uuid'], 'PUBLIC_LIABILITY', Carbon::today()->addYear()->toDateString());

        $this->service()->overrideAccess($this->companyId, $contractor['uuid'],
            'Emergency repair; letter of good standing confirmed by phone with the Commissioner.',
            Carbon::today()->addDays(3)->toDateString(), $this->userId);

        $result = $this->service()->mayWork($this->companyId, $contractor['uuid'], $this->required);

        $this->assertTrue($result['allowed']);
        // Deliberately EXPIRING, not COMPLIANT: the file is still short and the dashboard must say so.
        $this->assertSame('EXPIRING', $result['status']);
    }

    public function test_an_expired_override_stops_admitting_the_contractor(): void
    {
        /*
         * An override that outlives its own end date is the one failure this feature cannot
         * have, and it is the failure the old implementation had: nothing ever switched the
         * flag off, so the only expiry was a read-time comparison the cached access status did
         * not make.
         *
         * Granted with a real window and then travelled past, rather than back-dated — an
         * override that starts already expired is now refused outright, which is the other half
         * of the same rule.
         */
        $contractor = $this->contractor();

        $this->service()->overrideAccess($this->companyId, $contractor['uuid'],
            'Temporary, pending the letter of good standing.',
            Carbon::today()->addDay()->toDateString(), $this->userId);

        $this->assertTrue(
            $this->service()->mayWork($this->companyId, $contractor['uuid'], $this->required)['allowed']
        );

        $this->travelTo(Carbon::now()->addDays(3));

        $this->assertFalse(
            $this->service()->mayWork($this->companyId, $contractor['uuid'], $this->required)['allowed'],
            'An override went on admitting a contractor after its window closed.');
    }

    public function test_an_override_that_would_already_be_over_is_refused(): void
    {
        // Back-dating one is either a mistake or a way of writing a record that looks like
        // authority for something that already happened. Neither is worth supporting.
        $contractor = $this->contractor();

        $this->expectExceptionMessageMatches('/end after it starts/i');

        $this->service()->overrideAccess($this->companyId, $contractor['uuid'],
            'Backdated, which should not be possible.',
            Carbon::today()->subDay()->toDateString(), $this->userId);
    }

    public function test_an_override_without_a_reason_is_refused(): void
    {
        $contractor = $this->contractor();

        $this->expectExceptionMessageMatches('/needs a reason/');
        $this->service()->overrideAccess($this->companyId, $contractor['uuid'], '   ', null, $this->userId);
    }

    public function test_the_override_reason_reaches_the_audit_trail(): void
    {
        $contractor = $this->contractor();

        $this->service()->overrideAccess($this->companyId, $contractor['uuid'],
            'Plant breakdown, single shift only.', null, $this->userId);

        $this->assertStringContainsString('Plant breakdown', (string) DB::table('sheq_audit_log')
            ->where('subject_type', 'Contractor')->where('subject_id', $contractor['id'])
            ->where('action', 'access_override.granted')->value('reason'));
    }

    // ── Statistics (SHEQ-CON-4) ─────────────────────────────────────────────────────────────────

    public function test_incidents_roll_up_separately_and_in_aggregate(): void
    {
        // The s24 annual return needs the combined figure; the host needs the split to know whose
        // people are getting hurt.
        $reef = $this->contractor('Reef Scaffolding');
        $delta = $this->contractor('Delta Electrical');

        $incident = function (?int $contractorId, string $outcome) {
            DB::table('sheq_incidents')->insert([
                'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
                'reference' => 'INC-'.Str::random(8), 'site_id' => $this->siteId,
                'occurred_at' => Carbon::create(2026, 5, 10), 'reported_at' => Carbon::create(2026, 5, 10),
                'incident_type' => 'INJURY', 'description' => 'x', 'outcome' => $outcome,
                'treatment_level' => $outcome, 'is_reportable' => $outcome === 'FATALITY',
                'days_lost' => $outcome === 'LOST_TIME' ? 4 : 0,
                'contractor_id' => $contractorId, 'status' => 'REPORTED',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        };

        $incident(null, 'LOST_TIME');                 // host employee
        $incident($reef['id'], 'LOST_TIME');          // contractor
        $incident($reef['id'], 'FATALITY');           // contractor
        $incident($delta['id'], 'MEDICAL_TREATMENT'); // contractor

        $rollUp = $this->service()->incidentRollUp(
            $this->companyId, Carbon::create(2026, 5, 1), Carbon::create(2026, 5, 31), $this->siteId
        );

        $this->assertSame(1, $rollUp['employees']['total']);
        $this->assertSame(3, $rollUp['contractors']['total']);
        $this->assertSame(4, $rollUp['combined']['total']);

        $this->assertSame(0, $rollUp['employees']['fatalities']);
        $this->assertSame(1, $rollUp['contractors']['fatalities']);
        $this->assertSame(1, $rollUp['combined']['fatalities']);

        $this->assertSame(4, $rollUp['employees']['days_lost']);
        $this->assertSame(4, $rollUp['contractors']['days_lost']);
        $this->assertSame(8, $rollUp['combined']['days_lost']);

        $this->assertSame('Reef Scaffolding', $rollUp['by_contractor'][0]['contractor']);
        $this->assertSame(2, $rollUp['by_contractor'][0]['incidents']);
    }

    // ── Renewal reminders ───────────────────────────────────────────────────────────────────────

    public function test_documents_expiring_within_the_window_are_listed_with_a_contact(): void
    {
        $contractor = $this->contractor();
        $this->attach($contractor['uuid'], 'PUBLIC_LIABILITY', Carbon::today()->addDays(20)->toDateString());
        $this->attach($contractor['uuid'], 'TAX_CLEARANCE', Carbon::today()->addDays(200)->toDateString());

        $due = $this->service()->expiringDocuments($this->companyId, withinDays: 30);
        $types = array_column($due, 'document_type');

        $this->assertContains('PUBLIC_LIABILITY', $types);
        $this->assertNotContains('TAX_CLEARANCE', $types);
        $this->assertSame('ops@reef.test', $due[0]['contact_email'],
            'The reminder needs somewhere to go — the contractor renews their own paperwork.');
    }
}
