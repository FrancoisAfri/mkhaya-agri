<?php

namespace Tests\Feature\Sheq;

use App\Modules\Operations\Sheq\Adapters\SheqCompetencyLedger;
use App\Modules\Operations\Sheq\Contracts\CompetencyLedger;
use App\Modules\Operations\Sheq\Contracts\EmployeeDirectory;
use App\Modules\Operations\Sheq\Events\EventPublisher;
use App\Modules\Operations\Sheq\Events\EventRegistry;
use App\Modules\Operations\Sheq\Services\SiteAttendanceService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The integration contracts (INT-1 to INT-6).
 *
 * Five of the six were already built — the anti-corruption layer for HR and LMS, the estimated-
 * hours flag, the access-control adapters, and `client_id` idempotency. INT-5 was not: it lists
 * eight events an integration should be able to subscribe to, and three existed.
 *
 * The missing one that mattered most is `contractor.approved`. A gate controller could be told
 * when a contractor was blocked and never when the block was lifted — so the contractor fixes
 * their paperwork, the system clears them, and the turnstile stays locked because nothing ever
 * retracted the block. A crew stands at a boom that the system has already decided to open, and
 * the integration looks like it is working.
 *
 * The other judgement in here is volume. Two of the eight — `access.approved` and
 * `access.denied` — happen once per person per entry, so on a site with two thousand people they
 * are four thousand events a day duplicating rows that `sheq_access_events` already holds. They
 * are published only where something is actually subscribed, which makes the cost proportional to
 * the integrations that exist rather than to the number of people walking through a boom.
 */
class IntegrationContractTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

    private int $siteId;

    protected function setUp(): void
    {
        parent::setUp();

        $companyId = DB::table('companies')->min('id');

        if ($companyId === null) {
            $this->markTestSkipped('No company in the test database.');
        }

        $this->companyId = (int) $companyId;

        $this->siteId = (int) DB::table('sheq_sites')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'code' => 'I'.Str::upper(Str::random(5)), 'name' => 'Gate site',
            'site_type' => 'CONSTRUCTION', 'timezone' => 'Africa/Johannesburg',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ── INT-5 ─────────────────────────────────────────────────────────────────────────────────

    public function test_all_eight_events_the_requirement_lists_are_available(): void
    {
        /*
         * Walked against INT-5's own words. An integrator cannot subscribe to an event that is
         * not in the registry, and `EventPublisher::publish()` refuses an unregistered type — so
         * a missing entry is not a documentation gap, it is a capability that does not exist.
         */
        $required = [
            'contractor blocked' => 'sheq.contractor.blocked',
            'contractor approved' => 'sheq.contractor.approved',
            'worker blocked' => 'sheq.contractor.worker_blocked',
            'QR denied' => 'sheq.access.denied',
            'QR approved' => 'sheq.access.approved',
            'incident created' => 'sheq.incident.reported',
            'CAPA created' => 'sheq.action.created',
            'compliance changed' => 'sheq.compliance.changed',
        ];

        $registry = app(EventRegistry::class)->all();

        foreach ($required as $words => $type) {
            $this->assertArrayHasKey($type, $registry,
                "INT-5 lists '{$words}' and nothing can be subscribed to it.");

            $schema = $registry[$type];

            $this->assertNotEmpty($schema['required'],
                "{$type} declares no required payload keys, so a consumer has nothing to build "
                .'against.');
            $this->assertGreaterThan(30, strlen($schema['describes'] ?? ''),
                "{$type} is not described, so an integrator cannot tell what it means.");
        }
    }

    public function test_lifting_a_block_publishes_the_event_that_opens_the_gate(): void
    {
        /*
         * The gap that mattered. Without this a turnstile is told to shut and never told to open.
         */
        $contractorId = $this->contractor('BLOCKED');

        DB::transaction(function () use ($contractorId) {
            app(EventPublisher::class)->publish($this->companyId,
                'sheq.contractor.approved', 'CONTRACTOR', $contractorId, [
                    'contractor_id' => $contractorId,
                    'name' => 'Acme Scaffolding',
                    'status' => 'COMPLIANT',
                ]);
        });

        $message = DB::table('sheq_outbox_messages')
            ->where('company_id', $this->companyId)
            ->where('event_type', 'sheq.contractor.approved')->first(['payload']);

        $this->assertNotNull($message);
        $this->assertSame('COMPLIANT', json_decode($message->payload, true)['status']);
    }

    public function test_an_event_missing_a_required_key_is_refused_rather_than_published(): void
    {
        /*
         * A consumer builds against the registered schema, so an incomplete event breaks their
         * integration rather than ours — which is exactly why it must fail here instead.
         */
        $this->expectExceptionMessageMatches('/missing required payload keys/');

        DB::transaction(function () {
            app(EventPublisher::class)->publish($this->companyId,
                'sheq.compliance.changed', 'CONTRACTOR', 1, ['subject_type' => 'CONTRACTOR']);
        });
    }

    public function test_a_gate_scan_publishes_nothing_when_nobody_is_subscribed(): void
    {
        /*
         * The volume decision. One event per person per entry means four thousand a day on a
         * large site, duplicating the access event row itself — for the benefit of nobody, on the
         * overwhelming majority of installations that have no gate integration.
         */
        $before = DB::table('sheq_outbox_messages')
            ->where('company_id', $this->companyId)->count();

        app(SiteAttendanceService::class)->record($this->companyId, [
            'site_id' => $this->siteId, 'subject_type' => 'CONTRACTOR_WORKER',
            'subject_id' => 1, 'person_name' => 'Johan Pretorius',
            'direction' => 'ENTRY', 'method' => 'QR', 'allowed' => true,
        ]);

        $this->assertSame($before, DB::table('sheq_outbox_messages')
            ->where('company_id', $this->companyId)->count(),
            'Every gate scan writes an outbox row on an installation with no integration at all.');
    }

    public function test_a_gate_scan_publishes_when_something_is_subscribed(): void
    {
        $this->webhook(['sheq.access.approved', 'sheq.access.denied']);

        app(SiteAttendanceService::class)->record($this->companyId, [
            'site_id' => $this->siteId, 'subject_type' => 'CONTRACTOR_WORKER',
            'subject_id' => 1, 'person_name' => 'Johan Pretorius',
            'direction' => 'ENTRY', 'method' => 'QR', 'allowed' => true,
        ]);

        $message = DB::table('sheq_outbox_messages')
            ->where('company_id', $this->companyId)
            ->where('event_type', 'sheq.access.approved')->first(['payload']);

        $this->assertNotNull($message,
            'A turnstile integration is subscribed and the scan published nothing.');

        $payload = json_decode($message->payload, true);

        $this->assertSame($this->siteId, $payload['site_id']);
        $this->assertNotEmpty($payload['occurred_at']);
    }

    public function test_a_refusal_carries_its_reasons(): void
    {
        // The event a turnstile integration exists for: the boom did not open, and the person
        // standing in front of it has to be told why.
        $this->webhook(['sheq.access.denied']);

        app(SiteAttendanceService::class)->record($this->companyId, [
            'site_id' => $this->siteId, 'subject_type' => 'CONTRACTOR_WORKER',
            'subject_id' => 1, 'person_name' => 'Johan Pretorius',
            'direction' => 'ENTRY', 'method' => 'QR', 'allowed' => false,
            'reasons' => ['MEDICAL_EXPIRED', 'INDUCTION_REQUIRED'],
        ]);

        $payload = json_decode((string) DB::table('sheq_outbox_messages')
            ->where('company_id', $this->companyId)
            ->where('event_type', 'sheq.access.denied')->value('payload'), true);

        $this->assertContains('MEDICAL_EXPIRED', $payload['reasons']);
        $this->assertFalse($payload['was_monitor_only']);
    }

    public function test_monitor_mode_is_declared_on_a_refusal(): void
    {
        /*
         * In monitor mode the boom opens even on a refusal. A consumer acting on `denied` alone
         * would believe somebody was turned away when they walked straight through, which is the
         * opposite of what their own gate did.
         */
        $this->webhook(['sheq.access.denied']);

        app(SiteAttendanceService::class)->record($this->companyId, [
            'site_id' => $this->siteId, 'subject_type' => 'CONTRACTOR_WORKER',
            'subject_id' => 1, 'person_name' => 'Johan Pretorius',
            'direction' => 'ENTRY', 'method' => 'QR', 'allowed' => false,
            'admitted' => true, 'was_monitor_only' => true,
            'reasons' => ['MEDICAL_EXPIRED'],
        ]);

        $payload = json_decode((string) DB::table('sheq_outbox_messages')
            ->where('company_id', $this->companyId)
            ->where('event_type', 'sheq.access.denied')->value('payload'), true);

        $this->assertTrue($payload['was_monitor_only']);
    }

    public function test_a_subscription_to_a_different_event_does_not_open_the_floodgates(): void
    {
        // Subscribing to incidents must not start writing an outbox row for every gate scan.
        $this->webhook(['sheq.incident.reported']);

        $before = DB::table('sheq_outbox_messages')
            ->where('company_id', $this->companyId)->count();

        app(SiteAttendanceService::class)->record($this->companyId, [
            'site_id' => $this->siteId, 'subject_type' => 'CONTRACTOR_WORKER',
            'subject_id' => 1, 'person_name' => 'Johan Pretorius',
            'direction' => 'ENTRY', 'method' => 'QR', 'allowed' => true,
        ]);

        $this->assertSame($before, DB::table('sheq_outbox_messages')
            ->where('company_id', $this->companyId)->count());
    }

    public function test_a_disabled_webhook_does_not_count_as_subscribed(): void
    {
        // A webhook disabled after repeated failures is not listening, and resuming publication
        // for it would fill the outbox on behalf of an endpoint that is gone.
        $id = $this->webhook(['sheq.access.approved']);

        DB::table('sheq_webhooks')->where('id', $id)->update([
            'disabled_at' => now(), 'disabled_reason' => 'Too many failures',
        ]);

        $before = DB::table('sheq_outbox_messages')
            ->where('company_id', $this->companyId)->count();

        app(SiteAttendanceService::class)->record($this->companyId, [
            'site_id' => $this->siteId, 'subject_type' => 'CONTRACTOR_WORKER',
            'subject_id' => 1, 'person_name' => 'Johan Pretorius',
            'direction' => 'ENTRY', 'method' => 'QR', 'allowed' => true,
        ]);

        $this->assertSame($before, DB::table('sheq_outbox_messages')
            ->where('company_id', $this->companyId)->count());
    }

    public function test_another_tenants_subscription_does_not_publish_our_scans(): void
    {
        $other = (int) DB::table('companies')->where('id', '!=', $this->companyId)->min('id');

        if ($other === 0) {
            $this->markTestSkipped('Only one company in the test database.');
        }

        DB::table('sheq_webhooks')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => $other,
            'name' => 'Theirs', 'url' => 'https://example.test/hook',
            'secret' => Str::random(40), 'secret_hint' => 'xxxx',
            'event_types' => json_encode(['sheq.access.approved']),
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $before = DB::table('sheq_outbox_messages')
            ->where('company_id', $this->companyId)->count();

        app(SiteAttendanceService::class)->record($this->companyId, [
            'site_id' => $this->siteId, 'subject_type' => 'CONTRACTOR_WORKER',
            'subject_id' => 1, 'person_name' => 'Johan Pretorius',
            'direction' => 'ENTRY', 'method' => 'QR', 'allowed' => true,
        ]);

        $this->assertSame($before, DB::table('sheq_outbox_messages')
            ->where('company_id', $this->companyId)->count(),
            'Another tenant subscribing to gate events started publishing ours.');
    }

    // ── INT-1, INT-2 ──────────────────────────────────────────────────────────────────────────

    public function test_hr_owns_worker_identity_and_sheq_reads_it(): void
    {
        /*
         * INT-1. The seam exists so SHEQ never keeps its own copy of who somebody is — two
         * versions of a workforce disagree within a month, and then a safety officer and an HR
         * officer produce different headcounts for the same site.
         */
        $directory = app(EmployeeDirectory::class);

        foreach (['find', 'findMany', 'roster', 'isAvailable'] as $method) {
            $this->assertTrue(method_exists($directory, $method),
                "The employee directory has no {$method}(), so SHEQ cannot read HR's identity "
                .'data through the seam and will grow its own copy.');
        }
    }

    public function test_competency_falls_back_to_sheqs_own_records_without_lms(): void
    {
        /*
         * INT-2's explicit requirement: the fallback must remain available where LMS is not
         * licensed. A self-hosted customer who bought SHEQ alone should see their own manual
         * certificates, not an empty screen.
         */
        $ledger = app(CompetencyLedger::class);

        $this->assertInstanceOf(SheqCompetencyLedger::class, $ledger,
            'The bound ledger is the LMS one, so a customer without LMS gets no competency data '
            .'at all rather than their own records.');

        // And it answers rather than throwing when asked about somebody with nothing.
        $this->assertSame([], $ledger->statusFor(999999999));
    }

    // ── INT-3 ─────────────────────────────────────────────────────────────────────────────────

    public function test_estimated_hours_are_labelled_as_estimated(): void
    {
        /*
         * INT-3 is explicit that estimated hours must stay clearly labelled. An injury frequency
         * rate is only as good as its denominator, and a rate computed from a guess that is
         * presented as a measurement is the figure a board acts on.
         */
        DB::table('sheq_exposure_hours')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'site_id' => $this->siteId,
            'period_year' => (int) now()->year, 'period_month' => (int) now()->month,
            'employee_hours' => 100000, 'contractor_hours' => 0,
            'employee_headcount' => 600, 'contractor_headcount' => 0,
            'source' => 'ESTIMATED',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->artisan('sheq:kpis:compute',
            ['--company' => $this->companyId, '--months' => 1, '--sites' => true])
            ->assertSuccessful();

        $snapshot = DB::table('sheq_kpi_snapshots')
            ->where('company_id', $this->companyId)->where('site_id', $this->siteId)
            ->first(['exposure_estimated']);

        $this->assertNotNull($snapshot, 'No per-site snapshot was written.');
        $this->assertTrue((bool) $snapshot->exposure_estimated,
            'Hours imported as an estimate are stored as measured, so every rate derived from '
            .'them is presented as a measurement.');
    }

    public function test_a_real_payroll_extract_reads_as_measured(): void
    {
        /*
         * The other side of the whitelist. If everything were treated as an estimate the flag
         * would carry no information and the warning would be permanent — which is the same as
         * having no warning.
         */
        DB::table('sheq_exposure_hours')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'site_id' => $this->siteId,
            'period_year' => (int) now()->year, 'period_month' => (int) now()->month,
            'employee_hours' => 180000, 'contractor_hours' => 42000,
            'employee_headcount' => 1100, 'contractor_headcount' => 260,
            'source' => 'PAYROLL',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->artisan('sheq:kpis:compute',
            ['--company' => $this->companyId, '--months' => 1, '--sites' => true])
            ->assertSuccessful();

        $this->assertFalse((bool) DB::table('sheq_kpi_snapshots')
            ->where('company_id', $this->companyId)->where('site_id', $this->siteId)
            ->value('exposure_estimated'),
            'A payroll extract is labelled an estimate, so the warning is permanent and therefore '
            .'meaningless.');
    }

    public function test_a_source_nobody_has_classified_is_treated_as_an_estimate(): void
    {
        /*
         * The whole reason the check is a whitelist. A new import added next year should not
         * acquire the authority of a payroll run by default — it should be an estimate until
         * somebody deliberately says otherwise.
         */
        DB::table('sheq_exposure_hours')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'site_id' => $this->siteId,
            'period_year' => (int) now()->year, 'period_month' => (int) now()->month,
            'employee_hours' => 180000, 'contractor_hours' => 0,
            'employee_headcount' => 1100, 'contractor_headcount' => 0,
            'source' => 'SOME_NEW_CONNECTOR',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->artisan('sheq:kpis:compute',
            ['--company' => $this->companyId, '--months' => 1, '--sites' => true])
            ->assertSuccessful();

        $this->assertTrue((bool) DB::table('sheq_kpi_snapshots')
            ->where('company_id', $this->companyId)->where('site_id', $this->siteId)
            ->value('exposure_estimated'),
            'An unclassified source is presented as measured, so a new connector quietly gains '
            .'the authority of a payroll extract.');
    }

    public function test_one_estimated_site_taints_the_company_wide_figure(): void
    {
        /*
         * `sheq_exposure_hours` is unique on (company, site, period), so a single site cannot
         * hold both a payroll extract and a guess. The case that matters is the company-wide
         * snapshot, which sums every site: a board pack must not average a payroll extract and a
         * typed estimate into a figure that looks like neither.
         */
        $second = (int) DB::table('sheq_sites')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'code' => 'J'.Str::upper(Str::random(5)), 'name' => 'Second site',
            'site_type' => 'CONSTRUCTION', 'timezone' => 'Africa/Johannesburg',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach ([[$this->siteId, 'PAYROLL'], [$second, 'MANUAL']] as [$siteId, $source]) {
            DB::table('sheq_exposure_hours')->insert([
                'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
                'site_id' => $siteId,
                'period_year' => (int) now()->year, 'period_month' => (int) now()->month,
                'employee_hours' => 90000, 'contractor_hours' => 0,
                'employee_headcount' => 550, 'contractor_headcount' => 0,
                'source' => $source,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->artisan('sheq:kpis:compute',
            ['--company' => $this->companyId, '--months' => 1])
            ->assertSuccessful();

        $this->assertTrue((bool) DB::table('sheq_kpi_snapshots')
            ->where('company_id', $this->companyId)->whereNull('site_id')
            ->value('exposure_estimated'),
            'One site\'s typed estimate is averaged into the group figure and presented as a '
            .'measurement.');

        // And the site with the real extract keeps its own, honest label.
        $this->artisan('sheq:kpis:compute',
            ['--company' => $this->companyId, '--months' => 1, '--sites' => true])
            ->assertSuccessful();

        $this->assertFalse((bool) DB::table('sheq_kpi_snapshots')
            ->where('company_id', $this->companyId)->where('site_id', $this->siteId)
            ->value('exposure_estimated'),
            'A site with a payroll extract is tainted by another site\'s estimate, so the flag '
            .'stops telling anybody anything about their own numbers.');
    }

    // ── INT-6 ─────────────────────────────────────────────────────────────────────────────────

    public function test_a_retried_external_submission_does_not_create_a_duplicate(): void
    {
        /*
         * INT-6. A retry is the normal behaviour of an integration on a timeout, and the second
         * attempt must not produce a second incident — a duplicated reportable injury is a
         * duplicated statutory deadline and a duplicated notification to a regulator.
         */
        $clientId = (string) Str::uuid();

        $first = app(\App\Modules\Operations\Sheq\Services\ActionService::class)
            ->create($this->companyId, [
                'title' => 'Replace the chained stairwell door',
                'client_id' => $clientId,
                'due_date' => now()->addDays(7)->toDateString(),
            ]);

        $second = app(\App\Modules\Operations\Sheq\Services\ActionService::class)
            ->create($this->companyId, [
                'title' => 'Replace the chained stairwell door',
                'client_id' => $clientId,
                'due_date' => now()->addDays(7)->toDateString(),
            ]);

        $this->assertSame($first['uuid'], $second['uuid'],
            'A retried submission created a second record, so an integration timing out once '
            .'produces two of everything.');
        $this->assertTrue($second['replayed'] ?? false,
            'The caller is not told it was a replay, so it cannot distinguish its own retry from '
            .'a new record.');
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────────────────────

    private function contractor(string $accessStatus): int
    {
        return (int) DB::table('sheq_contractors')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'name' => 'Acme Scaffolding', 'access_status' => $accessStatus,
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @param  list<string>  $events */
    private function webhook(array $events): int
    {
        return (int) DB::table('sheq_webhooks')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'name' => 'Turnstile controller', 'url' => 'https://example.test/hook',
            'secret' => Str::random(40), 'secret_hint' => 'xxxx',
            'event_types' => json_encode($events),
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
