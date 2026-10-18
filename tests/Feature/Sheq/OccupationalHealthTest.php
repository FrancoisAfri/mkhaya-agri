<?php

namespace Tests\Feature\Sheq;

use App\Core\User\Models\User;
use App\Modules\Operations\Sheq\Services\ExposureRegisterService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Occupational health and hygiene (OH-1 to OH-6).
 *
 * The module recorded how many hours people worked — the denominator for an injury rate — and
 * nothing about what they breathed while working them. Occupational disease, not injury, is what
 * the Hazardous Chemical Agents Regulations and the Mine Health and Safety Act are mostly about.
 *
 * An injury is an event; an exposure is a dose. So the tests that matter are the two that make
 * the register answer a question fourteen years later: the limit is stamped on every result, and
 * group membership is dated.
 */
class OccupationalHealthTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

    private User $user;

    private int $siteId;

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
        $this->user = $this->userWith(['sheq.hazard.report', 'sheq.configure']);

        $this->siteId = (int) DB::table('sheq_sites')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'code' => 'H'.Str::upper(Str::random(5)), 'name' => 'Shaft',
            'site_type' => 'MINE', 'timezone' => 'Africa/Johannesburg', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->personId = (int) DB::table('hr_people')->insertGetId([
            'company_id' => $this->companyId, 'first_name' => 'Thandi', 'surname' => 'Nkosi',
            'id_number' => '88'.random_int(100000000, 999999999),
            'status' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->service()->installAgents($this->companyId, $this->user->id);
    }

    private function service(): ExposureRegisterService
    {
        return app(ExposureRegisterService::class);
    }

    private function agent(string $code): object
    {
        return DB::table('sheq_exposure_agents')
            ->where('company_id', $this->companyId)->where('code', $code)->first();
    }

    // ── OH-1 ─────────────────────────────────────────────────────────────────────────────────

    public function test_all_eight_agents_the_requirement_names_can_be_registered(): void
    {
        foreach (['NOISE', 'DUST', 'SILICA', 'CHEMICAL', 'FUME', 'HEAT', 'VIBRATION',
            'RADIATION'] as $type) {
            $this->assertArrayHasKey($type, ExposureRegisterService::AGENT_TYPES,
                "OH-1 lists {$type} and the register cannot hold one.");
        }

        // And the shipped register covers the ones a South African site actually monitors, with
        // the instrument each limit comes from.
        foreach (['NOISE_8H', 'SILICA_RESP', 'DUST_INHALABLE', 'WELD_FUME'] as $code) {
            $agent = $this->agent($code);

            $this->assertNotNull($agent);
            $this->assertNotNull($agent->limit_value);
            $this->assertNotSame('', trim((string) $agent->limit_source),
                "The {$code} limit has no source, so it cannot be defended or recomputed.");
        }
    }

    public function test_installing_the_register_twice_does_not_overwrite_a_revised_limit(): void
    {
        DB::table('sheq_exposure_agents')->where('company_id', $this->companyId)
            ->where('code', 'SILICA_RESP')->update(['limit_value' => 0.05]);

        $added = $this->service()->installAgents($this->companyId, $this->user->id);

        $this->assertSame(0, $added);
        $this->assertSame('0.0500', (string) $this->agent('SILICA_RESP')->limit_value,
            'Re-installing the register overwrote a limit the host had revised.');
    }

    // ── OH-2 ─────────────────────────────────────────────────────────────────────────────────

    public function test_a_result_has_to_apply_to_somebody(): void
    {
        /*
         * A result attached to nobody is a number with no history behind it, and the history is
         * the entire purpose of the register.
         */
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/who this result applies to/i');

        $this->service()->recordResult($this->companyId, [
            'agent_id' => $this->agent('NOISE_8H')->id, 'value' => 90,
        ], $this->user->id);
    }

    public function test_the_limit_in_force_is_stamped_onto_the_result(): void
    {
        /*
         * The single most important behaviour in this register. Limits move — the respirable
         * crystalline silica OEL was revised — and a result judged against today's limit would
         * retrospectively make a compliant year non-compliant, or the reverse. What matters in a
         * claim is whether the exposure was within the limit AT THE TIME.
         */
        $agent = $this->agent('SILICA_RESP');

        $saved = $this->service()->recordResult($this->companyId, [
            'agent_id' => $agent->id, 'hr_person_id' => $this->personId,
            'value' => 0.08, 'site_id' => $this->siteId,
        ], $this->user->id);

        $row = DB::table('sheq_exposure_results')->where('id', $saved['id'])->first();

        $this->assertSame((string) $agent->limit_value, (string) $row->limit_at_measurement);
        $this->assertFalse((bool) $row->exceeded_limit);
        // 0.08 is above the 0.05 action level, which is what surveillance keys on.
        $this->assertTrue((bool) $row->exceeded_action_level);

        // Now the limit is tightened. The old result must not become a breach.
        DB::table('sheq_exposure_agents')->where('id', $agent->id)
            ->update(['limit_value' => 0.05]);

        $this->assertFalse((bool) DB::table('sheq_exposure_results')
            ->where('id', $saved['id'])->value('exceeded_limit'),
            'Tightening a limit retrospectively made a compliant result a breach.');
        $this->assertSame('0.1000', (string) DB::table('sheq_exposure_results')
            ->where('id', $saved['id'])->value('limit_at_measurement'));
    }

    // ── OH-6 ─────────────────────────────────────────────────────────────────────────────────

    public function test_an_exceedance_raises_an_action_and_names_everybody_affected(): void
    {
        /*
         * The affected-worker list is the part easiest to leave out and the part that matters
         * most: a single static sample over the silica limit does not describe one person's bad
         * morning, it describes everybody who works in that area.
         */
        $groupId = (int) DB::table('sheq_exposure_groups')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'code' => 'SHOTCRETE', 'name' => 'Shotcrete crew', 'site_id' => $this->siteId,
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach (range(1, 3) as $n) {
            $personId = (int) DB::table('hr_people')->insertGetId([
                'company_id' => $this->companyId, 'first_name' => 'Crew', 'surname' => 'Member'.$n,
                'id_number' => '90'.random_int(100000000, 999999999),
                'status' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);

            DB::table('sheq_exposure_group_members')->insert([
                'company_id' => $this->companyId, 'group_id' => $groupId,
                'hr_person_id' => $personId,
                'from_date' => Carbon::today()->subYear()->toDateString(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $saved = $this->service()->recordResult($this->companyId, [
            'agent_id' => $this->agent('SILICA_RESP')->id,
            'group_id' => $groupId, 'value' => 0.4, 'sample_type' => 'STATIC',
            'site_id' => $this->siteId,
        ], $this->user->id);

        $this->assertTrue($saved['exceeded']);
        $this->assertSame(3, $saved['affected'],
            'An exceedance against a group did not name everybody in it.');

        // A corrective action in the real CAPA register.
        $actionId = DB::table('sheq_exposure_results')->where('id', $saved['id'])
            ->value('action_id');

        $this->assertNotNull($actionId);
        $this->assertSame('EXPOSURE', DB::table('sheq_actions')->where('id', $actionId)
            ->value('source_type'));

        // And the alert, through the same queue everything else uses.
        $this->assertTrue(DB::table('sheq_notification_dispatches')
            ->where('company_id', $this->companyId)
            ->where('event_type', 'sheq.exposure.limit_exceeded')->exists());
    }

    public function test_an_exceedance_can_open_a_real_investigation(): void
    {
        DB::table('sheq_exposure_agents')->where('code', 'NOISE_8H')
            ->where('company_id', $this->companyId)
            ->update(['investigate_on_exceedance' => true]);

        $saved = $this->service()->recordResult($this->companyId, [
            'agent_id' => $this->agent('NOISE_8H')->id,
            'hr_person_id' => $this->personId, 'value' => 95,
        ], $this->user->id);

        // The same engine incidents, non-conformances and limit breaches use.
        $this->assertTrue(DB::table('sheq_investigations')
            ->where('company_id', $this->companyId)
            ->where('subject_type', 'EXPOSURE')->where('subject_id', $saved['id'])->exists());
    }

    public function test_a_result_within_the_limit_raises_nothing(): void
    {
        $before = DB::table('sheq_actions')->where('company_id', $this->companyId)->count();

        $this->service()->recordResult($this->companyId, [
            'agent_id' => $this->agent('NOISE_8H')->id,
            'hr_person_id' => $this->personId, 'value' => 78,
        ], $this->user->id);

        $this->assertSame($before,
            DB::table('sheq_actions')->where('company_id', $this->companyId)->count());
    }

    // ── OH-3 ─────────────────────────────────────────────────────────────────────────────────

    public function test_group_membership_is_dated_so_a_dose_lands_on_the_right_people(): void
    {
        /*
         * Membership as a simple flag would attribute this year's results to somebody who left
         * two years ago, and spare somebody who has just arrived their predecessor's dose.
         */
        $groupId = (int) DB::table('sheq_exposure_groups')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'code' => 'DRILLERS', 'name' => 'Drillers', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $leaver = (int) DB::table('hr_people')->insertGetId([
            'company_id' => $this->companyId, 'first_name' => 'Old', 'surname' => 'Hand',
            'id_number' => '70'.random_int(100000000, 999999999),
            'status' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        // Left the group a year ago.
        DB::table('sheq_exposure_group_members')->insert([
            'company_id' => $this->companyId, 'group_id' => $groupId, 'hr_person_id' => $leaver,
            'from_date' => Carbon::today()->subYears(3)->toDateString(),
            'to_date' => Carbon::today()->subYear()->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // Joined last month.
        DB::table('sheq_exposure_group_members')->insert([
            'company_id' => $this->companyId, 'group_id' => $groupId,
            'hr_person_id' => $this->personId,
            'from_date' => Carbon::today()->subMonth()->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $saved = $this->service()->recordResult($this->companyId, [
            'agent_id' => $this->agent('DUST_INHALABLE')->id,
            'group_id' => $groupId, 'value' => 14,
            'measured_on' => Carbon::today()->toDateString(),
        ], $this->user->id);

        $affected = array_column($this->service()
            ->affectedWorkers($this->companyId, $saved['id']), 'hr_person_id');

        $this->assertContains($this->personId, $affected);
        $this->assertNotContains($leaver, $affected,
            'A result was charged to somebody who left the group a year earlier.');
    }

    public function test_a_persons_history_includes_results_measured_through_their_group(): void
    {
        $groupId = (int) DB::table('sheq_exposure_groups')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'code' => 'WELDERS', 'name' => 'Welders', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('sheq_exposure_group_members')->insert([
            'company_id' => $this->companyId, 'group_id' => $groupId,
            'hr_person_id' => $this->personId,
            'from_date' => Carbon::today()->subYears(2)->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // One through the group, one directly against them.
        $this->service()->recordResult($this->companyId, [
            'agent_id' => $this->agent('WELD_FUME')->id, 'group_id' => $groupId,
            'value' => 7, 'measured_on' => Carbon::today()->subMonths(6)->toDateString(),
        ], $this->user->id);

        $this->service()->recordResult($this->companyId, [
            'agent_id' => $this->agent('NOISE_8H')->id, 'hr_person_id' => $this->personId,
            'value' => 88, 'measured_on' => Carbon::today()->subMonths(3)->toDateString(),
        ], $this->user->id);

        $history = $this->service()->historyFor($this->companyId, $this->personId);

        $this->assertSame(2, $history['total']);
        // The fume result exceeded 5 mg/m³; the noise result did not exceed 85... it did.
        $this->assertGreaterThanOrEqual(1, $history['exceedances']);

        $agents = array_column($history['by_agent'], 'agent_code');

        $this->assertContains('WELD_FUME', $agents);
        $this->assertContains('NOISE_8H', $agents);
    }

    public function test_a_history_excludes_results_from_before_somebody_joined_the_group(): void
    {
        // Otherwise a new starter inherits their predecessor's fourteen years.
        $groupId = (int) DB::table('sheq_exposure_groups')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'code' => 'LOADERS', 'name' => 'Loaders', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->service()->recordResult($this->companyId, [
            'agent_id' => $this->agent('DUST_INHALABLE')->id, 'group_id' => $groupId,
            'value' => 3, 'measured_on' => Carbon::today()->subYears(5)->toDateString(),
        ], $this->user->id);

        DB::table('sheq_exposure_group_members')->insert([
            'company_id' => $this->companyId, 'group_id' => $groupId,
            'hr_person_id' => $this->personId,
            'from_date' => Carbon::today()->subMonth()->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertSame(0,
            $this->service()->historyFor($this->companyId, $this->personId)['total']);
    }

    // ── OH-4 ─────────────────────────────────────────────────────────────────────────────────

    public function test_surveillance_is_derived_from_exposure_rather_than_from_an_enrolment_list(): void
    {
        /*
         * The whole of OH-4. The obligation follows from what somebody was exposed to, not from
         * somebody remembering to enrol them — and it keys on the ACTION level, because
         * enrolling only the people already over the limit is enrolling them a year late.
         */
        $this->service()->recordResult($this->companyId, [
            'agent_id' => $this->agent('NOISE_8H')->id,
            'hr_person_id' => $this->personId,
            // Above the 82 action level, below the 85 limit.
            'value' => 83,
        ], $this->user->id);

        $due = $this->service()->surveillanceDue($this->companyId);

        $mine = collect($due)->firstWhere('hr_person_id', $this->personId);

        $this->assertNotNull($mine,
            'Somebody exposed above the action level is not on the surveillance list.');
        $this->assertFalse($mine['has_record'],
            'Nobody has examined them, and the list says they have been.');
    }

    public function test_an_examination_on_record_clears_them_from_the_list(): void
    {
        $typeId = (int) DB::table('sheq_medical_types')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'code' => 'AUD'.Str::random(4), 'name' => 'Audiometry',
            'examination_type' => 'AUDIOMETRY', 'frequency_months' => 12,
            'blocks_work_when_unfit' => false, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('sheq_exposure_agents')->where('company_id', $this->companyId)
            ->where('code', 'NOISE_8H')->update(['medical_type_id' => $typeId]);

        DB::table('sheq_medical_records')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'medical_type_id' => $typeId, 'hr_person_id' => $this->personId,
            'examined_on' => Carbon::today()->subMonths(2)->toDateString(),
            'fitness_outcome' => 'FIT', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->service()->recordResult($this->companyId, [
            'agent_id' => $this->agent('NOISE_8H')->id,
            'hr_person_id' => $this->personId, 'value' => 90,
        ], $this->user->id);

        $mine = collect($this->service()->surveillanceDue($this->companyId))
            ->firstWhere('hr_person_id', $this->personId);

        $this->assertTrue($mine['has_record']);
    }

    public function test_a_contractor_worker_is_reported_as_not_known_rather_than_overdue(): void
    {
        /*
         * A contractor worker's medicals are a credential their employer files; this system holds
         * no clinical record for them. Reporting "no examination on record" would assert
         * something false about another company's employee.
         */
        $contractorId = (int) DB::table('sheq_contractors')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'name' => 'Drilling Contractor', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $workerId = (int) DB::table('sheq_contractor_workers')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'contractor_id' => $contractorId, 'name' => 'Contract Driller',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->service()->recordResult($this->companyId, [
            'agent_id' => $this->agent('SILICA_RESP')->id,
            'contractor_worker_id' => $workerId, 'value' => 0.3,
        ], $this->user->id);

        $theirs = collect($this->service()->surveillanceDue($this->companyId))
            ->firstWhere('contractor_worker_id', $workerId);

        $this->assertNotNull($theirs);
        $this->assertNull($theirs['has_record']);
    }

    // ── OH-5 ─────────────────────────────────────────────────────────────────────────────────

    public function test_an_agent_can_be_linked_to_the_ppe_that_controls_it(): void
    {
        $ppeId = (int) DB::table('sheq_ppe_items')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'code' => 'RESP'.Str::random(4), 'name' => 'Respirator',
            'category' => 'RESPIRATORY', 'unit' => 'EACH', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $agent = $this->agent('SILICA_RESP');

        $this->service()->linkPpe($this->companyId, (int) $agent->id, $ppeId, true,
            'FFP3', $this->user->id);

        $row = collect($this->service()->agents($this->companyId))
            ->firstWhere('code', 'SILICA_RESP');

        $this->assertCount(1, $row['ppe']);
        $this->assertSame('FFP3', $row['ppe'][0]['minimum_specification']);

        // Linking the same item twice updates rather than duplicating — otherwise the list of
        // controls for one agent grows every time somebody saves the form.
        $this->service()->linkPpe($this->companyId, (int) $agent->id, $ppeId, false,
            'FFP2', $this->user->id);

        $this->assertCount(1, collect($this->service()->agents($this->companyId))
            ->firstWhere('code', 'SILICA_RESP')['ppe']);
    }

    public function test_an_action_level_above_the_limit_is_refused(): void
    {
        // It exists to catch exposure before it reaches the limit, so one above it can never
        // fire first.
        $this->actingAs($this->user)
            ->post('/sheq/occupational-health/agents/'.$this->agent('NOISE_8H')->uuid, [
                'limit_value' => 85, 'action_level' => 90,
            ])
            ->assertSessionHasErrors('action_level');
    }

    // ── The screen ────────────────────────────────────────────────────────────────────────────

    public function test_the_page_leads_with_the_surveillance_list(): void
    {
        $this->service()->recordResult($this->companyId, [
            'agent_id' => $this->agent('NOISE_8H')->id,
            'hr_person_id' => $this->personId, 'value' => 90,
        ], $this->user->id);

        $props = $this->actingAs($this->user)->get('/sheq/occupational-health')
            ->assertOk()->viewData('page')['props'];

        $this->assertNotEmpty($props['surveillance']);
        $this->assertNotEmpty($props['agents']);
        $this->assertCount(count(ExposureRegisterService::AGENT_TYPES), $props['agentTypes']);
    }

    public function test_the_hours_register_and_the_exposure_register_are_both_reachable(): void
    {
        /*
         * `/exposure` records exposure HOURS — the denominator for an injury rate. This records
         * what people were exposed to while working them. Different registers, different
         * questions.
         */
        $routes = collect(app('router')->getRoutes())->map(fn ($r) => $r->uri());

        $this->assertContains('sheq/exposure', $routes->all());
        $this->assertContains('sheq/occupational-health', $routes->all());
    }

    public function test_recording_a_result_needs_the_entitlement(): void
    {
        $this->actingAs($this->userWith([]))
            ->post('/sheq/occupational-health/results', [
                'agent_id' => $this->agent('NOISE_8H')->id, 'value' => 90,
            ])->assertForbidden();
    }

    private function userWith(array $permissions): User
    {
        $userId = DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(), 'email' => 'oh-'.Str::random(8).'@example.test',
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
