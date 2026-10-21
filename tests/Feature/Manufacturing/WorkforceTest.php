<?php

namespace Tests\Feature\Manufacturing;

use App\Core\Role\Models\Role;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Finance\Accounting\Services\StockLedger;
use App\Modules\Operations\Manufacturing\Models\Factory;
use App\Modules\Operations\Manufacturing\Models\FactoryPolicy;
use App\Modules\Operations\Manufacturing\Models\Item;
use App\Modules\Operations\Manufacturing\Models\OperationRequirement;
use App\Modules\Operations\Manufacturing\Models\ProductionOrder as PO;
use App\Modules\Operations\Manufacturing\Models\RosterEntry;
use App\Modules\Operations\Manufacturing\Models\RoutingOperation;
use App\Modules\Operations\Manufacturing\Models\Shift;
use App\Modules\Operations\Manufacturing\Models\WorkCentre;
use App\Modules\Operations\Manufacturing\Services\ProductionOrderLifecycle;
use App\Modules\Operations\Manufacturing\Services\ProductionOrders;
use App\Modules\Operations\Manufacturing\Services\Scheduler;
use App\Modules\Operations\Manufacturing\Services\ShopFloor;
use App\Modules\Operations\Manufacturing\Services\Workforce;
use App\Modules\Operations\Manufacturing\Support\RoleCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * MFG-016 -- qualifications read from HR skills, learning certifications and SHEQ competencies;
 * the floor refusing a job to somebody unqualified; roster against need; hours for costing, with
 * payroll left alone.
 *
 * Welding needs Welding at level 2 (HR skill), a current Hot Work certificate (learning) and the
 * Pressure Systems competency (SHEQ).
 */
class WorkforceTest extends ManufacturingTestCase
{
    private Factory $plant;

    private FinWarehouse $store;

    private WorkCentre $weld;

    private Item $frame;

    private Shift $day;

    private $welder;

    private $trainee;

    private $supervisor;

    private $labourLead;

