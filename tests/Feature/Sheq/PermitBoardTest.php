<?php

namespace Tests\Feature\Sheq;

use App\Core\User\Models\User;
use App\Modules\Operations\Sheq\Services\PermitService;
use App\Modules\Operations\Sheq\Services\RiskAssessmentService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The live board a control room needs (SHEQ-PTW-6).
 *
 * `PermitService::activeNow()` was written for this screen and nothing called it. The permits
 * register answers an administrator's question — every permit ever raised, newest first — so the
 * control room's question, "what work is live on my site right now and what is about to run out of
 * permit", had to be answered by eye from two hundred rows, most of them closed.
 *
 * `activeForSite()` was unreached for the same reason, and it belongs here: a permit is written
 * against a risk assessment, and if no approved assessment covers the site then the authority the
 * permit rests on does not exist. That is worth knowing while the work is happening.
 */
class PermitBoardTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

    private User $user;

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

        $userId = DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(), 'email' => 'board-'.Str::random(8).'@example.test',
            'password' => bcrypt('secret'), 'company_id' => $this->companyId, 'status' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->user = User::withoutGlobalScopes()->findOrFail($userId);

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

        foreach (['sheq.permit.approve', 'sheq.permit.request'] as $permission) {
            $this->user->givePermissionTo($permission);
        }

        $this->user->forgetCachedPermissions();

        $this->siteId = (int) DB::table('sheq_sites')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'code' => 'B'.Str::random(5), 'name' => 'Refinery', 'site_type' => 'PLANT',
            'timezone' => 'Africa/Johannesburg', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function approvedAssessment(): int
    {
        $service = app(RiskAssessmentService::class);

        $assessment = $service->create($this->companyId, [
            'title' => 'Hot work in process area', 'site_id' => $this->siteId,
        ], $this->user->id);

        $service->addLine($this->companyId, $assessment['uuid'], [
            'activity' => 'Welding', 'hazard' => 'Ignition of flammable vapour',
            'inherent_likelihood' => 4, 'inherent_severity' => 5,
            'residual_likelihood' => 2, 'residual_severity' => 3,
        ], [['hierarchy_level' => 'ENGINEERING', 'description' => 'Purge and isolate']]);

        $service->addParticipant($this->companyId, $assessment['uuid'], ['name' => 'Crew']);
        $service->approve($this->companyId, $assessment['uuid'], $this->user->id, ['SITE_MANAGER']);

        return (int) $assessment['id'];
    }

    /** A permit, left in whatever state the caller asks for. */
    private function permit(string $status, ?Carbon $validTo = null, ?int $assessmentId = null): array
    {
        $permit = app(PermitService::class)->request($this->companyId, [
            'permit_type' => 'HOT_WORK',
            'site_id' => $this->siteId,
            'work_description' => 'Weld a support bracket on line 4.',
            'valid_from' => Carbon::now()->subHour()->toDateTimeString(),
            'valid_to' => ($validTo ?? Carbon::now()->addHours(8))->toDateTimeString(),
            'risk_assessment_id' => $assessmentId,
        ], [['name' => 'T. Mokoena', 'worker_role' => 'WORKER']], $this->user->id);

        DB::table('sheq_permits')->where('id', $permit['id'])->update(['status' => $status]);

        return $permit;
    }

    private function board(array $query = []): array
    {
        return $this->actingAs($this->user)
            ->get('/sheq/permits/board'.($query === [] ? '' : '?'.http_build_query($query)))
            ->viewData('page')['props'];
    }

    public function test_only_live_work_is_on_the_board(): void
    {
        $this->permit('ACTIVE');
        $this->permit('CLOSED');
        $this->permit('DRAFT');

        $references = array_column($this->board()['permits'], 'status');

        // A board that includes closed permits is the register again, and the register is what
        // nobody could read in a control room.
        $this->assertSame(['ACTIVE'], $references);
    }

    public function test_a_suspended_permit_stays_on_the_board(): void
    {
        $this->permit('SUSPENDED');

        // Suspended work is not finished work. It is the thing most likely to restart without
        // anybody re-checking, so it has to stay visible.
        $this->assertCount(1, $this->board()['permits']);
    }

    public function test_time_left_is_signed_so_an_overrun_reads_as_an_overrun(): void
    {
        $this->permit('ACTIVE', Carbon::now()->addHours(3));

        $permits = $this->board()['permits'];

        $this->assertGreaterThan(0, $permits[0]['hours_left']);
        $this->assertLessThanOrEqual(3, $permits[0]['hours_left']);
    }

    public function test_a_permit_past_its_window_is_lapsed_rather_than_shown_as_live(): void
    {
        $this->permit('ACTIVE', Carbon::now()->subHours(2));

        // Reading the board lapses it: a permit cannot be shown as live when its own validity says
        // the window closed two hours ago.
        $this->assertSame([], $this->board()['permits']);
        $this->assertSame('EXPIRED', DB::table('sheq_permits')
            ->where('company_id', $this->companyId)->orderByDesc('id')->value('status'));
    }

    public function test_the_assessment_in_force_is_named_against_the_permit(): void
    {
        $this->permit('ACTIVE', null, $this->approvedAssessment());

        $permit = $this->board()['permits'][0];

        $this->assertNotNull($permit['risk_assessment'],
            'A permit rests on a HIRA; name the one in force.');
        $this->assertSame(1, $permit['risk_assessment']['version']);
        $this->assertFalse($permit['risk_assessment']['review_overdue']);
    }

    public function test_a_site_with_no_approved_assessment_says_so(): void
    {
        $this->permit('ACTIVE');

        // Nothing approved covers the site, so the authority the permit claims does not exist.
        // Silence here is the audit finding nobody saw coming.
        $this->assertNull($this->board()['permits'][0]['risk_assessment']);
    }

    public function test_the_board_can_be_narrowed_to_one_site(): void
    {
        $this->permit('ACTIVE');

        $otherSite = (int) DB::table('sheq_sites')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'code' => 'B'.Str::random(5), 'name' => 'Depot', 'site_type' => 'SITE',
            'timezone' => 'Africa/Johannesburg', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertCount(1, $this->board()['permits']);
        $this->assertCount(0, $this->board(['site_id' => $otherSite])['permits']);
    }

    public function test_another_tenants_live_permit_is_not_on_this_board(): void
    {
        $other = (int) DB::table('companies')->insertGetId([
            'uuid' => (string) Str::uuid(), 'name' => 'Other Employer',
            'slug' => 'board-other-'.Str::lower(Str::random(8)), 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $otherSite = (int) DB::table('sheq_sites')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $other,
            'code' => 'X'.Str::random(5), 'name' => 'Theirs', 'site_type' => 'SITE',
            'timezone' => 'Africa/Johannesburg', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('sheq_permits')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => $other, 'site_id' => $otherSite,
            'reference' => 'PTW-X-1', 'permit_type' => 'HOT_WORK', 'status' => 'ACTIVE',
            'work_description' => 'Not ours', 'valid_from' => Carbon::now()->subHour(),
            'valid_to' => Carbon::now()->addHours(4),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertSame([], array_column($this->board()['permits'], 'reference'));
    }

    public function test_the_board_opens_for_somebody_who_cannot_issue(): void
    {
        $this->permit('ACTIVE');

        $watcher = DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(), 'email' => 'watch-'.Str::random(8).'@example.test',
            'password' => bcrypt('secret'), 'company_id' => $this->companyId, 'status' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $moduleId = DB::table('security_modules')->where('code_name', 'sheq')->value('id');

        DB::table('security_module_access')->insert([
            'module_id' => $moduleId, 'user_id' => $watcher, 'access_level' => 4,
            'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        // A control-room board nobody but an issuer can open is a board nobody watches.
        $this->actingAs(User::withoutGlobalScopes()->findOrFail($watcher))
            ->get('/sheq/permits/board')->assertOk();
    }
}
