<?php

namespace Tests\Feature\Sheq;

use App\Core\User\Models\User;
use App\Modules\Operations\Sheq\Services\ObservationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * What people were seen doing, and what keeps happening (OBS-1 to OBS-4).
 *
 * Everything the module recorded before this was a condition or an outcome: a hazard says the
 * guard rail is missing, an incident says somebody fell. Neither says that three crews have been
 * climbing that scaffold unclipped all week, which is the thing anybody could have acted on.
 */
class ObservationTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

    private User $user;

    private int $siteId;

    private array $contractor;

    private int $workerId;

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
        $this->user = $this->userWith(['sheq.hazard.report', 'sheq.hazard.triage',
            'sheq.configure']);

        $this->siteId = (int) DB::table('sheq_sites')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'code' => 'O'.Str::upper(Str::random(5)), 'name' => 'Workshop',
            'site_type' => 'PLANT', 'timezone' => 'Africa/Johannesburg', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $contractorId = (int) DB::table('sheq_contractors')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'name' => 'Observed Scaffolding', 'trade' => 'SCAFFOLDING', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->contractor = ['id' => $contractorId];

        $this->workerId = (int) DB::table('sheq_contractor_workers')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'contractor_id' => $contractorId, 'name' => 'Observed Worker',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->service()->installBehaviours($this->companyId, $this->user->id);
    }

    private function service(): ObservationService
    {
        return app(ObservationService::class);
    }

    private function record(array $overrides = []): array
    {
        return $this->service()->record($this->companyId, array_merge([
            'observation_type' => ObservationService::UNSAFE,
            'site_id' => $this->siteId,
            'behaviour_code' => 'FALL_PROTECTION_NOT_USED',
            'behaviour' => 'Working on the third lift with the harness unclipped.',
            'risk_rating' => 'HIGH',
        ], $overrides), $this->user->id);
    }

    // ── OBS-1 ─────────────────────────────────────────────────────────────────────────────────

    public function test_all_four_kinds_the_requirement_lists_can_be_recorded(): void
    {
        foreach (['POSITIVE', 'UNSAFE', 'INTERVENTION', 'COACHING'] as $type) {
            $this->assertArrayHasKey($type, ObservationService::TYPES,
                "OBS-1 lists {$type} and the service does not know about it.");

            $created = $this->record(['observation_type' => $type]);

            $this->assertSame($type, DB::table('sheq_observations')
                ->where('id', $created['id'])->value('observation_type'));
        }
    }

    public function test_an_observation_has_to_be_one_of_the_four(): void
    {
        $this->expectException(RuntimeException::class);

        $this->record(['observation_type' => 'GRUMBLE']);
    }

    public function test_an_intervention_is_recorded_as_work_stopped_without_being_asked(): void
    {
        /*
         * An intervention IS work being stopped. Asking twice lets the two disagree, and then
         * "how many times did somebody stop work" counts the switch rather than the event.
         */
        $created = $this->record(['observation_type' => ObservationService::INTERVENTION]);

        $this->assertTrue((bool) DB::table('sheq_observations')
            ->where('id', $created['id'])->value('work_stopped'));
    }

    // ── OBS-2 ─────────────────────────────────────────────────────────────────────────────────

    public function test_everything_the_requirement_asks_to_capture_is_stored(): void
    {
        $created = $this->record([
            'activity' => 'working_at_height',
            'location_text' => 'North elevation, third lift',
            'contractor_worker_id' => $this->workerId,
            'immediate_action' => 'Brought them down and reclipped before restarting.',
        ]);

        $row = DB::table('sheq_observations')->where('id', $created['id'])->first();

        // OBS-2's list: activity, location, contractor, worker, behaviour, risk, immediate action.
        $this->assertSame('WORKING_AT_HEIGHT', $row->activity);
        $this->assertSame('North elevation, third lift', $row->location_text);
        $this->assertSame($this->workerId, (int) $row->contractor_worker_id);
        $this->assertSame('FALL_PROTECTION_NOT_USED', $row->behaviour_code);
        $this->assertStringContainsString('unclipped', $row->behaviour);
        $this->assertSame('HIGH', $row->risk_rating);
        $this->assertStringContainsString('reclipped', $row->immediate_action);

        // And a photograph has somewhere to go, which it did not before.
        $this->assertContains('OBSERVATION',
            app(\App\Modules\Operations\Sheq\Services\FileService::class)->subjects());
    }

    public function test_the_employer_is_derived_from_the_worker_rather_than_asked_for_twice(): void
    {
        /*
         * A form taking both lets them disagree, and then "observations by contractor" counts
         * the typed one while the worker belongs to somebody else.
         */
        $other = (int) DB::table('sheq_contractors')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'name' => 'Wrong Contractor', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $created = $this->record([
            'contractor_worker_id' => $this->workerId,
            'contractor_id' => $other,
        ]);

        $this->assertSame($this->contractor['id'], (int) DB::table('sheq_observations')
            ->where('id', $created['id'])->value('contractor_id'));
    }

    public function test_an_observation_can_be_filed_without_naming_anybody(): void
    {
        // Attributing every observation to an individual is how a behavioural programme becomes
        // a disciplinary one, and then observers stop filing.
        $created = $this->record(['contractor_worker_id' => null, 'hr_person_id' => null]);

        $row = DB::table('sheq_observations')->where('id', $created['id'])->first();

        $this->assertNull($row->contractor_worker_id);
        $this->assertNull($row->hr_person_id);
        $this->assertSame('OPEN', $row->status);
    }

    // ── OBS-3 ─────────────────────────────────────────────────────────────────────────────────

    public function test_an_observation_can_raise_a_capa_in_the_real_capa_register(): void
    {
        /*
         * Through `ActionService`, so it gets the same reference series, the same overdue sweep,
         * the same effectiveness review and the same screens as an action raised from an
         * incident. A second kind of action that looks like a CAPA and is not in the CAPA
         * register is worse than no action at all.
         */
        // With the worker named, so the employer carries through onto the action.
        $created = $this->record(['contractor_worker_id' => $this->workerId]);

        $action = $this->service()->raiseAction($this->companyId,
            DB::table('sheq_observations')->where('id', $created['id'])->value('uuid'),
            ['title' => 'Re-brief the crew and fit a static line'], $this->user->id);

        $row = DB::table('sheq_actions')->where('id', $action['id'])->first();

        $this->assertSame('OBSERVATION', $row->source_type);
        $this->assertSame($created['id'], (int) $row->source_id);
        // The employer carries through, so it appears on their outstanding-CAPA count.
        $this->assertSame($this->contractor['id'], (int) $row->contractor_id);
        $this->assertStringStartsWith('ACT', (string) $row->reference);

        $observation = DB::table('sheq_observations')->where('id', $created['id'])->first();

        $this->assertSame('ACTIONED', $observation->status);
        $this->assertSame((int) $action['id'], (int) $observation->action_id);
    }

    public function test_a_second_action_cannot_be_raised_from_the_same_observation(): void
    {
        $created = $this->record();
        $uuid = DB::table('sheq_observations')->where('id', $created['id'])->value('uuid');

        $this->service()->raiseAction($this->companyId, $uuid, [], $this->user->id);

        $this->expectException(RuntimeException::class);
        $this->service()->raiseAction($this->companyId, $uuid, [], $this->user->id);
    }

    // ── OBS-4 ─────────────────────────────────────────────────────────────────────────────────

    public function test_recurrence_is_reported_every_way_the_requirement_asks_for(): void
    {
        foreach (range(1, 3) as $ignored) {
            $this->record(['contractor_worker_id' => $this->workerId,
                'activity' => 'WORKING_AT_HEIGHT']);
        }

        $recurrence = $this->service()->recurrence($this->companyId);

        // OBS-4's list: site, contractor, activity, department, period.
        foreach (['by_site', 'by_contractor', 'by_activity', 'by_department',
            'by_period', 'by_behaviour'] as $cut) {
            $this->assertArrayHasKey($cut, $recurrence,
                "OBS-4 asks for a cut by {$cut} and the report does not produce one.");
        }

        $behaviour = collect($recurrence['by_behaviour'])
            ->firstWhere('code', 'FALL_PROTECTION_NOT_USED');

        $this->assertSame(3, (int) $behaviour['total']);
        // The grouping is the point: without a vocabulary this would read as three separate
        // behaviours that each happened once.
        $this->assertTrue((bool) $behaviour['is_high_consequence']);

        $this->assertSame(3, (int) collect($recurrence['by_contractor'])
            ->firstWhere('name', 'Observed Scaffolding')['total']);
    }

    public function test_positive_observations_are_not_counted_as_recurring_unsafe_behaviour(): void
    {
        /*
         * Including them would make the best-reporting crew look like the worst offender, which
         * is exactly the result that gets a behavioural programme switched off.
         */
        $this->record(['observation_type' => ObservationService::POSITIVE,
            'behaviour_code' => 'GOOD_PRACTICE']);
        $this->record(['observation_type' => ObservationService::COACHING,
            'behaviour_code' => 'GOOD_PRACTICE']);

        $recurrence = $this->service()->recurrence($this->companyId);

        $this->assertNull(collect($recurrence['by_behaviour'])
            ->firstWhere('code', 'GOOD_PRACTICE'));
    }

    public function test_the_mix_reports_the_ratio_that_says_whether_reporting_is_trusted(): void
    {
        /*
         * A site filing ninety unsafe observations and two positive ones does not have a more
         * dangerous workforce than one filing fifty of each — it has a culture in which being
         * observed is a punishment, and the behaviour figures from it cannot be believed.
         */
        foreach (range(1, 4) as $ignored) {
            $this->record(['observation_type' => ObservationService::UNSAFE]);
        }

        $this->record(['observation_type' => ObservationService::POSITIVE]);

        $mix = $this->service()->mix($this->companyId);

        $this->assertSame(4, $mix['concerning']);
        $this->assertSame(1, $mix['positive']);
        $this->assertSame(20, $mix['positive_share']);
    }

    public function test_nothing_filed_is_not_reported_as_a_zero_ratio(): void
    {
        // "No observations" and "all of them were unsafe" are different, and a share of 0 reads
        // as the second.
        $this->assertNull($this->service()
            ->mix($this->companyId, Carbon::today()->addYear(), Carbon::today()->addYears(2))
            ['positive_share']);
    }

    public function test_the_behaviour_vocabulary_installs_once_and_is_not_overwritten(): void
    {
        $first = DB::table('sheq_behaviours')->where('company_id', $this->companyId)->count();

        $this->assertGreaterThan(0, $first);

        DB::table('sheq_behaviours')->where('company_id', $this->companyId)
            ->where('code', 'HOUSEKEEPING')->update(['name' => 'Renamed by the host']);

        $added = $this->service()->installBehaviours($this->companyId, $this->user->id);

        $this->assertSame(0, $added);
        $this->assertSame('Renamed by the host', DB::table('sheq_behaviours')
            ->where('company_id', $this->companyId)->where('code', 'HOUSEKEEPING')->value('name'));
    }

    // ── The screen ────────────────────────────────────────────────────────────────────────────

    public function test_the_page_shows_the_register_and_the_recurrence_together(): void
    {
        $this->record();

        $props = $this->actingAs($this->user)->get('/sheq/observations')
            ->assertOk()->viewData('page')['props'];

        $this->assertNotEmpty($props['rows']);
        // Read together on purpose: one observation is an anecdote, and the people filing them
        // need to see what their filing produced.
        $this->assertNotNull($props['recurrence']);
        $this->assertCount(count(ObservationService::TYPES), $props['types']);
        $this->assertNotEmpty($props['behaviours']);
    }

    public function test_anybody_who_can_report_a_hazard_can_file_an_observation(): void
    {
        /*
         * Restricting the one register that depends on volume to safety staff is how it ends up
         * with four contributors and no pattern worth reading.
         */
        $reporter = $this->userWith(['sheq.hazard.report']);

        $this->actingAs($reporter)->post('/sheq/observations', [
            'observation_type' => 'UNSAFE',
            'site_id' => $this->siteId,
            'behaviour' => 'Grinding without a face shield.',
        ])->assertRedirect()->assertSessionHasNoErrors();
    }

    public function test_an_observation_cannot_be_filed_without_the_entitlement(): void
    {
        $this->actingAs($this->userWith([]))
            ->post('/sheq/observations', [
                'observation_type' => 'UNSAFE', 'behaviour' => 'Something.',
            ])->assertForbidden();
    }

    public function test_the_words_are_required_even_when_the_code_is_not(): void
    {
        // An observer who cannot find the right code must still be able to file what they saw:
        // a dropdown that blocks the report is a dropdown that loses the report.
        $this->actingAs($this->user)->post('/sheq/observations', [
            'observation_type' => 'UNSAFE', 'behaviour_code' => null,
            'behaviour' => 'Something nobody has catalogued.',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->actingAs($this->user)->post('/sheq/observations', [
            'observation_type' => 'UNSAFE', 'behaviour_code' => 'HOUSEKEEPING',
        ])->assertSessionHasErrors('behaviour');
    }

    private function userWith(array $permissions): User
    {
        $userId = DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(), 'email' => 'obs-'.Str::random(8).'@example.test',
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
