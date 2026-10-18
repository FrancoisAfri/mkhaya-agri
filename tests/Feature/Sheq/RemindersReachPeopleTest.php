<?php

namespace Tests\Feature\Sheq;

use App\Modules\Operations\Sheq\Contracts\NotificationTransport;
use App\Modules\Operations\Sheq\Services\CompetencyService;
use App\Modules\Operations\Sheq\Services\DocumentService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Reminders that reach a person, not a webhook.
 *
 * `sheq:events:sweep` already announced a lapsing competency and a stale procedure into the event
 * outbox, so an integration could read them. No person was ever told: `NotificationRouter` is the
 * only thing that addresses people and it is driven by the incident routing matrix, which cannot
 * express "tell the holder of this certificate". Three service methods existed for exactly that
 * and nothing called them.
 *
 * What is defended here is the whole path — the ladder picks the right day, the right person is
 * addressed, the wording is about a certificate rather than an incident, and a second run queues
 * nothing twice.
 */
class RemindersReachPeopleTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

    private int $userId;

    private int $personId;

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
            'uuid' => (string) Str::uuid(), 'email' => 'rem-'.Str::random(8).'@example.test',
            'password' => bcrypt('secret'), 'company_id' => $this->companyId, 'status' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->personId = (int) DB::table('hr_people')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'user_id' => $this->userId, 'first_name' => 'Sipho', 'surname' => 'Ndlovu',
            'status' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function sweep(): void
    {
        $this->artisan('sheq:reminders:send', ['--company' => $this->companyId])
            ->assertExitCode(0);
    }

    /** A competency record expiring exactly $days from today. */
    private function lapsingIn(int $days, bool $blocksWork = false): void
    {
        $competencyId = DB::table('sheq_competencies')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'code' => 'FA'.Str::random(5), 'title' => 'First aid level 1',
            'competency_type' => 'TRAINING', 'validity_months' => 24,
            'blocks_work_when_expired' => $blocksWork, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('sheq_competency_records')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'hr_person_id' => $this->personId, 'competency_id' => $competencyId,
            'status' => 'COMPLIANT', 'achieved_at' => Carbon::today()->subYear()->toDateString(),
            'expires_at' => Carbon::today()->addDays($days)->toDateString(),
            'source' => 'MANUAL', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function reminders(): \Illuminate\Support\Collection
    {
        return DB::table('sheq_notification_dispatches')
            ->where('company_id', $this->companyId)
            ->where('trigger_reason', 'REMINDER')
            ->get();
    }

    public function test_a_certificate_lapsing_in_thirty_days_reminds_its_holder(): void
    {
        $this->lapsingIn(30);

        $this->sweep();

        $reminder = $this->reminders()->firstWhere('event_type', 'sheq.competency.expiring');

        $this->assertNotNull($reminder, 'The holder must be told before the ticket lapses.');
        $this->assertSame($this->userId, (int) $reminder->recipient_user_id);
        $this->assertSame('EMAIL', $reminder->channel);
        $this->assertSame('PENDING', $reminder->status);

        $payload = json_decode((string) $reminder->payload, true);
        $this->assertStringContainsString('First aid', (string) $payload['title']);
        $this->assertStringContainsString('30 days', (string) $payload['body']);
    }

    public function test_a_day_that_is_not_a_ladder_gate_sends_nothing(): void
    {
        // The ladder is 90/60/30/14/7/0. Reminding every single day is how a mailbox filter gets
        // written, and then the 7-day warning is never read either.
        $this->lapsingIn(45);

        $this->sweep();

        $this->assertCount(0, $this->reminders());
    }

    public function test_the_wording_says_it_blocks_work_when_it_does(): void
    {
        $this->lapsingIn(7, blocksWork: true);

        $this->sweep();

        $reminder = $this->reminders()->firstWhere('event_type', 'sheq.competency.expiring');
        $payload = json_decode((string) $reminder->payload, true);

        $this->assertStringContainsString('blocks work', (string) $payload['body']);
        // Seven days out on something that stops a shift is not routine traffic.
        $this->assertSame('HIGH', $reminder->severity);
    }

    public function test_running_twice_in_a_day_does_not_remind_twice(): void
    {
        $this->lapsingIn(30);

        $this->sweep();
        $this->sweep();

        $this->assertCount(1, $this->reminders());
    }

    public function test_each_rung_of_the_ladder_is_its_own_reminder(): void
    {
        $this->lapsingIn(30);
        $this->sweep();

        // The same record, a rung further down. The gate is in the dedupe key, so this is a second
        // reminder rather than a suppressed duplicate.
        DB::table('sheq_competency_records')
            ->where('company_id', $this->companyId)->where('hr_person_id', $this->personId)
            ->update(['expires_at' => Carbon::today()->addDays(7)->toDateString()]);

        $this->sweep();

        $this->assertCount(2, $this->reminders());
    }

    public function test_a_holder_with_no_account_is_skipped_rather_than_guessed_at(): void
    {
        DB::table('hr_people')->where('id', $this->personId)->update(['user_id' => null]);
        $this->lapsingIn(30);

        $this->sweep();

        // Nothing to send to. The register still shows the lapse; inventing a recipient would hide
        // that nobody was reachable.
        $this->assertCount(0, $this->reminders());
    }

    public function test_a_stale_procedure_reminds_its_owner(): void
    {
        $service = app(DocumentService::class);

        $document = $service->create($this->companyId, [
            'doc_number' => 'DOC-'.Str::random(6), 'title' => 'Working at height',
            'doc_type' => 'PROCEDURE', 'owner_id' => $this->userId,
            'review_due' => Carbon::today()->subDays(10)->toDateString(),
        ], $this->userId);

        $service->draftVersion($this->companyId, $document['uuid'], [], $this->userId);
        $service->publish($this->companyId, $document['uuid'], 1, $this->userId);

        $this->sweep();

        $reminder = $this->reminders()->firstWhere('event_type', 'sheq.document.review_due');

        $this->assertNotNull($reminder, 'A procedure past review is still in force; say so.');
        $this->assertSame($this->userId, (int) $reminder->recipient_user_id);
        $this->assertGreaterThan(0, (int) $reminder->subject_id);
    }

    public function test_two_documents_sharing_a_review_date_each_get_a_reminder(): void
    {
        $service = app(DocumentService::class);

        foreach (['A', 'B'] as $which) {
            $document = $service->create($this->companyId, [
                'doc_number' => 'DOC-'.$which.Str::random(5), 'title' => 'Procedure '.$which,
                'doc_type' => 'PROCEDURE', 'owner_id' => $this->userId,
                'review_due' => Carbon::today()->subDays(3)->toDateString(),
            ], $this->userId);

            $service->draftVersion($this->companyId, $document['uuid'], [], $this->userId);
            $service->publish($this->companyId, $document['uuid'], 1, $this->userId);
        }

        $this->sweep();

        // The dedupe key is scoped by subject id. Keyed on the review date alone, the second
        // document's owner would silently never hear about it.
        $this->assertCount(2, $this->reminders()->where('event_type', 'sheq.document.review_due'));
    }

    public function test_a_supplier_sitting_on_an_invitation_is_chased(): void
    {
        DB::table('sheq_supplier_assessments')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'supplier_name' => 'Acme Scaffolding', 'contact_email' => 'acme@example.test',
            'status' => 'INVITED', 'risk_category' => 'HIGH',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->sweep();

        $reminder = $this->reminders()->firstWhere('event_type', 'sheq.supplier.evidence_outstanding');

        $this->assertNotNull($reminder, 'A supplier nobody chases never submits.');
        $this->assertSame('acme@example.test', $reminder->recipient_address);
        $this->assertNull($reminder->recipient_user_id);
    }

    public function test_a_reminder_is_not_sent_dressed_as_an_incident(): void
    {
        // Blocks-work, seven days out: the composed body carries a paragraph that no arrangement
        // of incident fields could produce.
        $this->lapsingIn(7, blocksWork: true);
        $this->sweep();

        $captured = [];

        $this->app->bind(NotificationTransport::class, function () use (&$captured) {
            return new class($captured) implements NotificationTransport
            {
                public function __construct(private &$captured) {}

                public function supports(string $channel): bool
                {
                    return true;
                }

                public function send(array $message): bool
                {
                    $this->captured[] = $message;

                    return true;
                }
            };
        });

        $this->artisan('sheq:notifications:send', ['--company' => $this->companyId])
            ->assertExitCode(0);

        $this->assertNotEmpty($captured, 'The reminder must leave through the normal sender.');

        $message = $captured[0];

        // "Reportable incident — immediate action required" for an expiring first-aid ticket is
        // both false and alarming, and a system that cries wolf is one people stop reading.
        $this->assertStringNotContainsStringIgnoringCase('incident', $message['subject']);
        $this->assertStringNotContainsStringIgnoringCase('an incident has been reported',
            $message['body']);
        $this->assertStringContainsString('First aid', $message['subject']);

        /*
         * The body the reminder composed, delivered whole.
         *
         * The sender's own body builder assembles incident fields — type, statutory category, the
         * role you are receiving it as — and would drop every sentence that makes this message
         * useful: when the ticket expires, and that it stops the holder working. A reminder whose
         * detail is thrown away on the way out is not a reminder.
         */
        $this->assertStringContainsString('blocks work', $message['body']);
        $this->assertStringContainsString('expires in 7 days', $message['body']);
        $this->assertStringContainsString('issued a permit', $message['body']);
    }

    public function test_the_reminder_is_marked_sent_once_it_has_gone(): void
    {
        $this->lapsingIn(0);
        $this->sweep();

        $this->app->bind(NotificationTransport::class, fn () => new class implements NotificationTransport
        {
            public function supports(string $channel): bool
            {
                return true;
            }

            public function send(array $message): bool
            {
                return true;
            }
        });

        $this->artisan('sheq:notifications:send', ['--company' => $this->companyId]);

        $this->assertSame('SENT', $this->reminders()->first()->status);
    }

    public function test_competency_reminders_do_not_leak_across_tenants(): void
    {
        $other = (int) DB::table('companies')->insertGetId([
            'uuid' => (string) Str::uuid(), 'name' => 'Other Employer',
            'slug' => 'rem-other-'.Str::lower(Str::random(8)), 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->lapsingIn(30);

        $this->artisan('sheq:reminders:send', ['--company' => $other])->assertExitCode(0);

        // Run for the other company, this company's reminder must not exist — the pass is scoped
        // by tenant, which is what keeps RLS active in a cron.
        $this->assertCount(0, $this->reminders());
    }
}
