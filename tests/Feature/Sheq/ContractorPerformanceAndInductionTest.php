<?php

namespace Tests\Feature\Sheq;

use App\Core\User\Models\User;
use App\Modules\Operations\Sheq\Services\ContractorService;
use App\Modules\Operations\Sheq\Services\InductionService;
use App\Modules\Operations\Sheq\Services\SheqReportService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Two contractor answers the module computed and never showed anybody.
 *
 * `ContractorService::incidentRollUp()` splits incidents into own staff and contractors and
 * combines them. Nothing called it, so the comparison a board actually asks for could not be
 * produced: a company whose own LTIFR is excellent while its contractors are getting hurt has not
 * got a good safety record, it has outsourced its injuries, and the combined figure is the one a
 * client or an inspector will use.
 *
 * `InductionService::isCurrent()` answers whether somebody's induction *in this system* is still
 * valid. The contractor profile read only the register's imported paperwork dates, while the
 * access gate honours either — so a worker who sat the induction here and will be let through the
 * turnstile was displayed on the profile as not inducted, and a safety manager was sent to chase
 * paperwork that was already in order.
 */
class ContractorPerformanceAndInductionTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

    private User $user;

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
            'uuid' => (string) Str::uuid(), 'email' => 'perf-'.Str::random(8).'@example.test',
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

        $this->user->givePermissionTo('sheq.contractor.manage');
        $this->user->forgetCachedPermissions();
    }

    private function contractor(string $name = 'Scaffold Co'): array
    {
        return app(ContractorService::class)->register($this->companyId, [
            'name' => $name, 'trade' => 'SCAFFOLDING',
        ], $this->user->id);
    }

    /** An incident, attributed to a contractor or to own staff. */
    private function incident(?int $contractorId, array $overrides = []): void
    {
        DB::table('sheq_incidents')->insert(array_merge([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'reference' => 'INC-'.Str::random(6), 'incident_type' => 'INJURY',
            'description' => 'Fall from a platform', 'status' => 'OPEN',
            'occurred_at' => Carbon::now()->subDays(3), 'reported_at' => Carbon::now()->subDays(3),
            'contractor_id' => $contractorId, 'days_lost' => 0, 'is_reportable' => false,
            'created_at' => now(), 'updated_at' => now(),
        ], $overrides));
    }

    private function report(string $key): array
    {
        return app(SheqReportService::class)->run($key, $this->companyId,
            Carbon::now()->subMonth(), Carbon::now()->endOfDay(), null);
    }

    public function test_the_comparison_separates_own_staff_from_contractors_and_combines_them(): void
    {
        $contractor = $this->contractor();

        $this->incident(null, ['days_lost' => 4]);
        $this->incident((int) $contractor['id'], ['days_lost' => 10, 'is_reportable' => true]);
        $this->incident((int) $contractor['id']);

        $rows = collect($this->report('incidents_employee_vs_contractor'))->keyBy('workforce');

        $this->assertSame(1, $rows['Own employees']['total']);
        $this->assertSame(2, $rows['Contractors']['total']);
        // The combined row is the one that goes to a client. A company cannot report its own
        // figure and leave the contractors' out.
        $this->assertSame(3, $rows['Combined']['total']);
        $this->assertSame(14, $rows['Combined']['days_lost']);
        $this->assertSame(1, $rows['Contractors']['reportable']);
        $this->assertSame(0, $rows['Own employees']['reportable']);
    }

    public function test_a_fatality_is_counted_against_the_workforce_it_happened_to(): void
    {
        $contractor = $this->contractor();

        $this->incident((int) $contractor['id'], ['outcome' => 'FATALITY']);

        $rows = collect($this->report('incidents_employee_vs_contractor'))->keyBy('workforce');

        $this->assertSame(1, $rows['Contractors']['fatalities']);
        $this->assertSame(0, $rows['Own employees']['fatalities']);
        $this->assertSame(1, $rows['Combined']['fatalities']);
    }

    public function test_contractors_are_ranked_so_the_comparison_is_actionable(): void
    {
        $bad = $this->contractor('Bad Scaffolding');
        $better = $this->contractor('Better Rigging');

        $this->incident((int) $bad['id']);
        $this->incident((int) $bad['id']);
        $this->incident((int) $better['id']);

        $rows = $this->report('incidents_by_contractor');

        $this->assertSame('Bad Scaffolding', $rows[0]['contractor']);
        $this->assertSame(2, $rows[0]['incidents']);
        $this->assertSame('Better Rigging', $rows[1]['contractor']);
    }

    public function test_another_tenants_incidents_are_not_in_the_comparison(): void
    {
        $other = (int) DB::table('companies')->insertGetId([
            'uuid' => (string) Str::uuid(), 'name' => 'Other Employer',
            'slug' => 'perf-other-'.Str::lower(Str::random(8)), 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('sheq_incidents')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => $other,
            'reference' => 'INC-OTHER', 'incident_type' => 'INJURY',
            'description' => 'Not ours', 'status' => 'OPEN',
            'occurred_at' => Carbon::now()->subDay(), 'reported_at' => Carbon::now()->subDay(),
            'days_lost' => 99, 'is_reportable' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $rows = collect($this->report('incidents_employee_vs_contractor'))->keyBy('workforce');

        $this->assertSame(0, $rows['Combined']['total']);
        $this->assertSame(0, $rows['Combined']['days_lost']);
    }

    /** A worker whose only evidence is an induction completed in this system. */
    private function workerInductedHere(int $contractorId): int
    {
        $workerId = (int) DB::table('sheq_contractor_workers')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'contractor_id' => $contractorId, 'name' => 'T. Mokoena', 'trade' => 'SCAFFOLDING',
            // Deliberately blank: the paperwork was never imported.
            'inducted_on' => null, 'induction_expires' => null,
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $inductions = app(InductionService::class);

        $induction = $inductions->publish($this->companyId, [
            'code' => 'IND'.Str::random(5), 'title' => 'Site induction',
            'audience' => 'CONTRACTOR',
            'content' => [['heading' => 'Assembly point', 'body' => 'Car park A.']],
            'questions' => [['id' => 'q1', 'text' => 'Where is the assembly point?',
                'options' => ['Car park A', 'The canteen'], 'answer' => 'Car park A']],
            'requires_signature' => false,
        ], $this->user->id);

        $inductions->complete($this->companyId, $induction['uuid'], [
            'subject_type' => 'CONTRACTOR_WORKER', 'subject_id' => $workerId,
            'person_name' => 'T. Mokoena', 'answers' => ['q1' => 'Car park A'],
        ]);

        return $workerId;
    }

    public function test_an_induction_completed_in_this_system_shows_on_the_profile(): void
    {
        $contractor = $this->contractor();
        $this->workerInductedHere((int) $contractor['id']);

        $props = $this->actingAs($this->user)
            ->get("/sheq/contractors/{$contractor['uuid']}")
            ->viewData('page')['props'];

        $worker = $props['workers'][0];

        // The gate will let this person through. The screen said they were not inducted, which
        // sent somebody to chase paperwork that was already in order.
        $this->assertTrue($worker['induction_completed_here']);
        $this->assertTrue($worker['induction_valid']);
    }

    public function test_a_worker_with_neither_kind_of_evidence_still_reads_as_not_inducted(): void
    {
        $contractor = $this->contractor();

        DB::table('sheq_contractor_workers')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'contractor_id' => $contractor['id'], 'name' => 'Nobody', 'trade' => 'SCAFFOLDING',
            'inducted_on' => null, 'induction_expires' => null,
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $worker = $this->actingAs($this->user)
            ->get("/sheq/contractors/{$contractor['uuid']}")
            ->viewData('page')['props']['workers'][0];

        $this->assertFalse($worker['induction_completed_here']);
        $this->assertFalse($worker['induction_valid']);
    }

    public function test_imported_paperwork_still_counts_on_its_own(): void
    {
        $contractor = $this->contractor();

        DB::table('sheq_contractor_workers')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'contractor_id' => $contractor['id'], 'name' => 'Imported', 'trade' => 'SCAFFOLDING',
            'inducted_on' => Carbon::today()->subMonth()->toDateString(),
            'induction_expires' => Carbon::today()->addYear()->toDateString(),
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $worker = $this->actingAs($this->user)
            ->get("/sheq/contractors/{$contractor['uuid']}")
            ->viewData('page')['props']['workers'][0];

        // Either kind of evidence is evidence; adding the second source must not have made the
        // first one stop counting.
        $this->assertFalse($worker['induction_completed_here']);
        $this->assertTrue($worker['induction_valid']);
    }
}
