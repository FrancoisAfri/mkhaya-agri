<?php

namespace Tests\Feature\ProjectManagement;

use App\Modules\Operations\ProjectManagement\Contracts\Providers\ContractorProvider;
use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Models\ProjectMember;
use App\Modules\Operations\ProjectManagement\Providers\Integrated\SupplierContractorProvider;
use App\Modules\Operations\ProjectManagement\Providers\Local\LocalContractorProvider;
use App\Modules\Operations\ProjectManagement\Services\ContractorRegistryService;
use App\Modules\Operations\ProjectManagement\Services\ProviderRegistry;
use App\Modules\Tenancy\Models\Company;
use Illuminate\Support\Facades\DB;

/**
 * The subcontractor register, on a tenant that has Project Management alone and on one that also
 * has Supplier Management.
 *
 * That split is the whole reason this is a provider seam rather than a table. The client put it
 * plainly: somebody may buy PM on its own, and a subcontractor is not optional — so PM owns the
 * register when it is alone, and points at the supplier records where those exist.
 */
class ContractorRegistryTest extends ProjectManagementTestCase
{
    private Company $company;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['name' => 'Aseda Register']);
        $this->entitleCompany($this->company);
        $this->manager->forceFill(['company_id' => $this->company->id])->save();

        $this->project = $this->asCompany(
            $this->company,
            fn () => $this->makeProject(['code' => 'MP23001', 'name' => 'Kwaggafontein'])
        );
    }

    /** The id the register itself uses, which is the provider's and not the engagement's key. */
    private function registerId(): string
    {
        return $this->asCompany(
            $this->company,
            fn () => app(ContractorRegistryService::class)->all()[0]['id'],
        );
    }

    /** Standalone is the default, and it is a complete state rather than a pending one. */
    public function test_project_management_alone_owns_the_register(): void
    {
        $this->asCompany($this->company, function () {
            $provider = app(ProviderRegistry::class)->for(ContractorProvider::class);

            $this->assertInstanceOf(LocalContractorProvider::class, $provider);
            $this->assertFalse($provider->isAuthoritative());
        });
    }

    /**
     * A tenant with Supplier Management gets its records instead.
     *
     * Asserted on the binding rather than by switching a module on mid-test: entitlement runs
     * through platform module rows, plan checks and a per-tenant cache, all of which have their own
     * tests. What is this feature's own is that the seam exists, upgrades on `procurement.suppliers`,
     * and that the integrated side really is authoritative — a provider that answered false there
     * would have PM quietly writing a second contractor list beside procurement's.
     */
    public function test_the_supplier_module_takes_over_the_register(): void
    {
        $bindings = (new \ReflectionClass(ProviderRegistry::class))->getConstant('BINDINGS');

        $this->assertArrayHasKey(ContractorProvider::class, $bindings);
        $this->assertSame(
            ['procurement.suppliers', SupplierContractorProvider::class, LocalContractorProvider::class],
            $bindings[ContractorProvider::class],
        );

        $this->assertTrue((new SupplierContractorProvider())->isAuthoritative());
        $this->assertFalse((new LocalContractorProvider())->isAuthoritative());
    }

    /** Added standalone, a subcontractor is PM's own record and is found again by name. */
    public function test_a_subcontractor_is_added_and_searchable_standalone(): void
    {
        $this->actingAs($this->manager)
            ->post(route('project-management.contractors.store'), [
                'name'       => 'Sizwe Plant Hire',
                'email'      => 'accounts@sizweplant.co.za',
                'vat_number' => '4123456789',
            ])->assertRedirect();

        $this->actingAs($this->manager)
            ->get(route('project-management.contractors.register'))
            ->assertInertia(fn ($page) => $page
                ->where('integrated', false)
                ->has('contractors', 1)
                ->where('contractors.0.name', 'Sizwe Plant Hire')
                ->where('contractors.0.meta.vat_number', '4123456789')
                // On the register before there is a job for them, which is a real state.
                ->where('contractors.0.projects', 0));
    }

    /** Added with a project, they are engaged on it and given portal access in one step. */
    public function test_adding_with_a_project_engages_them_and_invites_them(): void
    {
        $this->actingAs($this->manager)
            ->post(route('project-management.contractors.store'), [
                'name'    => 'Sizwe Plant Hire',
                'email'   => 'accounts@sizweplant.co.za',
                'project' => $this->project->uuid,
            ])->assertRedirect();

        $this->asCompany($this->company, function () {
            $member = ProjectMember::where('project_id', $this->project->id)
                ->where('role_key', 'contractor')->firstOrFail();

            $this->assertSame('Sizwe Plant Hire', $member->display_name);
            $this->assertNotNull($member->external_ref_id, 'The engagement must point at the register entry.');

            // The invitation is what makes them able to invoice, so it goes with the engagement.
            $this->assertDatabaseHas('pm_member_invites', ['member_id' => $member->id]);
        });
    }

    /** One firm, many projects — which is the point of a register rather than a member list. */
    public function test_one_firm_can_be_engaged_on_several_projects(): void
    {
        $second = $this->asCompany(
            $this->company,
            fn () => $this->makeProject(['code' => 'MP23002', 'name' => 'Marble Hall'])
        );

        $this->actingAs($this->manager)
            ->post(route('project-management.contractors.store'), [
                'name' => 'Sizwe Plant Hire', 'email' => 'accounts@sizweplant.co.za',
                'project' => $this->project->uuid,
            ])->assertRedirect();

        $id = $this->registerId();

        $this->actingAs($this->manager)
            ->post(route('project-management.contractors.engage', $id), ['project' => $second->uuid])
            ->assertRedirect();

        $this->actingAs($this->manager)
            ->get(route('project-management.contractors.show', $id))
            ->assertInertia(fn ($page) => $page
                ->where('contractor.name', 'Sizwe Plant Hire')
                ->has('contractor.projects', 2));

        // Still one row on the register, not two.
        $this->actingAs($this->manager)
            ->get(route('project-management.contractors.register'))
            ->assertInertia(fn ($page) => $page->has('contractors', 1)
                ->where('contractors.0.projects', 2));
    }

    /** Engaging twice on the same project keeps one engagement, not two. */
    public function test_engaging_twice_does_not_split_their_invoices(): void
    {
        $this->actingAs($this->manager)
            ->post(route('project-management.contractors.store'), [
                'name' => 'Sizwe Plant Hire', 'project' => $this->project->uuid,
            ])->assertRedirect();

        $id = $this->registerId();

        $this->actingAs($this->manager)
            ->post(route('project-management.contractors.engage', $id), ['project' => $this->project->uuid])
            ->assertRedirect();

        $this->asCompany($this->company, fn () => $this->assertSame(
            1,
            ProjectMember::where('project_id', $this->project->id)->where('role_key', 'contractor')->count(),
        ));
    }

    /** A subcontractor added on one tenant is invisible on another. */
    public function test_the_register_is_scoped_to_its_tenant(): void
    {
        $this->actingAs($this->manager)
            ->post(route('project-management.contractors.store'), ['name' => 'Sizwe Plant Hire'])
            ->assertRedirect();

        $other = Company::create(['name' => 'Someone Else']);

        $this->asCompany($other, function () {
            $found = app(ContractorRegistryService::class)->all();

            $this->assertSame([], $found, 'Another tenant must not see this register.');
        });
    }

    /** Search narrows the register rather than the browser having to. */
    public function test_the_register_can_be_searched(): void
    {
        foreach (['Sizwe Plant Hire', 'Bokamoso Civils'] as $name) {
            $this->actingAs($this->manager)
                ->post(route('project-management.contractors.store'), ['name' => $name])->assertRedirect();
        }

        $this->actingAs($this->manager)
            ->get(route('project-management.contractors.register', ['q' => 'bokamoso']))
            ->assertInertia(fn ($page) => $page
                ->has('contractors', 1)
                ->where('contractors.0.name', 'Bokamoso Civils'));
    }
}
