<?php

namespace Tests\Feature\Sheq;

use App\Core\User\Models\User;
use App\Modules\Operations\Sheq\Services\EmergencyCommandService;
use App\Modules\Operations\Sheq\Services\EmergencyService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * The emergency command centre (EMR-1 to EMR-6).
 *
 * Most of this module can be wrong for a week before anybody notices. This part is read once, by
 * somebody standing outside a building, and the cost of it being wrong is a person still inside.
 * So the tests here are shaped around the ways a roll call can be confidently incomplete rather
 * than around the happy path:
 *
 *   - built from one of the two occupancy tables, so the visitor is left behind
 *   - a `subject_type` nobody anticipated, so the name is filtered out of its own roll call
 *   - a second declaration, so there are two roll calls and each looks complete
 *   - re-read during the emergency, so the person who walked in at 10:12 is not on it — or the
 *     people already on it get a duplicate row and can be both found and missing
 *   - a first aider whose certificate lapsed last month
 *   - a stand-down while two names are still outstanding
 */
class EmergencyCommandTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

    private User $user;

    private int $siteId;

    private int $otherSiteId;

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
        $this->user = $this->userWith(['sheq.configure']);

        $this->siteId = $this->site('Tower block');
        $this->otherSiteId = $this->site('Yard');
    }

    private function service(): EmergencyCommandService
    {
        return app(EmergencyCommandService::class);
    }

    // ── EMR-1: declaring ──────────────────────────────────────────────────────────────────────

    public function test_declaring_captures_everything_the_requirement_lists(): void
    {
        $personId = $this->person('Nomsa', 'Dlamini');
        $at = Carbon::parse('2026-10-13 09:40:00');

        $result = $this->service()->declare($this->companyId, [
            'site_id' => $this->siteId,
            'emergency_type' => 'FIRE',
            'incident_commander_id' => $personId,
            'declared_at' => $at,
            'instructions' => 'Evacuate to the north gate. Do not use the lifts.',
        ], $this->user->id);

        $row = DB::table('sheq_emergencies')->where('uuid', $result['uuid'])->first();

        // EMR-1 names five things. All five, from the row rather than from the return value.
        $this->assertSame($this->siteId, (int) $row->site_id, 'EMR-1: the site is not recorded.');
        $this->assertSame('FIRE', $row->emergency_type, 'EMR-1: the type is not recorded.');
        $this->assertSame($personId, (int) $row->incident_commander_id,
            'EMR-1: the incident commander is not recorded.');
        $this->assertSame($at->toDateTimeString(), Carbon::parse($row->declared_at)->toDateTimeString(),
            'EMR-1: the start time is not the time it was declared.');
        $this->assertStringContainsString('north gate', $row->instructions,
            'EMR-1: the instructions are not recorded.');

        // The commander's name is denormalised so the board still reads correctly after the
        // person record is archived.
        $this->assertSame('Nomsa Dlamini', $row->incident_commander_name,
            'The board would show a blank commander once the person record is gone.');

        $this->assertSame('ACTIVE', $row->status);
        $this->assertSame($this->user->id, (int) $row->declared_by,
            'Nothing records who declared it, which is the first question an enquiry asks.');
    }

    public function test_an_unknown_emergency_type_is_refused(): void
    {
        $this->expectExceptionMessageMatches('/not a known emergency type/');

        $this->service()->declare($this->companyId, [
            'site_id' => $this->siteId, 'emergency_type' => 'ZOMBIES',
        ], $this->user->id);
    }

    public function test_a_second_declaration_on_the_same_site_is_refused_and_says_which_one_is_running(): void
    {
        $this->service()->declare($this->companyId, [
            'site_id' => $this->siteId, 'emergency_type' => 'FIRE',
            'declared_at' => Carbon::parse('2026-10-13 09:40:00'),
        ], $this->user->id);

        try {
            $this->service()->declare($this->companyId, [
                'site_id' => $this->siteId, 'emergency_type' => 'STRUCTURAL',
            ], $this->user->id);

            $this->fail('A second emergency was declared on a site that already had one. The roll '
                .'call is now in two halves and each of them looks complete.');
        } catch (RuntimeException $e) {
            // The refusal has to tell the person pressing the button what to do instead, because
            // they are pressing it in an emergency.
            $this->assertStringContainsString('already under a declared FIRE emergency', $e->getMessage());
            $this->assertStringContainsString('09:40', $e->getMessage(),
                'The refusal does not say when the running emergency started, so the person '
                .'cannot tell whether it is a stale one somebody forgot to stand down.');
        }

        // A different site is a different emergency and must still be allowed.
        $other = $this->service()->declare($this->companyId, [
            'site_id' => $this->otherSiteId, 'emergency_type' => 'FIRE',
        ], $this->user->id);

        $this->assertNotEmpty($other['uuid'],
            'Two sites cannot have emergencies at the same time, which a storm would cause.');
    }

    public function test_the_database_refuses_a_second_active_emergency_even_if_the_code_does_not(): void
    {
        $first = $this->service()->declare($this->companyId, [
            'site_id' => $this->siteId, 'emergency_type' => 'FIRE',
        ], $this->user->id);

        /*
         * Straight past the service, the way a future controller or an import would.
         *
         * Inside a savepoint, because the insert is supposed to fail and a failed statement
         * aborts the whole Postgres transaction — including the `DatabaseTransactions` one this
         * test runs in. Catching the exception does not restore the connection; every query after
         * it would fail with 25P02 and the rest of this test would be unreadable.
         */
        DB::statement('SAVEPOINT bypass_attempt');

        try {
            DB::table('sheq_emergencies')->insert([
                'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
                'site_id' => $this->siteId, 'reference' => 'EMR-BYPASS',
                'emergency_type' => 'FLOOD', 'declared_at' => now(), 'status' => 'ACTIVE',
                'created_at' => now(), 'updated_at' => now(),
            ]);

            DB::statement('RELEASE SAVEPOINT bypass_attempt');

            $this->fail('The partial unique index does not exist, so the guard against a split '
                .'roll call lives only in the one method that happens to check.');
        } catch (\Illuminate\Database\QueryException $e) {
            DB::statement('ROLLBACK TO SAVEPOINT bypass_attempt');

            $this->assertStringContainsString('sheq_one_active_emergency_per_site', $e->getMessage());
        }

        // Standing the first one down must release the site for the next real emergency.
        $this->service()->standDown($this->companyId, $first['uuid'], $this->user->id);

        $second = $this->service()->declare($this->companyId, [
            'site_id' => $this->siteId, 'emergency_type' => 'FLOOD',
        ], $this->user->id);

        $this->assertNotEmpty($second['uuid'],
            'A site that has had one emergency can never have another, because the index does not '
            .'exclude stood-down rows.');
    }

    // ── EMR-2: who is on site ─────────────────────────────────────────────────────────────────

    public function test_the_roll_call_includes_the_visitor_and_not_only_the_workers(): void
    {
        /*
         * The whole reason this class exists. Employees and contractors sign in to
         * `sheq_site_attendance`; visitors sign in to `sheq_visits`. Nothing joins them, so a roll
         * call built from the obvious table is complete, ordered, plausible, and leaves the
         * visitor inside the building.
         */
        $this->attend('Sipho Khumalo', 'EMPLOYEE');
        $this->attend('Johan Pretorius', 'CONTRACTOR_WORKER', $this->contractor('Acme Scaffolding'));
        $this->visit('Dr Reddy', 'Department of Labour');

        $declared = $this->service()->declare($this->companyId, [
            'site_id' => $this->siteId, 'emergency_type' => 'FIRE',
        ], $this->user->id);

        $names = DB::table('sheq_emergency_roll_call')
            ->where('emergency_id', DB::table('sheq_emergencies')->where('uuid', $declared['uuid'])->value('id'))
            ->pluck('person_name')->all();

        $this->assertContains('Sipho Khumalo', $names);
        $this->assertContains('Johan Pretorius', $names);
        $this->assertContains('Dr Reddy', $names,
            'The visitor is not on the roll call. The list reads as complete and the building is '
            .'declared clear with an inspector from the Department of Labour still inside it.');

        $this->assertSame(3, $declared['on_site']);
    }

    public function test_the_four_categories_the_requirement_asks_for_are_all_distinguished(): void
    {
        $parent = $this->contractor('Acme Scaffolding');
        $child = $this->contractor('Ndlovu Rigging', $parent);

        $this->attend('Sipho Khumalo', 'EMPLOYEE');
        $this->attend('Johan Pretorius', 'CONTRACTOR_WORKER', $parent);
        $this->attend('Thabo Ndlovu', 'CONTRACTOR_WORKER', $child);
        $this->visit('Dr Reddy', 'Department of Labour');

        $declared = $this->service()->declare($this->companyId, [
            'site_id' => $this->siteId, 'emergency_type' => 'FIRE',
        ], $this->user->id);

        $board = $this->service()->board($this->companyId, $declared['uuid']);
        $byCategory = $board['counts']['by_category'];

        // EMR-2 lists exactly these four.
        $this->assertSame(1, $byCategory[EmergencyCommandService::EMPLOYEE]);
        $this->assertSame(1, $byCategory[EmergencyCommandService::CONTRACTOR]);
        $this->assertSame(1, $byCategory[EmergencyCommandService::SUBCONTRACTOR],
            'A subcontractor worker is counted as a contractor, so the parent contractor is told '
            .'to account for people it does not employ and nobody is told to account for these.');
        $this->assertSame(1, $byCategory[EmergencyCommandService::VISITOR]);
    }

    public function test_a_person_whose_type_we_do_not_recognise_is_still_on_the_roll_call(): void
    {
        /*
         * `subject_type` is whatever the gate integration passed. It is CONTRACTOR_WORKER today
         * and will be something else the day somebody wires up a turnstile. Classifying it is a
         * presentation concern; it is never permission to leave a name off the list.
         */
        $this->attend('Unknown Visitor', 'BIOMETRIC_WALK_IN');

        $declared = $this->service()->declare($this->companyId, [
            'site_id' => $this->siteId, 'emergency_type' => 'FIRE',
        ], $this->user->id);

        $board = $this->service()->board($this->companyId, $declared['uuid']);

        $this->assertSame(1, $board['counts']['total'],
            'A person with an unrecognised subject_type was filtered out of the roll call.');
        $this->assertSame(EmergencyCommandService::PERSON, $board['roll_call'][0]['subject_type'],
            'An unclassifiable person must be listed as a person, not dropped and not guessed at.');
    }

    public function test_a_person_with_no_name_is_listed_rather_than_shown_as_a_blank_row(): void
    {
        DB::table('sheq_site_attendance')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'site_id' => $this->siteId, 'subject_type' => 'EMPLOYEE', 'person_name' => null,
            'entered_at' => now()->subHour(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $declared = $this->service()->declare($this->companyId, [
            'site_id' => $this->siteId, 'emergency_type' => 'FIRE',
        ], $this->user->id);

        $board = $this->service()->board($this->companyId, $declared['uuid']);

        $this->assertSame(1, $board['counts']['total']);
        $this->assertNotSame('', trim($board['roll_call'][0]['person_name']),
            'An unnamed person is an empty cell a reader\'s eye slides past. They are still '
            .'somebody who is inside.');
    }

    public function test_someone_who_walks_in_after_the_declaration_joins_the_roll_call(): void
    {
        $this->attend('Sipho Khumalo', 'EMPLOYEE');

        $declared = $this->service()->declare($this->companyId, [
            'site_id' => $this->siteId, 'emergency_type' => 'FIRE',
        ], $this->user->id);

        $this->assertSame(1, $declared['on_site']);

        // A delivery driver who did not know, or a manager who came to help.
        $this->attend('Late Arrival', 'EMPLOYEE');

        $board = $this->service()->board($this->companyId, $declared['uuid']);

        $this->assertSame(2, $board['counts']['total'],
            'The roll call was captured once at declaration and is wrong within minutes.');
    }

    public function test_re_reading_occupancy_does_not_duplicate_anybody_or_undo_a_judgement(): void
    {
        $this->attend('Sipho Khumalo', 'EMPLOYEE');

        $declared = $this->service()->declare($this->companyId, [
            'site_id' => $this->siteId, 'emergency_type' => 'FIRE',
        ], $this->user->id);

        $emergencyId = (int) DB::table('sheq_emergencies')->where('uuid', $declared['uuid'])->value('id');

        $row = DB::table('sheq_emergency_roll_call')->where('emergency_id', $emergencyId)
            ->first(['uuid']);

        $this->service()->mark($this->companyId, $declared['uuid'], $row->uuid,
            EmergencyCommandService::MISSING, $this->user->id);

        // Three more reads, which is what a screen polling every ten seconds does.
        $this->service()->board($this->companyId, $declared['uuid']);
        $this->service()->board($this->companyId, $declared['uuid']);
        $board = $this->service()->board($this->companyId, $declared['uuid']);

        $this->assertSame(1, $board['counts']['total'],
            'Re-reading occupancy duplicated the roll-call row. The same person can now be marked '
            .'accounted for in one row and missing in the other, and the screen shows whichever '
            .'it happens to order first.');

        $this->assertSame(1, $board['counts']['missing'],
            'Re-reading occupancy reset a judgement somebody had already made.');
    }

    public function test_the_roll_call_keeps_its_own_copy_of_the_name(): void
    {
        $contractorId = $this->contractor('Acme Scaffolding');
        $this->attend('Johan Pretorius', 'CONTRACTOR_WORKER', $contractorId);

        $declared = $this->service()->declare($this->companyId, [
            'site_id' => $this->siteId, 'emergency_type' => 'FIRE',
        ], $this->user->id);

        // The attendance row is closed and the contractor is gone, which is the state an enquiry
        // two years later reads this in.
        DB::table('sheq_site_attendance')->where('company_id', $this->companyId)
            ->update(['exited_at' => now()]);
        DB::table('sheq_contractors')->where('id', $contractorId)
            ->update(['deleted_at' => now()]);

        $board = $this->service()->board($this->companyId, $declared['uuid']);

        $this->assertSame('Johan Pretorius', $board['roll_call'][0]['person_name'],
            'The roll call joins to live occupancy, so the record of who was inside the building '
            .'empties itself as those rows are archived.');
        $this->assertSame('Acme Scaffolding', $board['roll_call'][0]['contractor_name']);
    }

    // ── EMR-3: accounting for people ──────────────────────────────────────────────────────────

    public function test_all_four_states_the_requirement_names_are_recordable_with_a_timestamp(): void
    {
        foreach ([EmergencyCommandService::ACCOUNTED, EmergencyCommandService::MISSING,
            EmergencyCommandService::INJURED, EmergencyCommandService::EVACUATED] as $status) {
            $this->assertContains($status, EmergencyCommandService::STATUSES,
                "EMR-3 names {$status} and the roll call cannot record it.");
        }

        $this->attend('Sipho Khumalo', 'EMPLOYEE');

        $declared = $this->service()->declare($this->companyId, [
            'site_id' => $this->siteId, 'emergency_type' => 'FIRE',
        ], $this->user->id);

        $uuid = DB::table('sheq_emergency_roll_call')
            ->where('company_id', $this->companyId)->value('uuid');

        $result = $this->service()->mark($this->companyId, $declared['uuid'], $uuid,
            EmergencyCommandService::INJURED, $this->user->id,
            ['location_text' => 'Third floor, east stairwell', 'note' => 'Smoke inhalation']);

        $this->assertSame(EmergencyCommandService::UNACCOUNTED, $result['was']);
        $this->assertSame(EmergencyCommandService::INJURED, $result['now']);

        $row = DB::table('sheq_emergency_roll_call')->where('uuid', $uuid)->first();

        $this->assertNotNull($row->status_at,
            'EMR-3 asks for timestamps. "Thabo is missing" is a different statement at 10:04 and '
            .'at 10:41, and a rescue decision is made on the second one.');
        $this->assertSame($this->user->id, (int) $row->status_by);
        $this->assertSame('Third floor, east stairwell', $row->location_text,
            'Nothing records where an injured person was found, which is what a rescue team is told.');
    }

    public function test_the_board_counts_each_state_separately(): void
    {
        foreach (['A', 'B', 'C', 'D'] as $initial) {
            $this->attend("Person {$initial}", 'EMPLOYEE');
        }

        $declared = $this->service()->declare($this->companyId, [
            'site_id' => $this->siteId, 'emergency_type' => 'FIRE',
        ], $this->user->id);

        $uuids = DB::table('sheq_emergency_roll_call')
            ->where('company_id', $this->companyId)->orderBy('person_name')->pluck('uuid')->all();

        $this->service()->mark($this->companyId, $declared['uuid'], $uuids[0],
            EmergencyCommandService::ACCOUNTED, $this->user->id);
        $this->service()->mark($this->companyId, $declared['uuid'], $uuids[1],
            EmergencyCommandService::MISSING, $this->user->id);
        $this->service()->mark($this->companyId, $declared['uuid'], $uuids[2],
            EmergencyCommandService::INJURED, $this->user->id);

        $counts = $this->service()->board($this->companyId, $declared['uuid'])['counts'];

        $this->assertSame(4, $counts['total']);
        $this->assertSame(1, $counts['accounted']);
        $this->assertSame(1, $counts['missing']);
        $this->assertSame(1, $counts['injured']);
        $this->assertSame(1, $counts['unaccounted']);
    }

    public function test_the_people_who_have_not_been_found_are_at_the_top_of_the_list(): void
    {
        $this->attend('Aaron Accounted', 'EMPLOYEE');
        $this->attend('Zodwa Zulu', 'EMPLOYEE');

        $declared = $this->service()->declare($this->companyId, [
            'site_id' => $this->siteId, 'emergency_type' => 'FIRE',
        ], $this->user->id);

        $aaron = DB::table('sheq_emergency_roll_call')
            ->where('person_name', 'Aaron Accounted')->value('uuid');
        $zodwa = DB::table('sheq_emergency_roll_call')
            ->where('person_name', 'Zodwa Zulu')->value('uuid');

        $this->service()->mark($this->companyId, $declared['uuid'], $aaron,
            EmergencyCommandService::ACCOUNTED, $this->user->id);
        $this->service()->mark($this->companyId, $declared['uuid'], $zodwa,
            EmergencyCommandService::MISSING, $this->user->id);

        $board = $this->service()->board($this->companyId, $declared['uuid']);

        // Alphabetically Aaron comes first. The screen is read top-down under pressure, so the
        // name that needs acting on belongs where the eye lands.
        $this->assertSame('Zodwa Zulu', $board['roll_call'][0]['person_name'],
            'The missing person is below the accounted-for one because the list is sorted by name.');
    }

    public function test_marking_somebody_who_is_not_on_this_roll_call_is_refused(): void
    {
        $this->attend('Sipho Khumalo', 'EMPLOYEE');

        $mine = $this->service()->declare($this->companyId, [
            'site_id' => $this->siteId, 'emergency_type' => 'FIRE',
        ], $this->user->id);

        $this->attend('Someone Else', 'EMPLOYEE', null, $this->otherSiteId);

        $other = $this->service()->declare($this->companyId, [
            'site_id' => $this->otherSiteId, 'emergency_type' => 'FLOOD',
        ], $this->user->id);

        $theirs = DB::table('sheq_emergency_roll_call')
            ->where('person_name', 'Someone Else')->value('uuid');

        $this->expectExceptionMessageMatches('/not on this roll call/');

        // Marking the other site's person accounted for from this site's board would clear a name
        // that nobody has actually seen.
        $this->service()->mark($this->companyId, $mine['uuid'], $theirs,
            EmergencyCommandService::ACCOUNTED, $this->user->id);
    }

    public function test_a_stood_down_emergency_cannot_be_marked_up_again(): void
    {
        $this->attend('Sipho Khumalo', 'EMPLOYEE');

        $declared = $this->service()->declare($this->companyId, [
            'site_id' => $this->siteId, 'emergency_type' => 'FIRE',
        ], $this->user->id);

        $uuid = DB::table('sheq_emergency_roll_call')->value('uuid');

        $this->service()->mark($this->companyId, $declared['uuid'], $uuid,
            EmergencyCommandService::ACCOUNTED, $this->user->id);
        $this->service()->standDown($this->companyId, $declared['uuid'], $this->user->id);

        $this->expectExceptionMessageMatches('/already been stood down/');

        $this->service()->mark($this->companyId, $declared['uuid'], $uuid,
            EmergencyCommandService::MISSING, $this->user->id);
    }

    public function test_standing_down_with_people_outstanding_needs_a_reason_and_raises_an_action(): void
    {
        $this->attend('Sipho Khumalo', 'EMPLOYEE');
        $this->attend('Thabo Ndlovu', 'EMPLOYEE');

        $declared = $this->service()->declare($this->companyId, [
            'site_id' => $this->siteId, 'emergency_type' => 'FIRE',
        ], $this->user->id);

        try {
            $this->service()->standDown($this->companyId, $declared['uuid'], $this->user->id);
            $this->fail('An emergency was stood down with two people unaccounted for and nothing '
                .'was recorded about that decision.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('2 of the people', $e->getMessage());
        }

        $result = $this->service()->standDown($this->companyId, $declared['uuid'], $this->user->id, [
            'stood_down_with_unaccounted_reason' => 'Both signed out at the far gate without '
                .'scanning. Confirmed by phone.',
        ]);

        $this->assertSame(2, $result['unaccounted']);

        $row = DB::table('sheq_emergencies')->where('uuid', $declared['uuid'])->first();

        $this->assertSame(2, (int) $row->unaccounted_at_stand_down,
            'The count is not kept, so the record no longer shows that the decision was made with '
            .'people outstanding.');
        $this->assertStringContainsString('far gate', $row->stood_down_with_unaccounted_reason);

        // An evacuation that ended with somebody unaccounted for is the strongest thing this
        // module can know about a site's arrangements. It does not get to be a note.
        $this->assertTrue(
            DB::table('sheq_actions')->where('source_type', 'EMERGENCY')
                ->where('source_id', $row->id)->exists(),
            'No corrective action was raised, so the two unaccounted names are a sentence in a '
            .'field nobody reads again.'
        );
    }

    public function test_standing_down_with_everybody_found_needs_no_reason(): void
    {
        $this->attend('Sipho Khumalo', 'EMPLOYEE');

        $declared = $this->service()->declare($this->companyId, [
            'site_id' => $this->siteId, 'emergency_type' => 'FIRE',
        ], $this->user->id);

        $uuid = DB::table('sheq_emergency_roll_call')->value('uuid');

        $this->service()->mark($this->companyId, $declared['uuid'], $uuid,
            EmergencyCommandService::EVACUATED, $this->user->id);

        $result = $this->service()->standDown($this->companyId, $declared['uuid'], $this->user->id);

        $this->assertSame(0, $result['unaccounted']);
        $this->assertFalse(
            DB::table('sheq_actions')->where('source_type', 'EMERGENCY')->exists(),
            'A clean evacuation raised a corrective action, which trains people to ignore them.'
        );
    }

    // ── EMR-4: who and what can help ──────────────────────────────────────────────────────────

    public function test_an_expired_first_aid_certificate_removes_the_person_from_the_responders(): void
    {
        /*
         * The reason this derives from competency records instead of a roster. A roster is stale
         * the day somebody's certificate lapses, and in an emergency it sends people to somebody
         * who may no longer be qualified — or worse, who knows they are not and has to say so at
         * the assembly point.
         */
        $current = $this->person('Current', 'Responder');
        $lapsed = $this->person('Lapsed', 'Responder');

        $competencyId = $this->competency('FIRST_AID', 'First aid level 1', 'FIRST_AIDER');

        $this->competencyRecord($competencyId, $current, Carbon::today()->addMonths(6));
        $this->competencyRecord($competencyId, $lapsed, Carbon::today()->subMonth());

        $responders = $this->service()->responders($this->companyId, $this->siteId);
        $names = array_column($responders['FIRST_AIDER'], 'name');

        $this->assertContains('Current Responder', $names);
        $this->assertNotContains('Lapsed Responder', $names,
            'Somebody whose first-aid certificate expired last month is still listed as a first '
            .'aider, and in an emergency people will be sent to them.');
    }

    public function test_an_unaccepted_appointment_does_not_make_somebody_a_first_aider(): void
    {
        /*
         * Section 17 appoints a first aider in writing, and the appointment takes effect when it
         * is accepted. `CompetencyService::deriveStatus()` already treats an unaccepted
         * appointment as NOT_STARTED; this list has to agree with it, because a responder board
         * that disagreed with the competency screen would be read by somebody with no way to know
         * which of the two was lying.
         */
        $person = $this->person('Unsigned', 'Letter');
        $competencyId = $this->competency('FIRST_AID_2', 'First aid level 2', 'FIRST_AIDER');

        $id = $this->competencyRecord($competencyId, $person, Carbon::today()->addYear());

        DB::table('sheq_competency_records')->where('id', $id)->update([
            'appointment_scope' => 'SITE', 'accepted_at' => null,
        ]);

        $names = array_column(
            $this->service()->responders($this->companyId, $this->siteId)['FIRST_AIDER'], 'name');

        $this->assertNotContains('Unsigned Letter', $names,
            'An appointment letter nobody signed put a name on the responder board.');

        DB::table('sheq_competency_records')->where('id', $id)->update(['accepted_at' => now()]);

        $names = array_column(
            $this->service()->responders($this->companyId, $this->siteId)['FIRST_AIDER'], 'name');

        $this->assertContains('Unsigned Letter', $names,
            'Accepting the appointment did not put them on the board either, so the role can '
            .'never be filled.');
    }

    public function test_a_revoked_appointment_removes_the_person(): void
    {
        $person = $this->person('Revoked', 'Marshal');
        $competencyId = $this->competency('FIRE_MARSHAL', 'Fire marshal', 'FIRE_MARSHAL');
        $id = $this->competencyRecord($competencyId, $person, Carbon::today()->addYear());

        $this->assertNotEmpty($this->service()->responders($this->companyId, $this->siteId)['FIRE_MARSHAL']);

        DB::table('sheq_competency_records')->where('id', $id)
            ->update(['revoked_at' => now(), 'revocation_reason' => 'Left the company']);

        $this->assertEmpty(
            $this->service()->responders($this->companyId, $this->siteId)['FIRE_MARSHAL'],
            'A revoked appointment still lists the person as a fire marshal.'
        );
    }

    public function test_every_emergency_role_the_service_declares_appears_on_the_board(): void
    {
        $board = $this->service()->responders($this->companyId, $this->siteId);

        foreach (EmergencyCommandService::ROLES as $role) {
            $this->assertArrayHasKey($role, $board,
                "The {$role} role is declared and the board has no key for it, so a commander "
                .'looking for one sees nothing rather than "none appointed".');
        }
    }

    public function test_responders_who_are_on_site_are_marked_and_sorted_first(): void
    {
        $here = $this->person('Here', 'Now');
        $home = $this->person('At', 'Home');
        $competencyId = $this->competency('FIRST_AID_3', 'First aid', 'FIRST_AIDER');

        $this->competencyRecord($competencyId, $here, Carbon::today()->addYear());
        $this->competencyRecord($competencyId, $home, Carbon::today()->addYear());

        $this->attend('Here Now', 'EMPLOYEE', null, $this->siteId, $here);

        $first = $this->service()->responders($this->companyId, $this->siteId)['FIRST_AIDER'][0];

        $this->assertSame('Here Now', $first['name'],
            'A first aider who is at home is a phone call, not a responder, and is listed above '
            .'the one standing at the assembly point.');
        $this->assertTrue($first['on_site']);
    }

    public function test_the_board_shows_equipment_and_nearby_facilities_and_flags_a_lapsed_service(): void
    {
        $this->service()->saveResource($this->companyId, [
            'site_id' => $this->siteId, 'kind' => 'EQUIPMENT', 'category' => 'FIRE_EXTINGUISHER',
            'name' => 'DCP 9kg — reception', 'location_text' => 'Reception, behind the desk',
            'quantity' => 2, 'next_inspection_due' => Carbon::today()->subMonth()->toDateString(),
        ], $this->user->id);

        $this->service()->saveResource($this->companyId, [
            'site_id' => null, 'kind' => 'FACILITY', 'category' => 'HOSPITAL',
            'name' => 'Milpark Hospital', 'phone' => '011 480 5600',
            'address' => '9 Guild Rd, Parktown', 'distance_km' => 4.2,
        ], $this->user->id);

        $resources = $this->service()->resources($this->companyId, $this->siteId);

        $this->assertCount(1, $resources[EmergencyCommandService::EQUIPMENT]);
        $this->assertTrue($resources[EmergencyCommandService::EQUIPMENT][0]['overdue_inspection'],
            'A fire extinguisher whose service date passed last month is listed as available '
            .'equipment with nothing to say it may not work.');

        $this->assertCount(1, $resources[EmergencyCommandService::FACILITY],
            'A facility recorded against no site in particular is invisible from every site, '
            .'which is where one ambulance service covering them all ends up.');
        $this->assertSame('011 480 5600', $resources[EmergencyCommandService::FACILITY][0]['phone']);
    }

    public function test_the_board_carries_the_plan_the_assembly_points_and_the_contacts(): void
    {
        app(EmergencyService::class)->createPlan($this->companyId, [
            'site_id' => $this->siteId, 'emergency_type' => 'FIRE',
            'name' => 'Tower block fire plan',
            'procedure' => 'Sound the alarm. Evacuate by the north stairwell.',
            'assembly_points' => [['name' => 'North gate car park', 'capacity' => 200]],
            'emergency_contacts' => [['role' => 'Fire brigade', 'phone' => '10177']],
            'incident_command' => [['role' => 'Incident commander', 'name' => 'Site manager']],
        ], $this->user->id);

        $declared = $this->service()->declare($this->companyId, [
            'site_id' => $this->siteId, 'emergency_type' => 'FIRE',
        ], $this->user->id);

        $board = $this->service()->board($this->companyId, $declared['uuid'])['emergency'];

        // EMR-4 lists all three, and they are on the plan rather than duplicated onto the
        // emergency — so the board has to reach them through the plan it attached.
        $this->assertSame('Tower block fire plan', $board['plan_name'],
            'The emergency did not attach the site\'s plan, so the commander has the procedure '
            .'open in another tab or not at all.');
        $this->assertSame('North gate car park', $board['assembly_points'][0]['name']);
        $this->assertSame('10177', $board['emergency_contacts'][0]['phone']);
        $this->assertNotEmpty($board['incident_command']);
    }

    // ── EMR-5: what is dangerous right now ────────────────────────────────────────────────────

    public function test_the_board_shows_the_four_hazard_pictures_the_requirement_asks_for(): void
    {
        $hazards = $this->service()->hazardPicture($this->companyId, $this->siteId);

        foreach (['active_permits', 'hazardous_materials', 'high_risk_activities', 'major_risks'] as $key) {
            $this->assertArrayHasKey($key, $hazards,
                "EMR-5 asks for {$key} and the board does not provide it.");
        }
    }

    public function test_a_suspended_permit_still_counts_as_open_work(): void
    {
        /*
         * A suspended permit means the work stopped. The scaffold, the open trench and the
         * hot-work residue are all still there, which is exactly what somebody deciding whether
         * to send a crew back inside is asking about.
         */
        $this->permit('PTW-ACTIVE', 'HOT_WORK', 'ACTIVE');
        $this->permit('PTW-SUSPENDED', 'EXCAVATION', 'SUSPENDED');
        $this->permit('PTW-CLOSED', 'CONFINED_SPACE', 'CLOSED');

        $references = array_column(
            $this->service()->hazardPicture($this->companyId, $this->siteId)['active_permits'],
            'reference');

        $this->assertContains('PTW-ACTIVE', $references);
        $this->assertContains('PTW-SUSPENDED', $references,
            'A suspended permit is left off the board, so the commander is not told about the '
            .'open trench the crew stopped digging.');
        $this->assertNotContains('PTW-CLOSED', $references,
            'Closed permits are listed as open work, which buries the ones that matter.');
    }

    public function test_the_hazardous_substances_held_on_the_site_are_shown_with_what_to_put_on_them(): void
    {
        /*
         * This register had to be added, because nothing held it. Waste movements looked like the
         * place — and `removed_on` is NOT NULL on that table, so a movement row is a record of
         * waste that has already left. Exposure agents are what people are monitored against,
         * which is a third question. The drums in the store were nowhere, which is the first
         * thing a fire officer asks about on arrival.
         */
        $this->service()->saveSubstance($this->companyId, [
            'site_id' => $this->siteId, 'name' => 'Acetylene', 'un_number' => 'UN1001',
            'hazard_class' => '2.1', 'quantity' => 6, 'unit' => 'cylinders',
            'location_text' => 'Gas cage, north wall', 'is_flammable' => true,
            'fire_fighting_media' => 'Cool with water spray from a protected position. Do not '
                .'extinguish a leaking gas flame unless the supply can be isolated.',
            'incompatible_with' => 'Copper, silver, mercury and their alloys.',
        ], $this->user->id);

        $this->service()->saveSubstance($this->companyId, [
            'site_id' => $this->otherSiteId, 'name' => 'Sodium hypochlorite',
            'quantity' => 200, 'unit' => 'L', 'is_toxic' => true,
        ], $this->user->id);

        $materials = $this->service()
            ->hazardPicture($this->companyId, $this->siteId)['hazardous_materials'];

        $this->assertCount(1, $materials['substances'],
            'The inventory shows substances from other sites, or none at all.');

        $acetylene = $materials['substances'][0];

        $this->assertSame('Acetylene', $acetylene['name']);
        $this->assertSame('Gas cage, north wall', $acetylene['location_text'],
            'Nothing says where it is, so the inventory cannot be acted on.');
        $this->assertStringContainsString('protected position', $acetylene['fire_fighting_media'],
            'The board lists the substance and not what to put on it, which is the part that '
            .'stops somebody spraying the wrong thing at it.');
        $this->assertStringContainsString('Copper', $acetylene['incompatible_with']);
    }

    public function test_a_substance_with_no_site_is_refused(): void
    {
        $this->expectExceptionMessageMatches('/held somewhere/');

        $this->service()->saveSubstance($this->companyId, ['name' => 'Acetylene'], $this->user->id);
    }

    public function test_an_open_spill_is_on_the_board_and_a_closed_one_is_not(): void
    {
        DB::table('sheq_spills')->insert([
            ['uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
                'reference' => 'SP-OPEN', 'substance' => 'Diesel', 'is_hazardous' => true,
                'quantity' => 40, 'unit' => 'L', 'site_id' => $this->siteId,
                'receiving_environment' => 'SOIL', 'occurred_at' => now()->subHour(),
                'status' => 'OPEN', 'created_at' => now(), 'updated_at' => now()],
            ['uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
                'reference' => 'SP-CLOSED', 'substance' => 'Hydraulic oil', 'is_hazardous' => true,
                'quantity' => 5, 'unit' => 'L', 'site_id' => $this->siteId,
                'receiving_environment' => 'HARD_SURFACE', 'occurred_at' => now()->subDays(30),
                'status' => 'CLOSED', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $references = array_column(
            $this->service()->hazardPicture($this->companyId, $this->siteId)['hazardous_materials']['open_spills'],
            'reference');

        $this->assertContains('SP-OPEN', $references,
            'A spill that has not been cleaned up is not shown to the people arriving at it.');
        $this->assertNotContains('SP-CLOSED', $references);
    }

    public function test_only_this_sites_hazards_are_shown(): void
    {
        $this->permit('PTW-HERE', 'HOT_WORK', 'ACTIVE', $this->siteId);
        $this->permit('PTW-THERE', 'HOT_WORK', 'ACTIVE', $this->otherSiteId);

        $references = array_column(
            $this->service()->hazardPicture($this->companyId, $this->siteId)['active_permits'],
            'reference');

        $this->assertContains('PTW-HERE', $references);
        $this->assertNotContains('PTW-THERE', $references,
            'The hazard picture shows work on other sites, so the commander cannot tell what is '
            .'in front of them.');
    }

    // ── EMR-6: drills ─────────────────────────────────────────────────────────────────────────

    public function test_a_drill_records_everything_the_requirement_lists(): void
    {
        $planId = app(EmergencyService::class)->createPlan($this->companyId, [
            'site_id' => $this->siteId, 'emergency_type' => 'FIRE', 'name' => 'Fire plan',
            'assembly_points' => [['name' => 'North gate']],
        ], $this->user->id)['id'];

        $drill = app(EmergencyService::class)->recordDrill($this->companyId, [
            'plan_id' => $planId, 'site_id' => $this->siteId,
            'drill_date' => Carbon::today()->toDateString(), 'drill_type' => 'FIRE',
            // One unaccounted for, which is what raises the action EMR-6 asks a drill to
            // produce — and matches the attendance list below, where one person did not come out.
            'evacuation_seconds' => 265, 'participants' => 3, 'unaccounted' => 1,
            'observations' => 'The east stairwell door was chained.',
            'improvements' => 'Daily door check added to the opening routine.',
        ], $this->user->id);

        DB::table('sheq_emergency_drills')->where('uuid', $drill['uuid'])->update([
            'scenario' => 'Fire in the third-floor server room during a shift change.',
        ]);

        $this->service()->recordDrillAttendance($this->companyId, $drill['uuid'], [
            ['person_name' => 'Sipho Khumalo', 'subject_type' => 'EMPLOYEE',
                'was_present' => true, 'accounted_seconds' => 180],
            ['person_name' => 'Johan Pretorius', 'subject_type' => 'CONTRACTOR',
                'contractor_name' => 'Acme Scaffolding', 'was_present' => true,
                'accounted_seconds' => 265],
            ['person_name' => 'Thabo Ndlovu', 'subject_type' => 'EMPLOYEE',
                'was_present' => false, 'note' => 'Did not reach the assembly point.'],
        ], $this->user->id);

        $row = DB::table('sheq_emergency_drills')->where('uuid', $drill['uuid'])->first();

        // EMR-6 names six things.
        $this->assertStringContainsString('server room', $row->scenario, 'EMR-6: no scenario.');
        $this->assertSame(265, (int) $row->evacuation_seconds, 'EMR-6: no response time.');
        $this->assertStringContainsString('chained', $row->observations, 'EMR-6: no findings.');
        $this->assertStringContainsString('door check', $row->improvements,
            'EMR-6: no lessons learned.');
        $this->assertNotNull($row->action_id, 'EMR-6: no action came out of the drill.');

        $attendance = $this->service()->drillAttendance($this->companyId, $drill['uuid']);

        $this->assertCount(3, $attendance, 'EMR-6: no attendance, only a count of participants.');
        $seconds = array_column($attendance, 'accounted_seconds', 'person_name');

        $this->assertSame(180, (int) $seconds['Sipho Khumalo'],
            'Per-person times are not kept, so the drill reports the slowest person\'s time and '
            .'says nothing about who that was or which area they came from.');
        $this->assertSame(265, (int) $seconds['Johan Pretorius'],
            'The site time of 265 seconds is one person\'s time. Without the per-person column '
            .'there is no way to see that it was the contractor crew holding it up.');
    }

    public function test_recording_attendance_corrects_the_drills_own_counts(): void
    {
        $drill = app(EmergencyService::class)->recordDrill($this->companyId, [
            'site_id' => $this->siteId, 'drill_date' => Carbon::today()->toDateString(),
            'drill_type' => 'EVACUATION', 'evacuation_seconds' => 300,
            // Wrong on purpose: somebody typed it in before the attendance list was collated.
            'participants' => 9, 'unaccounted' => 0,
        ], $this->user->id);

        $this->service()->recordDrillAttendance($this->companyId, $drill['uuid'], [
            ['person_name' => 'A', 'was_present' => true],
            ['person_name' => 'B', 'was_present' => true],
            ['person_name' => 'C', 'was_present' => false],
        ], $this->user->id);

        $row = DB::table('sheq_emergency_drills')->where('uuid', $drill['uuid'])->first();

        $this->assertSame(2, (int) $row->participants,
            'The drill says nine participants and the attendance list has three names. Nobody '
            .'reading the report can tell which number is the drill.');
        $this->assertSame(1, (int) $row->unaccounted);
    }

    public function test_attendance_for_an_unknown_drill_is_refused(): void
    {
        $this->expectExceptionMessageMatches('/No such drill/');

        $this->service()->recordDrillAttendance($this->companyId, (string) Str::uuid(),
            [['person_name' => 'A']], $this->user->id);
    }

    // ── Tenancy ───────────────────────────────────────────────────────────────────────────────

    public function test_another_tenants_emergency_is_not_reachable(): void
    {
        $declared = $this->service()->declare($this->companyId, [
            'site_id' => $this->siteId, 'emergency_type' => 'FIRE',
        ], $this->user->id);

        $other = (int) DB::table('companies')->where('id', '!=', $this->companyId)->min('id');

        if ($other === 0) {
            $this->markTestSkipped('Only one company in the test database.');
        }

        $this->expectExceptionMessageMatches('/No such emergency/');

        $this->service()->board($other, $declared['uuid']);
    }

    // ── The screen ────────────────────────────────────────────────────────────────────────────

    public function test_the_page_opens_on_the_running_emergency_without_being_asked(): void
    {
        $this->attend('Sipho Khumalo', 'EMPLOYEE');
        $this->visit('Dr Reddy', 'Department of Labour');

        $declared = $this->service()->declare($this->companyId, [
            'site_id' => $this->siteId, 'emergency_type' => 'FIRE',
            'instructions' => 'Evacuate to the north gate.',
        ], $this->user->id);

        $response = $this->actingAs($this->user)->get('/sheq/emergency-command');

        $response->assertOk();

        $page = $response->viewData('page')['props'];

        $this->assertNotNull($page['board'],
            'The screen opened with no board while an emergency was running, so the commander has '
            .'to find it in a list first.');
        $this->assertSame($declared['reference'], $page['board']['emergency']['reference']);
        $this->assertSame(2, $page['board']['counts']['total'],
            'The page does not carry both occupancy sources.');
    }

    public function test_the_page_sends_everything_the_six_requirements_need(): void
    {
        $declared = $this->service()->declare($this->companyId, [
            'site_id' => $this->siteId, 'emergency_type' => 'FIRE',
        ], $this->user->id);

        $page = $this->actingAs($this->user)->get('/sheq/emergency-command')
            ->viewData('page')['props'];

        // EMR-1: the vocabulary the declare form needs.
        $this->assertNotEmpty($page['types']);
        $this->assertNotEmpty($page['sites']);

        // EMR-2/EMR-3.
        $this->assertArrayHasKey('roll_call', $page['board']);
        $this->assertSame(EmergencyCommandService::STATUSES, $page['statuses']);
        $this->assertSame(EmergencyCommandService::CATEGORIES, $page['categories']);

        // EMR-4.
        $this->assertSame(EmergencyCommandService::ROLES, $page['roles']);
        $this->assertArrayHasKey('responders', $page['board']);
        $this->assertArrayHasKey(EmergencyCommandService::EQUIPMENT, $page['board']['resources']);
        $this->assertArrayHasKey(EmergencyCommandService::FACILITY, $page['board']['resources']);

        // EMR-5, all four of them.
        foreach (['active_permits', 'hazardous_materials', 'high_risk_activities',
            'major_risks'] as $key) {
            $this->assertArrayHasKey($key, $page['board']['hazards'],
                "The screen cannot show {$key}, which EMR-5 asks for.");
        }

        // EMR-6.
        $this->assertArrayHasKey('drills', $page);
        $this->assertArrayHasKey('overdueDrills', $page);

        // CMD-3's data freshness, which matters more on this screen than any other.
        $this->assertNotEmpty($page['board']['as_at'],
            'Nothing tells the reader how old the roll call they are looking at is.');
    }

    public function test_a_stale_emergency_in_the_url_does_not_blank_the_screen(): void
    {
        $response = $this->actingAs($this->user)
            ->get('/sheq/emergency-command?emergency='.Str::uuid());

        $response->assertOk();

        $this->assertNull($response->viewData('page')['props']['board'],
            'A link to a deleted emergency should show the registers, not an error page.');
    }

    public function test_the_page_tells_this_reader_whether_they_may_declare(): void
    {
        $page = $this->actingAs($this->user)->get('/sheq/emergency-command')
            ->viewData('page')['props'];

        $this->assertFalse($page['canDeclare'],
            'A reader without sheq.incident.report is offered a Declare button the server will '
            .'refuse.');
        $this->assertTrue($page['canConfigure']);

        $declarer = $this->userWith(['sheq.incident.report']);

        $page = $this->actingAs($declarer)->get('/sheq/emergency-command')
            ->viewData('page')['props'];

        $this->assertTrue($page['canDeclare']);
    }

    public function test_declaring_through_the_route_refuses_a_reader_who_may_not(): void
    {
        $this->actingAs($this->user)->post('/sheq/emergency-command/declare', [
            'site_id' => $this->siteId, 'emergency_type' => 'FIRE',
        ])->assertForbidden();
    }

    public function test_the_whole_roll_call_round_trip_works_through_the_routes(): void
    {
        $declarer = $this->userWith(['sheq.incident.report']);

        $this->attend('Sipho Khumalo', 'EMPLOYEE');
        $this->attend('Thabo Ndlovu', 'EMPLOYEE');

        $this->actingAs($declarer)->post('/sheq/emergency-command/declare', [
            'site_id' => $this->siteId, 'emergency_type' => 'FIRE',
            'instructions' => 'North gate.',
        ])->assertRedirect();

        $uuid = DB::table('sheq_emergencies')->where('company_id', $this->companyId)
            ->where('status', 'ACTIVE')->value('uuid');

        $this->assertNotNull($uuid, 'The route did not create the emergency.');

        $rollCall = DB::table('sheq_emergency_roll_call')
            ->where('company_id', $this->companyId)->pluck('uuid')->all();

        $this->actingAs($declarer)->post("/sheq/emergency-command/{$uuid}/roll-call", [
            'roll_call_uuid' => $rollCall[0], 'status' => 'ACCOUNTED',
        ])->assertRedirect();

        // Standing down with one still outstanding must come back with the error, not a success.
        $this->actingAs($declarer)->post("/sheq/emergency-command/{$uuid}/stand-down", [])
            ->assertSessionHasErrors('stood_down_with_unaccounted_reason');

        $this->actingAs($declarer)->post("/sheq/emergency-command/{$uuid}/stand-down", [
            'stood_down_with_unaccounted_reason' => 'Left by the far gate. Confirmed by phone.',
        ])->assertRedirect();

        $this->assertSame('STOOD_DOWN',
            DB::table('sheq_emergencies')->where('uuid', $uuid)->value('status'));
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────────────────────

    private function site(string $name): int
    {
        return (int) DB::table('sheq_sites')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'code' => 'E'.Str::upper(Str::random(5)), 'name' => $name,
            'site_type' => 'CONSTRUCTION', 'timezone' => 'Africa/Johannesburg',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function person(string $first, string $surname): int
    {
        return (int) DB::table('hr_people')->insertGetId([
            'company_id' => $this->companyId, 'first_name' => $first, 'surname' => $surname,
            'cell_number' => '082 000 0000', 'status' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function contractor(string $name, ?int $parentId = null): int
    {
        return (int) DB::table('sheq_contractors')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId, 'name' => $name,
            'parent_contractor_id' => $parentId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function attend(string $name, string $subjectType, ?int $contractorId = null,
        ?int $siteId = null, ?int $subjectId = null): string
    {
        $uuid = (string) Str::uuid();

        DB::table('sheq_site_attendance')->insert([
            'uuid' => $uuid, 'company_id' => $this->companyId,
            'site_id' => $siteId ?? $this->siteId,
            'subject_type' => $subjectType, 'subject_id' => $subjectId,
            'person_name' => $name,
            'contractor_id' => $contractorId,
            'contractor_name' => $contractorId === null ? null
                : DB::table('sheq_contractors')->where('id', $contractorId)->value('name'),
            'entered_at' => now()->subHour(), 'entry_method' => 'QR',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $uuid;
    }

    private function visit(string $name, string $company): string
    {
        $uuid = (string) Str::uuid();

        DB::table('sheq_visits')->insert([
            'uuid' => $uuid, 'company_id' => $this->companyId, 'site_id' => $this->siteId,
            'reference' => 'V-'.Str::upper(Str::random(6)),
            'visitor_name' => $name, 'visitor_company' => $company,
            'status' => 'ON_SITE', 'signed_in_at' => now()->subMinutes(30),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $uuid;
    }

    private function competency(string $code, string $title, string $role): int
    {
        return (int) DB::table('sheq_competencies')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'code' => $code.Str::upper(Str::random(4)), 'title' => $title,
            'competency_type' => 'CERTIFICATE', 'emergency_role' => $role,
            'is_mandatory' => false, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function competencyRecord(int $competencyId, int $personId, Carbon $expires): int
    {
        return (int) DB::table('sheq_competency_records')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'competency_id' => $competencyId, 'hr_person_id' => $personId,
            'status' => 'COMPLIANT', 'achieved_at' => Carbon::today()->subYear(),
            'expires_at' => $expires, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function permit(string $reference, string $type, string $status,
        ?int $siteId = null): int
    {
        return (int) DB::table('sheq_permits')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'reference' => $reference, 'permit_type' => $type,
            'site_id' => $siteId ?? $this->siteId,
            'work_description' => 'Test work', 'status' => $status,
            'valid_from' => now()->subHour(), 'valid_to' => now()->addHours(6),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function userWith(array $permissions): User
    {
        $userId = DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(), 'email' => 'emr-'.Str::random(8).'@example.test',
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
}
