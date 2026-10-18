<?php

namespace Tests\Feature\Sheq;

use App\Core\User\Models\User;
use App\Modules\Operations\Sheq\Access\AccessDecisionService;
use App\Modules\Operations\Sheq\Services\ContractorNotifier;
use App\Modules\Operations\Sheq\Services\ContractorService;
use App\Modules\Operations\Sheq\Services\SafetyFileWorkflow;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The nine things a contractor has to be told (CON-19).
 *
 * Before this they were told one of them — a document approaching expiry, by email, from the
 * weekday sweep. Everything else happened in silence. A safety file was refused and the
 * contractor found out when somebody telephoned; a gate closed and they found out when a crew
 * of twelve was turned away at 06:00.
 *
 * Two shapes, tested differently. The ones a person causes have to fire at the moment of the
 * change — a contractor who learns on Tuesday that they were blocked on Monday has already
 * lost a shift to a cron schedule. The ones time causes are swept, and what matters there is
 * that they fire once: a notification channel that repeats is a channel that gets filtered
 * into a folder nobody opens, which is the same as not sending at all.
 */
class ContractorNotificationTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

    private User $user;

    private array $contractor;

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
            'code' => 'N'.Str::upper(Str::random(5)), 'name' => 'Refinery', 'site_type' => 'PLANT',
            'timezone' => 'Africa/Johannesburg', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('sheq_settings')->updateOrInsert(
            ['company_id' => $this->companyId],
            ['contractor_required_documents' => json_encode(['COIDA_LETTER']),
                'contractor_notification_channels' => json_encode(['EMAIL']),
                'updated_at' => now()],
        );

        $this->contractor = app(ContractorService::class)->register($this->companyId, [
            'name' => 'Acme Scaffolding', 'trade' => 'SCAFFOLDING',
            'contact_email' => 'site@acme.test', 'contact_phone' => '+27 82 555 0100',
        ], $this->user->id);
    }

    private function userWith(array $permissions): User
    {
        $userId = DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(), 'email' => 'ntf-'.Str::random(8).'@example.test',
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

    private function contractorRow(): object
    {
        return DB::table('sheq_contractors')->where('id', $this->contractor['id'])
            ->first(['id', 'name', 'contact_email', 'contact_phone']);
    }

    private function notifier(): ContractorNotifier
    {
        return app(ContractorNotifier::class);
    }

    /** @return \Illuminate\Support\Collection<int,object> */
    private function queued(?string $event = null)
    {
        return DB::table('sheq_notification_dispatches')
            ->where('company_id', $this->companyId)
            ->where('event_type', 'like', 'sheq.contractor.%')
            ->when($event !== null, fn ($q) => $q->where('event_type', $event))
            ->get(['event_type', 'channel', 'recipient_address', 'severity', 'payload',
                'dedupe_key']);
    }

    private function bodyOf(object $row): string
    {
        $payload = json_decode((string) $row->payload, true) ?: [];

        return ($payload['title'] ?? '')."\n".($payload['body'] ?? '');
    }

    // ── The catalogue is honest ───────────────────────────────────────────────────────────────

    public function test_every_declared_event_is_actually_sent_from_somewhere(): void
    {
        /*
         * A catalogue that lists an event nothing sends is worse than no catalogue: the screen
         * renders it as a promise the system has made, and a host plans around a message that
         * will never arrive.
         *
         * Read out of the source rather than asserted one by one, so the tenth event somebody
         * adds has to be wired before this passes.
         */
        $sources = collect(File::allFiles(app_path('Modules/Operations/Sheq')))
            ->filter(fn ($f) => $f->getExtension() === 'php'
                && $f->getFilename() !== 'ContractorNotifier.php')
            ->map(fn ($f) => $f->getContents())
            ->implode("\n");

        $unsent = [];

        foreach (array_keys(ContractorNotifier::EVENTS) as $event) {
            $method = Str::camel(str_replace('sheq.contractor.', '', $event));

            // Either the notifier method is called, or the event type is queued directly.
            if (! str_contains($sources, '->'.$method.'(')
                && ! str_contains($sources, $event)) {
                $unsent[] = $event;
            }
        }

        $this->assertSame([], $unsent,
            'These events are declared and nothing sends them, so the screen promises a '
            .'message that never arrives: '.implode(', ', $unsent));
    }

    // ── The five a person causes, which fire at the moment ────────────────────────────────────

    public function test_a_contractor_is_told_the_moment_the_gate_closes(): void
    {
        /*
         * The one whose absence costs the most. A contractor who is not told arrives with a
         * crew at 06:00 and loses the shift — so it fires on the transition, not on the next
         * sweep.
         */
        /*
         * Cleared first, then let the certificate lapse.
         *
         * `register()` seeds `access_status` as BLOCKED — the safe default for a gate before
         * anybody has looked at the file — so a contractor who has never been cleared is not
         * "newly blocked" and gets no notice. Which is right: they were never let in. The
         * message exists for the gate CLOSING on somebody who was working yesterday.
         */
        $contractors = app(ContractorService::class);

        $contractors->attachDocument($this->companyId, $this->contractor['uuid'], [
            'document_type' => 'COIDA_LETTER',
            'expires_on' => Carbon::today()->addDay()->toDateString(),
        ], $this->user->id);

        $this->assertCount(0, $this->queued('sheq.contractor.blocked'));

        $this->travelTo(Carbon::now()->addDays(3));

        $contractors->refreshAccessStatus($this->companyId, $this->contractor['uuid'], [],
            $this->user->id);

        $blocked = $this->queued('sheq.contractor.blocked');

        $this->assertCount(1, $blocked);
        $this->assertSame('CRITICAL', $blocked[0]->severity);
        $this->assertSame('site@acme.test', $blocked[0]->recipient_address);
        // The exact reason, not its code: "MISSING_MANDATORY_DOCUMENT" tells a contractor
        // nothing they can act on.
        $this->assertStringContainsString('COID', $this->bodyOf($blocked[0]));
    }

    public function test_a_contractor_is_told_when_the_gate_opens_again(): void
    {
        /*
         * The direction that otherwise never reaches them at all: a contractor who sent a
         * renewal on Friday has no way of knowing it was accepted, and telephones on Monday.
         */
        $contractors = app(ContractorService::class);

        // Cleared into BLOCKED first, the way a real contractor arrives at that state.
        $contractors->refreshAccessStatus($this->companyId, $this->contractor['uuid'], [],
            $this->user->id);

        $contractors->attachDocument($this->companyId, $this->contractor['uuid'], [
            'document_type' => 'COIDA_LETTER',
            'expires_on' => Carbon::today()->addYear()->toDateString(),
        ], $this->user->id);

        $this->assertCount(1, $this->queued('sheq.contractor.unblocked'));
    }

    public function test_an_unchanged_status_announces_nothing(): void
    {
        // Recomputation runs on every document touch. Re-announcing an already-blocked
        // contractor each time would train them to ignore the message, which is the same
        // failure as not sending it, arrived at more expensively.
        $contractors = app(ContractorService::class);

        $contractors->attachDocument($this->companyId, $this->contractor['uuid'], [
            'document_type' => 'COIDA_LETTER',
            'expires_on' => Carbon::today()->addDay()->toDateString(),
        ], $this->user->id);

        $this->travelTo(Carbon::now()->addDays(3));

        foreach (range(1, 3) as $ignored) {
            $contractors->refreshAccessStatus($this->companyId, $this->contractor['uuid'], [],
                $this->user->id);
        }

        $this->assertCount(1, $this->queued('sheq.contractor.blocked'));
    }

    public function test_a_second_block_for_a_different_reason_is_told_again(): void
    {
        /*
         * Blocked for a lapsed COID, cleared, then blocked a month later for lapsed insurance
         * is two different facts and both have to arrive. Keying the dedupe on the contractor
         * alone would send the first and silently swallow the second.
         */
        $contractor = $this->contractorRow();

        $this->notifier()->blocked($this->companyId, $contractor, ['COID letter expired.']);
        $this->notifier()->blocked($this->companyId, $contractor, ['COID letter expired.']);

        $this->assertCount(1, $this->queued('sheq.contractor.blocked'));

        $this->notifier()->blocked($this->companyId, $contractor,
            ['Public liability expired.']);

        $this->assertCount(2, $this->queued('sheq.contractor.blocked'));
    }

    public function test_a_safety_file_decision_reaches_them_with_the_reason(): void
    {
        $contractor = $this->contractorRow();

        $this->notifier()->safetyFileRejected($this->companyId, $contractor,
            SafetyFileWorkflow::CHANGES_REQUIRED,
            'Section 3 is missing the appointment letters.',
            ['Appointment letter for the site supervisor.']);

        $rejected = $this->queued('sheq.contractor.safety_file_rejected');

        $this->assertCount(1, $rejected);

        $body = $this->bodyOf($rejected[0]);

        // Both the reason and the itemised changes: "rejected" on its own starts a telephone
        // call rather than a corrected file.
        $this->assertStringContainsString('appointment letters', $body);
        $this->assertStringContainsString('site supervisor', $body);
    }

    public function test_approving_a_safety_file_tells_them_so(): void
    {
        $this->notifier()->safetyFileApproved($this->companyId, $this->contractorRow(),
            Carbon::today()->addYear()->toDateString());

        $approved = $this->queued('sheq.contractor.safety_file_approved');

        $this->assertCount(1, $approved);
        $this->assertStringContainsString('approved', strtolower($this->bodyOf($approved[0])));
    }

    public function test_the_workflow_itself_announces_its_decisions(): void
    {
        /*
         * Through the real transition, not the notifier directly. The hook is inside `move()`,
         * which every transition passes through — a hook per caller is a hook somebody forgets
         * to add to the next one.
         */
        $workflow = app(SafetyFileWorkflow::class);

        /*
         * The submission is moved into SUBMITTED directly rather than through `submit()`,
         * which demands every safety-file section first — that is the workflow's own guard and
         * has its own tests. What is under test here is that a transition announces itself at
         * all, which is the thing a hook per caller gets wrong.
         */
        $workflow->current($this->companyId, $this->contractor['uuid'], $this->user->id);

        DB::table('sheq_safety_file_submissions')
            ->where('company_id', $this->companyId)
            ->where('contractor_id', $this->contractor['id'])
            ->update(['state' => SafetyFileWorkflow::SUBMITTED]);

        $workflow->requestChanges($this->companyId, $this->contractor['uuid'],
            'The COID letter names a different company.', [], $this->user->id);

        $this->assertCount(1, $this->queued('sheq.contractor.safety_file_rejected'));
    }

    public function test_a_worker_turned_away_at_a_gate_is_reported_to_their_employer(): void
    {
        /*
         * Otherwise the worker tells their supervisor, who tells the contractor's office some
         * time that morning, with whatever version of the reason survived two retellings.
         */
        $identifier = 'CARD-'.Str::upper(Str::random(8));

        DB::table('sheq_contractor_workers')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'contractor_id' => $this->contractor['id'], 'name' => 'Thabo Mokoena',
            'id_number_hash' => AccessDecisionService::hashIdentifier($identifier),
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        app(ContractorService::class)->refreshAccessStatus($this->companyId,
            $this->contractor['uuid'], [], $this->user->id);

        app(AccessDecisionService::class)->decideAndRecord($this->companyId, $identifier, [
            'site_id' => $this->siteId, 'direction' => 'ENTRY', 'enforce' => true,
        ]);

        $refused = $this->queued('sheq.contractor.worker_blocked');

        $this->assertCount(1, $refused);
        // Named, because "one of your workers" sends a contractor to check all forty.
        $this->assertStringContainsString('Thabo Mokoena', $this->bodyOf($refused[0]));
    }

    public function test_monitor_mode_does_not_tell_them_anybody_was_refused(): void
    {
        /*
         * Monitor mode exists to find out who WOULD be refused. The boom opens anyway, so
         * telling a contractor their worker was turned away would be false — and it would
         * teach them the message means nothing.
         */
        $identifier = 'CARD-'.Str::upper(Str::random(8));

        DB::table('sheq_contractor_workers')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'contractor_id' => $this->contractor['id'], 'name' => 'Thabo Mokoena',
            'id_number_hash' => AccessDecisionService::hashIdentifier($identifier),
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        app(ContractorService::class)->refreshAccessStatus($this->companyId,
            $this->contractor['uuid'], [], $this->user->id);

        app(AccessDecisionService::class)->decideAndRecord($this->companyId, $identifier, [
            'site_id' => $this->siteId, 'direction' => 'ENTRY', 'enforce' => false,
        ]);

        $this->assertCount(0, $this->queued('sheq.contractor.worker_blocked'));
    }

    // ── The four time causes ──────────────────────────────────────────────────────────────────

    public function test_a_document_approaching_expiry_warns_once_per_rung(): void
    {
        app(ContractorService::class)->attachDocument($this->companyId,
            $this->contractor['uuid'], [
                'document_type' => 'COIDA_LETTER',
                'expires_on' => Carbon::today()->addDays(6)->toDateString(),
            ], $this->user->id);

        $this->artisan('sheq:reminders:send', ['--company' => $this->companyId])
            ->assertSuccessful();

        $first = $this->queued('sheq.contractor.document_expiring')->count();

        $this->assertGreaterThan(0, $first);

        // Run again the same day: the gate is already claimed, so nothing is added.
        $this->artisan('sheq:reminders:send', ['--company' => $this->companyId])
            ->assertSuccessful();

        $this->assertSame($first, $this->queued('sheq.contractor.document_expiring')->count(),
            'A second run on the same day chased the contractor twice.');
    }

    public function test_a_lapsed_document_is_its_own_message_sent_once(): void
    {
        /*
         * Not the same as the last rung of the warning ladder. "Expires today" is a request;
         * "expired on the 3rd and your people are being refused" is a consequence that has
         * already begun, and the day it becomes true is the day it has to be sent.
         */
        app(ContractorService::class)->attachDocument($this->companyId,
            $this->contractor['uuid'], [
                'document_type' => 'COIDA_LETTER',
                'expires_on' => Carbon::today()->subDays(2)->toDateString(),
            ], $this->user->id);

        $this->artisan('sheq:reminders:send', ['--company' => $this->companyId]);
        $this->artisan('sheq:reminders:send', ['--company' => $this->companyId]);

        $expired = $this->queued('sheq.contractor.document_expired');

        $this->assertCount(1, $expired);
        $this->assertSame('HIGH', $expired[0]->severity);
    }

    public function test_a_document_expiring_today_is_not_yet_announced_as_lapsed(): void
    {
        // Expiry is end of day. Announcing it as lapsed in the morning turns people away on a
        // certificate that is still in force.
        app(ContractorService::class)->attachDocument($this->companyId,
            $this->contractor['uuid'], [
                'document_type' => 'COIDA_LETTER',
                'expires_on' => Carbon::today()->toDateString(),
            ], $this->user->id);

        $this->artisan('sheq:reminders:send', ['--company' => $this->companyId]);

        $this->assertCount(0, $this->queued('sheq.contractor.document_expired'));
    }

    public function test_history_is_not_dredged_up_when_this_is_switched_on(): void
    {
        /*
         * A tenant with three years of records would otherwise send a contractor forty notices
         * about certificates that lapsed in 2024 — which is exactly how a notification channel
         * gets filtered into a folder nobody opens.
         */
        app(ContractorService::class)->attachDocument($this->companyId,
            $this->contractor['uuid'], [
                'document_type' => 'PUBLIC_LIABILITY',
                'expires_on' => Carbon::today()->subYears(2)->toDateString(),
            ], $this->user->id);

        $this->artisan('sheq:reminders:send', ['--company' => $this->companyId]);

        $this->assertCount(0, $this->queued('sheq.contractor.document_expired'));
    }

    public function test_uninducted_people_are_one_message_not_one_each(): void
    {
        // A firm with fifteen uninducted workers needs a list, not fifteen emails — fifteen
        // emails is how the sixteenth gets ignored.
        foreach (['Thabo Mokoena', 'Nomsa Dlamini', 'Sipho Ndlovu'] as $name) {
            DB::table('sheq_contractor_workers')->insert([
                'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
                'contractor_id' => $this->contractor['id'], 'name' => $name,
                'inducted_on' => null, 'induction_expires' => null,
                'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->artisan('sheq:reminders:send', ['--company' => $this->companyId]);

        $induction = $this->queued('sheq.contractor.induction_required');

        $this->assertCount(1, $induction);

        $body = $this->bodyOf($induction[0]);

        foreach (['Thabo Mokoena', 'Nomsa Dlamini', 'Sipho Ndlovu'] as $name) {
            $this->assertStringContainsString($name, $body);
        }
    }

    public function test_an_inducted_worker_is_left_off_the_list(): void
    {
        DB::table('sheq_contractor_workers')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'contractor_id' => $this->contractor['id'], 'name' => 'Already Done',
            'inducted_on' => Carbon::today()->subMonth()->toDateString(),
            'induction_expires' => Carbon::today()->addYear()->toDateString(),
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->artisan('sheq:reminders:send', ['--company' => $this->companyId]);

        $this->assertCount(0, $this->queued('sheq.contractor.induction_required'));
    }

    public function test_a_worker_competency_running_out_is_chased(): void
    {
        /*
         * `CompetencyService` already does this ladder for the host's own employees and cannot
         * do it here: it is keyed on `hr_person_id` and a contractor's people have no HR
         * record. Nothing was reading their credentials for expiry at all.
         */
        $workerId = DB::table('sheq_contractor_workers')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'contractor_id' => $this->contractor['id'], 'name' => 'Thabo Mokoena',
            'inducted_on' => Carbon::today()->subMonth()->toDateString(),
            'induction_expires' => Carbon::today()->addYear()->toDateString(),
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('sheq_contractor_worker_credentials')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'worker_id' => $workerId, 'kind' => 'COMPETENCY', 'code' => 'WORK_AT_HEIGHT',
            'title' => 'Work at height', 'status' => 'ACCEPTED',
            'expires_on' => Carbon::today()->addDays(12)->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->artisan('sheq:reminders:send', ['--company' => $this->companyId]);

        $competency = $this->queued('sheq.contractor.competency_expiring');

        $this->assertCount(1, $competency);

        $body = $this->bodyOf($competency[0]);

        $this->assertStringContainsString('Thabo Mokoena', $body);
        $this->assertStringContainsString('Work at height', $body);
    }

    // ── Channels ──────────────────────────────────────────────────────────────────────────────

    public function test_email_only_by_default(): void
    {
        // Switching a host onto WhatsApp without being asked would start sending to numbers
        // nobody has checked are right.
        DB::table('sheq_settings')->where('company_id', $this->companyId)
            ->update(['contractor_notification_channels' => null]);

        $this->notifier()->blocked($this->companyId, $this->contractorRow(), ['Something.']);

        $this->assertSame(['EMAIL'], $this->queued()->pluck('channel')->unique()->all());
    }

    public function test_a_host_can_send_by_whatsapp_as_well(): void
    {
        /*
         * A small firm's registered email is frequently an accountant's, checked weekly; the
         * number on the same record is the site manager's. A notice that the gate closed is
         * worth nothing on Tuesday if it shut on Monday morning.
         */
        DB::table('sheq_settings')->where('company_id', $this->companyId)
            ->update(['contractor_notification_channels' => json_encode(['EMAIL', 'WHATSAPP'])]);

        $this->notifier()->blocked($this->companyId, $this->contractorRow(), ['Something.']);

        $queued = $this->queued('sheq.contractor.blocked');

        $this->assertEqualsCanonicalizing(['EMAIL', 'WHATSAPP'],
            $queued->pluck('channel')->all());

        // Each addressed correctly rather than both to the email.
        $this->assertSame('+27 82 555 0100',
            $queued->firstWhere('channel', 'WHATSAPP')->recipient_address);
    }

    public function test_a_channel_with_no_address_is_dropped_rather_than_queued(): void
    {
        /*
         * A WhatsApp dispatch to a null number sits in the queue failing five times and then
         * reads, in the log, like an attempt that was made.
         */
        DB::table('sheq_contractors')->where('id', $this->contractor['id'])
            ->update(['contact_phone' => null]);

        DB::table('sheq_settings')->where('company_id', $this->companyId)
            ->update(['contractor_notification_channels' => json_encode(['EMAIL', 'WHATSAPP'])]);

        $this->notifier()->blocked($this->companyId, $this->contractorRow(), ['Something.']);

        $this->assertSame(['EMAIL'], $this->queued()->pluck('channel')->all());
    }

    public function test_a_contractor_nobody_can_reach_queues_nothing(): void
    {
        // Silent rather than queued to an invented recipient: a dispatch addressed to nothing
        // reads in the log as a message that was sent.
        DB::table('sheq_contractors')->where('id', $this->contractor['id'])
            ->update(['contact_email' => null, 'contact_phone' => null]);

        $this->notifier()->blocked($this->companyId, $this->contractorRow(), ['Something.']);

        $this->assertCount(0, $this->queued());
    }

    // ── The screen ────────────────────────────────────────────────────────────────────────────

    public function test_the_screen_lists_every_event_and_what_went_out(): void
    {
        $this->notifier()->blocked($this->companyId, $this->contractorRow(), ['COID expired.']);

        $props = $this->actingAs($this->user)->get('/sheq/contractor-notices')->assertOk()
            ->viewData('page')['props'];

        $this->assertCount(count(ContractorNotifier::EVENTS), $props['events']);
        $this->assertCount(1, $props['recent']);
        $this->assertSame(['EMAIL'], $props['chosen']);
    }

    public function test_the_screen_names_the_contractors_nobody_can_reach(): void
    {
        /*
         * The quietest failure in the module: everything works, nothing is queued, the
         * register still shows the expiry, and the contractor being told nothing is the last
         * to know.
         */
        DB::table('sheq_contractors')->where('id', $this->contractor['id'])
            ->update(['contact_email' => null]);

        $unreachable = $this->actingAs($this->user)->get('/sheq/contractor-notices')
            ->viewData('page')['props']['unreachable'];

        $this->assertCount(1, $unreachable);
        $this->assertSame('Acme Scaffolding', $unreachable[0]['name']);
    }

    public function test_a_contractor_reachable_on_one_chosen_channel_is_not_listed(): void
    {
        // A host sending by both email and WhatsApp has not failed to reach somebody who has
        // an email address and no mobile. Listing them would make the list noise.
        DB::table('sheq_settings')->where('company_id', $this->companyId)
            ->update(['contractor_notification_channels' => json_encode(['EMAIL', 'WHATSAPP'])]);

        DB::table('sheq_contractors')->where('id', $this->contractor['id'])
            ->update(['contact_phone' => null]);

        $this->assertCount(0, $this->actingAs($this->user)->get('/sheq/contractor-notices')
            ->viewData('page')['props']['unreachable']);
    }

    public function test_the_screen_can_change_the_channels(): void
    {
        $this->actingAs($this->user)->post('/sheq/contractor-notices/channels',
            ['channels' => ['EMAIL', 'WHATSAPP']])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertEqualsCanonicalizing(['EMAIL', 'WHATSAPP'],
            json_decode((string) DB::table('sheq_settings')
                ->where('company_id', $this->companyId)
                ->value('contractor_notification_channels'), true));
    }

    public function test_switching_every_channel_off_is_allowed_and_recorded(): void
    {
        // An empty list is a decision. Silently keeping email on would be the system
        // overruling a host, and the audit entry is what makes it answerable.
        $this->actingAs($this->user)->post('/sheq/contractor-notices/channels',
            ['channels' => []])->assertRedirect();

        $this->assertSame([], json_decode((string) DB::table('sheq_settings')
            ->where('company_id', $this->companyId)
            ->value('contractor_notification_channels'), true));

        $this->notifier()->blocked($this->companyId, $this->contractorRow(), ['Something.']);

        $this->assertCount(0, $this->queued());

        $this->assertNotNull(DB::table('sheq_audit_log')
            ->where('company_id', $this->companyId)
            ->where('action', 'contractor.notification_channels_changed')->first());
    }

    public function test_an_unknown_channel_is_refused(): void
    {
        $this->actingAs($this->user)->post('/sheq/contractor-notices/channels',
            ['channels' => ['CARRIER_PIGEON']])->assertSessionHasErrors('channels.0');
    }

    public function test_changing_the_channels_needs_the_configure_permission(): void
    {
        $this->actingAs($this->userWith([]))
            ->post('/sheq/contractor-notices/channels', ['channels' => ['EMAIL']])
            ->assertForbidden();
    }
}
