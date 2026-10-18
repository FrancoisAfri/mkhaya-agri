<?php

namespace Tests\Feature\Onboarding;

use App\Modules\HR\Onboarding\Models\OnboardingTemplate;
use Database\Seeders\OnboardingTemplateCatalogueSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The shared onboarding catalogue: readable by every tenant, writable by none.
 *
 * The whole arrangement rests on one subtlety. The existing RLS policy is
 * `session IS NULL OR company_id = session`, and a catalogue row has `company_id = NULL` — so
 * `NULL = 5` evaluates to NULL, which is not true, and the row is invisible. Seeding the catalogue
 * without the accompanying `global_catalogue_read` policy produces 39 templates nobody can see and
 * no error anywhere. That is what these tests exist to catch.
 *
 * Edition-neutral by construction: the rules are row-level security and a null company_id, neither
 * of which knows what a licence is. The self-hosted install is simply a platform with one tenant.
 */
class GlobalTemplateCatalogueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(OnboardingTemplateCatalogueSeeder::class);
    }

    /** Enter a tenant the way a request does — by setting the session variable RLS reads. */
    private function asCompany(int $companyId): void
    {
        DB::statement("SET app.company_id = '{$companyId}'");
    }

    private function untenanted(): void
    {
        DB::statement('RESET app.company_id');
    }

    public function test_the_catalogue_is_seeded_globally_and_belongs_to_no_company(): void
    {
        $this->untenanted();

        $templates = DB::table('onboarding_templates')->whereNull('company_id')->count();

        $this->assertGreaterThanOrEqual(30, $templates, 'The catalogue should be broad, not a token few.');
        $this->assertSame(
            0,
            DB::table('onboarding_templates')->whereNull('company_id')->whereNull('catalogue_key')->count(),
            'Every catalogue row carries its key, which is what makes re-seeding an upgrade rather than a duplicate.'
        );
    }

    public function test_every_tenant_can_read_the_whole_catalogue(): void
    {
        $this->untenanted();
        $expected = DB::table('onboarding_templates')->whereNull('company_id')->count();

        foreach ([1, 2, 3] as $companyId) {
            $this->asCompany($companyId);

            $this->assertSame(
                $expected,
                DB::table('onboarding_templates')->whereNull('company_id')->count(),
                "Company {$companyId} cannot see the catalogue — the global_catalogue_read policy is missing or wrong."
            );

            $this->assertGreaterThan(
                0,
                DB::table('onboarding_template_tasks')->whereNull('company_id')->count(),
                'A template without its tasks is an empty checklist.'
            );
        }

        $this->untenanted();
    }

    public function test_a_tenant_cannot_edit_or_delete_the_catalogue(): void
    {
        $this->asCompany(1);

        $updated = DB::table('onboarding_templates')->whereNull('company_id')->update(['name' => 'HIJACKED']);
        $deleted = DB::table('onboarding_templates')->whereNull('company_id')->delete();

        $this->assertSame(0, $updated, 'A tenant rewrote the shared catalogue.');
        $this->assertSame(0, $deleted, 'A tenant deleted from the shared catalogue.');

        $this->untenanted();

        $this->assertSame(0, DB::table('onboarding_templates')->where('name', 'HIJACKED')->count());
    }

    public function test_one_tenants_own_template_is_invisible_to_another(): void
    {
        $this->asCompany(1);

        DB::table('onboarding_templates')->insert([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'name' => 'Company One Private', 'is_active' => true, 'company_id' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->asCompany(2);

        $this->assertSame(
            0,
            DB::table('onboarding_templates')->where('name', 'Company One Private')->count(),
            'Opening the catalogue up must not have opened up tenant templates with it.'
        );

        $this->untenanted();
    }

    /** The copy is the tenant's own — editable, and detached from the catalogue. */
    public function test_copying_a_catalogue_template_produces_an_editable_tenant_copy(): void
    {
        $this->untenanted();

        $source = OnboardingTemplate::whereNull('company_id')->with('templateTasks')->first();

        $this->assertNotNull($source);
        $this->assertTrue($source->isGlobal());

        $copy = OnboardingTemplate::create([
            'name' => $source->name, 'description' => $source->description,
            'is_active' => true, 'company_id' => 1, 'created_by' => null,
            'copied_from_template_id' => $source->id,
        ]);

        foreach ($source->templateTasks as $task) {
            $copy->templateTasks()->create([
                'type' => $task->type, 'title' => $task->title, 'day_offset' => $task->day_offset,
                'owner_type' => $task->owner_type, 'sort_order' => $task->sort_order, 'company_id' => 1,
            ]);
        }

        $this->asCompany(1);

        $fresh = OnboardingTemplate::find($copy->id);

        $this->assertFalse($fresh->isGlobal(), 'The copy belongs to the tenant that made it.');
        $this->assertSame($source->id, $fresh->copied_from_template_id);
        $this->assertSame($source->templateTasks->count(), $fresh->templateTasks()->count());

        $fresh->update(['name' => 'Our Version']);
        $this->assertSame('Our Version', $fresh->fresh()->name, 'A tenant must be able to edit its own copy.');

        $this->untenanted();

        $this->assertNotSame('Our Version', $source->fresh()->name, 'Editing a copy changed the catalogue.');
    }

    public function test_re_seeding_upgrades_the_catalogue_rather_than_duplicating_it(): void
    {
        $this->untenanted();

        $before = DB::table('onboarding_templates')->whereNull('company_id')->count();

        $this->seed(OnboardingTemplateCatalogueSeeder::class);

        $this->assertSame(
            $before,
            DB::table('onboarding_templates')->whereNull('company_id')->count(),
            'A second run duplicated the catalogue instead of updating it in place.'
        );
    }
}
