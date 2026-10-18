<?php

namespace Tests\Feature\Sheq;

use App\Core\User\Models\User;
use App\Modules\Operations\Sheq\Compliance\ReasonCode;
use App\Modules\Operations\Sheq\Services\ContractorNotifier;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The expiry and escalation automation (AUTO-1 to AUTO-6).
 *
 * AUTO-1 names seven things that expire. Six of them were being warned about and one was not, and
 * the test below that found it is the shape that matters here: it walks the requirement's own list
 * and asserts that something queues a notice for each kind, rather than asserting that the six
 * known ones work. A sweep with a hard-coded list of kinds drops the ones added after it was
 * written, and nothing about the output looks wrong — the command reports a success and a count,
 * and the count is simply lower than it should be.
 *
 * The one that was missing was medicals, which is also the worst of the seven: an expired medical
 * is refused outright by the compliance engine and no override reaches it, and the renewal needs
 * an occupational-health appointment booked weeks ahead. So the behaviour was that somebody's
 * medical lapsed in silence and they found out at a boom gate on a Monday, with no way to fix it
 * that day.
 *
 * AUTO-5 and AUTO-6 are the same property stated twice, and it is a property of the database
 * rather than of the command: a unique index on the dedupe key, with `insertOrIgnore` against it.
 * Tested by running the command twice and counting, because that is the only thing that would
 * actually catch a dedupe key accidentally made unique per run.
 */
class ExpiryAutomationTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

    private User $user;

    private int $personId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $companyId = DB::table('companies')->min('id');

        if ($companyId === null) {
            $this->markTestSkipped('No company in the test database.');
        }

        $this->companyId = (int) $companyId;
        $this->user = $this->userWith();
        $this->personId = $this->person('Sipho', 'Khumalo', $this->user->id);
    }

    /**
     * The seven kinds AUTO-1 names, each with the table the expiry lives in and a label.
     *
     * Kept here rather than derived from the command, deliberately: the point of this list is to
     * be the requirement, so that a kind the command stops covering fails rather than quietly
     * disappearing from both sides at once.
     *
     * Insurance and COID are contractor document types rather than separate mechanisms, so they
     * are asserted as document types that exist and are warned about by the document path.
     */
    public static function expiringKinds(): array
    {
        return [
            'contractor documents' => ['contractor_document'],
            'worker competencies' => ['worker_credential'],
            'medicals' => ['medical'],
            'inductions' => ['induction'],
            'insurance' => ['insurance'],
            'COID' => ['coid'],
            'permits' => ['permit'],
        ];
    }

    // ── AUTO-1 ────────────────────────────────────────────────────────────────────────────────

    #[DataProvider('expiringKinds')]
    public function test_every_kind_the_requirement_lists_is_warned_about(string $kind): void
    {
        $this->{'arrange'.str_replace(' ', '', ucwords(str_replace('_', ' ', $kind)))}();

        $before = $this->noticeCount();

        $this->artisan('sheq:reminders:send', ['--company' => $this->companyId])
            ->assertSuccessful();
        $this->artisan('sheq:events:sweep', ['--company' => $this->companyId])
            ->assertSuccessful();

        $this->assertGreaterThan($before, $this->noticeCount(),
            'AUTO-1 lists this kind of expiry and nothing announced it. Both commands report '
            .'success, so the only visible symptom is a count that is lower than it should be.');
    }

    public function test_a_medical_expiring_next_month_reaches_the_person_who_has_to_book_it(): void
    {
        $this->arrangeMedical();

        $this->artisan('sheq:reminders:send', ['--company' => $this->companyId])
            ->assertSuccessful();

        $row = DB::table('sheq_notification_dispatches')
            ->where('company_id', $this->companyId)
            ->where('subject_type', 'MEDICAL_RECORD')
            ->first(['recipient_address', 'payload', 'severity', 'event_type']);

        $this->assertNotNull($row, 'Nothing warns about a medical running out.');

        $this->assertSame($this->user->email, $row->recipient_address,
            'The notice goes somewhere other than the person who attends the appointment.');

        $payload = json_decode($row->payload, true);

        $this->assertStringContainsString('refused access', $payload['body'],
            'The notice does not say what happens when it expires, which is the reason to act '
            .'on it: an expired medical refuses access on its own.');
        $this->assertSame('sheq.medical.expiring', $row->event_type);
    }

    public function test_an_expired_medical_says_that_no_override_can_authorise_it(): void
    {
        $this->arrangeMedical(Carbon::today()->subDays(3));

        $this->artisan('sheq:reminders:send', ['--company' => $this->companyId])
            ->assertSuccessful();

        $row = DB::table('sheq_notification_dispatches')
            ->where('company_id', $this->companyId)
            ->where('subject_type', 'MEDICAL_RECORD')->first(['payload', 'severity', 'event_type']);

        $this->assertNotNull($row);
        $this->assertSame('sheq.medical.expired', $row->event_type,
            'A lapsed medical sends the same message as one that is merely approaching, so the '
            .'consequence that has already begun reads as a request to renew.');
        $this->assertSame('HIGH', $row->severity);

        $payload = json_decode($row->payload, true);

        $this->assertStringContainsString('no temporary override', $payload['body'],
            'Somebody will ask for an override. The notice should already have told them that '
            .'one cannot be granted for this.');
    }

    public function test_a_medical_that_lapsed_years_ago_is_not_dredged_up(): void
    {
        $this->arrangeMedical(Carbon::today()->subYears(2));

        $this->artisan('sheq:reminders:send', ['--company' => $this->companyId])
            ->assertSuccessful();

        $this->assertSame(0, DB::table('sheq_notification_dispatches')
            ->where('company_id', $this->companyId)
            ->where('subject_type', 'MEDICAL_RECORD')->count(),
            'Switching this on sends everybody a notice about every medical that has expired '
            .'since the module was installed, which is how a useful alert becomes noise.');
    }

    public function test_only_the_newest_medical_of_a_type_is_chased(): void
    {
        $typeId = $this->medicalType();

        // Three annual medicals. The two old ones have expired; the current one expires soon.
        $this->medicalRecord($typeId, Carbon::today()->subYears(2));
        $this->medicalRecord($typeId, Carbon::today()->subYear());
        $this->medicalRecord($typeId, Carbon::today()->addDays(30));

        $this->artisan('sheq:reminders:send', ['--company' => $this->companyId])
            ->assertSuccessful();

        $this->assertSame(1, DB::table('sheq_notification_dispatches')
            ->where('company_id', $this->companyId)
            ->where('subject_type', 'MEDICAL_RECORD')->count(),
            'Somebody with a few years of annual medicals is chased about the ones that expired '
            .'in previous years as well as the one that matters.');
    }

    public function test_a_person_with_no_account_does_not_crash_the_sweep(): void
    {
        // A medical against somebody who has no login. The register still shows the lapse; there
        // is simply nobody to send it to, and inventing a recipient would hide that.
        $orphan = $this->person('No', 'Account', null);
        $this->medicalRecord($this->medicalType(), Carbon::today()->addDays(10), $orphan);

        $this->artisan('sheq:reminders:send', ['--company' => $this->companyId])
            ->assertSuccessful();

        $this->assertSame(0, DB::table('sheq_notification_dispatches')
            ->where('company_id', $this->companyId)
            ->where('subject_type', 'MEDICAL_RECORD')->count());
    }

    // ── AUTO-5, AUTO-6 ────────────────────────────────────────────────────────────────────────

    public function test_running_the_sweep_again_sends_nothing_twice(): void
    {
        $this->arrangeMedical();
        $this->arrangeContractorDocument();
        $this->arrangePermit();

        $this->artisan('sheq:reminders:send', ['--company' => $this->companyId])->assertSuccessful();
        $this->artisan('sheq:events:sweep', ['--company' => $this->companyId])->assertSuccessful();

        $after = $this->dispatchCount();

        $this->assertGreaterThan(0, $after, 'Nothing was queued, so this proves nothing.');

        // Four more runs, which is what an hourly cron does in an afternoon.
        for ($i = 0; $i < 4; $i++) {
            $this->artisan('sheq:reminders:send', ['--company' => $this->companyId])
                ->assertSuccessful();
            $this->artisan('sheq:events:sweep', ['--company' => $this->companyId])
                ->assertSuccessful();
        }

        $this->assertSame($after, $this->dispatchCount(),
            'Repeated sweeps queue the same notice again. On an hourly schedule that is 24 '
            .'identical emails a day, which trains people to filter the one that matters.');
    }

    public function test_the_database_refuses_a_duplicate_dispatch_even_if_the_code_does_not(): void
    {
        /*
         * AUTO-5 and AUTO-6 are a property of the schema rather than of the command, because the
         * command is not the only thing that writes here. Asserted against the index directly, so
         * a future writer that forgets `insertOrIgnore` still cannot produce a duplicate.
         */
        $row = [
            'company_id' => $this->companyId, 'uuid' => (string) Str::uuid(),
            'trigger_reason' => 'REMINDER', 'event_type' => 'sheq.test.duplicate',
            'subject_type' => 'MEDICAL_RECORD', 'subject_id' => 1,
            'subject_reference' => 'test', 'channel' => 'EMAIL', 'recipient_kind' => 'CONTACT',
            'recipient_label' => 'Test', 'recipient_address' => 'test@example.test',
            'dedupe_key' => 'reminder:test:0', 'severity' => 'NORMAL',
            'payload' => json_encode(['title' => 'x', 'body' => 'y']),
            'send_after' => now(),
            'status' => 'PENDING', 'created_at' => now(), 'updated_at' => now(),
        ];

        DB::table('sheq_notification_dispatches')->insert($row);

        // The deliberate violation aborts the whole Postgres transaction, so it has to happen
        // inside a savepoint or every query after it fails with 25P02.
        DB::statement('SAVEPOINT duplicate_attempt');

        try {
            DB::table('sheq_notification_dispatches')
                ->insert(array_merge($row, ['uuid' => (string) Str::uuid()]));

            DB::statement('RELEASE SAVEPOINT duplicate_attempt');

            $this->fail('A duplicate dispatch was accepted. The dedupe guarantee lives only in '
                .'the callers that remember to use insertOrIgnore.');
        } catch (\Illuminate\Database\QueryException $e) {
            DB::statement('ROLLBACK TO SAVEPOINT duplicate_attempt');

            $this->assertStringContainsString('sheq_dispatch_dedupe_uniq', $e->getMessage());
        }
    }

    public function test_a_later_rung_of_the_ladder_is_its_own_notice(): void
    {
        /*
         * The dedupe key has to be narrow enough that two runs in a day send nothing twice, and
         * wide enough that the 30-day warning and the 7-day warning are different messages. A key
         * of just the record would silence every rung after the first.
         */
        $typeId = $this->medicalType();
        $recordId = $this->medicalRecord($typeId, Carbon::today()->addDays(30));

        $this->artisan('sheq:reminders:send', ['--company' => $this->companyId])->assertSuccessful();

        // Time passes and the same medical is now a week away.
        DB::table('sheq_medical_records')->where('id', $recordId)
            ->update(['expires_on' => Carbon::today()->addDays(5)->toDateString()]);

        $this->artisan('sheq:reminders:send', ['--company' => $this->companyId])->assertSuccessful();

        $keys = DB::table('sheq_notification_dispatches')
            ->where('company_id', $this->companyId)
            ->where('subject_type', 'MEDICAL_RECORD')->pluck('dedupe_key')->all();

        $this->assertContains('reminder:medical:30', $keys);
        $this->assertContains('reminder:medical:7', $keys,
            'The 7-day warning was silenced by the 30-day one, so the escalation never escalates.');
    }

    public function test_a_permit_about_to_lapse_is_warned_about_before_it_does(): void
    {
        /*
         * The one kind where the window is minutes rather than days, and the only one of the
         * seven where nothing warned in advance at all — the sweep announced a permit that had
         * already lapsed and flipped it to EXPIRED, which is a consequence rather than a warning.
         *
         * A hot-work permit valid until 16:00 needs the hour before it, so the issuer can extend
         * it or stop the work and set a fire watch, instead of discovering at 16:05 that welding
         * which is still happening is no longer authorised.
         */
        $this->arrangePermit(Carbon::now()->addMinutes(20));

        $this->artisan('sheq:events:sweep', ['--company' => $this->companyId])->assertSuccessful();

        $message = DB::table('sheq_outbox_messages')
            ->where('company_id', $this->companyId)
            ->where('event_type', 'sheq.permit.expiring')->first(['payload']);

        $this->assertNotNull($message, 'A permit lapsing in twenty minutes warns nobody.');

        $payload = json_decode($message->payload, true);

        $this->assertLessThanOrEqual(20, $payload['minutes_left'] ?? 999);
        $this->assertNotNull($payload['issuer_id'] ?? null,
            'The warning does not name the issuer, who is the only person who can extend it.');

        // And it must not also already be reported as expired.
        $this->assertSame(0, DB::table('sheq_outbox_messages')
            ->where('company_id', $this->companyId)
            ->where('event_type', 'sheq.permit.expired')->count());
    }

    public function test_a_permit_that_lapses_tomorrow_is_not_warned_about_yet(): void
    {
        $this->arrangePermit(Carbon::now()->addDay());

        $this->artisan('sheq:events:sweep', ['--company' => $this->companyId])->assertSuccessful();

        $this->assertSame(0, DB::table('sheq_outbox_messages')
            ->where('company_id', $this->companyId)
            ->where('event_type', 'sheq.permit.expiring')->count(),
            'Every permit issued for tomorrow is announced as expiring today.');
    }

    public function test_a_suspended_permit_is_not_warned_about(): void
    {
        $this->arrangePermit(Carbon::now()->addMinutes(20));

        DB::table('sheq_permits')->where('company_id', $this->companyId)
            ->update(['status' => 'SUSPENDED']);

        $this->artisan('sheq:events:sweep', ['--company' => $this->companyId])->assertSuccessful();

        $this->assertSame(0, DB::table('sheq_outbox_messages')
            ->where('company_id', $this->companyId)
            ->where('event_type', 'sheq.permit.expiring')->count(),
            'The work has already stopped on a suspended permit, so there is nothing to warn '
            .'anybody to wind up.');
    }

    public function test_the_permit_warning_is_announced_once_not_every_sweep(): void
    {
        $this->arrangePermit(Carbon::now()->addMinutes(45));

        for ($i = 0; $i < 5; $i++) {
            $this->artisan('sheq:events:sweep', ['--company' => $this->companyId])
                ->assertSuccessful();
        }

        $this->assertSame(1, DB::table('sheq_outbox_messages')
            ->where('company_id', $this->companyId)
            ->where('event_type', 'sheq.permit.expiring')->count(),
            'An hourly sweep inside the warning window announces the same permit every time.');
    }

    // ── AUTO-3 ────────────────────────────────────────────────────────────────────────────────

    public function test_every_reason_code_has_a_required_corrective_action(): void
    {
        /*
         * AUTO-3 asks a blocking notice to carry the required corrective action, and the remedy
         * is a property of the reason rather than of the message — the same answer belongs on the
         * email, the portal, the refused-access screen and the copilot's reply. So a code added
         * without one fails here rather than producing a notice that says "resolve the issue".
         */
        foreach (ReasonCode::all() as $code) {
            $this->assertArrayHasKey($code, ReasonCode::REMEDIES,
                "The reason code {$code} has no required corrective action, so a contractor "
                .'blocked for it is told what is wrong and not what to do about it.');

            $remedy = ReasonCode::REMEDIES[$code];

            $this->assertGreaterThan(30, strlen($remedy),
                "The remedy for {$code} is too short to be an instruction somebody can act on.");

            // "Resolve the issue" and "contact us" are the failure mode this list exists to
            // prevent, so they are refused outright.
            foreach (['resolve the issue', 'contact us', 'as required', 'take action'] as $empty) {
                $this->assertStringNotContainsStringIgnoringCase($empty, $remedy,
                    "The remedy for {$code} says '{$empty}', which is not a corrective action.");
            }
        }
    }

    public function test_an_unknown_code_still_gets_an_answer_rather_than_nothing(): void
    {
        $this->assertNotSame('', trim(ReasonCode::remedyFor('SOMETHING_ADDED_LATER')),
            'A code with no remedy produces an empty line in the notice.');
    }

    public function test_the_blocking_notice_carries_the_reason_the_evidence_and_the_remedy(): void
    {
        $contractorId = (int) DB::table('sheq_contractors')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'name' => 'Acme Scaffolding', 'contact_email' => 'acme@example.test',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $contractor = DB::table('sheq_contractors')->where('id', $contractorId)
            ->first(['id', 'name', 'contact_email', 'contact_phone']);

        // A finding in the shape the compliance engine actually produces.
        app(ContractorNotifier::class)->blocked($this->companyId, $contractor, [[
            'code' => ReasonCode::DOCUMENT_EXPIRED,
            'subject' => 'DOCUMENT',
            'detail' => 'The letter of good standing expired on 2026-09-30.',
            'document_title' => 'Letter of good standing',
            'document_type' => 'LETTER_OF_GOOD_STANDING',
            'expires_on' => '2026-09-30',
        ]]);

        $row = DB::table('sheq_notification_dispatches')
            ->where('company_id', $this->companyId)
            ->where('event_type', 'sheq.contractor.blocked')->first(['payload']);

        $this->assertNotNull($row, 'The blocking notice was not queued.');

        $body = json_decode($row->payload, true)['body'];

        // All three things AUTO-3 names.
        $this->assertStringContainsString('expired on 2026-09-30', $body,
            'AUTO-3: the reason is missing.');
        $this->assertStringContainsString('Letter of good standing', $body,
            'AUTO-3: the evidence is missing, so the contractor is told a document expired and '
            .'not which one.');
        $this->assertStringContainsString('upload the renewed document', strtolower($body),
            'AUTO-3: the required corrective action is missing, so the notice generates a phone '
            .'call instead of a renewal.');
    }

    public function test_the_blocking_notice_does_not_send_clinical_detail_to_a_contractor(): void
    {
        /*
         * The evidence on a medical finding can include a fitness restriction, which is health
         * information about one person and goes to a contractor's shared mailbox. The renderer
         * whitelists its fields for exactly this reason, so a check that starts attaching more
         * evidence cannot widen what gets sent.
         */
        $contractorId = (int) DB::table('sheq_contractors')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'name' => 'Acme Scaffolding', 'contact_email' => 'acme@example.test',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $contractor = DB::table('sheq_contractors')->where('id', $contractorId)
            ->first(['id', 'name', 'contact_email', 'contact_phone']);

        app(ContractorNotifier::class)->blocked($this->companyId, $contractor, [[
            'code' => ReasonCode::MEDICAL_EXPIRED,
            'detail' => 'The medical certificate has expired.',
            'worker_name' => 'Johan Pretorius',
            'expires_on' => '2026-09-01',
            'restrictions' => 'No work at height. Hypertension under treatment.',
            'clinical_findings' => 'BP 165/100.',
            'hr_person_id' => 4821,
        ]]);

        $body = json_decode(DB::table('sheq_notification_dispatches')
            ->where('company_id', $this->companyId)
            ->where('event_type', 'sheq.contractor.blocked')->value('payload'), true)['body'];

        $this->assertStringContainsString('Johan Pretorius', $body);
        $this->assertStringContainsString('2026-09-01', $body);

        $this->assertStringNotContainsString('Hypertension', $body,
            'A fitness restriction was sent to the contractor. That is health information about '
            .'one person going to a shared mailbox.');
        $this->assertStringNotContainsString('165/100', $body,
            'Clinical findings were sent to the contractor.');
        $this->assertStringNotContainsString('4821', $body,
            'An internal person id was sent, which is noise at best.');
    }

    public function test_being_blocked_for_a_different_reason_later_is_announced_again(): void
    {
        $contractorId = (int) DB::table('sheq_contractors')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'name' => 'Acme Scaffolding', 'contact_email' => 'acme@example.test',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $contractor = DB::table('sheq_contractors')->where('id', $contractorId)
            ->first(['id', 'name', 'contact_email', 'contact_phone']);

        $notifier = app(ContractorNotifier::class);

        $notifier->blocked($this->companyId, $contractor, [[
            'code' => ReasonCode::DOCUMENT_EXPIRED, 'detail' => 'COID expired.']]);

        // Same notice again — must be swallowed.
        $notifier->blocked($this->companyId, $contractor, [[
            'code' => ReasonCode::DOCUMENT_EXPIRED, 'detail' => 'COID expired.']]);

        $this->assertSame(1, DB::table('sheq_notification_dispatches')
            ->where('company_id', $this->companyId)
            ->where('event_type', 'sheq.contractor.blocked')->count());

        // A month later, blocked for something else. A different fact, and it has to arrive.
        $notifier->blocked($this->companyId, $contractor, [[
            'code' => ReasonCode::SAFETY_FILE_EXPIRED, 'detail' => 'Safety file approval lapsed.']]);

        $this->assertSame(2, DB::table('sheq_notification_dispatches')
            ->where('company_id', $this->companyId)
            ->where('event_type', 'sheq.contractor.blocked')->count(),
            'The second block was swallowed as a duplicate of the first, so the contractor was '
            .'never told about the new problem.');
    }

    // ── Arrangements, one per AUTO-1 kind ─────────────────────────────────────────────────────

    private function arrangeMedical(?Carbon $expires = null): void
    {
        $this->medicalRecord($this->medicalType(), $expires ?? Carbon::today()->addDays(30));
    }

    private function arrangeContractorDocument(?string $code = null): void
    {
        $contractorId = (int) DB::table('sheq_contractors')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'name' => 'Acme Scaffolding', 'contact_email' => 'acme@example.test',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('sheq_contractor_documents')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'contractor_id' => $contractorId, 'document_type' => $code ?? 'SAFETY_PLAN',
            'title' => $code ?? 'Safety plan', 'status' => 'ACCEPTED',
            'expires_on' => Carbon::today()->addDays(14)->toDateString(),
            'version' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function arrangeInsurance(): void
    {
        $this->arrangeContractorDocument('PUBLIC_LIABILITY');
    }

    private function arrangeCoid(): void
    {
        $this->arrangeContractorDocument('LETTER_OF_GOOD_STANDING');
    }

    private function arrangeWorkerCredential(): void
    {
        $contractorId = (int) DB::table('sheq_contractors')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'name' => 'Acme Scaffolding', 'contact_email' => 'acme@example.test',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $workerId = (int) DB::table('sheq_contractor_workers')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'contractor_id' => $contractorId, 'name' => 'Johan Pretorius',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('sheq_contractor_worker_credentials')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'worker_id' => $workerId, 'kind' => 'COMPETENCY', 'code' => 'WORKING_AT_HEIGHT',
            'title' => 'Working at height', 'status' => 'ACCEPTED',
            'expires_on' => Carbon::today()->addDays(14)->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function arrangeInduction(): void
    {
        $contractorId = (int) DB::table('sheq_contractors')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'name' => 'Acme Scaffolding', 'contact_email' => 'acme@example.test',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('sheq_contractor_workers')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'contractor_id' => $contractorId, 'name' => 'Not Inducted',
            // There is no induction_status column: an un-inducted worker is one with no
            // `inducted_on` date, and an expiring induction is one whose `induction_expires`
            // is approaching.
            'is_active' => true, 'inducted_on' => null, 'induction_expires' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function arrangePermit(?Carbon $validTo = null): void
    {
        $siteId = (int) DB::table('sheq_sites')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'code' => 'A'.Str::upper(Str::random(5)), 'name' => 'Yard',
            'site_type' => 'CONSTRUCTION', 'timezone' => 'Africa/Johannesburg',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('sheq_permits')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'reference' => 'PTW-'.Str::upper(Str::random(5)), 'permit_type' => 'HOT_WORK',
            'site_id' => $siteId, 'work_description' => 'Welding', 'status' => 'ACTIVE',
            'valid_from' => now()->subHours(8),
            'valid_to' => $validTo ?? now()->addMinutes(20),
            // An ACTIVE permit has been issued by somebody, and the issuer is the only person
            // who can extend it — which is what the warning exists to let them do.
            'requested_by' => $this->user->id, 'issuer_id' => $this->user->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────────────────────

    private function dispatchCount(): int
    {
        return (int) DB::table('sheq_notification_dispatches')
            ->where('company_id', $this->companyId)->count();
    }

    /**
     * Anything that tells somebody, by either of the two mechanisms this module has.
     *
     * A reminder writes straight to the dispatch queue; a sweep publishes to the outbox and the
     * router turns that into dispatches where a rule matches. Counting only the first would
     * declare the permit warning missing when it exists, and counting only the second would
     * declare the medical warning missing when it exists. The requirement asks whether somebody
     * is told, so the test asks that.
     */
    private function noticeCount(): int
    {
        return $this->dispatchCount() + (int) DB::table('sheq_outbox_messages')
            ->where('company_id', $this->companyId)->count();
    }

    private function medicalType(): int
    {
        return (int) DB::table('sheq_medical_types')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'code' => 'M'.Str::upper(Str::random(5)), 'name' => 'Annual medical',
            'examination_type' => 'PERIODIC', 'frequency_months' => 12,
            'blocks_work_when_unfit' => true, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function medicalRecord(int $typeId, Carbon $expires, ?int $personId = null): int
    {
        return (int) DB::table('sheq_medical_records')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'medical_type_id' => $typeId, 'hr_person_id' => $personId ?? $this->personId,
            'examined_on' => $expires->copy()->subYear()->toDateString(),
            'expires_on' => $expires->toDateString(), 'fitness_outcome' => 'FIT',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function person(string $first, string $surname, ?int $userId): int
    {
        return (int) DB::table('hr_people')->insertGetId([
            'company_id' => $this->companyId, 'first_name' => $first, 'surname' => $surname,
            'user_id' => $userId, 'status' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function userWith(): User
    {
        $userId = DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(), 'email' => 'auto-'.Str::random(8).'@example.test',
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

        return User::withoutGlobalScopes()->findOrFail($userId);
    }
}
