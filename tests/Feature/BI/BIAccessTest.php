<?php

namespace Tests\Feature\BI;

use App\Core\User\Models\User;
use App\Modules\BI\Support\BIAccess;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Workflow\WorkflowTestCase;

/**
 * BI is a paid add-on: no add-on, no BI. With it, BI level 1 opens templates and shared dashboards;
 * only level 4+ (admins, senior executives) designs. Each visual is gated again by the module behind it.
 */
class BIAccessTest extends WorkflowTestCase
{
    private User $viewer;
    private User $designer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->viewer = $this->biUser('viewer', 1);
        $this->designer = $this->biUser('designer', 5);
    }

    private function biUser(string $name, int $level): User
    {
        $u = $this->user($name, []);
        $id = DB::table('security_modules')->where('code_name', BIAccess::MODULE)->value('id');
        DB::table('security_module_access')->updateOrInsert(['module_id' => $id, 'user_id' => $u->id], ['active' => 1, 'access_level' => $level]);

        return $u;
    }

    private function switchOnBi(): void
    {
        DB::table('company_modules')->updateOrInsert(['company_id' => $this->company->id, 'module_code' => BIAccess::MODULE],
            ['source' => 'addon', 'price_monthly' => 0, 'created_at' => now(), 'updated_at' => now()]);
        Cache::forget('module_active_' . BIAccess::MODULE);
    }

    public function test_a_company_without_the_add_on_gets_no_bi(): void
    {
        $this->actingAs($this->designer)->get('/bi')->assertRedirect(route('dashboard'))->assertSessionHas('error');
        $this->actingAs($this->designer)->postJson('/bi/query', ['config' => ['kind' => 'kpi', 'dataset' => 'fin_sales']])->assertStatus(403);
    }

    public function test_a_viewer_opens_templates_but_cannot_design(): void
    {
        $this->switchOnBi();
        $this->actingAs($this->viewer)->get('/bi')->assertOk()->assertInertia(fn ($p) => $p->component('Modules/BI/Index', false)->where('canDesign', false));
        $this->actingAs($this->viewer)->get('/bi/t/farm_overview')->assertOk();
        $this->actingAs($this->viewer)->post('/bi', ['name' => 'Mine', 'template' => 'farm_overview'])->assertStatus(403);
        $this->assertSame(0, DB::table('bi_dashboards')->where('company_id', $this->company->id)->count());
    }

    public function test_a_designer_copies_a_template_and_others_cannot_change_it(): void
    {
        $this->switchOnBi();
        $this->actingAs($this->designer)->post('/bi', ['name' => 'CEO (mine)', 'template' => 'farm_overview'])->assertRedirect();
        $d = DB::table('bi_dashboards')->where('company_id', $this->company->id)->first();
        $this->assertSame('farm_overview', $d->template_key);
        $this->assertNotEmpty(json_decode($d->widgets, true));

        $this->actingAs($this->designer)->get("/bi/{$d->uuid}?edit=1")->assertOk()->assertInertia(fn ($p) => $p->where('editing', true));
        // Private: another person in the company cannot open or change it.
        $this->actingAs($this->viewer)->get("/bi/{$d->uuid}")->assertNotFound();
        $this->actingAs($this->viewer)->putJson("/bi/{$d->uuid}", ['name' => 'Hijacked', 'widgets' => []])->assertStatus(404);
    }

    public function test_a_visual_on_a_module_the_company_does_not_have_is_locked(): void
    {
        $this->switchOnBi();
        // The test company subscribes to workflow + BI only, so finance data is outside its subscription.
        $this->actingAs($this->designer)->postJson('/bi/query', ['config' => ['kind' => 'kpi', 'dataset' => 'fin_sales', 'measures' => ['revenue']]])
            ->assertOk()->assertJson(['locked' => 'subscription']);
    }
}
