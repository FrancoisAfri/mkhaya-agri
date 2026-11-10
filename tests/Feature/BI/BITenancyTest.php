<?php

namespace Tests\Feature\BI;

use App\Core\User\Models\User;
use App\Modules\BI\Mail\BIDashboardMail;
use App\Modules\BI\Services\BIEngine;
use App\Modules\BI\Support\BIAccess;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Feature\Workflow\WorkflowTestCase;

/**
 * BI on a multi-tenant (SaaS) install and a stand-alone (self-hosted) one. Every figure, saved
 * dashboard, export and email schedule stays inside its own company; on a stand-alone install the
 * signed licence, not a company subscription, switches BI on.
 */
class BITenancyTest extends WorkflowTestCase
{
    private Company $other;
    private User $designerA;
    private User $designerB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->other = Company::create(['name' => 'Other Co ' . Str::random(5)]);
        foreach ([$this->company, $this->other] as $c) {
            foreach (['workflow', BIAccess::MODULE] as $m) {
                DB::table('company_modules')->updateOrInsert(['company_id' => $c->id, 'module_code' => $m],
                    ['source' => 'addon', 'price_monthly' => 0, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        Cache::forget('module_active_' . BIAccess::MODULE);
        $this->designerA = $this->designer('a', $this->company);
        $this->designerB = $this->designer('b', $this->other);
    }

    private function designer(string $name, Company $c): User
    {
        $u = $this->user("bi{$name}", []);
        $u->forceFill(['company_id' => $c->id])->save();
        $id = DB::table('security_modules')->where('code_name', BIAccess::MODULE)->value('id');
        DB::table('security_module_access')->updateOrInsert(['module_id' => $id, 'user_id' => $u->id], ['active' => 1, 'access_level' => 5]);

        return $u->fresh();
    }

    private function cases(Company $c, $user): float
    {
        $this->enterTenant($c);

        return (float) app(BIEngine::class)->run(['kind' => 'kpi', 'dataset' => 'wf_instances', 'measures' => ['instances'], 'period' => 'all'], [], $user, false)['values']['instances'];
    }

    public function test_figures_only_count_the_companys_own_rows(): void
    {
        $d = $this->published($this->simpleApprovalGraph());
        $this->startAs($this->requester, $d);
        $this->startAs($this->requester, $d);
        $v = DB::table('workflow_instances')->where('company_id', $this->company->id)->value('version_id');
        $this->enterTenant($this->other);
        DB::table('workflow_instances')->insert(['uuid' => (string) Str::uuid(), 'company_id' => $this->other->id, 'definition_id' => $d->id, 'version_id' => $v,
            'reference' => 'OTHER-1', 'title' => 'Other company case', 'started_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        Cache::flush();

        $this->assertSame(2.0, $this->cases($this->company, $this->designerA));
        $this->assertSame(1.0, $this->cases($this->other, $this->designerB));
    }

    public function test_another_companys_dashboards_exports_and_schedules_are_out_of_reach(): void
    {
        Mail::fake();
        $this->actingAs($this->designerA)->post('/bi', ['name' => 'A only', 'template' => 'farm_overview']);
        $uuid = DB::table('bi_dashboards')->where('company_id', $this->company->id)->value('uuid');
        DB::table('bi_dashboards')->where('uuid', $uuid)->update(['visibility' => 'organisation']);
        $this->actingAs($this->designerA)->post('/bi/schedules', ['dashboard' => $uuid, 'name' => 'A mail', 'format' => 'xlsx', 'period' => 'mtd',
            'frequency' => 'daily', 'send_time' => '07:00', 'recipients' => ['a@example.com']])->assertSessionHas('success');
        $sid = DB::table('bi_schedules')->where('company_id', $this->company->id)->value('id');

        // Organisation-wide means this organisation, not every company on the platform. Company B's
        // request runs with B's database session, as the ResolveCompany middleware sets it live.
        $this->enterTenant($this->other);
        $this->actingAs($this->designerB)->get("/bi/{$uuid}")->assertNotFound();
        $this->actingAs($this->designerB)->get("/bi/export/{$uuid}")->assertNotFound();
        $this->actingAs($this->designerB)->putJson("/bi/{$uuid}", ['name' => 'Taken'])->assertNotFound();
        $this->actingAs($this->designerB)->post("/bi/schedules/{$sid}/send")->assertNotFound();
        $this->actingAs($this->designerB)->delete("/bi/schedules/{$sid}")->assertNotFound();
        $this->actingAs($this->designerB)->post('/bi/schedules', ['dashboard' => $uuid, 'name' => 'Steal', 'format' => 'xlsx', 'period' => 'mtd',
            'frequency' => 'daily', 'send_time' => '07:00', 'recipients' => ['b@example.com']])->assertNotFound();
        $this->actingAs($this->designerB)->get('/bi')->assertInertia(fn ($p) => $p->where('dashboards', []));
        Mail::assertNothingSent();
        $this->assertNull(DB::table('bi_dashboards')->where('uuid', $uuid)->value('name')); // RLS: B's session cannot even read it
        $this->enterTenant($this->company);
        $this->assertSame('A only', DB::table('bi_dashboards')->where('uuid', $uuid)->value('name'));
    }

    public function test_the_scheduler_sends_each_schedule_inside_its_own_company(): void
    {
        Mail::fake();
        foreach ([[$this->designerA, 'a@example.com', $this->company], [$this->designerB, 'b@example.com', $this->other]] as [$u, $to, $c]) {
            $this->enterTenant($c);
            $this->actingAs($u)->post('/bi/schedules', ['dashboard' => 't:farm_overview', 'name' => 'Daily', 'format' => 'xlsx', 'period' => 'mtd',
                'frequency' => 'daily', 'send_time' => '07:00', 'recipients' => [$to]])->assertSessionHas('success');
        }
        app(CurrentCompany::class)->clear();
        DB::statement("SELECT set_config('app.company_id', '', false)"); // the scheduler starts with no tenant, like cron
        $idB = DB::table('bi_schedules')->where('company_id', $this->other->id)->value('id');

        $this->artisan('bi:schedules', ['--id' => $idB])->assertSuccessful();
        Mail::assertSent(BIDashboardMail::class, fn ($m) => $m->hasTo('b@example.com') && $m->company === $this->other->name);
        Mail::assertNotSent(BIDashboardMail::class, fn ($m) => $m->hasTo('a@example.com'));
    }

    public function test_a_stand_alone_install_switches_bi_on_from_its_licence(): void
    {
        config(['bi.included' => false]); // sold as an add-on, so the licence decides
        DB::table('company_modules')->where('module_code', BIAccess::MODULE)->delete();
        Cache::forget('module_active_' . BIAccess::MODULE);
        $this->actingAs($this->designerA)->get('/bi')->assertRedirect(); // SaaS: no add-on, no BI

        config(['edition.edition' => 'self_hosted']);
        Cache::forget('module_active_' . BIAccess::MODULE);
        // No licence installed = licensing dormant = everything allowed (as on a fresh self-hosted box).
        $this->actingAs($this->designerA)->get('/bi')->assertOk()->assertInertia(fn ($p) => $p->where('canDesign', true));
        $this->actingAs($this->designerA)->post('/bi', ['name' => 'Stand-alone', 'template' => 'farm_overview'])->assertRedirect();
        $this->assertSame($this->company->id, (int) DB::table('bi_dashboards')->where('name', 'Stand-alone')->value('company_id'));
    }
}