    protected function setUp(): void
    {
        parent::setUp();
        $this->openBooks();

        $floor = Role::firstOrCreate(['name' => 'mfg test welder', 'guard_name' => 'web']);
        $floor->syncPermissions([RoleCatalog::VIEW_DASHBOARD, RoleCatalog::VIEW_ORDERS, RoleCatalog::EXECUTE]);
        $sup = Role::firstOrCreate(['name' => 'mfg test floor lead', 'guard_name' => 'web']);
        $sup->syncPermissions([RoleCatalog::VIEW_DASHBOARD, RoleCatalog::VIEW_ORDERS, RoleCatalog::EXECUTE, RoleCatalog::SUPERVISE_FLOOR,
            RoleCatalog::VIEW_LABOUR, RoleCatalog::MANAGE_LABOUR]);
        $this->welder = $this->makeUser('welder@example.test', $floor);
        $this->trainee = $this->makeUser('trainee@example.test', $floor);
        $this->supervisor = $this->makeUser('floorlead@example.test', $sup);
        foreach ([$this->welder, $this->trainee, $this->supervisor] as $u) {
            $this->grantModuleTier($u);
        }

        $this->plant = $this->factory('PLT');
        $this->store = $this->warehouse('STORE');
        $this->day = $this->asCompany($this->company, fn () => Shift::create(['company_id' => $this->company->id, 'factory_id' => $this->plant->id,
            'code' => 'D', 'name' => 'Day', 'starts_at' => '06:00', 'ends_at' => '14:00', 'break_minutes' => 0, 'days_of_week' => [1, 2, 3, 4, 5, 6, 7],
            'overtime_multiplier' => 1.5, 'observes_public_holidays' => false, 'is_active' => true]));
        $this->asCompany($this->company, fn () => FactoryPolicy::for($this->plant->id, $this->company->id)->update(['require_material_for_approval' => false]));

        $this->weld = $this->asCompany($this->company, fn () => WorkCentre::create(['company_id' => $this->company->id, 'factory_id' => $this->plant->id,
            'code' => 'WLD', 'name' => 'Welding bay', 'cost_per_hour' => 150, 'labour_rate_per_hour' => 90, 'is_active' => true, 'concurrent_jobs' => 1]));
        $steel = $this->stockItem('STEEL', 'kg', 10);
        $this->frame = $this->asCompany($this->company, fn () => Item::create(['company_id' => $this->company->id,
            'stock_item_id' => $this->stockItem('FRAME', 'ea', 0)->id, 'kind' => 'FINISHED', 'default_uom_id' => $this->uom('ea')->id, 'is_active' => true]));
        $this->approvedBom($this->frame, [[$steel, 3, 0, 'kg']]);
        $this->routing($this->frame, [['Weld', 0, 60, ['work_centre_id' => $this->weld->id]]], [], true);
        $this->asCompany($this->company, fn () => app(StockLedger::class)->receive($steel->fresh(), 500, 10, $this->store, ['reference' => 'GRN']));

        // What each person holds, where HR, learning and SHEQ keep it.
        $c = $this->company->id;
        $skill = DB::table('skills')->insertGetId(['company_id' => $c, 'name' => 'Welding', 'slug' => 'welding-'.Str::random(4), 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('employee_skills')->insert([
            ['company_id' => $c, 'user_id' => $this->welder->id, 'skill_id' => $skill, 'current_level' => 3, 'created_at' => now(), 'updated_at' => now()],
            ['company_id' => $c, 'user_id' => $this->trainee->id, 'skill_id' => $skill, 'current_level' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);
        $course = DB::table('lms_courses')->insertGetId(['company_id' => $c, 'title' => 'Hot Work', 'created_by' => $this->engineer->id, 'created_at' => now(), 'updated_at' => now()]);
        $cert = DB::table('lms_certifications')->insertGetId(['company_id' => $c, 'name' => 'Hot Work', 'course_id' => $course, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('employee_certifications')->insert(['company_id' => $c, 'user_id' => $this->welder->id, 'certification_id' => $cert, 'status' => 'active',
            'expires_at' => now()->addDays(20), 'created_at' => now(), 'updated_at' => now()]);
        $comp = DB::table('sheq_competencies')->insertGetId(['uuid' => (string) Str::uuid(), 'company_id' => $c, 'code' => 'PSR', 'title' => 'Pressure Systems',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $person = DB::table('hr_people')->insertGetId(['user_id' => $this->welder->id, 'first_name' => 'Thandi', 'surname' => 'Welder', 'company_id' => $c, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('sheq_competency_records')->insert(['uuid' => (string) Str::uuid(), 'company_id' => $c, 'competency_id' => $comp, 'hr_person_id' => $person,
            'status' => 'COMPLIANT', 'expires_at' => now()->addYear(), 'created_at' => now(), 'updated_at' => now()]);

        $op = $this->asCompany($this->company, fn () => RoutingOperation::query()->where('work_centre_id', $this->weld->id)->first());
        $this->asCompany($this->company, function () use ($op, $skill, $cert, $comp) {
            foreach ([['SKILL', $skill, 2], ['CERTIFICATION', $cert, null], ['COMPETENCY', $comp, null]] as [$kind, $ref, $level]) {
                OperationRequirement::create(['company_id' => $this->company->id, 'routing_operation_id' => $op->id, 'kind' => $kind,
                    'reference_id' => $ref, 'min_level' => $level, 'is_mandatory' => true]);
            }
        });
    }

    private function order(): PO
    {
        return $this->asCompany($this->company, function () {
            $o = app(ProductionOrders::class)->create(['item' => $this->frame, 'quantity' => 4, 'due_date' => now()->addDays(3)->toDateString(),
                'factory' => $this->plant, 'warehouse' => $this->store], $this->engineer);
            $life = app(ProductionOrderLifecycle::class);
            $life->plan($o, $this->engineer);
            $life->approve($o, $this->approver);
            $life->release($o, $this->approver);

            return $o->fresh();
        });
    }

    public function test_only_a_qualified_operator_can_be_given_or_start_the_job(): void
    {
        $op = $this->order()->operations()->first();
        $floor = app(ShopFloor::class);

        try {
            $this->asCompany($this->company, fn () => $floor->assign($op, $this->trainee, $this->supervisor));
            $this->fail('A job was given to somebody without the qualifications.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('Welding (level 2) (level 1, needs 2)', $e->getMessage());
            $this->assertStringContainsString('Hot Work (not held)', $e->getMessage());
            $this->assertStringContainsString('Pressure Systems (not held)', $e->getMessage());
        }

        // Nor can they start it themselves.
        try {
            $this->asCompany($this->company, fn () => $floor->start($op, $this->trainee));
            $this->fail('An unqualified operator started the job.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('not qualified', $e->getMessage());
        }

        $this->asCompany($this->company, fn () => $floor->assign($op, $this->welder, $this->supervisor));
        $this->asCompany($this->company, fn () => $floor->start($op->fresh(), $this->welder));
        $this->assertSame('IN_PROGRESS', $op->fresh()->status);

        // An expired certificate is the same as none.
        DB::table('employee_certifications')->where('user_id', $this->welder->id)->update(['expires_at' => now()->subDay()]);
        $eligibility = $this->asCompany($this->company, fn () => app(Workforce::class)->eligibility($this->welder, app(Workforce::class)->requirementsFor($op)));
        $this->assertSame('expired', collect($eligibility)->firstWhere('kind', 'CERTIFICATION')['detail']);
    }

    public function test_training_needs_show_what_is_missing_or_expiring_for_people_on_the_roster(): void
    {
        $this->asCompany($this->company, function () {
            foreach ([$this->welder, $this->trainee] as $u) {
                RosterEntry::create(['company_id' => $this->company->id, 'user_id' => $u->id, 'shift_id' => $this->day->id,
                    'work_centre_id' => $this->weld->id, 'work_date' => now()->toDateString()]);
            }
        });

        $needs = collect($this->asCompany($this->company, fn () => app(Workforce::class)->trainingNeeds(30)));
        $this->assertTrue($needs->contains(fn ($n) => str_starts_with($n['person'], 'trainee') && $n['requirement'] === 'Hot Work' && $n['status'] === 'MISSING'));
        // The welder's certificate runs out in 20 days: inside the 30-day window.
        $this->assertTrue($needs->contains(fn ($n) => $n['person'] === 'Thandi Welder' && $n['requirement'] === 'Hot Work' && $n['status'] === 'EXPIRING'));
    }

    public function test_the_roster_against_what_the_schedule_needs_and_hours_for_costing(): void
    {
        $o = $this->order();   // 4 x 60 min = 240 min of welding labour
        $this->asCompany($this->company, fn () => app(Scheduler::class)->schedule('DUE_DATE', 14, [], true, $this->supervisor));

        $gaps = collect($this->asCompany($this->company, fn () => app(Workforce::class)->capacityGaps(now()->startOfDay(), now()->addDays(2))));
        $this->assertEquals(240.0, $gaps->firstWhere('work_centre', 'WLD')['gap'], 'Nobody is rostered, so all of the labour is a gap.');

        $this->actingAs($this->supervisor)->post(route('manufacturing.workforce.roster.store'), [
            'user_id' => $this->welder->id, 'shift_id' => $this->day->id, 'work_centre_id' => $this->weld->id, 'dates' => [now()->toDateString()],
        ])->assertSessionHas('success');
        $gaps = collect($this->asCompany($this->company, fn () => app(Workforce::class)->capacityGaps(now()->startOfDay(), now()->addDays(2))));
        $this->assertEquals(0.0, $gaps->firstWhere('work_centre', 'WLD')['gap']);
        $this->assertEquals(240.0, $gaps->firstWhere('work_centre', 'WLD')['spare']);

        // The welder works 45 minutes; it shows against their 480 rostered, and in the hours file.
        $this->freezeTime();
        $op = $o->operations()->first();
        $this->asCompany($this->company, fn () => app(ShopFloor::class)->start($op, $this->welder));
        $this->travel(45)->minutes();
        $this->asCompany($this->company, fn () => app(ShopFloor::class)->pause($op->fresh(), $this->welder));

        $util = collect($this->asCompany($this->company, fn () => app(Workforce::class)->utilisation(now()->subDay(), now())))->firstWhere('user_id', $this->welder->id);
        $this->assertEquals(45.0, $util['logged_minutes']);
        $this->assertEquals(480.0, $util['rostered_minutes']);

        $this->flushSession();
        $csv = $this->actingAs($this->supervisor)->get(route('manufacturing.workforce.labour.hours'))->assertOk()->streamedContent();
        $this->assertStringContainsString('"Thandi Welder",0.75', $csv);
    }

    public function test_the_workforce_screens_are_for_those_with_the_labour_grant(): void
    {
        // The workforce cards now sit in the production hub; only theirs are compared.
        $cards = array_values(array_filter(array_column($this->actingAs($this->supervisor)->get('/manufacturing/production')->assertOk()->viewData('page')['props']['cards'], 'path'), fn ($p) => str_starts_with($p, '/manufacturing/workforce/')));
        $this->assertEqualsCanonicalizing(['/manufacturing/workforce/roster', '/manufacturing/workforce/qualifications', '/manufacturing/workforce/labour'], $cards);
        $this->actingAs($this->welder)->get(route('manufacturing.workforce.labour'))->assertForbidden();

        $op = $this->order()->operations()->first();
        $page = $this->actingAs($this->supervisor)->get(route('manufacturing.workforce.qualifications', ['operation' => $op->uuid]))->assertOk()->viewData('page');
        $this->assertCount(3, $page['props']['requirements']);
        $people = collect($page['props']['candidates']['people']);
        $this->assertTrue($people->firstWhere('id', $this->welder->id)['eligible']);
        $this->assertFalse($people->firstWhere('id', $this->trainee->id)['eligible']);

        $this->actingAs($this->supervisor)->get(route('manufacturing.workforce.roster'))->assertOk();
        $this->actingAs($this->supervisor)->get(route('manufacturing.workforce.labour'))->assertOk();
    }
}
