<?php

namespace Tests\Feature\Sheq;

use App\Core\User\Models\User;
use App\Modules\Operations\Sheq\Services\DocumentService;
use App\Modules\Operations\Sheq\Services\InductionService;
use App\Modules\Operations\Sheq\Services\MedicalSurveillanceService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Where a person stands, told to the person themselves.
 *
 * `sheq:reminders:send` emails somebody that their first-aid certificate lapses in thirty days, and
 * that email was the only place the fact existed: gone once deleted, uncheckable between sends, and
 * no use at all to somebody who was on leave for the 30-day mail and the 14-day one. The register
 * that holds the same answer needs `sheq.competency.manage` — a permission the person themselves
 * does not have — so the only way to find out was to ask the safety officer to look them up.
 *
 * The two ways a screen like this misleads are what most of these tests are about: an empty page
 * reading as "you are fine", and a personal view computing its own answers and so disagreeing with
 * the gate that will actually turn somebody away.
 */
class MyComplianceTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

    private User $user;

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
        $this->user = $this->account();
        $this->personId = $this->personFor($this->user->id);
    }

    /** An ordinary account: module access, no SHEQ roles, not an administrator. */
    private function account(): User
    {
        $userId = DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(), 'email' => 'mine-'.Str::random(8).'@example.test',
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

    private function personFor(int $userId, ?int $companyId = null): int
    {
        return (int) DB::table('hr_people')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $companyId ?? $this->companyId,
            'user_id' => $userId, 'first_name' => 'Sipho', 'surname' => Str::random(6),
            'employee_number' => 'EMP'.random_int(10000, 99999), 'status' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function competency(array $overrides = []): int
    {
        return (int) DB::table('sheq_competencies')->insertGetId(array_merge([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'code' => 'FA'.Str::random(5), 'title' => 'First aid level 1',
            'competency_type' => 'TRAINING', 'validity_months' => 24,
            'blocks_work_when_expired' => false, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ], $overrides));
    }

    private function record(int $competencyId, array $overrides = [], ?int $personId = null): void
    {
        DB::table('sheq_competency_records')->insert(array_merge([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'hr_person_id' => $personId ?? $this->personId, 'competency_id' => $competencyId,
            'status' => 'COMPLIANT', 'achieved_at' => Carbon::today()->subYear()->toDateString(),
            'expires_at' => Carbon::today()->addYear()->toDateString(),
            'source' => 'MANUAL', 'created_at' => now(), 'updated_at' => now(),
        ], $overrides));
    }

    private function props(?User $as = null): array
    {
        return $this->actingAs($as ?? $this->user)->get('/sheq/my-compliance')
            ->assertOk()->viewData('page')['props'];
    }

    // ── It opens for an ordinary person ───────────────────────────────────────────────────────

    public function test_a_person_with_no_sheq_permissions_can_see_their_own_record(): void
    {
        $this->record($this->competency());

        $props = $this->props();

        /*
         * The whole point. The competency register needs `sheq.competency.manage`, which this
         * account does not have, so the only way to find out where they stood was to ask somebody
         * to look them up.
         */
        $this->assertFalse($this->user->can('sheq.competency.manage'));
        $this->assertCount(1, $props['competencies']);
        $this->assertSame('First aid level 1', $props['competencies'][0]['title']);
    }

    // ── An empty screen must not read as compliance ───────────────────────────────────────────

    public function test_somebody_with_no_personnel_record_is_told_so_rather_than_shown_an_empty_page(): void
    {
        $stranger = $this->account();   // an account, deliberately with no hr_people row

        $props = $this->props($stranger);

        /*
         * Creating a user does not create a personnel record — manage-users does not — so this is a
         * real situation for a real employee. A tidy page of empty sections is indistinguishable
         * from compliance, which is the wrong answer to give somebody who will be standing at a
         * permit gate tomorrow.
         */
        $this->assertTrue($props['noRecord']);
        $this->assertNull($props['person']);
        $this->assertSame([], $props['competencies']);
    }

    public function test_a_person_with_a_record_but_nothing_required_is_not_told_they_are_compliant(): void
    {
        $props = $this->props();

        // Nothing on file is not the same as compliant. The screen distinguishes them by having
        // nothing in any section *and* a person — the Vue says "nothing to check".
        $this->assertFalse($props['noRecord']);
        $this->assertSame([], $props['competencies']);
        $this->assertSame([], $props['gaps']);
        $this->assertSame(0, $props['summary']['expired']);
    }

    // ── The status is the derived one, so it cannot disagree with the gate ─────────────────────

    public function test_a_lapsed_ticket_reads_as_lapsed_with_the_days_signed(): void
    {
        $this->record($this->competency(), [
            'expires_at' => Carbon::today()->subDays(12)->toDateString(),
        ]);

        $ticket = $this->props()['competencies'][0];

        $this->assertSame('EXPIRED', $ticket['status']);
        // Signed, so the screen never has to work out which side of the date a number is on.
        $this->assertSame(-12, $ticket['days_left']);
    }

    public function test_a_ticket_inside_the_expiring_window_says_so(): void
    {
        $this->record($this->competency(), [
            'expires_at' => Carbon::today()->addDays(20)->toDateString(),
        ]);

        $ticket = $this->props()['competencies'][0];

        $this->assertSame('EXPIRING', $ticket['status']);
        $this->assertSame(20, $ticket['days_left']);
    }

    public function test_a_revoked_ticket_is_lapsed_whatever_its_date_says(): void
    {
        $this->record($this->competency(), [
            'expires_at' => Carbon::today()->addYears(2)->toDateString(),
            'revoked_at' => now(), 'revocation_reason' => 'Withdrawn',
        ]);

        // Derived, not stored: the row still says COMPLIANT and the expiry is two years out. A
        // screen that read the column would tell somebody they hold a ticket that was taken away.
        $this->assertSame('EXPIRED', $this->props()['competencies'][0]['status']);
    }

    public function test_an_appointment_nobody_signed_is_not_in_force_and_asks_for_the_signature(): void
    {
        $this->record($this->competency(['competency_type' => 'APPOINTMENT']), [
            'appointment_scope' => 'Section 16(2) for the Rustenburg plant',
            'accepted_at' => null,
        ]);

        $ticket = $this->props()['competencies'][0];

        // A letter nobody signed is paper. The person reading this is the one who has to sign it,
        // so the screen says that rather than only showing the status.
        $this->assertSame('NOT_STARTED', $ticket['status']);
        $this->assertTrue($ticket['awaiting_my_signature']);
    }

    // ── The figure that decides whether they work tomorrow ────────────────────────────────────

    public function test_a_lapsed_ticket_that_stops_work_is_counted_apart_from_one_that_does_not(): void
    {
        $this->record($this->competency(['blocks_work_when_expired' => true, 'code' => 'B'.Str::random(5)]),
            ['expires_at' => Carbon::today()->subDay()->toDateString()]);
        $this->record($this->competency(['blocks_work_when_expired' => false, 'code' => 'N'.Str::random(5)]),
            ['expires_at' => Carbon::today()->subDay()->toDateString()]);

        $summary = $this->props()['summary'];

        // Two lapsed tickets, but only one of them stops a shift, and that is different news.
        $this->assertSame(2, $summary['expired']);
        $this->assertSame(1, $summary['blocking']);
    }

    public function test_the_worst_ticket_is_first(): void
    {
        $this->record($this->competency(['code' => 'C'.Str::random(5)]));   // current
        $this->record($this->competency(['code' => 'E'.Str::random(5)]),
            ['expires_at' => Carbon::today()->subMonth()->toDateString()]);

        // Somebody opening this on a phone at a gate reads the first row.
        $this->assertSame('EXPIRED', $this->props()['competencies'][0]['status']);
    }

    // ── Required and not held is a different ask from lapsed ──────────────────────────────────

    public function test_a_competency_required_of_me_and_never_held_is_listed_as_a_gap(): void
    {
        $competencyId = $this->competency(['code' => 'RQ'.Str::random(4), 'title' => 'Working at heights']);

        DB::table('sheq_competency_requirements')->insert([
            'company_id' => $this->companyId, 'competency_id' => $competencyId,
            'applies_to_type' => 'EMPLOYEE', 'applies_to_id' => (string) $this->personId,
            'is_exempt' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $props = $this->props();

        // A lapse is a renewal; this is training that has to be arranged. Listed separately for
        // that reason, and not mixed in with the tickets they do hold.
        $this->assertSame([], $props['competencies']);
        $this->assertSame('Working at heights', $props['gaps'][0]['title']);
    }

    public function test_an_exemption_removes_the_requirement_rather_than_leaving_it_outstanding(): void
    {
        $competencyId = $this->competency(['code' => 'EX'.Str::random(4)]);

        DB::table('sheq_competency_requirements')->insert([
            'company_id' => $this->companyId, 'competency_id' => $competencyId,
            'applies_to_type' => 'EMPLOYEE', 'applies_to_id' => (string) $this->personId,
            'is_exempt' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        // Otherwise an exempt person sits on their own non-compliance list for ever, which is how
        // people learn to ignore the screen.
        $this->assertSame([], $this->props()['gaps']);
    }

    // ── Medicals, induction and reading ───────────────────────────────────────────────────────

    public function test_my_medical_shows_its_outcome_and_whether_it_has_lapsed(): void
    {
        $typeId = DB::table('sheq_medical_types')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'code' => 'AUD'.Str::random(4), 'name' => 'Audiometry',
            'examination_type' => 'PERIODIC', 'frequency_months' => 12,
            'blocks_work_when_unfit' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        app(MedicalSurveillanceService::class)->record($this->companyId, [
            'medical_type_id' => $typeId, 'hr_person_id' => $this->personId,
            'examined_on' => Carbon::today()->subMonths(13)->toDateString(),
            'expires_on' => Carbon::today()->subMonth()->toDateString(),
            'fitness_outcome' => 'FIT_WITH_RESTRICTION',
            'restrictions' => 'No work above 85 dB without protection',
        ], $this->user->id);

        $medical = $this->props()['medicals'][0];

        $this->assertSame('Audiometry', $medical['examination']);
        $this->assertTrue($medical['expired']);
        // The outcome and the restriction are readable by the person they are about — they are not
        // in the encrypted set, deliberately, because a supervisor has to be able to act on them.
        $this->assertSame('FIT_WITH_RESTRICTION', $medical['fitness_outcome']);
        $this->assertSame('No work above 85 dB without protection', $medical['restrictions']);
    }

    public function test_the_clinical_findings_are_not_on_this_screen(): void
    {
        $typeId = DB::table('sheq_medical_types')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'code' => 'AUD'.Str::random(4), 'name' => 'Audiometry',
            'examination_type' => 'PERIODIC', 'frequency_months' => 12,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        app(MedicalSurveillanceService::class)->record($this->companyId, [
            'medical_type_id' => $typeId, 'hr_person_id' => $this->personId,
            'examined_on' => Carbon::today()->toDateString(), 'fitness_outcome' => 'FIT',
            'clinical_findings' => 'Mild high-frequency loss, left ear',
            'practitioner_name' => 'Dr N. Pillay',
        ], $this->user->id);

        // POPIA s26 material reaches a holder of `sheq.pii.read` through its own endpoint. This
        // screen needs no entitlement at all, so it must not carry any of it in its payload.
        $this->assertStringNotContainsString('high-frequency',
            json_encode($this->props()['medicals']));
    }

    public function test_my_induction_is_only_reported_where_the_company_published_one(): void
    {
        $this->assertFalse($this->props()['induction']['required'],
            'A company that has not authored an induction must not show its workforce as out of it.');

        $inductions = app(InductionService::class);

        $induction = $inductions->publish($this->companyId, [
            'code' => 'IND'.Str::random(5), 'title' => 'Site induction', 'audience' => 'EMPLOYEE',
            'content' => [['heading' => 'Assembly point', 'body' => 'Car park A.']],
            'questions' => [['id' => 'q1', 'text' => 'Where is the assembly point?',
                'options' => ['Car park A', 'The canteen'], 'answer' => 'Car park A']],
            'requires_signature' => false,
        ], $this->user->id);

        $before = $this->props()['induction'];
        $this->assertTrue($before['required']);
        $this->assertFalse($before['current']);

        $inductions->complete($this->companyId, $induction['uuid'], [
            'subject_type' => 'EMPLOYEE', 'subject_id' => $this->personId,
            'person_name' => 'Sipho', 'answers' => ['q1' => 'Car park A'],
        ]);

        $this->assertTrue($this->props()['induction']['current']);
    }

    public function test_the_reading_i_owe_is_counted_and_agrees_with_the_library(): void
    {
        $service = app(DocumentService::class);

        $document = $service->create($this->companyId, [
            'doc_number' => 'DOC-'.Str::random(6), 'title' => 'Working at height',
            'doc_type' => 'PROCEDURE', 'requires_acknowledgement' => true,
            'applies_to' => ['mode' => 'EVERYONE'],
        ], $this->user->id);

        $service->draftVersion($this->companyId, $document['uuid'], [], $this->user->id);
        $service->publish($this->companyId, $document['uuid'], 1, $this->user->id);

        $this->assertSame(1, $this->props()['unacknowledged']);

        $this->actingAs($this->user)
            ->post("/sheq/library/{$document['uuid']}/acknowledge")->assertRedirect();

        // The count and the library read the same audience rule, so confirming it there must clear
        // it here. Two implementations would eventually disagree about what somebody owes.
        $this->assertSame(0, $this->props()['unacknowledged']);
    }

    public function test_a_procedure_addressed_to_somebody_else_is_not_counted_against_me(): void
    {
        $service = app(DocumentService::class);

        $document = $service->create($this->companyId, [
            'doc_number' => 'DOC-'.Str::random(6), 'title' => 'Rigging procedure',
            'doc_type' => 'PROCEDURE', 'requires_acknowledgement' => true,
            'applies_to' => ['mode' => 'ROLES', 'roles' => ['Rigger']],
        ], $this->user->id);

        $service->draftVersion($this->companyId, $document['uuid'], [], $this->user->id);
        $service->publish($this->companyId, $document['uuid'], 1, $this->user->id);

        $this->assertSame(0, $this->props()['unacknowledged']);
    }

    public function test_a_procedure_i_own_and_have_not_reviewed_is_mine_to_deal_with(): void
    {
        $service = app(DocumentService::class);

        $document = $service->create($this->companyId, [
            'doc_number' => 'DOC-'.Str::random(6), 'title' => 'Lifting plan',
            'doc_type' => 'PROCEDURE', 'owner_id' => $this->user->id,
            'review_due' => Carbon::today()->subWeek()->toDateString(),
        ], $this->user->id);

        $service->draftVersion($this->companyId, $document['uuid'], [], $this->user->id);
        $service->publish($this->companyId, $document['uuid'], 1, $this->user->id);

        // The other half of what the reminder emails say, standing somewhere it can be checked.
        $this->assertSame($document['uuid'], $this->props()['documentsIOwn'][0]['uuid']);
    }

    public function test_a_procedure_somebody_else_owns_is_not_listed_as_mine(): void
    {
        $owner = $this->account();
        $service = app(DocumentService::class);

        $document = $service->create($this->companyId, [
            'doc_number' => 'DOC-'.Str::random(6), 'title' => 'Not mine',
            'doc_type' => 'PROCEDURE', 'owner_id' => $owner->id,
            'review_due' => Carbon::today()->subWeek()->toDateString(),
        ], $owner->id);

        $service->draftVersion($this->companyId, $document['uuid'], [], $owner->id);
        $service->publish($this->companyId, $document['uuid'], 1, $owner->id);

        $this->assertSame([], $this->props()['documentsIOwn']);
    }

    // ── It is my record and nobody else's ─────────────────────────────────────────────────────

    public function test_another_persons_ticket_is_not_on_my_screen(): void
    {
        $colleague = $this->account();
        $colleaguePersonId = $this->personFor($colleague->id);

        $this->record($this->competency(['title' => 'Theirs, not mine']), [], $colleaguePersonId);

        // The screen is gated on nothing but being signed in, so the scoping *is* the control.
        $this->assertSame([], $this->props()['competencies']);
        $this->assertCount(1, $this->props($colleague)['competencies']);
    }

    public function test_my_record_at_another_company_is_not_mine_here(): void
    {
        $other = (int) DB::table('companies')->insertGetId([
            'uuid' => (string) Str::uuid(), 'name' => 'Other Employer',
            'slug' => 'mine-other-'.Str::lower(Str::random(8)), 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // The same account carrying a personnel record at another tenant. Matching on user_id
        // alone, without the company, would hand this person the other employer's record.
        $elsewhere = $this->personFor($this->user->id, $other);

        $competencyId = (int) DB::table('sheq_competencies')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $other,
            'code' => 'X'.Str::random(5), 'title' => 'Theirs', 'competency_type' => 'TRAINING',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('sheq_competency_records')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => $other,
            'hr_person_id' => $elsewhere, 'competency_id' => $competencyId,
            'status' => 'COMPLIANT', 'achieved_at' => Carbon::today()->toDateString(),
            'source' => 'MANUAL', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $props = $this->props();

        $this->assertSame($this->personId, (int) DB::table('hr_people')
            ->where('company_id', $this->companyId)->where('user_id', $this->user->id)->value('id'));
        $this->assertSame([], $props['competencies']);
    }
}
